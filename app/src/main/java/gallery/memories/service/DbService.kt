package gallery.memories.service

import android.content.Context
import android.database.sqlite.SQLiteOpenHelper
import android.database.sqlite.SQLiteDatabase

class DbService(context: Context) : SQLiteOpenHelper(context, "memories", null, 27) {
    override fun onCreate(db: SQLiteDatabase) {
        db.execSQL("""
            CREATE TABLE images (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                local_id INTEGER,
                mtime INTEGER,
                date_taken INTEGER,
                dayid INTEGER,
                exif_uid TEXT,
                basename TEXT,
                flag INTEGER
            )
        """)

        // Add index on local_id, dayid, and flag
        db.execSQL("CREATE INDEX images_local_id ON images (local_id)")
        db.execSQL("CREATE INDEX images_dayid ON images (dayid)")
        db.execSQL("CREATE INDEX images_flag ON images (flag)")
    }

    override fun onUpgrade(database: SQLiteDatabase, oldVersion: Int, newVersion: Int) {
        database.execSQL("DROP TABLE IF EXISTS images")
        onCreate(database)
    }
}