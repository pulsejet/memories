package gallery.memories.ui.web

import android.annotation.SuppressLint
import android.os.Build
import android.view.View
import android.webkit.WebView
import android.widget.Toast
import androidx.annotation.OptIn
import androidx.media3.common.util.UnstableApi
import gallery.memories.NativeX

/**
 * One-time WebView configuration.
 * Popups stay in the same window so MemoriesWebViewClient can route them
 * (loopback inside, everything else to the browser).
 */
object WebViewSetup {
    /**
     * Applies the app's WebView settings and injects [jsInterface] as
     * `nativex`. [debug] additionally clears the cache and enables remote
     * debugging with a toast; release builds pass false.
     */
    @OptIn(UnstableApi::class)
    @SuppressLint("SetJavaScriptEnabled")
    fun setup(webview: WebView, userAgent: String, jsInterface: NativeX, debug: Boolean) {
        val s = webview.settings
        s.javaScriptEnabled = true
        s.javaScriptCanOpenWindowsAutomatically = false
        s.setSupportMultipleWindows(false)
        s.domStorageEnabled = true
        s.userAgentString = userAgent
        s.setSupportZoom(false)
        s.builtInZoomControls = false
        s.displayZoomControls = false
        s.useWideViewPort = true
        s.allowFileAccess = false
        s.allowContentAccess = false
        s.safeBrowsingEnabled = false // no hashes to Google

        if (Build.VERSION.SDK_INT >= 35) {
            webview.requestedFrameRate = View.REQUESTED_FRAME_RATE_CATEGORY_HIGH
        }

        // Transparent webview to allow looking at elements below.
        webview.setBackgroundColor(android.graphics.Color.TRANSPARENT)
        webview.background = null

        // Renderer priority important to avoid being killed in background.
        webview.setRendererPriorityPolicy(WebView.RENDERER_PRIORITY_IMPORTANT, false)
        webview.addJavascriptInterface(jsInterface, "nativex")

        if (debug) {
            Toast.makeText(webview.context, "Debugging enabled", Toast.LENGTH_SHORT).show()
            webview.clearCache(true)
            WebView.setWebContentsDebuggingEnabled(true)
        }
    }
}
