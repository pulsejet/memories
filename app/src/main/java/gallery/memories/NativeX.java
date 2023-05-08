package gallery.memories;

import android.app.Activity;
import android.webkit.JavascriptInterface;
import android.webkit.WebView;

import gallery.memories.service.ImageService;
import gallery.memories.service.JsService;
import gallery.memories.service.TimelineQuery;

public class NativeX {
    Activity mActivity;

    protected JsService mJsService;
    protected ImageService mImageService;
    protected TimelineQuery mQuery;

    public NativeX(Activity activity, WebView webView) {
        mActivity = activity;
        mJsService = new JsService(activity, webView);
        mImageService = new ImageService(activity);
        mQuery = new TimelineQuery(activity);
    }

    @JavascriptInterface
    public boolean isNative() {
        return true;
    }

    @JavascriptInterface
    public void getLocalDays(final String call, final long _ignore) {
        mJsService.runAsync(call, () -> mQuery.getDays().toString().getBytes());
    }

    @JavascriptInterface
    public void getLocalByDayId(final String call, final long dayId) {
        mJsService.runAsync(call, () -> mQuery.getByDayId(dayId).toString().getBytes());
    }

    @JavascriptInterface
    public void getJpeg(final String call, final String uri) {
        mJsService.runAsync(call, () -> mImageService.getFromURI(uri));
    }
}
