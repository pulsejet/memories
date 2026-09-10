package gallery.memories.ui.web

import android.annotation.SuppressLint
import android.graphics.Color
import android.view.View
import android.webkit.WebView
import android.widget.Toast

import android.annotation.SuppressLint
import android.graphics.Color
import android.view.View
import android.webkit.WebView
import android.widget.Toast
import gallery.memories.NativeX

/**
 * One-time WebView configuration. Transparent background: pages paint over
 * the themed root.
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
        s.javaScriptCanOpenWindowsAutomatically = true
        s.allowContentAccess = true
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
