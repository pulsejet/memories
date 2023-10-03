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
import gallery.memories.dao.AppDatabase
import gallery.memories.mapper.Fields
import gallery.memories.mapper.Response
import gallery.memories.mapper.SystemImage
import org.json.JSONArray
import org.json.JSONException
import org.json.JSONObject
import java.io.IOException
import java.time.Instant
import java.util.concurrent.CountDownLatch

@UnstableApi
class TimelineQuery(private val mCtx: MainActivity) {
    private val TAG = TimelineQuery::class.java.simpleName
    private val mConfigService = ConfigService(mCtx)

    // Database
    private val mDb = AppDatabase.get(mCtx)
    private val mPhotoDao = mDb.photoDao()

    // Photo deletion events
    var deleting = false
    var deleteIntentLauncher: ActivityResultLauncher<IntentSenderRequest>
    var deleteCallback: ((ActivityResult?) -> Unit)? = null

    // Observers
    var imageObserver: ContentObserver? = null
    var videoObserver: ContentObserver? = null
    var refreshPending: Boolean = false

    // Status of synchronization process
    // -1 = not started
    // >0 = number of files updated
    var syncStatus = -1

    init {
        // Register intent launcher for callback
        deleteIntentLauncher =
            mCtx.registerForActivityResult(ActivityResultContracts.StartIntentSenderForResult()) { result: ActivityResult? ->
                synchronized(this) {
                    deleteCallback?.let { it(result) }
                }
            }
    }

    /**
     * Initialize content observers for system store.
     * Runs the first sync pass.
     */
    fun initialize() {
        mPhotoDao.ping()
        if (syncDeltaDb() > 0) {
            mCtx.refreshTimeline()
        }
        registerHooks()
    }

    /**
     * Destroy content observers for system store.
     */
    fun destroy() {
        if (imageObserver != null)
            mCtx.contentResolver.unregisterContentObserver(imageObserver!!)
        if (videoObserver != null)
            mCtx.contentResolver.unregisterContentObserver(videoObserver!!)
    }

    /**
     * Register content observers for system store.
     */
    fun registerHooks() {
        imageObserver = registerContentObserver(SystemImage.IMAGE_URI)
        videoObserver = registerContentObserver(SystemImage.VIDEO_URI)
    }

    /**
     * Register content observer for system store.
     * @param uri Content URI
     * @return Content observer
     */
    private fun registerContentObserver(uri: Uri): ContentObserver {
        val observer = object : ContentObserver(null) {
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

    /**
     * Get system images by AUIDs
     * @param auids List of AUIDs
     * @return List of SystemImage
     */
    fun getSystemImagesByAUIDs(auids: List<Long>): List<SystemImage> {
        val photos = mPhotoDao.getPhotosByAUIDs(auids)
        if (photos.isEmpty()) return listOf()
        return SystemImage.getByIds(mCtx, photos.map { it.localId })
    }

    /**
     * Get the days response for local files.
     * @return JSON response
     */
    @Throws(JSONException::class)
    fun getDays(): JSONArray {
        return mPhotoDao.getDays(mConfigService.enabledBucketIds).map {
            JSONObject()
                .put(Fields.Day.DAYID, it.dayId)
                .put(Fields.Day.COUNT, it.count)
        }.let { JSONArray(it) }
    }

    /**
     * Get the day response for local files.
     * @param dayId Day ID
     * @return JSON response
     */
    @Throws(JSONException::class)
    fun getDay(dayId: Long): JSONArray {
        // Get the photos for the day from DB
        val fileIds = mPhotoDao.getPhotosByDay(dayId, mConfigService.enabledBucketIds)
            .map { it.localId }.toMutableList()
        if (fileIds.isEmpty()) return JSONArray()

        // Get latest metadata from system table
        val photos = SystemImage.getByIds(mCtx, fileIds).map { image ->
            // Mark file exists
            fileIds.remove(image.fileId)

            // Add missing dayId to JSON
            image.json.put(Fields.Photo.DAYID, dayId)
        }.let { JSONArray(it) }

        // Remove files that were not found
        mPhotoDao.deleteFileIds(fileIds)

        return photos
    }

    /**
     * Get the image EXIF info response for local files.
     * @param id File ID
     * @return JSON response
     */
    @Throws(Exception::class)
    fun getImageInfo(id: Long): JSONObject {
        val photos = mPhotoDao.getPhotosByFileIds(listOf(id))
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
            obj.put(Fields.Photo.EXIF, JSONObject().apply {
                Fields.EXIF.MAP.forEach { (key, field) ->
                    put(field, exif.getAttribute(key))
                }
            })
        } catch (e: IOException) {
            Log.w(TAG, "Error reading EXIF data for $id")
        }

        return obj

    }

    /**
     * Delete images from local database and system store.
     * @param auids List of AUIDs
     * @param dry Dry run (returns whether confirmation will be needed)
     * @return JSON response
     */
    @Throws(Exception::class)
    fun delete(auids: List<Long>, dry: Boolean): JSONObject {
        synchronized(this) {
            if (deleting) throw Exception("Already deleting another set of images")
            deleting = true
        }

        val response = Response.OK

        try {
            // Get list of file IDs
            val sysImgs = getSystemImagesByAUIDs(auids)

            // Let the UI know how many files we are deleting
            response.put("count", sysImgs.size)
            // Let the UI know if we are going to ask for confirmation
            response.put("confirms", Build.VERSION.SDK_INT >= Build.VERSION_CODES.R)

            // Exit if dry or nothing to do
            if (dry || sysImgs.isEmpty()) return response

            // List of URIs
            val uris = sysImgs.map { it.uri }
            if (uris.isEmpty()) return Response.OK

            // Delete file with media store
            if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.R) {
                val intent = MediaStore.createTrashRequest(mCtx.contentResolver, uris, true)
                deleteIntentLauncher.launch(
                    IntentSenderRequest.Builder(intent.intentSender).build()
                )

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
            mPhotoDao.deleteFileIds(sysImgs.map { it.fileId })
        } finally {
            synchronized(this) { deleting = false }
        }

        return response
    }

    /**
     * Sync local database with system store.
     * @param startTime Only sync files modified after this time
     * @return Number of updated files
     */
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

        // Count number of updates
        var updates = 0

        try {
            // Iterate all images from system store
            for (image in SystemImage.cursor(
                mCtx,
                SystemImage.IMAGE_URI,
                selection,
                selectionArgs,
                null
            )) {
                insertItemDb(image)
                updates++
                syncStatus = updates
            }

            // Iterate all videos from system store
            for (video in SystemImage.cursor(
                mCtx,
                SystemImage.VIDEO_URI,
                selection,
                selectionArgs,
                null
            )) {
                insertItemDb(video)
                updates++
                syncStatus = updates
            }

            // Store last sync time
            mCtx.getSharedPreferences(mCtx.getString(R.string.preferences_key), 0).edit()
                .putLong(mCtx.getString(R.string.preferences_last_sync_time), syncTime)
                .apply()
        } catch (e: Exception) {
            Log.e(TAG, "Error syncing database", e)
        }

        // Reset sync status
        synchronized(this) {
            syncStatus = -1
        }

        // Number of updated files
        return updates
    }

