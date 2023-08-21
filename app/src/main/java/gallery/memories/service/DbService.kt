package gallery.memories.service

import android.content.Context
import android.database.sqlite.SQLiteOpenHelper
import android.database.sqlite.SQLiteDatabase
import gallery.memories.R

class DbService(val context: Context) : SQLiteOpenHelper(context, "memories", null, 37) {
    override fun onCreate(db: SQLiteDatabase) {
        db.execSQL("""
            CREATE TABLE images (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                local_id INTEGER,
                auid INTEGER,
                mtime INTEGER,
                date_taken INTEGER,
                dayid INTEGER,
                exif_uid TEXT,
                basename TEXT,
                bucket_id INTEGER,
                bucket_name TEXT,
                flag INTEGER
            )
        """)

        // Add index on local_id, dayid, and flag
        db.execSQL("CREATE INDEX images_local_id ON images (local_id)")
        db.execSQL("CREATE INDEX images_auid ON images (auid)")
        db.execSQL("CREATE INDEX images_dayid ON images (dayid)")
        db.execSQL("CREATE INDEX images_flag ON images (flag)")
        db.execSQL("CREATE INDEX images_bucket ON images (bucket_id)")
        db.execSQL("CREATE INDEX images_bucket_dayid ON images (bucket_id, dayid)")
    }

    override fun onUpgrade(database: SQLiteDatabase, oldVersion: Int, newVersion: Int) {
        database.execSQL("DROP TABLE IF EXISTS images")

        // Reset sync time
        context.getSharedPreferences(context.getString(R.string.preferences_key), 0).edit()
            .remove(context.getString(R.string.preferences_last_sync_time))
            .apply()

        onCreate(database)
    }
}