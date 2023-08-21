package gallery.memories.service

import android.annotation.SuppressLint
import android.app.Activity
import android.database.ContentObserver
import android.net.Uri
import android.os.Build
import android.provider.MediaStore
import android.util.Log
import androidx.activity.result.ActivityResult
import androidx.activity.result.ActivityResultLauncher
import androidx.activity.result.IntentSenderRequest
import androidx.activity.result.contract.ActivityResultContracts
import androidx.exifinterface.media.ExifInterface
import androidx.media3.common.util.UnstableApi
import gallery.memories.MainActivity
import gallery.memories.R
import gallery.memories.mapper.Fields
import gallery.memories.mapper.SystemImage
import org.json.JSONArray
import org.json.JSONException
import org.json.JSONObject
import java.io.IOException
import java.time.Instant
import java.util.concurrent.CountDownLatch

@UnstableApi class TimelineQuery(private val mCtx: MainActivity) {
    private val mDbService = DbService(mCtx).initialize()
    private val TAG = TimelineQuery::class.java.simpleName

    // Photo deletion events
    var deleting = false
    var deleteIntentLauncher: ActivityResultLauncher<IntentSenderRequest>
    var deleteCallback: ((ActivityResult?) -> Unit)? = null

    // Caches
    var mEnabledBuckets: Set<String>? = null

    // Observers
    var imageObserver: ContentObserver? = null
    var videoObserver: ContentObserver? = null
    var refreshPending: Boolean = false

    companion object {
        val okResponse get(): JSONObject {
            return JSONObject().put("message", "ok")
        }
    }

    init {
        // Register intent launcher for callback
        deleteIntentLauncher = mCtx.registerForActivityResult(ActivityResultContracts.StartIntentSenderForResult()) { result: ActivityResult? ->
            synchronized(this) {
                deleteCallback?.let { it(result) }
            }
        }
    }

    fun initialize() {
        if (syncDeltaDb() > 0) {
            mCtx.refreshTimeline()
        }
        registerHooks()
    }

    fun destroy() {
        if (imageObserver != null) {
            mCtx.contentResolver.unregisterContentObserver(imageObserver!!)
        }
        if (videoObserver != null) {
            mCtx.contentResolver.unregisterContentObserver(videoObserver!!)
        }
    }

    fun registerHooks() {
        imageObserver = registerContentObserver(SystemImage.IMAGE_URI)
        videoObserver = registerContentObserver(SystemImage.VIDEO_URI)
    }

    private fun registerContentObserver(uri: Uri): ContentObserver {
        val observer = @UnstableApi object : ContentObserver(null) {
            override fun onChange(selfChange: Boolean) {
                super.onChange(selfChange)

                // Debounce refreshes
                synchronized(this@TimelineQuery) {
                    if (refreshPending) return
                    refreshPending = true
                }

                // Refresh after 750ms
                Thread {
                    Thread.sleep(750)
                    synchronized(this@TimelineQuery) {
                        refreshPending = false
                    }

                    // Check if anything to update
                    if (syncDeltaDb() == 0 || mCtx.isDestroyed || mCtx.isFinishing) return@Thread

                    mCtx.refreshTimeline()
                }.start()
            }
        }

        mCtx.contentResolver.registerContentObserver(uri, true, observer)
        return observer
    }

    @Throws(JSONException::class)
    fun getByDayId(dayId: Long): JSONArray {
        // Get the photos for the day from DB
        val dbPhotos = mDbService.getPhotosByDay(dayId, getEnabledBucketIds())
        val fileIds = dbPhotos.map { it.localId }.toMutableList()
        if (fileIds.isEmpty()) return JSONArray()

        // Get latest metadata from system table
        val photos = SystemImage.getByIds(mCtx, fileIds).map { image ->
            // Mark file exists
            fileIds.remove(image.fileId)

            // Add missing dayId to JSON
            image.json.put(Fields.Photo.DAYID, dayId)
        }.let { JSONArray(it) }

        // Remove files that were not found
        mDbService.deleteFileIds(fileIds)

        return photos
    }

