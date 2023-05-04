package gallery.memories;

import android.content.ContentUris;
import android.database.Cursor;
import android.graphics.Bitmap;
import android.net.Uri;
import android.os.Bundle;
import android.provider.MediaStore;
import android.util.Log;
import android.webkit.JavascriptInterface;
import android.webkit.WebResourceRequest;
import android.webkit.WebSettings;
import android.webkit.WebView;
import android.webkit.WebViewClient;

import androidx.appcompat.app.AppCompatActivity;

import org.json.JSONArray;
import org.json.JSONException;
import org.json.JSONObject;

import java.io.ByteArrayOutputStream;
import java.io.IOException;
import java.util.ArrayList;
import java.util.Base64;

import gallery.memories.databinding.ActivityMainBinding;

public class MainActivity extends AppCompatActivity {

    private ActivityMainBinding binding;

    public static final String TAG = "memories-native";

    @Override
    protected void onCreate(Bundle savedInstanceState) {
        super.onCreate(savedInstanceState);

        binding = ActivityMainBinding.inflate(getLayoutInflater());
        setContentView(binding.getRoot());

        initWebview();
    }

    protected void initWebview() {
        binding.webview.setWebViewClient(new WebViewClient() {
            public boolean shouldOverrideUrlLoading(WebView view, WebResourceRequest request) {
                // do your handling codes here, which url is the requested url
                // probably you need to open that url rather than redirect:
                view.loadUrl(request.getUrl().toString());
                return false; // then it is not handled by default action
            }
        });

        WebSettings webSettings = binding.webview.getSettings();
        webSettings.setJavaScriptEnabled(true);
        webSettings.setJavaScriptCanOpenWindowsAutomatically(true);
        webSettings.setAllowContentAccess(true);
        webSettings.setDomStorageEnabled(true);
        webSettings.setDatabaseEnabled(true);
        webSettings.setUserAgentString("memories-native-android/0.0");

        binding.webview.addJavascriptInterface(this, "nativex");
        binding.webview.loadUrl("http://10.0.2.2:8035/index.php/apps/memories/");
    }

    @JavascriptInterface
    public boolean isNative() {
        return true;
    }

    @JavascriptInterface
    public void getLocalByDayId(final String call, final long dayId) {
        Log.d(TAG, "getLocalByDayId: " + dayId);

        new Thread(() -> {
            Uri collection = MediaStore.Images.Media.EXTERNAL_CONTENT_URI;

            String[] projection = new String[] {
                    MediaStore.Images.Media._ID,
                    MediaStore.Images.Media.DISPLAY_NAME,
                    MediaStore.Images.Media.MIME_TYPE,
                    MediaStore.Images.Media.DATE_TAKEN,
                    MediaStore.Images.Media.HEIGHT,
                    MediaStore.Images.Media.WIDTH,
                    MediaStore.Images.Media.SIZE,
            };
            String selection = MediaStore.Images.Media.DATE_TAKEN + " >= ? AND "
                    + MediaStore.Images.Media.DATE_TAKEN + " <= ?";
            String[] selectionArgs = new String[] {
                    Long.toString(dayId * 86400000L),
                    Long.toString(((dayId+1) * 86400000L)),
            };

            String sortOrder = MediaStore.Images.Media.DISPLAY_NAME + " ASC";

            ArrayList<JSONObject> files = new ArrayList<>();

            try (Cursor cursor = getContentResolver().query(
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

                    Uri contentUri = ContentUris.withAppendedId(
                            MediaStore.Images.Media.EXTERNAL_CONTENT_URI, id);

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

                this.jsResolve(call, new JSONArray(files).toString());
            }
        }).start();
    }

    @JavascriptInterface
    public void getJpeg(String call, String uri) {
        Log.d(TAG, "getPreviewById: " + uri);

        // URI looks like nativex://<type>/<id>
        String[] parts = uri.split("/");
        if (parts.length != 4) {
            this.jsReject(call, "Invalid URI");
            return;
        }

        final String type = parts[2];
        final long id = Long.parseLong(parts[3]);

        new Thread(() -> {
            Bitmap bitmap = null;

            if (type.equals("preview")) {
                bitmap = MediaStore.Images.Thumbnails.getThumbnail(
                    getContentResolver(), id, MediaStore.Images.Thumbnails.MINI_KIND, null);
            } else if (type.equals("full")) {
                try {
                    bitmap = MediaStore.Images.Media.getBitmap(
                        getContentResolver(), ContentUris.withAppendedId(
                            MediaStore.Images.Media.EXTERNAL_CONTENT_URI, id));
                } catch (IOException e) {
                    e.printStackTrace();
                }
            } else {
                this.jsReject(call, "Invalid type");
            }

            if (bitmap == null) {
                this.jsReject(call, "Thumbnail not found");
                return;
            }

            ByteArrayOutputStream stream = new ByteArrayOutputStream();
            bitmap.compress(Bitmap.CompressFormat.JPEG, 90, stream);
            jsResolve(call, stream.toByteArray());
        }).start();
    }

    protected void jsResolve(String call, byte[] ret) {
        final String b64 = Base64.getEncoder().encodeToString(ret);
        runOnUiThread(() -> binding.webview.loadUrl("javascript:(function() { window.nativexr('" + call + "', '" + b64 + "'); })();void(0);"));
    }

    protected void jsResolve(String call, String ret) {
        jsResolve(call, ret.getBytes());
    }

    protected void jsReject(String call, String ret) {
        final String b64 = Base64.getEncoder().encodeToString(ret.getBytes());
        runOnUiThread(() -> binding.webview.loadUrl("javascript:(function() { window.nativexr('" + call + "', undefined, '" + b64 + "'); })();void(0);"));
    }
}