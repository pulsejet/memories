package gallery.memories.service;

import android.content.Context;
import android.database.Cursor;
import android.database.sqlite.SQLiteDatabase;
import android.net.Uri;
import android.provider.MediaStore;
import android.util.Log;

import org.json.JSONArray;
import org.json.JSONException;
import org.json.JSONObject;

import java.util.ArrayList;
import java.util.TimeZone;

public class TimelineQuery {
    final static String TAG = "TimelineQuery";
    Context mCtx;
    SQLiteDatabase mDb;

    public TimelineQuery(Context context) {
        mCtx = context;
        mDb = new DbService(context).getWritableDatabase();

        fullSyncDb();
    }

    public JSONArray getByDayId(final long dayId) {
        Uri collection = MediaStore.Images.Media.EXTERNAL_CONTENT_URI;

        // Offset of current timezone from UTC
        long utcOffset = TimeZone.getDefault().getOffset(System.currentTimeMillis());

        // Same fields as server response
        String[] projection = new String[] {
                MediaStore.Images.Media._ID,
                MediaStore.Images.Media.DISPLAY_NAME,
                MediaStore.Images.Media.MIME_TYPE,
                MediaStore.Images.Media.DATE_TAKEN,
                MediaStore.Images.Media.HEIGHT,
                MediaStore.Images.Media.WIDTH,
                MediaStore.Images.Media.SIZE,
        };

        // Filter for given day
        String selection = MediaStore.Images.Media.DATE_TAKEN + " >= ? AND "
                + MediaStore.Images.Media.DATE_TAKEN + " <= ?";
        String[] selectionArgs = new String[] {
                Long.toString(dayId * 86400000L - utcOffset),
                Long.toString(((dayId+1) * 86400000L - utcOffset)),
        };

        // Sort by name? TODO: fix this
        String sortOrder = MediaStore.Images.Media.DISPLAY_NAME + " ASC";

        // Make list of files
        ArrayList<JSONObject> files = new ArrayList<>();

        try (Cursor cursor = mCtx.getContentResolver().query(
                collection,
                projection,
                selection,
                selectionArgs,
                sortOrder
        )) {
            int idColumn = cursor.getColumnIndexOrThrow(MediaStore.Images.Media._ID);
            int nameColumn = cursor.getColumnIndexOrThrow(MediaStore.Images.Media.DISPLAY_NAME);
            int mimeColumn = cursor.getColumnIndexOrThrow(MediaStore.Images.Media.MIME_TYPE);
            int dateColumn = cursor.getColumnIndexOrThrow(MediaStore.Images.Media.DATE_TAKEN);
            int heightColumn = cursor.getColumnIndexOrThrow(MediaStore.Images.Media.HEIGHT);
            int widthColumn = cursor.getColumnIndexOrThrow(MediaStore.Images.Media.WIDTH);
            int sizeColumn = cursor.getColumnIndexOrThrow(MediaStore.Images.Media.SIZE);

            while (cursor.moveToNext()) {
                long id = cursor.getLong(idColumn);
                String name = cursor.getString(nameColumn);
                String mime = cursor.getString(mimeColumn);
                long dateTaken = cursor.getLong(dateColumn);
                long height = cursor.getLong(heightColumn);
                long width = cursor.getLong(widthColumn);
                long size = cursor.getLong(sizeColumn);

                try {
                    JSONObject file = new JSONObject()
                            .put("fileid", id)
                            .put("basename", name)
                            .put("mimetype", mime)
                            .put("dayid", (dateTaken / 86400000))
                            .put("h", height)
                            .put("w", width)
                            .put("size", size);
                    files.add(file);
                } catch (JSONException e) {
                    Log.e(TAG, "JSON error");
                }
            }
        }

        // Return JSON string of files
        return new JSONArray(files);
    }

    protected void fullSyncDb() {
        Uri collection = MediaStore.Images.Media.EXTERNAL_CONTENT_URI;

        // Flag all images for removal
        mDb.execSQL("UPDATE images SET flag = 1");

        // Same fields as server response
        String[] projection = new String[] {
                MediaStore.Images.Media._ID,
                MediaStore.Images.Media.DISPLAY_NAME,
                MediaStore.Images.Media.DATE_TAKEN,
                MediaStore.Images.Media.DATE_MODIFIED,
        };

        try (Cursor cursor = mCtx.getContentResolver().query(
                collection,
                projection,
                null,
                null,
                null
        )) {
            int idColumn = cursor.getColumnIndexOrThrow(MediaStore.Images.Media._ID);
            int nameColumn = cursor.getColumnIndexOrThrow(MediaStore.Images.Media.DISPLAY_NAME);
            int dateColumn = cursor.getColumnIndexOrThrow(MediaStore.Images.Media.DATE_TAKEN);
            int mtimeColumn = cursor.getColumnIndexOrThrow(MediaStore.Images.Media.DATE_MODIFIED);

            while (cursor.moveToNext()) {
                long id = cursor.getLong(idColumn);
                String name = cursor.getString(nameColumn);
                long dateTaken = cursor.getLong(dateColumn);
                long mtime = cursor.getLong(mtimeColumn);

                // Check if file with local_id and mtime already exists
                try (Cursor c = mDb.rawQuery("SELECT id FROM images WHERE local_id = ?",
                        new String[]{Long.toString(id)})) {
                    if (c.getCount() > 0) {
                        // File already exists, remove flag
                        mDb.execSQL("UPDATE images SET flag = 0 WHERE local_id = ?", new Object[]{id});

                        Log.v(TAG, "File already exists: " + id + " / " + name);
                        continue;
                    }
                }

                // Delete file with same local_id and insert new one
                mDb.beginTransaction();
                mDb.execSQL("DELETE FROM images WHERE local_id = ?", new Object[] { id });
                mDb.execSQL("INSERT OR IGNORE INTO images (local_id, mtime, basename, dayid) VALUES (?, ?, ?, ?)",
                        new Object[] { id, mtime, name, (dateTaken / 86400000) });
                mDb.setTransactionSuccessful();
                mDb.endTransaction();

                Log.v(TAG, "Inserted file to local DB: " + id + " / " + name);
            }
        }
    }
}