    @Throws(JSONException::class)
    fun getDays(): JSONArray {
        return mDbService.getDays(getEnabledBucketIds()).map { day -> day.json }.let { JSONArray(it) }
    }

    @Throws(Exception::class)
    fun getImageInfo(id: Long): JSONObject {
        val photos = mDbService.getPhotosByFileIds(listOf(id))
        if (photos.isEmpty()) throw Exception("File not found in database")

        // Get image from system table
        val images = SystemImage.getByIds(mCtx, listOf(id))
        if (images.isEmpty()) throw Exception("File not found in system")

        // Get the photo and image
        val photo = photos[0]
        val image = images[0];

        // Augment image JSON with database info
        val obj = image.json
            .put(Fields.Photo.DAYID, photo.dayId)
            .put(Fields.Photo.DATETAKEN, photo.dateTaken)
            .put(Fields.Photo.PERMISSIONS, Fields.Perm.DELETE)

        try {
            val exif = ExifInterface(image.dataPath)
            obj.put(Fields.Photo.EXIF, JSONObject()
                .put("Aperture", exif.getAttribute(ExifInterface.TAG_APERTURE_VALUE))
                .put("FocalLength", exif.getAttribute(ExifInterface.TAG_FOCAL_LENGTH))
                .put("FNumber", exif.getAttribute(ExifInterface.TAG_F_NUMBER))
                .put("ShutterSpeed", exif.getAttribute(ExifInterface.TAG_SHUTTER_SPEED_VALUE))
                .put("ExposureTime", exif.getAttribute(ExifInterface.TAG_EXPOSURE_TIME))
                .put("ISO", exif.getAttribute(ExifInterface.TAG_ISO_SPEED))
                .put("DateTimeOriginal", exif.getAttribute(ExifInterface.TAG_DATETIME_ORIGINAL))
                .put("OffsetTimeOriginal", exif.getAttribute(ExifInterface.TAG_OFFSET_TIME_ORIGINAL))
                .put("GPSLatitude", exif.getAttribute(ExifInterface.TAG_GPS_LATITUDE))
                .put("GPSLongitude", exif.getAttribute(ExifInterface.TAG_GPS_LONGITUDE))
                .put("GPSAltitude", exif.getAttribute(ExifInterface.TAG_GPS_ALTITUDE))
                .put("Make", exif.getAttribute(ExifInterface.TAG_MAKE))
                .put("Model", exif.getAttribute(ExifInterface.TAG_MODEL))
                .put("Orientation", exif.getAttribute(ExifInterface.TAG_ORIENTATION))
                .put("Description", exif.getAttribute(ExifInterface.TAG_IMAGE_DESCRIPTION))
            )
        } catch (e: IOException) {
            Log.w(TAG, "Error reading EXIF data for $id")
        }

        return obj

    }

    @Throws(Exception::class)
    fun delete(auids: List<Long>): JSONObject {
        synchronized(this) {
            if (deleting) {
                throw Exception("Already deleting another set of images")
            }
            deleting = true
        }

        try {
            // Get list of file IDs
            val photos = mDbService.getPhotosByAUIDs(auids)
            if (photos.isEmpty()) return okResponse
            val fileIds = photos.map { it.localId }

            // List of URIs
            val uris = SystemImage.getByIds(mCtx, fileIds).map { it.uri }
            if (uris.isEmpty()) return okResponse

            // Delete file with media store
            if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.R) {
                val intent = MediaStore.createTrashRequest(mCtx.contentResolver, uris, true)
                deleteIntentLauncher.launch(IntentSenderRequest.Builder(intent.intentSender).build())

                // Wait for response
                val latch = CountDownLatch(1)
                var res: ActivityResult? = null
                deleteCallback = fun(result: ActivityResult?) {
                    res = result
                    latch.countDown()
                }
                latch.await()
                deleteCallback = null;

                // Throw if canceled or failed
                if (res == null || res!!.resultCode != Activity.RESULT_OK) {
                    throw Exception("Delete canceled or failed")
                }
            } else {
                for (uri in uris) {
                    mCtx.contentResolver.delete(uri, null, null)
                }
            }

            // Delete from database
            mDbService.deleteFileIds(fileIds)
        } finally {
            synchronized(this) { deleting = false }
        }

