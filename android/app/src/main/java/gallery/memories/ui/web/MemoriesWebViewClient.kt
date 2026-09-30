package gallery.memories.ui.web

import android.annotation.SuppressLint
import android.content.ActivityNotFoundException
import android.content.Intent
import android.net.Uri
import android.net.http.SslError
import android.util.Log
import android.webkit.SslErrorHandler
import android.webkit.WebResourceError
import android.webkit.WebResourceRequest
import android.webkit.WebResourceResponse
import android.webkit.WebView
import android.webkit.WebViewClient
import android.widget.Toast
import androidx.media3.common.util.UnstableApi
import gallery.memories.MainActivity

@UnstableApi
/** Only loopback pages load inside; everything else goes to the browser or is blocked. */
class MemoriesWebViewClient(private val activity: MainActivity) : WebViewClient() {
    companion object {
        private val TAG = MemoriesWebViewClient::class.java.simpleName

        /** Loopback hosts serving the app. API calls elsewhere are fine, pages are not. */
        fun isLoopback(url: Uri): Boolean {
            if (url.scheme != "http" && url.scheme != "https") return false
            return when (url.host?.lowercase()) {
                "127.0.0.1", "localhost" -> true
                else -> false
            }
        }
    }

    override fun shouldOverrideUrlLoading(view: WebView, request: WebResourceRequest): Boolean {
        if (!request.isForMainFrame) return false
        return handleMainFrame(view, request.url)
    }

    @Suppress("DEPRECATION")
    override fun shouldOverrideUrlLoading(view: WebView, url: String): Boolean =
        handleMainFrame(view, Uri.parse(url))

    private fun handleMainFrame(view: WebView, url: Uri): Boolean {
        if (url.scheme == "about") return false
        if (isLoopback(url)) return handleLoopback(view, url)
        if (url.scheme?.lowercase() in setOf("data", "blob", "javascript")) {
            Log.w(TAG, "Blocked top-level navigation to ${url.scheme}: URL")
            return true
        }
        openExternal(view, url)
        return true
    }

    /**
     * Local documents stay inside; proxied upstream paths are unwrapped to the
     * origin URL and opened in the browser. Unresolvable ones stay put: loopback
     * URLs are meaningless (403) outside this process.
     */
    private fun handleLoopback(view: WebView, url: Uri): Boolean {
        val external = try {
            activity.nativex.local.browserUrlFor(url.encodedPath, url.encodedQuery)
        } catch (_: Exception) {
            null
        } ?: return false
        openExternal(view, Uri.parse(external))
        return true
    }

    private fun openExternal(view: WebView, url: Uri) {
        try {
            Intent(Intent.ACTION_VIEW, url).apply { activity.startActivity(this) }
        } catch (e: ActivityNotFoundException) {
            Toast.makeText(view.context, "No app found to open this link", Toast.LENGTH_SHORT).show()
        }
    }

    override fun onPageFinished(view: WebView, url: String) {
        super.onPageFinished(view, url)
        activity.consumeClearHistoryFlag(view)
    }

    /** Main-frame network failures otherwise render as a silent blank page. */
    override fun onReceivedError(view: WebView, request: WebResourceRequest, error: WebResourceError) {
        if (request.isForMainFrame) {
            Log.w(TAG, "Page failed: ${request.url} code=${error.errorCode} ${error.description}")
            Toast.makeText(view.context, "Failed to load page: ${error.description}", Toast.LENGTH_LONG).show()
        }
    }

    /** HTTP 4xx/5xx on the main frame otherwise renders as a blank page with no log. */
    override fun onReceivedHttpError(view: WebView, request: WebResourceRequest, errorResponse: WebResourceResponse) {
        if (request.isForMainFrame) {
            Log.w(TAG, "Page HTTP error: ${request.url} code=${errorResponse.statusCode} ${errorResponse.reasonPhrase}")
            Toast.makeText(view.context, "Failed to load page: HTTP ${errorResponse.statusCode}", Toast.LENGTH_LONG).show()
        }
    }

    /**
     * Proceeds on SSL errors only when the user explicitly trusts all
     * certificates for this server; otherwise surfaces a toast.
     */
    @SuppressLint("WebViewClientOnReceivedSslError")
    override fun onReceivedSslError(view: WebView?, handler: SslErrorHandler?, error: SslError?) {
        if (activity.nativex.auth.isTrustingAllCertificates) {
            handler?.proceed()
        } else {
            activity.nativex.toast("Failed to load due to SSL error: ${error?.primaryError}", true)
            super.onReceivedSslError(view, handler, error)
        }
    }
}
