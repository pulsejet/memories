package gallery.memories

import android.net.Uri
import android.util.Log
import android.webkit.WebView
import androidx.media3.common.util.UnstableApi
import androidx.webkit.JavaScriptReplyProxy
import androidx.webkit.WebMessageCompat
import androidx.webkit.WebViewCompat
import androidx.webkit.WebViewFeature
import org.json.JSONArray
import org.json.JSONException
import org.json.JSONObject

@UnstableApi
/**
 * Fire-and-forget async bridge, exposed to the page as `window.nativex_async`.
 * Takes JSON postMessages; replies go back over the reply proxy for the event bus.
 * Serves as a high-performance alternative for passing state to NativeX.
 */
class NativeXAsync(private val mCtx: MainActivity, private val nativex: NativeX) {
    companion object {
        val TAG: String = NativeXAsync::class.java.simpleName

        /** JS object name for the async WebMessage bridge. See [attach]. */
        const val NAME = "nativex_async"
    }

    /**
     * Exposes `window.nativex_async` to the page.
     */
    fun attach() {
        val webview: WebView = mCtx.binding.webview
        if (!WebViewFeature.isFeatureSupported(WebViewFeature.WEB_MESSAGE_LISTENER)) {
            Log.w(TAG, "nativex_async unavailable: WEB_MESSAGE_LISTENER unsupported")
            return
        }
        if (nativex.local.port == 0) {
            Log.w(TAG, "nativex_async attach skipped: local server not started")
            return
        }
        val origin = nativex.local.origin()
        try {
            WebViewCompat.removeWebMessageListener(webview, NAME)
        } catch (_: Exception) {
        }
        try {
            WebViewCompat.addWebMessageListener(webview, NAME, setOf(origin), Listener())
        } catch (e: Exception) {
            Log.w(TAG, "nativex_async attach failed for $origin", e)
        }
    }

    fun detach() {
        try {
            if (WebViewFeature.isFeatureSupported(WebViewFeature.WEB_MESSAGE_LISTENER)) {
                WebViewCompat.removeWebMessageListener(mCtx.binding.webview, NAME)
            }
        } catch (_: Exception) {
        }
        runCatching { mCtx.eventBus.replyProxy = null }
    }

    private inner class Listener : WebViewCompat.WebMessageListener {
        override fun onPostMessage(
            view: WebView,
            message: WebMessageCompat,
            sourceOrigin: Uri,
            isMainFrame: Boolean,
            replyProxy: JavaScriptReplyProxy,
        ) {
            if (!isMainFrame) {
                Log.w(TAG, "nativex_async: ignoring iframe message from $sourceOrigin")
                return
            }
            val expected = runCatching { nativex.local.origin() }.getOrNull()
            if (expected != null && sourceOrigin.toString() != expected) {
                Log.w(TAG, "nativex_async: ignoring untrusted origin $sourceOrigin (expected $expected)")
                return
            }
            val raw = message.data ?: return
            mCtx.runOnUiThread { handleMessage(raw, replyProxy) }
        }
    }

    /** Parses one JSON postMessage and runs it. */
    private fun handleMessage(raw: String, replyProxy: JavaScriptReplyProxy) {
        val payload = try {
            JSONObject(raw)
        } catch (e: JSONException) {
            Log.w(TAG, "nativex_async: invalid JSON: $raw", e)
            return
        }
        val method = payload.optString("method", "")
        if (method.isEmpty()) {
            Log.w(TAG, "nativex_async: missing method: $raw")
            return
        }
        try {
            dispatch(method, payload.optJSONArray("args") ?: JSONArray(), replyProxy)
        } catch (e: Exception) {
            Log.w(TAG, "nativex_async: $method failed", e)
        }
    }

    /** Runs one fire-and-forget method on the UI thread. */
    private fun dispatch(method: String, args: JSONArray, replyProxy: JavaScriptReplyProxy) {
        when (method) {
            "setupEventBus" -> mCtx.eventBus.replyProxy = replyProxy
            "setThemeColor" -> nativex.setThemeColor(
                args.opt(0)?.takeIf { it != JSONObject.NULL }?.toString(),
                args.optBoolean(1, false),
            )
            "playTouchSound" -> nativex.playTouchSound()
            "vibrate" -> nativex.vibrate(args.optLong(0, 0))
            "toast" -> nativex.toast(
                args.optString(0),
                args.optBoolean(1, false),
            )
            "setHasRemote" -> nativex.setHasRemote(
                args.optString(0),
                args.optString(1),
                args.optBoolean(2, false),
            )
            "showNativeImage" -> nativex.showNativeImage(args.optBoolean(0, false))
            "setHdrColorMode" -> nativex.setHdrColorMode(args.optBoolean(0, false))
            "setNativeImageRect" -> nativex.setNativeImageRect(
                args.optDouble(0, 0.0).toFloat(),
                args.optDouble(1, 0.0).toFloat(),
                args.optDouble(2, 0.0).toFloat(),
                args.optDouble(3, 0.0).toFloat(),
            )
            "showNativeVideo" -> nativex.showNativeVideo(args.optBoolean(0, false))
            "setNativeVideoRect" -> nativex.setNativeVideoRect(
                args.optDouble(0, 0.0).toFloat(),
                args.optDouble(1, 0.0).toFloat(),
                args.optDouble(2, 0.0).toFloat(),
                args.optDouble(3, 0.0).toFloat(),
            )
            else -> Log.w(TAG, "nativex_async: unknown method: $method")
        }
    }
}
