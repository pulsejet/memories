package gallery.memories.ui.web

import android.annotation.SuppressLint
import android.content.ActivityNotFoundException
import android.content.Intent
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
/** Keeps localhost/app navigation inside, opens everything else in the browser. */
class MemoriesWebViewClient(private val activity: MainActivity) : WebViewClient() {
    companion object {
        private val TAG = MemoriesWebViewClient::class.java.simpleName
    }

    override fun shouldOverrideUrlLoading(view: WebView, request: WebResourceRequest): Boolean {
        if (request.url.host == "127.0.0.1") return false
        val pathMatches = request.url.path?.matches(activity.memoriesRegex) == true
        val hostMatches = request.url.host.equals(activity.host)
        if (pathMatches && hostMatches) return false
        try {
            Intent(Intent.ACTION_VIEW, request.url).apply { activity.startActivity(this) }
        } catch (e: ActivityNotFoundException) {
            Toast.makeText(view.context, "No app found to open this link", Toast.LENGTH_SHORT).show()
        }
        return true
    }

    override fun onPageFinished(view: WebView, url: String) {
        super.onPageFinished(view, url)
        activity.consumeClearHistoryFlag(view)
    }

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
