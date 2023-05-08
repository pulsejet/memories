package gallery.memories.service;

import android.content.Context;
import android.database.sqlite.SQLiteDatabase;
import android.database.sqlite.SQLiteOpenHelper;

public class DbService extends SQLiteOpenHelper {
    public DbService(Context context) {
        super(context, "memories", null, 16);
    }

    public void onCreate(SQLiteDatabase db) {
        // Add table for images
        db.execSQL("CREATE TABLE images ("
            + "id INTEGER PRIMARY KEY AUTOINCREMENT,"
            + "local_id INTEGER,"
            + "mtime INTEGER,"
            + "date_taken INTEGER,"
            + "dayid INTEGER,"
            + "exif_uid TEXT,"
            + "basename TEXT,"
            + "flag INTEGER"
            + ");");
    }

    public void onUpgrade(SQLiteDatabase database, int oldVersion, int newVersion) {
        database.execSQL("DROP TABLE IF EXISTS images");
        onCreate(database);
    }
}