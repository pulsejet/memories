package gallery.memories;

import static android.view.WindowInsetsController.APPEARANCE_LIGHT_STATUS_BARS;

import android.graphics.Color;
import android.os.Build;
import android.util.Log;
import android.view.View;
import android.view.Window;
import android.webkit.JavascriptInterface;
import android.webkit.WebResourceRequest;
import android.webkit.WebResourceResponse;
import android.webkit.WebView;

import androidx.appcompat.app.AppCompatActivity;
import androidx.collection.ArrayMap;

import java.io.ByteArrayInputStream;
import java.net.URLDecoder;
import java.util.ArrayList;
import java.util.List;
import java.util.Map;

import gallery.memories.service.DownloadService;
import gallery.memories.service.ImageService;
import gallery.memories.service.TimelineQuery;

public class NativeX {
    public static final String TAG = "NativeX";
    protected final AppCompatActivity mActivity;
    protected final WebView mWebView;

    protected final ImageService mImageService;
    protected final TimelineQuery mQuery;
    public static DownloadService mDlService;

    public NativeX(AppCompatActivity activity, WebView webView) {
        mActivity = activity;
        mWebView = webView;
        mImageService = new ImageService(activity);
        mQuery = new TimelineQuery(activity);
        mDlService = new DownloadService(activity);
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
        mDlService.queue(url, filename);
    }

    protected WebResourceResponse routerGet(final String path) throws Exception {
        String[] parts = path.split("/");

        if (path.matches("^/image/preview/\\d+$")) {
            return makeResponse(mImageService.getPreview(Long.parseLong(parts[3])), "image/jpeg");
        } else if (path.matches("^/image/full/\\d+$")) {
            return makeResponse(mImageService.getFull(Long.parseLong(parts[3])), "image/jpeg");
        } else if (path.matches("^/api/image/info/\\d+$")) {
            return makeResponse(mQuery.getImageInfo(Long.parseLong(parts[4])));
        } else if (path.matches("^/api/image/delete/\\d+(,\\d+)*$")) {
            return makeResponse(mQuery.delete(parseIds(parts[4])));
        } else if (path.matches("^/api/days$")) {
            return makeResponse(mQuery.getDays());
        } else if (path.matches("/api/days/\\d+$")) {
            return makeResponse(mQuery.getByDayId(Long.parseLong(parts[3])));
        } else if (path.matches("/api/share/url/.+$")) {
            return makeResponse(mDlService.shareUrl(URLDecoder.decode(parts[4], "UTF-8")));
        } else if (path.matches("/api/share/blob/.+$")) {
            return makeResponse(mDlService.shareBlobFromUrl(URLDecoder.decode(parts[4], "UTF-8")));
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

    protected static List<Long> parseIds(String ids) {
        List<Long> result = new ArrayList<>();
        for (String id : ids.split(",")) {
            result.add(Long.parseLong(id));
        }
        return result;
    }
}
