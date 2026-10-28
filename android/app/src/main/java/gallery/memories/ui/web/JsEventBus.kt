package gallery.memories.ui.web

import android.util.Log
import androidx.lifecycle.Lifecycle
import androidx.media3.common.util.UnstableApi
import androidx.webkit.JavaScriptReplyProxy
import gallery.memories.MainActivity
import org.json.JSONObject

@UnstableApi
/** Emits events into the web page's bus. Deferred while paused so no refresh is lost. */
class JsEventBus(private val activity: MainActivity) {
    companion object {
        val TAG: String = JsEventBus::class.java.simpleName
    }

    /** Reply channel from the last `setupEventBus` call. Primary path; null until JS registers. */
    @Volatile
    var replyProxy: JavaScriptReplyProxy? = null

    private var needRefresh = false

    /** Notifies the page that the local index changed, deferring while paused. */
    fun refreshTimeline(force: Boolean = false) {
        activity.runOnUiThread {
            if (activity.binding.webview.url == null) return@runOnUiThread
            if (activity.lifecycle.currentState.isAtLeast(Lifecycle.State.RESUMED) || force) {
                needRefresh = false
                emitNow("nativex:db:updated")
                emitNow("memories:timeline:soft-refresh")
            } else {
                needRefresh = true
            }
        }
    }

    /** Replays a refresh deferred while paused. */
    fun onResumeRefresh() {
        if (needRefresh) refreshTimeline(true)
    }

    /** Emits one bus event. Safe to call from any thread. */
    fun busEmit(event: String, data: Any? = null) {
        activity.runOnUiThread { emitNow(event, data) }
    }

    /** Emits one bus event. Caller must already be on the UI thread. */
    private fun emitNow(event: String, data: Any? = null) {
        if (activity.binding.webview.url == null) return
        val inner = JSONObject()
            .put("event", event)
            .put("data", data ?: JSONObject.NULL)

        // Primary path: reply proxy captured by setupEventBus.
        replyProxy?.let { proxy ->
            try {
                proxy.postMessage(JSONObject().put("event-bus", inner).toString())
                return
            } catch (e: Exception) {
                Log.w(TAG, "replyProxy failed, falling back to evaluateJavascript", e)
                replyProxy = null
            }
        }
        // Fallback path: direct JS eval.
        val raw = inner.opt("data")
        val dataJson = if (raw == null || raw === JSONObject.NULL) "null"
        else if (raw is String) JSONObject.quote(raw)
        else raw.toString()
        activity.binding.webview.evaluateJavascript("window._nc_event_bus?.emit('$event', $dataJson)", null)
    }
}
