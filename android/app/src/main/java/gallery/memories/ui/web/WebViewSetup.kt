package gallery.memories.ui.web

import android.annotation.SuppressLint
import android.graphics.Color
import android.view.View
import android.webkit.WebView
import android.widget.Toast
import gallery.memories.NativeX

/**
 * One-time WebView configuration. Transparent background: pages paint over
 * the themed root. Popups stay in the same window so MemoriesWebViewClient
 * can route them (loopback inside, everything else to the browser).
 */
object WebViewSetup {
    /**
     * Applies the app's WebView settings and injects [jsInterface] as
     * `nativex`. [debug] additionally clears the cache and enables remote
     * debugging with a toast; release builds pass false.
     */
    @SuppressLint("SetJavaScriptEnabled")
    fun setup(webview: WebView, userAgent: String, jsInterface: NativeX, debug: Boolean) {
        val s = webview.settings
        s.javaScriptEnabled = true
        // Popups never get their own window: window.open/target=_blank falls back
        // into shouldOverrideUrlLoading, which externalizes non-loopback URLs.
        s.javaScriptCanOpenWindowsAutomatically = false
        s.setSupportMultipleWindows(false)
        s.domStorageEnabled = true
        s.userAgentString = userAgent
        s.setSupportZoom(false)
        s.builtInZoomControls = false
        s.displayZoomControls = false
        webview.addJavascriptInterface(jsInterface, "nativex")
        webview.setLayerType(View.LAYER_TYPE_HARDWARE, null)
        webview.setBackgroundColor(Color.TRANSPARENT)
        if (debug) {
            Toast.makeText(webview.context, "Debugging enabled", Toast.LENGTH_SHORT).show()
            webview.clearCache(true)
            WebView.setWebContentsDebuggingEnabled(true)
        }
    }
}
