package gallery.memories.mapper

import android.database.Cursor

class Photo {
    val id: Long
    val localId: Long
    val auid: Long
    val mtime: Long
    val dateTaken: Long
    val dayId: Long
    val basename: String
    val bucketId: Long
    val bucketName: String
    val flag: Int

    companion object {
        val FIELD_ID = "id"
        val FIELD_LOCAL_ID = "local_id"
        val FIELD_AUID = "auid"
        val FIELD_MTIME = "mtime"
        val FIELD_DATE_TAKEN = "date_taken"
        val FIELD_DAY_ID = "dayid"
        val FIELD_BASENAME = "basename"
        val FIELD_BUCKET_ID = "bucket_id"
        val FIELD_BUCKET_NAME = "bucket_name"
        val FIELD_FLAG = "flag"

        val FIELDS get(): Array<String> {
            return arrayOf(
                FIELD_ID,
                FIELD_LOCAL_ID,
                FIELD_AUID,
                FIELD_MTIME,
                FIELD_DATE_TAKEN,
                FIELD_DAY_ID,
                FIELD_BASENAME,
                FIELD_BUCKET_ID,
                FIELD_BUCKET_NAME,
                FIELD_FLAG
            )
        }

        val FIELDS_CREATE get(): String {
            return """
                $FIELD_ID INTEGER PRIMARY KEY AUTOINCREMENT,
                $FIELD_LOCAL_ID INTEGER,
                $FIELD_AUID INTEGER,
                $FIELD_MTIME INTEGER,
                $FIELD_DATE_TAKEN INTEGER,
                $FIELD_DAY_ID INTEGER,
                $FIELD_BASENAME TEXT,
                $FIELD_BUCKET_ID INTEGER,
                $FIELD_BUCKET_NAME TEXT,
                $FIELD_FLAG INTEGER
            """.trimIndent()
        }

        fun unpack(cursor: Cursor): List<Photo> {
            val photos = mutableListOf<Photo>()
            while (cursor.moveToNext()) {
                photos.add(Photo(cursor))
            }
            return photos
        }
    }

    constructor(cursor: Cursor) {
        id = cursor.getLong(0)
        localId = cursor.getLong(1)
        auid = cursor.getLong(2)
        mtime = cursor.getLong(3)
        dateTaken = cursor.getLong(4)
        dayId = cursor.getLong(5)
        basename = cursor.getString(6)
        bucketId = cursor.getLong(7)
        bucketName = cursor.getString(8)
        flag = cursor.getInt(9)
    }
}