        return okResponse
    }

    private fun syncDb(startTime: Long): Int {
        // Date modified is in seconds, not millis
        val syncTime = Instant.now().toEpochMilli() / 1000;

        // SystemImage query
        var selection: String? = null
        var selectionArgs: Array<String>? = null

        // Query everything modified after startTime
        if (startTime != 0L) {
            selection = MediaStore.Images.Media.DATE_MODIFIED + " > ?"
            selectionArgs = arrayOf(startTime.toString())
        }

        // Iterate all images and videos from system store
        val files =
            SystemImage.query(mCtx, SystemImage.IMAGE_URI, selection, selectionArgs, null) +
            SystemImage.query(mCtx, SystemImage.VIDEO_URI, selection, selectionArgs, null)
        files.forEach { insertItemDb(it) }

        // Store last sync time
        mCtx.getSharedPreferences(mCtx.getString(R.string.preferences_key), 0).edit()
            .putLong(mCtx.getString(R.string.preferences_last_sync_time), syncTime)
            .apply()

        // Number of updated files
        return files.size
    }

    fun syncDeltaDb(): Int {
        // Get last sync time
        val syncTime = mCtx.getSharedPreferences(mCtx.getString(R.string.preferences_key), 0)
            .getLong(mCtx.getString(R.string.preferences_last_sync_time), 0L)
        return syncDb(syncTime)
    }

    fun syncFullDb() {
        // Flag all images for removal
        mDbService.flagAll()

        // Sync all files, marking them in the process
        syncDb(0L)

        // Clean up stale files
        mDbService.deleteFlagged()
    }

    @SuppressLint("SimpleDateFormat")
    private fun insertItemDb(image: SystemImage) {
        val fileId = image.fileId
        val baseName = image.baseName

        // Check if file with local_id and mtime already exists
        val l = mDbService.getPhotosByFileIds(listOf(fileId))
        if (!l.isEmpty() && l[0].mtime == image.mtime) {
            // File already exists, remove flag
            mDbService.unflag(fileId)
            Log.v(TAG, "File already exists: $fileId / $baseName")
            return
        }

        // Delete file with same local_id and insert new one
        mDbService.deleteFileIds(listOf(fileId))
        mDbService.insertImage(image)
        Log.v(TAG, "Inserted file to local DB: $fileId / $baseName")
    }

    fun getEnabledBucketIds(): Set<String> {
        if (mEnabledBuckets != null) return mEnabledBuckets!!
        mEnabledBuckets = mCtx.getSharedPreferences(mCtx.getString(R.string.preferences_key), 0)
            .getStringSet(mCtx.getString(R.string.preferences_enabled_local_folders), null) ?: setOf()
        return mEnabledBuckets!!
    }

    fun getLocalFoldersConfig(): JSONArray {
        val enabledSet = getEnabledBucketIds()

        return mDbService.getBuckets().map {
            JSONObject()
                .put("id", it.key)
                .put("name", it.value)
                .put("enabled", enabledSet.contains(it.key))
        }.let { JSONArray(it) }
    }

    fun configSetLocalFolders(json: String) {
        val enabledSet = mutableSetOf<String>()
        val array = JSONArray(json)
        for (i in 0 until array.length()) {
            val obj = array.getJSONObject(i)
            if (obj.getBoolean("enabled")) {
                enabledSet.add(obj.getLong("id").toString())
            }
        }
        mEnabledBuckets = enabledSet
        mCtx.getSharedPreferences(mCtx.getString(R.string.preferences_key), 0).edit()
            .putStringSet(mCtx.getString(R.string.preferences_enabled_local_folders), enabledSet)
            .apply()
    }
}