package gallery.memories.service

import android.annotation.SuppressLint
import android.app.Activity
import android.content.ContentUris
import android.database.sqlite.SQLiteDatabase
import android.icu.text.SimpleDateFormat
import android.icu.util.TimeZone
import android.net.Uri
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
        val imageIds: MutableSet<Long?> = ArraySet()
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
        val idColName = MediaStore.Images.Media._ID
        val imageIdsSl = TextUtils.join(",", imageIds)
        val selection = "$idColName IN ($imageIdsSl)"

        // Make list of files
        val files = ArrayList<JSONObject?>()
        mCtx.contentResolver.query(
            MediaStore.Images.Media.EXTERNAL_CONTENT_URI,
            arrayOf(
                MediaStore.Images.Media._ID,
                MediaStore.Images.Media.DISPLAY_NAME,
                MediaStore.Images.Media.MIME_TYPE,
                MediaStore.Images.Media.HEIGHT,
                MediaStore.Images.Media.WIDTH,
                MediaStore.Images.Media.SIZE,
                MediaStore.Images.Media.DATE_MODIFIED
            ),
            selection,
            null,
            null
        ).use { cursor ->
            while (cursor?.moveToNext() == true) {
                val fileId = cursor.getLong(0)
                imageIds.remove(fileId)
                files.add(JSONObject()
                    .put(Fields.Photo.FILEID, fileId)
                    .put(Fields.Photo.BASENAME, cursor.getString(1))
                    .put(Fields.Photo.MIMETYPE, cursor.getString(2))
                    .put(Fields.Photo.HEIGHT, cursor.getLong(3))
                    .put(Fields.Photo.WIDTH, cursor.getLong(4))
                    .put(Fields.Photo.SIZE, cursor.getLong(5))
                    .put(Fields.Photo.ETAG, java.lang.Long.toString(cursor.getLong(6)))
                    .put(Fields.Photo.DATETAKEN, datesTaken[fileId])
                    .put(Fields.Photo.DAYID, dayId))
            }
        }
        mCtx.contentResolver.query(
            MediaStore.Video.Media.EXTERNAL_CONTENT_URI,
            arrayOf(
                MediaStore.Video.Media._ID,
                MediaStore.Video.Media.DISPLAY_NAME,
                MediaStore.Video.Media.MIME_TYPE,
                MediaStore.Video.Media.HEIGHT,
                MediaStore.Video.Media.WIDTH,
                MediaStore.Video.Media.SIZE,
                MediaStore.Video.Media.DATE_MODIFIED,
                MediaStore.Video.Media.DURATION
            ),
            selection,
            null,
            null
        ).use { cursor ->
            while (cursor?.moveToNext() == true) {
                // Remove from list of ids
                val fileId = cursor.getLong(0)
                imageIds.remove(fileId)
                files.add(JSONObject()
                    .put(Fields.Photo.FILEID, fileId)
                    .put(Fields.Photo.BASENAME, cursor.getString(1))
                    .put(Fields.Photo.MIMETYPE, cursor.getString(2))
                    .put(Fields.Photo.HEIGHT, cursor.getLong(3))
                    .put(Fields.Photo.WIDTH, cursor.getLong(4))
                    .put(Fields.Photo.SIZE, cursor.getLong(5))
                    .put(Fields.Photo.ETAG, java.lang.Long.toString(cursor.getLong(6)))
                    .put(Fields.Photo.DATETAKEN, datesTaken[fileId])
                    .put(Fields.Photo.DAYID, dayId)
                    .put(Fields.Photo.ISVIDEO, 1)
                    .put(Fields.Photo.VIDEO_DURATION, cursor.getLong(7) / 1000))
            }
        }

        // Remove files that were not found
        if (imageIds.size > 0) {
            val delIds = TextUtils.join(",", imageIds)
            mDb.execSQL("DELETE FROM images WHERE local_id IN ($delIds)")
        }

        // Return JSON string of files
        return JSONArray(files)
    }

    @Throws(JSONException::class)
    fun getDays(): JSONArray {
        mDb.rawQuery(
            "SELECT dayid, COUNT(local_id) FROM images GROUP BY dayid",
            null
        ).use { cursor ->
            val days = JSONArray()
            while (cursor.moveToNext()) {
                val id = cursor.getLong(0)
                val count = cursor.getLong(1)
                days.put(JSONObject()
                    .put("dayid", id)
                    .put("count", count)
                )
            }
            return days
        }
    }

    @Throws(Exception::class)
    fun getImageInfo(id: Long): JSONObject {
        val sql = "SELECT local_id, date_taken, dayid FROM images WHERE local_id = ?"
        mDb.rawQuery(sql, arrayOf(id.toString())).use { cursor ->
            if (!cursor.moveToNext()) {
                throw Exception("Image not found")
            }

            val localId = cursor.getLong(0)
            val dateTaken = cursor.getLong(1)
            val dayId = cursor.getLong(2)

            return getImageInfoForCollection(
                MediaStore.Images.Media.EXTERNAL_CONTENT_URI,
                localId, dateTaken, dayId)
            ?: return getImageInfoForCollection(
                MediaStore.Video.Media.EXTERNAL_CONTENT_URI,
                localId, dateTaken, dayId)
            ?: throw Exception("File not found in any collection")
        }
    }

    private fun getImageInfoForCollection(
            collection: Uri,
            localId: Long,
            dateTaken: Long,
            dayId: Long
    ): JSONObject? {
        val selection = MediaStore.Images.Media._ID + " = " + localId
        mCtx.contentResolver.query(
            collection,
            arrayOf(
                MediaStore.Images.Media._ID,
                MediaStore.Images.Media.DISPLAY_NAME,
                MediaStore.Images.Media.MIME_TYPE,
                MediaStore.Images.Media.HEIGHT,
                MediaStore.Images.Media.WIDTH,
                MediaStore.Images.Media.SIZE,
                MediaStore.Images.Media.DATA
            ),
            selection,
            null,
            null
        ).use { cursor ->
            if (!cursor!!.moveToNext()) {
                throw Exception("Image not found")
            }

            // Create basic info
            val obj = JSONObject()
                .put(Fields.Photo.FILEID, cursor.getLong(0))
                .put(Fields.Photo.BASENAME, cursor.getString(1))
                .put(Fields.Photo.MIMETYPE, cursor.getString(2))
                .put(Fields.Photo.DAYID, dayId)
                .put(Fields.Photo.DATETAKEN, dateTaken)
                .put(Fields.Photo.HEIGHT, cursor.getLong(3))
                .put(Fields.Photo.WIDTH, cursor.getLong(4))
                .put(Fields.Photo.SIZE, cursor.getLong(5))
                .put(Fields.Photo.PERMISSIONS, Fields.Perm.DELETE)
            val uri = cursor.getString(6)

            // Get EXIF data
            try {
                val exif = ExifInterface(uri)
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
                Log.e(TAG, "Error reading EXIF data for $uri")
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
            val uris = ids.map {
                ContentUris.withAppendedId(MediaStore.Images.Media.EXTERNAL_CONTENT_URI, it)
            }

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

    protected fun fullSyncDb() {
        // Flag all images for removal
        mDb.execSQL("UPDATE images SET flag = 1")
        mCtx.contentResolver.query(
            MediaStore.Images.Media.EXTERNAL_CONTENT_URI,
            arrayOf(
                MediaStore.Images.Media._ID,
                MediaStore.Images.Media.DISPLAY_NAME,
                MediaStore.Images.Media.DATE_TAKEN,
                MediaStore.Images.Media.DATE_MODIFIED,
                MediaStore.Images.Media.DATA
            ),
            null,
            null,
            null
        ).use { cursor ->
            while (cursor!!.moveToNext()) {
                insertItemDb(
                    cursor.getLong(0),
                    cursor.getString(1),
                    cursor.getLong(2),
                    cursor.getLong(3),
                    cursor.getString(4),
                    false
                )
            }
        }
        mCtx.contentResolver.query(
            MediaStore.Video.Media.EXTERNAL_CONTENT_URI,
            arrayOf(
                MediaStore.Video.Media._ID,
                MediaStore.Video.Media.DISPLAY_NAME,
                MediaStore.Video.Media.DATE_TAKEN,
                MediaStore.Video.Media.DATE_MODIFIED,
                MediaStore.Video.Media.DATA
            ),
            null,
            null,
            null
        ).use { cursor ->
            while (cursor!!.moveToNext()) {
                insertItemDb(
                    cursor.getLong(0),
                    cursor.getString(1),
                    cursor.getLong(2),
                    cursor.getLong(3),
                    cursor.getString(4),
                    true
                )
            }
        }

        // Clean up stale files
        mDb.execSQL("DELETE FROM images WHERE flag = 1")
    }

    @SuppressLint("SimpleDateFormat")
    private fun insertItemDb(
            id: Long,
            name: String,
            dateTaken: Long,
            mtime: Long,
            uri: String,
            isVideo: Boolean,
    ) {
        var dateTaken = dateTaken

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
        if (!isVideo) {
            try {
                val exif = ExifInterface(uri!!)
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
        if (isVideo) {
            dateTaken += TimeZone.getDefault().getOffset(dateTaken).toLong()
        }

        // This will use whatever is available
        dateTaken /= 1000
        val dayId = dateTaken / 86400

        // Delete file with same local_id and insert new one
        mDb.beginTransaction()
        mDb.execSQL("DELETE FROM images WHERE local_id = ?", arrayOf(id))
        mDb.execSQL("INSERT OR IGNORE INTO images (local_id, mtime, basename, date_taken, dayid) VALUES (?, ?, ?, ?, ?)", arrayOf(id, mtime, name, dateTaken, dayId))
        mDb.setTransactionSuccessful()
        mDb.endTransaction()
        Log.v(TAG, "Inserted file to local DB: $id / $name / $dayId")
    }
}