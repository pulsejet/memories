package gallery.memories.service

import android.annotation.SuppressLint
import android.app.Activity
import android.database.sqlite.SQLiteDatabase
import android.icu.text.SimpleDateFormat
import android.icu.util.TimeZone
import android.os.Build
import android.provider.MediaStore
import android.text.TextUtils
import android.util.Log
import androidx.activity.result.ActivityResult
import androidx.activity.result.ActivityResultLauncher
import androidx.activity.result.IntentSenderRequest
import androidx.activity.result.contract.ActivityResultContracts
import androidx.appcompat.app.AppCompatActivity
import androidx.collection.ArraySet
import androidx.exifinterface.media.ExifInterface
import gallery.memories.mapper.SystemImage
import org.json.JSONArray
import org.json.JSONException
import org.json.JSONObject
import java.io.IOException
import java.util.concurrent.CountDownLatch

class TimelineQuery(private val mCtx: AppCompatActivity) {
    private val mDb: SQLiteDatabase = DbService(mCtx).writableDatabase
    private val TAG = "TimelineQuery"

    // Photo deletion events
    var deleting = false
    var deleteIntentLauncher: ActivityResultLauncher<IntentSenderRequest>
    var deleteCallback: ((ActivityResult?) -> Unit)? = null

    init {
        // Register intent launcher for callback
        deleteIntentLauncher = mCtx.registerForActivityResult(ActivityResultContracts.StartIntentSenderForResult()) { result: ActivityResult? ->
            synchronized(this) {
                deleteCallback?.let { it(result) }
            }
        }

        // TODO: remove this in favor of a selective sync
        fullSyncDb()
    }

    @Throws(JSONException::class)
    fun getByDayId(dayId: Long): JSONArray {
        // Get list of images from DB
        val imageIds: MutableSet<Long> = ArraySet()
        val datesTaken: MutableMap<Long, Long> = HashMap()
        val sql = "SELECT local_id, date_taken FROM images WHERE dayid = ?"
        mDb.rawQuery(sql, arrayOf(dayId.toString())).use { cursor ->
            while (cursor.moveToNext()) {
                val localId = cursor.getLong(0)
                datesTaken[localId] = cursor.getLong(1)
                imageIds.add(localId)
            }
        }

        // Nothing to do
        if (imageIds.size == 0) return JSONArray()

        // Filter for given day
        val photos = JSONArray()
        SystemImage.getByIds(mCtx, imageIds.toMutableList()).forEach { image ->
            val obj = JSONObject()
                .put(Fields.Photo.FILEID, image.fileId)
                .put(Fields.Photo.BASENAME, image.baseName)
                .put(Fields.Photo.MIMETYPE, image.mimeType)
                .put(Fields.Photo.HEIGHT, image.height)
                .put(Fields.Photo.WIDTH, image.width)
                .put(Fields.Photo.SIZE, image.size)
                .put(Fields.Photo.ETAG, image.mtime.toString())
                .put(Fields.Photo.DATETAKEN, datesTaken[image.fileId])
                .put(Fields.Photo.DAYID, dayId)

            if (image.isVideo) {
                obj.put(Fields.Photo.ISVIDEO, 1)
                    .put(Fields.Photo.VIDEO_DURATION, image.videoDuration / 1000)
            }

            photos.put(obj)
            imageIds.remove(image.fileId)
        }

        // Remove files that were not found
        if (imageIds.size > 0) {
            val delIds = TextUtils.join(",", imageIds)
            mDb.execSQL("DELETE FROM images WHERE local_id IN ($delIds)")
        }

        return photos
    }

    @Throws(JSONException::class)
    fun getDays(): JSONArray {
        mDb.rawQuery(
            "SELECT dayid, COUNT(local_id) FROM images GROUP BY dayid",
            null
        ).use { cursor ->
            val days = JSONArray()
            while (cursor.moveToNext()) {
                days.put(JSONObject()
                    .put(Fields.Day.DAYID, cursor.getLong(0))
                    .put(Fields.Day.COUNT, cursor.getLong(1))
                )
            }
            return days
        }
    }