    /**
     * Sync local database with system store.
     * @return Number of updated files
     */
    fun syncDeltaDb(): Int {
        // Exit if already running
        synchronized(this) {
            if (syncStatus != -1) return 0
            syncStatus = 0
        }

        // Get last sync time
        val syncTime = mCtx.getSharedPreferences(mCtx.getString(R.string.preferences_key), 0)
            .getLong(mCtx.getString(R.string.preferences_last_sync_time), 0L)
        return syncDb(syncTime)
    }

    /**
     * Sync local database with system store.
     * Runs a full synchronization pass, flagging all files for removal.
     * @return Number of updated files
     */
    fun syncFullDb() {
        // Exit if already running
        synchronized(this) {
            if (syncStatus != -1) return
            syncStatus = 0
        }

        // Flag all images for removal
        mPhotoDao.flagAll()

        // Sync all files, marking them in the process
        syncDb(0L)

        // Clean up stale files
        mPhotoDao.deleteFlagged()
    }

    /**
     * Insert item into local database.
     * @param image SystemImage
     */
    @SuppressLint("SimpleDateFormat")
    private fun insertItemDb(image: SystemImage) {
        val fileId = image.fileId
        val baseName = image.baseName

        // Check if file with local_id and mtime already exists
        val l = mPhotoDao.getPhotosByFileIds(listOf(fileId))
        if (!l.isEmpty() && l[0].mtime == image.mtime) {
            // File already exists, remove flag
            mPhotoDao.unflag(fileId)
            Log.v(TAG, "File already exists: $fileId / $baseName")
            return
        }

        // Delete file with same local_id and insert new one
        mPhotoDao.deleteFileIds(listOf(fileId))
        mPhotoDao.insert(image.photo)
        Log.v(TAG, "Inserted file to local DB: $fileId / $baseName")
    }

    /**
     * Set server ID for local file.
     * @param auid AUID
     * @param serverId Server ID
     */
    fun setServerId(auid: Long, serverId: Long) {
        mPhotoDao.setServerId(auid, serverId)
    }

    /**
     * Active local folders response.
     * This is in timeline query because it calls the database service.
     */
    var localFolders: JSONArray
        get() {
            return mPhotoDao.getBuckets().map {
                JSONObject()
                    .put(Fields.Bucket.ID, it.id)
                    .put(Fields.Bucket.NAME, it.name)
                    .put(Fields.Bucket.ENABLED, mConfigService.enabledBucketIds.contains(it.id))
            }.let { JSONArray(it) }
        }
        set(value) {
            val enabled = mutableListOf<String>()
            for (i in 0 until value.length()) {
                val obj = value.getJSONObject(i)
                if (obj.getBoolean(Fields.Bucket.ENABLED)) {
                    enabled.add(obj.getString(Fields.Bucket.ID))
                }
            }
            mConfigService.enabledBucketIds = enabled
        }
}