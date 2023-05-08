package gallery.memories;

import static android.view.WindowInsetsController.APPEARANCE_LIGHT_STATUS_BARS;

import android.app.Activity;
import android.graphics.Color;
import android.os.Build;
import android.view.View;
import android.view.Window;
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
