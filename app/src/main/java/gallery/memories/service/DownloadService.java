package gallery.memories.service;

import android.app.DownloadManager;
import android.content.ContentUris;
import android.content.Context;
import android.content.Intent;
import android.database.Cursor;
import android.net.Uri;
import android.provider.MediaStore;
import android.webkit.CookieManager;
import android.widget.Toast;

import androidx.appcompat.app.AppCompatActivity;
import androidx.collection.ArrayMap;

import java.util.Map;

public class DownloadService {
    class DownloadCallback {
        public void onComplete(Intent intent) {
        }
    }

    final AppCompatActivity mActivity;
    final Map<Long, DownloadCallback> mDownloads = new ArrayMap<>();

    public DownloadService(AppCompatActivity activity) {
        mActivity = activity;
    }

    public void runDownloadCallback(Intent intent) {
        if (mActivity.isDestroyed()) return;

        String action = intent.getAction();

        if (DownloadManager.ACTION_DOWNLOAD_COMPLETE.equals(action)) {
            long id = intent.getLongExtra(DownloadManager.EXTRA_DOWNLOAD_ID, 0);
            synchronized (mDownloads) {
                DownloadCallback callback = mDownloads.get(id);
                if (callback != null) {
                    callback.onComplete(intent);
                    mDownloads.remove(id);
                    return;
                }
            }

            Toast.makeText(mActivity, "Download Complete", Toast.LENGTH_SHORT).show();
        }
    }

    public long queue(final String url, final String filename) {
        Uri uri = Uri.parse(url);
        DownloadManager manager = (DownloadManager) mActivity.getSystemService(Context.DOWNLOAD_SERVICE);
        DownloadManager.Request request = new DownloadManager.Request(uri);
        request.setNotificationVisibility(DownloadManager.Request.VISIBILITY_VISIBLE);

        // Copy all cookies from the webview to the download request
        String cookies = CookieManager.getInstance().getCookie(url);
        request.addRequestHeader("cookie", cookies);

        if (!filename.equals("")) {
            // Save the file to external storage
            request.setDestinationInExternalPublicDir(android.os.Environment.DIRECTORY_DOWNLOADS, "memories/" + filename);
        }

        // Start the download
        return manager.enqueue(request);
    }

    public Boolean shareUrl(final String url) {
        Intent intent = new Intent(Intent.ACTION_SEND);
        intent.setType("text/plain");
        intent.putExtra(Intent.EXTRA_TEXT, url);
        mActivity.startActivity(Intent.createChooser(intent, null));
        return true;
    }

    public Boolean shareBlobFromUrl(final String url) throws Exception {
        final long id = queue(url, "");
        final Object sync = new Object();

        synchronized (mDownloads) {
            mDownloads.put(id, new DownloadCallback() {
                @Override
                public void onComplete(Intent intent) {
                    synchronized (sync) {
                        sync.notify();
                    }
                }
            });
        }

        synchronized (sync) {
            sync.wait();
        }

        // Get the URI of the downloaded file
        String sUri = getDownloadedFileURI(id);
        if (sUri == null) {
            throw new Exception("Failed to download file");
        }
        Uri uri = Uri.parse(sUri);

        // Create sharing intent
        Intent intent = new Intent(Intent.ACTION_SEND);
        intent.setType(mActivity.getContentResolver().getType(uri));
        intent.putExtra(Intent.EXTRA_STREAM, uri);
        mActivity.startActivity(Intent.createChooser(intent, null));

        return true;
    }

    public Boolean shareLocal(final long id) throws Exception {
        Uri uri = ContentUris.withAppendedId(MediaStore.Files.getContentUri("external"), id);
        Intent intent = new Intent(Intent.ACTION_SEND);
        intent.addFlags(Intent.FLAG_GRANT_READ_URI_PERMISSION);
        intent.setType(mActivity.getContentResolver().getType(uri));
        intent.putExtra(Intent.EXTRA_STREAM, uri);
        mActivity.startActivity(Intent.createChooser(intent, null));
        return true;
    }

    protected String getDownloadedFileURI(long downloadId) {
        DownloadManager downloadManager = (DownloadManager) mActivity.getSystemService(Context.DOWNLOAD_SERVICE);
        DownloadManager.Query query = new DownloadManager.Query();
        query.setFilterById(downloadId);
        Cursor cursor = downloadManager.query(query);
        if (cursor.moveToFirst()) {
            int columnIndex = cursor.getColumnIndex(DownloadManager.COLUMN_LOCAL_URI);
            return cursor.getString(columnIndex);
        }
        cursor.close();
        return null;
    }
}
