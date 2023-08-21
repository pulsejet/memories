package gallery.memories.service

import android.content.ContentValues
import android.content.Context
import android.database.sqlite.SQLiteDatabase
import android.database.sqlite.SQLiteOpenHelper
import gallery.memories.R
import gallery.memories.mapper.Day
import gallery.memories.mapper.Photo
import gallery.memories.mapper.SystemImage

class DbService(val context: Context) : SQLiteOpenHelper(context, "memories", null, 42) {
    companion object {
        val MEMORIES = "images"
    }

    override fun onCreate(db: SQLiteDatabase) {
        db.execSQL("CREATE TABLE $MEMORIES (${Photo.FIELDS_CREATE})")

        // Add index on local_id, dayid, and flag
        db.execSQL("CREATE INDEX images_local_id ON $MEMORIES (${Photo.FIELD_LOCAL_ID})")
        db.execSQL("CREATE INDEX images_auid ON $MEMORIES (${Photo.FIELD_AUID})")
        db.execSQL("CREATE INDEX images_dayid ON $MEMORIES (${Photo.FIELD_DAY_ID})")
        db.execSQL("CREATE INDEX images_flag ON $MEMORIES (${Photo.FIELD_FLAG})")
        db.execSQL("CREATE INDEX images_bucket ON $MEMORIES (${Photo.FIELD_BUCKET_ID})")
        db.execSQL("CREATE INDEX images_bucket_dayid ON $MEMORIES (${Photo.FIELD_BUCKET_ID}, ${Photo.FIELD_DAY_ID})")
    }

    override fun onUpgrade(database: SQLiteDatabase, oldVersion: Int, newVersion: Int) {
        database.execSQL("DROP TABLE IF EXISTS $MEMORIES")

        // Reset sync time
        context.getSharedPreferences(context.getString(R.string.preferences_key), 0).edit()
            .remove(context.getString(R.string.preferences_last_sync_time))
            .apply()

        onCreate(database)
    }

    fun initialize(): DbService {
        writableDatabase
        return this
    }

    fun getPhotosByDay(dayId: Long, buckets: Set<String>): List<Photo> {
        if (buckets.isEmpty()) return emptyList()

        val bs = buckets.joinToString(",")
        val where = "${Photo.FIELD_DAY_ID} = ? AND ${Photo.FIELD_BUCKET_ID} IN ($bs)"
        readableDatabase.query(MEMORIES, Photo.FIELDS, where, arrayOf(
            dayId.toString(),
        ), null, null, null).use { cursor ->
            return Photo.unpack(cursor)
        }
    }

    fun getPhotosBy(field: String, vals: List<Long>): List<Photo> {
        if (vals.isEmpty()) return emptyList()

        val vls = vals.joinToString(",")
        readableDatabase.query(MEMORIES, Photo.FIELDS, "$field IN ($vls)", null,
            null, null, null
        ).use { cursor ->
            return Photo.unpack(cursor)
        }
    }

    fun getPhotosByFileIds(fileIds: List<Long>): List<Photo> {
        return getPhotosBy(Photo.FIELD_LOCAL_ID, fileIds)
    }

    fun getPhotosByAUIDs(auids: List<Long>): List<Photo> {
        return getPhotosBy(Photo.FIELD_AUID, auids)
    }

    fun getDays(bucketIds: Set<String>): List<Day> {
        if (bucketIds.isEmpty()) return emptyList()

        val bs = bucketIds.joinToString(",")
        val where = "${Photo.FIELD_BUCKET_ID} IN ($bs)"
        readableDatabase.query(MEMORIES, Day.FIELDS, where, null,
            Photo.FIELD_DAY_ID, null, null
        ).use { cursor ->
            return Day.unpack(cursor)
        }
    }

    fun deleteFileIds(fileIds: List<Long>) {
        if (fileIds.isEmpty()) return

        val ids = fileIds.joinToString(",")
        writableDatabase.delete(MEMORIES, "${Photo.FIELD_LOCAL_ID} IN ($ids)", null)
    }

    fun flagAll() {
        writableDatabase.execSQL("UPDATE $MEMORIES SET ${Photo.FIELD_FLAG} = 1")
    }

    fun unflag(fileId: Long) {
        writableDatabase.execSQL("UPDATE $MEMORIES SET ${Photo.FIELD_FLAG} = 0 WHERE ${Photo.FIELD_LOCAL_ID} = $fileId")
    }

    fun deleteFlagged() {
        writableDatabase.delete(MEMORIES, "${Photo.FIELD_FLAG} = 1", null)
    }

    fun insertImage(image: SystemImage) {
        val dateTaken = image.utcDate

        ContentValues().apply {
            put(Photo.FIELD_LOCAL_ID, image.fileId)
            put(Photo.FIELD_MTIME, image.mtime)
            put(Photo.FIELD_DATE_TAKEN, dateTaken)
            put(Photo.FIELD_DAY_ID, dateTaken / 86400)
            put(Photo.FIELD_AUID, image.auid)
            put(Photo.FIELD_BASENAME, image.baseName)
            put(Photo.FIELD_BUCKET_ID, image.bucketId)
            put(Photo.FIELD_BUCKET_NAME, image.bucketName)
        }.let {
            writableDatabase.insert(MEMORIES, null, it)
        }
    }

    fun getBuckets(): Map<String, String> {
        val ret = mutableMapOf<String, String>()

        readableDatabase.query(MEMORIES, arrayOf(Photo.FIELD_BUCKET_ID, Photo.FIELD_BUCKET_NAME),
            null, null, Photo.FIELD_BUCKET_ID, null, null
        ).use { cursor ->
            while (cursor.moveToNext()) {
                ret[cursor.getString(0)] = cursor.getString(1)
            }
        }

        return ret
    }
}