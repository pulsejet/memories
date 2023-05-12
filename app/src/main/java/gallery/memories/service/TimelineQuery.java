package gallery.memories.service;

import android.app.Activity;
import android.app.PendingIntent;
import android.content.ContentUris;
import android.database.Cursor;
import android.database.sqlite.SQLiteDatabase;
import android.icu.text.SimpleDateFormat;
import android.icu.util.TimeZone;
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
import java.util.Date;
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

    public JSONArray getByDayId(final long dayId) throws JSONException {
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

        // Filter for given day
        String selection = MediaStore.Images.Media._ID
                + " IN (" + TextUtils.join(",", imageIds) + ")";

        // Make list of files
        ArrayList<JSONObject> files = new ArrayList<>();

        // Add all images
        try (Cursor cursor = mCtx.getContentResolver().query(
            MediaStore.Images.Media.EXTERNAL_CONTENT_URI,
            new String[] {
                MediaStore.Images.Media._ID,
                MediaStore.Images.Media.DISPLAY_NAME,
                MediaStore.Images.Media.MIME_TYPE,
                MediaStore.Images.Media.HEIGHT,
                MediaStore.Images.Media.WIDTH,
                MediaStore.Images.Media.SIZE,
                MediaStore.Images.Media.DATE_MODIFIED,
            },
            selection,
            null,
            null
        )) {
            while (cursor.moveToNext()) {
                long fileId = cursor.getLong(0);
                imageIds.remove(fileId);

                files.add(new JSONObject()
                    .put(Fields.Photo.FILEID, fileId)
                    .put(Fields.Photo.BASENAME, cursor.getString(1))
                    .put(Fields.Photo.MIMETYPE, cursor.getString(2))
                    .put(Fields.Photo.HEIGHT, cursor.getLong(3))
                    .put(Fields.Photo.WIDTH, cursor.getLong(4))
                    .put(Fields.Photo.SIZE, cursor.getLong(5))
                    .put(Fields.Photo.ETAG, Long.toString(cursor.getLong(6)))
                    .put(Fields.Photo.DATETAKEN, datesTaken.get(fileId))
                    .put(Fields.Photo.DAYID, dayId));
            }
        }

        // Add all videos
        try (Cursor cursor = mCtx.getContentResolver().query(
            MediaStore.Video.Media.EXTERNAL_CONTENT_URI,
            new String[] {
                MediaStore.Video.Media._ID,
                MediaStore.Video.Media.DISPLAY_NAME,
                MediaStore.Video.Media.MIME_TYPE,
                MediaStore.Video.Media.HEIGHT,
                MediaStore.Video.Media.WIDTH,
                MediaStore.Video.Media.SIZE,
                MediaStore.Video.Media.DATE_MODIFIED,
                MediaStore.Video.Media.DURATION,
            },
            selection,
            null,
            null
        )) {
            while (cursor.moveToNext()) {
                // Remove from list of ids
                long fileId = cursor.getLong(0);
                imageIds.remove(fileId);

                files.add(new JSONObject()
                    .put(Fields.Photo.FILEID, fileId)
                    .put(Fields.Photo.BASENAME, cursor.getString(1))
                    .put(Fields.Photo.MIMETYPE, cursor.getString(2))
                    .put(Fields.Photo.HEIGHT, cursor.getLong(3))
                    .put(Fields.Photo.WIDTH, cursor.getLong(4))
                    .put(Fields.Photo.SIZE, cursor.getLong(5))
                    .put(Fields.Photo.ETAG, Long.toString(cursor.getLong(6)))
                    .put(Fields.Photo.DATETAKEN, datesTaken.get(fileId))
                    .put(Fields.Photo.DAYID, dayId)
                    .put(Fields.Photo.ISVIDEO, 1)
                    .put(Fields.Photo.VIDEO_DURATION, cursor.getLong(7) / 1000));
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
        try (Cursor cursor = mDb.rawQuery(
                "SELECT local_id, date_taken, dayid FROM images WHERE local_id = ?",
                new String[] { Long.toString(id) }
        )) {
            if (!cursor.moveToNext()) {
                throw new Exception("Image not found");
            }

            final long localId = cursor.getLong(0);
            final long dateTaken = cursor.getLong(1);
            final long dayId = cursor.getLong(2);

            try {
                return _getImageInfoForCollection(
                        MediaStore.Images.Media.EXTERNAL_CONTENT_URI,
                        localId, dateTaken, dayId
                );
            } catch (Exception e) {/* Ignore */}

            try {
                return _getImageInfoForCollection(
                        MediaStore.Video.Media.EXTERNAL_CONTENT_URI,
                        localId, dateTaken, dayId
                );
            } catch (Exception e) {/* Ignore */}
        }

        throw new Exception("File not found in any collection");
    }

    public JSONObject _getImageInfoForCollection(
        final Uri collection,
        final long localId,
        final long dateTaken,
        final long dayId
    ) throws Exception {
        String selection = MediaStore.Images.Media._ID + " = " + localId;
        try (Cursor cursor = mCtx.getContentResolver().query(
            collection,
            new String[] {
                MediaStore.Images.Media._ID,
                MediaStore.Images.Media.DISPLAY_NAME,
                MediaStore.Images.Media.MIME_TYPE,
                MediaStore.Images.Media.HEIGHT,
                MediaStore.Images.Media.WIDTH,
                MediaStore.Images.Media.SIZE,
                MediaStore.Images.Media.DATA,
            },
            selection,
            null,
            null
        )) {
            if (!cursor.moveToNext()) {
                throw new Exception("Image not found");
            }

            JSONObject obj = new JSONObject()
                .put(Fields.Photo.FILEID, cursor.getLong(0))
                .put(Fields.Photo.BASENAME, cursor.getString(1))
                .put(Fields.Photo.MIMETYPE, cursor.getString(2))
                .put(Fields.Photo.DAYID, dayId)
                .put(Fields.Photo.DATETAKEN, dateTaken)
                .put(Fields.Photo.HEIGHT, cursor.getLong(3))
                .put(Fields.Photo.WIDTH, cursor.getLong(4))
                .put(Fields.Photo.SIZE, cursor.getLong(5))
                .put(Fields.Photo.PERMISSIONS, Fields.Perm.DELETE);

            String uri = cursor.getString(6);

            // Get EXIF data
            try {
                ExifInterface exif = new ExifInterface(uri);
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

                obj.put(Fields.Photo.EXIF, exifObj);
            } catch (IOException e) {
                Log.e(TAG, "Error reading EXIF data for " + uri);
            }

            return obj;
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

            // Delete from images table
            mDb.execSQL("DELETE FROM images WHERE local_id IN (" + TextUtils.join(",", ids) + ")");

            return new JSONObject().put("message", "ok");
        } finally {
            synchronized (this) {
                deleting = false;
            }
        }
    }

    protected void fullSyncDb() {
        // Flag all images for removal
        mDb.execSQL("UPDATE images SET flag = 1");

        // Add all images
        try (Cursor cursor = mCtx.getContentResolver().query(
            MediaStore.Images.Media.EXTERNAL_CONTENT_URI,
            new String[] {
                MediaStore.Images.Media._ID,
                MediaStore.Images.Media.DISPLAY_NAME,
                MediaStore.Images.Media.DATE_TAKEN,
                MediaStore.Images.Media.DATE_MODIFIED,
                MediaStore.Images.Media.DATA,
            },
            null,
            null,
            null
        )) {
            while (cursor.moveToNext()) {
                insertItemDb(
                    cursor.getLong(0),
                    cursor.getString(1),
                    cursor.getLong(2),
                    cursor.getLong(3),
                    cursor.getString(4),
                    false
                );
            }
        }

        // Add all videos
        try (Cursor cursor = mCtx.getContentResolver().query(
            MediaStore.Video.Media.EXTERNAL_CONTENT_URI,
            new String[] {
                MediaStore.Video.Media._ID,
                MediaStore.Video.Media.DISPLAY_NAME,
                MediaStore.Video.Media.DATE_TAKEN,
                MediaStore.Video.Media.DATE_MODIFIED,
                MediaStore.Video.Media.DATA,
            },
            null,
            null,
            null
        )) {
            while (cursor.moveToNext()) {
                insertItemDb(
                    cursor.getLong(0),
                    cursor.getString(1),
                    cursor.getLong(2),
                    cursor.getLong(3),
                    cursor.getString(4),
                    true
                );
            }
        }


        // Clean up stale files
        mDb.execSQL("DELETE FROM images WHERE flag = 1");
    }

    protected void insertItemDb(long id, String name, long dateTaken, long mtime, String uri, boolean isVideo) {
        // Check if file with local_id and mtime already exists
        try (Cursor c = mDb.rawQuery("SELECT id FROM images WHERE local_id = ?",
                new String[]{Long.toString(id)})) {
            if (c.getCount() > 0) {
                // File already exists, remove flag
                mDb.execSQL("UPDATE images SET flag = 0 WHERE local_id = ?", new Object[]{id});

                Log.v(TAG, "File already exists: " + id + " / " + name);
                return;
            }
        }

        // Get EXIF date using ExifInterface if image
        if (!isVideo) {
            try {
                ExifInterface exif = new ExifInterface(uri);
                String exifDate = exif.getAttribute(ExifInterface.TAG_DATETIME);
                if (exifDate == null) {
                    throw new IOException();
                }
                SimpleDateFormat sdf = new SimpleDateFormat("yyyy:MM:dd HH:mm:ss");
                sdf.setTimeZone(android.icu.util.TimeZone.GMT_ZONE);
                Date date = sdf.parse(exifDate);
                if (date != null) {
                    dateTaken = date.getTime();
                }
            } catch (IOException e) {
                Log.e(TAG, "Failed to read EXIF data: " + e.getMessage());
            } catch (ParseException e) {
                e.printStackTrace();
            }
        }

        if (isVideo) {
            // No way to get the actual local date, so just assume current timezone
            dateTaken += TimeZone.getDefault().getOffset(dateTaken);
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