    @Throws(Exception::class)
    fun getImageInfo(id: Long): JSONObject {
        val sql = "SELECT dayid, date_taken FROM images WHERE local_id = ?"
        mDb.rawQuery(sql, arrayOf(id.toString())).use { cursor ->
            if (!cursor.moveToNext()) {
                throw Exception("Image not found")
            }

            // Get image from system table
            val imageList = SystemImage.getByIds(mCtx, arrayListOf(id))
            if (imageList.isEmpty()) {
                throw Exception("File not found in any collection")
            }

            // Add EXIF to json object
            val image = imageList[0];
            val dayId = cursor.getLong(0)
            val dateTaken = cursor.getLong(1)

            val obj = JSONObject()
                .put(Fields.Photo.FILEID, image.fileId)
                .put(Fields.Photo.BASENAME, image.baseName)
                .put(Fields.Photo.MIMETYPE, image.mimeType)
                .put(Fields.Photo.DAYID, dayId)
                .put(Fields.Photo.DATETAKEN, dateTaken)
                .put(Fields.Photo.HEIGHT, image.height)
                .put(Fields.Photo.WIDTH, image.width)
                .put(Fields.Photo.SIZE, image.size)
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
                Log.e(TAG, "Error reading EXIF data for $id")
            }

            return obj
        }
    }

    @Throws(Exception::class)
    fun delete(ids: List<Long>): JSONObject {
        synchronized(this) {
            if (deleting) {
                throw Exception("Already deleting another set of images")
            }
            deleting = true
        }

        return try {
            // List of URIs
            val uris = SystemImage.getByIds(mCtx, ids).map { it.uri }

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

            // Delete from images table
            val idsList = TextUtils.join(",", ids)
            mDb.execSQL("DELETE FROM images WHERE local_id IN ($idsList)")
            JSONObject().put("message", "ok")
        } finally {
            synchronized(this) { deleting = false }
        }
    }

    private fun fullSyncDb() {
        // Flag all images for removal
        mDb.execSQL("UPDATE images SET flag = 1")

        // Iterate all images and videos from system store
        val files =
            SystemImage.query(mCtx, SystemImage.IMAGE_URI, null, null, null) +
            SystemImage.query(mCtx, SystemImage.VIDEO_URI, null, null, null)
        files.forEach { insertItemDb(it) }

        // Clean up stale files
        mDb.execSQL("DELETE FROM images WHERE flag = 1")
    }

    @SuppressLint("SimpleDateFormat")
    private fun insertItemDb(image: SystemImage) {
        var dateTaken = image.dateTaken
        val id = image.fileId
        val name = image.baseName

        // Check if file with local_id and mtime already exists
        mDb.rawQuery("SELECT id FROM images WHERE local_id = ?", arrayOf(id.toString())).use { c ->
            if (c.count > 0) {
                // File already exists, remove flag
                mDb.execSQL("UPDATE images SET flag = 0 WHERE local_id = ?", arrayOf(id))
                Log.v(TAG, "File already exists: $id / $name")
                return
            }
        }

        // Get EXIF date using ExifInterface if image
        if (!image.isVideo) {
            try {
                val exif = ExifInterface(image.dataPath)
                val exifDate = exif.getAttribute(ExifInterface.TAG_DATETIME)
                    ?: throw IOException()
                val sdf = SimpleDateFormat("yyyy:MM:dd HH:mm:ss")
                sdf.timeZone = TimeZone.GMT_ZONE
                val date = sdf.parse(exifDate)
                if (date != null) {
                    dateTaken = date.time
                }
            } catch (e: Exception) {
                Log.e(TAG, "Failed to read EXIF data: " + e.message)
            }
        }

        // No way to get the actual local date, so just assume current timezone
        else { // !isVideo
            dateTaken += TimeZone.getDefault().getOffset(dateTaken).toLong()
        }

        // This will use whatever is available
        dateTaken /= 1000
        val dayId = dateTaken / 86400

        // Delete file with same local_id and insert new one
        mDb.beginTransaction()
        mDb.execSQL("DELETE FROM images WHERE local_id = ?", arrayOf(id))
        mDb.execSQL("INSERT OR IGNORE INTO images (local_id, mtime, basename, date_taken, dayid) VALUES (?, ?, ?, ?, ?)", arrayOf(
            id, image.mtime, name, dateTaken, dayId
        ))
        mDb.setTransactionSuccessful()
        mDb.endTransaction()
        Log.v(TAG, "Inserted file to local DB: $id / $name / $dayId")
    }
}