package gallery.memories;

import static android.view.WindowInsetsController.APPEARANCE_LIGHT_STATUS_BARS;

import android.app.Activity;
import android.app.DownloadManager;
import android.content.Context;
import android.graphics.Color;
import android.net.Uri;
import android.os.Build;
import android.util.Log;
import android.view.View;
import android.view.Window;
import android.webkit.JavascriptInterface;
import android.webkit.WebResourceRequest;
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
    WebView mWebView;

    protected ImageService mImageService;
    protected TimelineQuery mQuery;

    public NativeX(Activity activity, WebView webView) {
        mActivity = activity;
        mWebView = webView;
        mImageService = new ImageService(activity);
        mQuery = new TimelineQuery(activity);
    }

    public WebResourceResponse handleRequest(final WebResourceRequest request) {
        final String path = request.getUrl().getPath();

        WebResourceResponse response;
        try {
            if (request.getMethod().equals("GET")) {
                response = routerGet(path);
            } else if (request.getMethod().equals("OPTIONS")) {
                response = new WebResourceResponse("text/plain", "UTF-8", new ByteArrayInputStream("".getBytes()));
            } else {
                throw new Exception("Method Not Allowed");
            }
        } catch (Exception e) {
            Log.e(TAG, "handleRequest: ", e);
            response = makeErrorResponse();
        }

        // Allow CORS from all origins
        Map<String, String> headers = new ArrayMap<>();
        headers.put("Access-Control-Allow-Origin", "*");
        headers.put("Access-Control-Allow-Headers", "*");
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

    @JavascriptInterface
    public void downloadFromUrl(final String url, final String filename) {
        Uri uri = Uri.parse(url);
        DownloadManager manager = (DownloadManager) mActivity.getSystemService(Context.DOWNLOAD_SERVICE);
        DownloadManager.Request request = new DownloadManager.Request(uri);
        request.setNotificationVisibility(DownloadManager.Request.VISIBILITY_VISIBLE);

        // Copy all cookies from the webview to the download request
        String cookies = android.webkit.CookieManager.getInstance().getCookie(url);
        request.addRequestHeader("cookie", cookies);

        // Save the file to external storage
        request.setDestinationInExternalPublicDir(android.os.Environment.DIRECTORY_DOWNLOADS, "memories/" + filename);

        // Start the download
        manager.enqueue(request);
    }

    protected WebResourceResponse routerGet(final String path) throws Exception {
        String[] parts = path.split("/");

        if (path.matches("^/image/preview/\\d+$")) {
            return makeResponse(mImageService.getPreview(Long.parseLong(parts[3])), "image/jpeg");
        } else if (path.matches("^/image/full/\\d+$")) {
            return makeResponse(mImageService.getFull(Long.parseLong(parts[3])), "image/jpeg");
        }  else if (path.matches("^/image/info/\\d+$")) {
            return makeResponse(mQuery.getImageInfo(Long.parseLong(parts[3])));
        } else if (path.matches("^/api/days$")) {
            return makeResponse(mQuery.getDays());
        } else if (path.matches("/api/days/\\d+$")) {
            return makeResponse(mQuery.getByDayId(Long.parseLong(parts[3])));
        }

        throw new Exception("Not Found");
    }

    protected WebResourceResponse makeResponse(byte[] bytes, String mimeType) {
        if (bytes != null) {
            return new WebResourceResponse(mimeType, "UTF-8", new ByteArrayInputStream(bytes));
        }

        return makeErrorResponse();
    }

    protected WebResourceResponse makeResponse(Object json) {
        return makeResponse(json.toString().getBytes(), "application/json");
    }

    protected WebResourceResponse makeErrorResponse() {
        WebResourceResponse response = new WebResourceResponse("application/json", "UTF-8", new ByteArrayInputStream("{}".getBytes()));
        response.setStatusCodeAndReasonPhrase(500, "Internal Server Error");
        return response;
    }
}
