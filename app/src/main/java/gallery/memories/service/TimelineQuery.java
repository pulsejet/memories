package gallery.memories.service;

import android.app.Activity;
import android.app.PendingIntent;
import android.content.ContentUris;
import android.database.Cursor;
import android.database.sqlite.SQLiteDatabase;
import android.icu.text.SimpleDateFormat;
import android.net.Uri;
import android.os.Build;
import android.provider.MediaStore;
import android.text.TextUtils;
import android.util.Log;

import androidx.activity.result.ActivityResult;
import androidx.activity.result.ActivityResultLauncher;
import androidx.activity.result.IntentSenderRequest;
import androidx.activity.result.contract.ActivityResultContracts;
import androidx.appcompat.app.AppCompatActivity;
import androidx.collection.ArraySet;
import androidx.exifinterface.media.ExifInterface;

import org.json.JSONArray;
import org.json.JSONException;
import org.json.JSONObject;

import java.io.IOException;
import java.text.ParseException;
import java.util.ArrayList;
import java.util.HashMap;
import java.util.List;
import java.util.Map;
import java.util.Set;

public class TimelineQuery {
    final static String TAG = "TimelineQuery";
    AppCompatActivity mCtx;
    SQLiteDatabase mDb;

    boolean deleting = false;
    ActivityResultLauncher<IntentSenderRequest> deleteIntentLauncher;
    ActivityResult deleteResult;

