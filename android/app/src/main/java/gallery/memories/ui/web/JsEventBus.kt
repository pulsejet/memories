package gallery.memories.ui.web

import androidx.lifecycle.Lifecycle
import androidx.media3.common.util.UnstableApi
import gallery.memories.MainActivity

@UnstableApi
/** Emits events into the web page's bus. Deferred while paused so no refresh is lost. */
class JsEventBus(private val activity: MainActivity) {
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
    fun busEmit(event: String, data: String = "null") {
        activity.runOnUiThread { emitNow(event, data) }
    }

    /** Emits one bus event. Caller must already be on the UI thread. */
    private fun emitNow(event: String, data: String = "null") {
        if (activity.binding.webview.url == null) return
        activity.binding.webview.evaluateJavascript("window._nc_event_bus?.emit('$event', $data)", null)
    }
}
