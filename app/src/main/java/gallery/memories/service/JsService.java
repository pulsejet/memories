package gallery.memories.service;

import android.app.Activity;
import android.webkit.WebView;

import java.util.Base64;

public class JsService {
    protected Activity mActivity;
    protected WebView mWebView;

    public JsService(Activity activity, WebView webView) {
        mActivity = activity;
        mWebView = webView;
    }

    public interface AsyncFunction {
        byte[] run() throws Exception;
    }

    public void runAsync(final String call, final AsyncFunction callable) {
        new Thread(() -> {
            try {
                jsResolve(call, callable.run());
            } catch (Exception e) {
                jsReject(call, e);
            }
        }).start();
    }

    protected void jsResolve(String call, byte[] ret) {
        final String b64 = Base64.getEncoder().encodeToString(ret);
        mActivity.runOnUiThread(() -> mWebView.evaluateJavascript("window.nativexr('" + call + "', '" + b64 + "');", null));
    }

    protected void jsReject(String call, Exception e) {
        String message = e.getMessage();
        message = message == null ? "Unknown error occured" : message;
        final String b64 = Base64.getEncoder().encodeToString(message.getBytes());
        mActivity.runOnUiThread(() -> mWebView.evaluateJavascript("window.nativexr('" + call + "', undefined, '" + b64 + "');", null));
    }
}
