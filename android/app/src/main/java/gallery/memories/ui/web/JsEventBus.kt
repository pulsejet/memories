package gallery.memories.ui.web

import androidx.lifecycle.Lifecycle
import androidx.media3.common.util.UnstableApi
import gallery.memories.MainActivity

@UnstableApi
/** Emits events into the web page's bus. Deferred while paused so no refresh is lost. */
class JsEventBus(private val activity: MainActivity) {
    private var needRefresh = false

    fun refreshTimeline(force: Boolean = false) {
        activity.runOnUiThread {
            if (activity.binding.webview.url == null) return@runOnUiThread
            if (activity.lifecycle.currentState.isAtLeast(Lifecycle.State.RESUMED) || force) {
                needRefresh = false
                busEmit("nativex:db:updated")
                busEmit("memories:timeline:soft-refresh")
            } else {
                needRefresh = true
            }
        }
    }

    fun onResumeRefresh() {
        if (needRefresh) refreshTimeline(true)
    }

    fun busEmit(event: String, data: String = "null") {
        activity.runOnUiThread {
            if (activity.binding.webview.url == null) return@runOnUiThread
            activity.binding.webview.evaluateJavascript("window._nc_event_bus?.emit('$event', $data)", null)
        }
    }
}
