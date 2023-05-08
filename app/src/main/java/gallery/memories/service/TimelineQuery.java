package gallery.memories.service;

import android.content.Context;
import android.database.Cursor;
import android.database.sqlite.SQLiteDatabase;
import android.icu.text.SimpleDateFormat;
import android.net.Uri;
import android.provider.MediaStore;
import android.text.TextUtils;
import android.util.Log;

import androidx.collection.ArraySet;
import androidx.exifinterface.media.ExifInterface;

import org.json.JSONArray;
import org.json.JSONException;
import org.json.JSONObject;

import java.io.IOException;
import java.text.ParseException;
import java.util.ArrayList;
import java.util.HashMap;
import java.util.Map;
import java.util.Set;

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
        // Get list of images from DB
        final Set<Long> imageIds = new ArraySet<>();
        final Map<Long, Long> datesTaken = new HashMap<>();
        try (Cursor cursor = mDb.rawQuery(
            "SELECT local_id, date_taken FROM images WHERE dayid = ?",
            new String[] { Long.toString(dayId) }
        )) {
            while (cursor.moveToNext()) {
                final long localId = cursor.getLong(0);
                final long dateTaken = cursor.getLong(1);
                imageIds.add(localId);
                datesTaken.put(localId, dateTaken);
            }
        }

        // Nothing to do
        if (imageIds.size() == 0) {
            return new JSONArray();
        }

        // All external storage images
        Uri collection = MediaStore.Images.Media.EXTERNAL_CONTENT_URI;

        // Same fields as server response
        String[] projection = new String[] {
            MediaStore.Images.Media._ID,
            MediaStore.Images.Media.DISPLAY_NAME,
            MediaStore.Images.Media.MIME_TYPE,
            MediaStore.Images.Media.HEIGHT,
            MediaStore.Images.Media.WIDTH,
            MediaStore.Images.Media.SIZE,
        };

        // Filter for given day
        String selection = MediaStore.Images.Media._ID
                + " IN (" + TextUtils.join(",", imageIds) + ")";

        // Make list of files
        ArrayList<JSONObject> files = new ArrayList<>();

        try (Cursor cursor = mCtx.getContentResolver().query(
            collection,
            projection,
            selection,
            null,
            null
        )) {
            int idColumn = cursor.getColumnIndexOrThrow(MediaStore.Images.Media._ID);
            int nameColumn = cursor.getColumnIndexOrThrow(MediaStore.Images.Media.DISPLAY_NAME);
            int mimeColumn = cursor.getColumnIndexOrThrow(MediaStore.Images.Media.MIME_TYPE);
            int heightColumn = cursor.getColumnIndexOrThrow(MediaStore.Images.Media.HEIGHT);
            int widthColumn = cursor.getColumnIndexOrThrow(MediaStore.Images.Media.WIDTH);
            int sizeColumn = cursor.getColumnIndexOrThrow(MediaStore.Images.Media.SIZE);

            while (cursor.moveToNext()) {
                long id = cursor.getLong(idColumn);
                String name = cursor.getString(nameColumn);
                String mime = cursor.getString(mimeColumn);
                long height = cursor.getLong(heightColumn);
                long width = cursor.getLong(widthColumn);
                long size = cursor.getLong(sizeColumn);
                long dateTaken = datesTaken.get(id);

                // Remove from list of ids
                imageIds.remove(id);

                try {
                    JSONObject file = new JSONObject()
                        .put("fileid", id)
                        .put("basename", name)
                        .put("mimetype", mime)
                        .put("dayid", dayId)
                        .put("datetaken", dateTaken)
                        .put("h", height)
                        .put("w", width)
                        .put("size", size);
                    files.add(file);
                } catch (JSONException e) {
                    Log.e(TAG, "JSON error");
                }
            }
        }

        // Remove files that were not found
        if (imageIds.size() > 0) {
            mDb.execSQL("DELETE FROM images WHERE local_id IN (" + TextUtils.join(",", imageIds) + ")");
        }

        // Return JSON string of files
        return new JSONArray(files);
    }

    public JSONArray getDays() {
        try (Cursor cursor = mDb.rawQuery(
            "SELECT dayid, COUNT(local_id) FROM images GROUP BY dayid",
            null
        )) {
            JSONArray days = new JSONArray();
            while (cursor.moveToNext()) {
                long id = cursor.getLong(0);
                long count = cursor.getLong(1);
                days.put(new JSONObject()
                    .put("dayid", id)
                    .put("count", count)
                );
            }

            return days;
        } catch (JSONException e) {
            Log.e(TAG, "JSON error");
            return new JSONArray();
        }
    }

    protected void fullSyncDb() {
        Uri collection = MediaStore.Images.Media.EXTERNAL_CONTENT_URI;

        // Flag all images for removal
        mDb.execSQL("UPDATE images SET flag = 1");

        // Same fields as server response
        String[] projection = new String[] {
            MediaStore.Images.Media._ID,
            MediaStore.Images.Media.DATA,
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
            int uriColumn = cursor.getColumnIndexOrThrow(MediaStore.Images.Media.DATA);
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

                // Get EXIF date using ExifInterface
                String uri = cursor.getString(uriColumn);
                try {
                    ExifInterface exif = new ExifInterface(uri);
                    String exifDate = exif.getAttribute(ExifInterface.TAG_DATETIME);
                    SimpleDateFormat sdf = new SimpleDateFormat("yyyy:MM:dd HH:mm:ss");
                    sdf.setTimeZone(android.icu.util.TimeZone.GMT_ZONE);
                    dateTaken = sdf.parse(exifDate).getTime();
                } catch (IOException e) {
                    Log.e(TAG, "Failed to read EXIF data: " + e.getMessage());
                } catch (ParseException e) {
                    e.printStackTrace();
                }

                // This will use whatever is available
                final long dayId = dateTaken / 86400000;

                // Delete file with same local_id and insert new one
                mDb.beginTransaction();
                mDb.execSQL("DELETE FROM images WHERE local_id = ?", new Object[] { id });
                mDb.execSQL("INSERT OR IGNORE INTO images (local_id, mtime, basename, dayid) VALUES (?, ?, ?, ?)",
                    new Object[] { id, mtime, name, dayId });
                mDb.setTransactionSuccessful();
                mDb.endTransaction();

                Log.v(TAG, "Inserted file to local DB: " + id + " / " + name + " / " + dayId);
            }
        }
    }
}