    public TimelineQuery(AppCompatActivity context) {
        mCtx = context;
        mDb = new DbService(context).getWritableDatabase();

        deleteIntentLauncher = mCtx.registerForActivityResult(new ActivityResultContracts.StartIntentSenderForResult(), result -> {
            synchronized (deleteIntentLauncher) {
                deleteResult = result;
                deleteIntentLauncher.notify();
            }
        });

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
            MediaStore.Images.Media.DATE_MODIFIED,
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
            int dateModifiedColumn = cursor.getColumnIndexOrThrow(MediaStore.Images.Media.DATE_MODIFIED);

            while (cursor.moveToNext()) {
                long id = cursor.getLong(idColumn);
                String name = cursor.getString(nameColumn);
                String mime = cursor.getString(mimeColumn);
                long height = cursor.getLong(heightColumn);
                long width = cursor.getLong(widthColumn);
                long size = cursor.getLong(sizeColumn);
                long dateTaken = datesTaken.get(id);
                Long dateModified = cursor.getLong(dateModifiedColumn);

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
                        .put("size", size)
                        .put("etag", dateModified.toString());
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

    public JSONObject getImageInfo(final long id) throws Exception {
        // Get image info from DB
        try (Cursor cursor = mDb.rawQuery(
            "SELECT local_id, date_taken, dayid FROM images WHERE local_id = ?",
            new String[] { Long.toString(id) }
        )) {
            if (!cursor.moveToNext()) {
                throw new Exception("Image not found");
            }

            final long localId = cursor.getLong(0);
            final long dateTaken = cursor.getLong(1);
            final long dayid = cursor.getLong(2);

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
                MediaStore.Images.Media.DATA,
            };

            // Filter for given day
            String selection = MediaStore.Images.Media._ID
                    + " = " + localId;

            try (Cursor cursor2 = mCtx.getContentResolver().query(
                collection,
                projection,
                selection,
                null,
                null
            )) {
                int idColumn = cursor2.getColumnIndexOrThrow(MediaStore.Images.Media._ID);
                int nameColumn = cursor2.getColumnIndexOrThrow(MediaStore.Images.Media.DISPLAY_NAME);
                int mimeColumn = cursor2.getColumnIndexOrThrow(MediaStore.Images.Media.MIME_TYPE);
                int heightColumn = cursor2.getColumnIndexOrThrow(MediaStore.Images.Media.HEIGHT);
                int widthColumn = cursor2.getColumnIndexOrThrow(MediaStore.Images.Media.WIDTH);
                int sizeColumn = cursor2.getColumnIndexOrThrow(MediaStore.Images.Media.SIZE);
                int dataColumn = cursor2.getColumnIndexOrThrow(MediaStore.Images.Media.DATA);

                if (!cursor2.moveToNext()) {
                    throw new Exception("Image not found");
                }

                long id2 = cursor2.getLong(idColumn);
                String name = cursor2.getString(nameColumn);
                String mime = cursor2.getString(mimeColumn);
                long height = cursor2.getLong(heightColumn);
                long width = cursor2.getLong(widthColumn);
                long size = cursor2.getLong(sizeColumn);
                String data = cursor2.getString(dataColumn);

                JSONObject obj = new JSONObject()
                    .put("fileid", id2)
                    .put("basename", name)
                    .put("mimetype", mime)
                    .put("dayid", dayid)
                    .put("datetaken", dateTaken)
                    .put("h", height)
                    .put("w", width)
                    .put("size", size)
                    .put("permissions", "D");

                // Get EXIF data
                try {
                    ExifInterface exif = new ExifInterface(data);
                    JSONObject exifObj = new JSONObject();
                    exifObj.put("Aperture", exif.getAttribute(ExifInterface.TAG_APERTURE_VALUE));
                    exifObj.put("FocalLength", exif.getAttribute(ExifInterface.TAG_FOCAL_LENGTH));
                    exifObj.put("FNumber", exif.getAttribute(ExifInterface.TAG_F_NUMBER));
                    exifObj.put("ShutterSpeed", exif.getAttribute(ExifInterface.TAG_SHUTTER_SPEED_VALUE));
                    exifObj.put("ExposureTime", exif.getAttribute(ExifInterface.TAG_EXPOSURE_TIME));
                    exifObj.put("ISO", exif.getAttribute(ExifInterface.TAG_ISO_SPEED));

                    exifObj.put("DateTimeOriginal", exif.getAttribute(ExifInterface.TAG_DATETIME_ORIGINAL));
                    exifObj.put("OffsetTimeOriginal", exif.getAttribute(ExifInterface.TAG_OFFSET_TIME_ORIGINAL));
                    exifObj.put("GPSLatitude", exif.getAttribute(ExifInterface.TAG_GPS_LATITUDE));
                    exifObj.put("GPSLongitude", exif.getAttribute(ExifInterface.TAG_GPS_LONGITUDE));
                    exifObj.put("GPSAltitude", exif.getAttribute(ExifInterface.TAG_GPS_ALTITUDE));

                    exifObj.put("Make", exif.getAttribute(ExifInterface.TAG_MAKE));
                    exifObj.put("Model", exif.getAttribute(ExifInterface.TAG_MODEL));

                    exifObj.put("Orientation", exif.getAttribute(ExifInterface.TAG_ORIENTATION));
                    exifObj.put("Description", exif.getAttribute(ExifInterface.TAG_IMAGE_DESCRIPTION));

                    obj.put("exif", exifObj);
                } catch (IOException e) {
                    Log.e(TAG, "Error reading EXIF data for " + data);
                }

                return obj;
            }
        }
    }

    public JSONObject delete(List<Long> ids) throws Exception {
        synchronized (this) {
            if (deleting) {
                throw new Exception("Already deleting another set of images");
            }
            deleting = true;
        }

        try {
            // List of URIs
            List<Uri> uris = new ArrayList<>();
            for (long id : ids) {
                uris.add(ContentUris.withAppendedId(MediaStore.Images.Media.EXTERNAL_CONTENT_URI, id));
            }

            // Delete file with media store
            Uri collection = MediaStore.Images.Media.EXTERNAL_CONTENT_URI;
            if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.R) {
                PendingIntent intent = MediaStore.createTrashRequest(mCtx.getContentResolver(), uris, true);
                deleteIntentLauncher.launch(new IntentSenderRequest.Builder(intent.getIntentSender()).build());

                // Wait for response
                synchronized (deleteIntentLauncher) {
                    deleteIntentLauncher.wait();
                }

                // Throw if canceled or failed
                if (deleteResult.getResultCode() != Activity.RESULT_OK) {
                    throw new Exception("Delete canceled or failed");
                }
            } else {
                for (Uri uri : uris) {
                    mCtx.getContentResolver().delete(uri, null, null);
                }
            }

            return new JSONObject().put("message", "ok");
        } finally {
            synchronized (this) {
                deleting = false;
            }
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
                dateTaken /= 1000;
                final long dayId = dateTaken / 86400;

                // Delete file with same local_id and insert new one
                mDb.beginTransaction();
                mDb.execSQL("DELETE FROM images WHERE local_id = ?", new Object[] { id });
                mDb.execSQL("INSERT OR IGNORE INTO images (local_id, mtime, basename, date_taken, dayid) VALUES (?, ?, ?, ?, ?)",
                    new Object[] { id, mtime, name, dateTaken, dayId });
                mDb.setTransactionSuccessful();
                mDb.endTransaction();

                Log.v(TAG, "Inserted file to local DB: " + id + " / " + name + " / " + dayId);
            }
        }

        // Clean up stale files
        mDb.execSQL("DELETE FROM images WHERE flag = 1");
    }
}
