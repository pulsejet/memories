package gallery.memories;

import static android.view.WindowInsetsController.APPEARANCE_LIGHT_STATUS_BARS;

import android.app.Activity;
import android.graphics.Color;
import android.os.Build;
import android.util.Log;
import android.view.View;
import android.view.Window;
import android.webkit.JavascriptInterface;
import android.webkit.WebResourceResponse;
import android.webkit.WebView;

import androidx.collection.ArrayMap;

import java.io.ByteArrayInputStream;
import java.util.Map;

import gallery.memories.service.ImageService;
import gallery.memories.service.TimelineQuery;

public class NativeX {
    public static final String TAG = "NativeX";
    Activity mActivity;

    protected ImageService mImageService;
    protected TimelineQuery mQuery;

    public NativeX(Activity activity, WebView webView) {
        mActivity = activity;
        mImageService = new ImageService(activity);
        mQuery = new TimelineQuery(activity);
    }

    public WebResourceResponse handleRequest(final String path) {
        byte[] bytes = null;
        String mimeType = "application/json";

        try {
            // Match the path using regex
            String[] parts = path.split("/");

            if (path.matches("^/image/preview/\\d+$")) {
                // Preview Image
                bytes = mImageService.getPreview(Long.parseLong(parts[3]));
                mimeType = "image/jpeg";
            } else if (path.matches("^/image/full/\\d+$")) {
                // Full sized image
                bytes = mImageService.getFull(Long.parseLong(parts[3]));
                mimeType = "image/jpeg";
            } else if (path.matches("^/api/days$")) {
                // Days list
                bytes = mQuery.getDays().toString().getBytes();
            } else if (path.matches("/api/days/\\d+$")) {
                // Single day photos
                bytes = mQuery.getByDayId(Long.parseLong(parts[3])).toString().getBytes();
            } else {
                Log.e(TAG, "handleRequest: Unknown path: " + path);
            }
        } catch (Exception e) {
            Log.e(TAG, "handleRequest: ", e);
        }

        // Construct the response
        WebResourceResponse response;
        if (bytes != null) {
            response = new WebResourceResponse(mimeType, "UTF-8", new ByteArrayInputStream(bytes));
        } else {
            response = new WebResourceResponse(mimeType, "UTF-8", new ByteArrayInputStream("{}".getBytes()));
            response.setStatusCodeAndReasonPhrase(500, "Internal Server Error");
        }

        // Allow CORS from all origins
        Map<String, String> headers = new ArrayMap<>();
        headers.put("Access-Control-Allow-Origin", "*");
        response.setResponseHeaders(headers);

        return response;
    }

    @JavascriptInterface
    public boolean isNative() {
        return true;
    }

    @JavascriptInterface
    public void setThemeColor(final String color, final boolean isDark) {
        Window window = mActivity.getWindow();

        mActivity.setTheme(isDark
            ? android.R.style.Theme_Black
            : android.R.style.Theme_Light);
        window.setNavigationBarColor(Color.parseColor(color));
        window.setStatusBarColor(Color.parseColor(color));

        if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.R) {
            window.getInsetsController().setSystemBarsAppearance(isDark ? 0 : APPEARANCE_LIGHT_STATUS_BARS, APPEARANCE_LIGHT_STATUS_BARS);
        } else {
            window.getDecorView().setSystemUiVisibility(isDark ? 0 : View.SYSTEM_UI_FLAG_LIGHT_STATUS_BAR);
        }
    }
}
