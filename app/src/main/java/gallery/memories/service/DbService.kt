package gallery.memories.service

import android.content.Context
import android.database.sqlite.SQLiteOpenHelper
import android.database.sqlite.SQLiteDatabase

class DbService(context: Context) : SQLiteOpenHelper(context, "memories", null, 26) {
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
    }

    override fun onUpgrade(database: SQLiteDatabase, oldVersion: Int, newVersion: Int) {
        database.execSQL("DROP TABLE IF EXISTS images")
        onCreate(database)
    }
}