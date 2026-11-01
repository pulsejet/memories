package gallery.memories.ui.theme

import android.content.res.Configuration
import android.graphics.Insets
import android.os.Build.VERSION.SDK_INT
import android.view.View
import android.view.ViewGroup
import android.view.WindowInsets
import android.view.WindowInsetsController
import android.view.WindowManager
import androidx.core.view.updateLayoutParams
import androidx.media3.common.util.UnstableApi
import gallery.memories.MainActivity
import kotlin.math.roundToInt

@UnstableApi
/** System bars and cutout handling. */
class EdgeToEdgeController(private val activity: MainActivity) {
    /** Last value sent to the page, in CSS px. */
    private var keyboardHeight = 0

    /**
     * Applies system-bar insets as coordinator margins (below SDK 35 the
     * platform fits them instead) and forwards the rest to WebView.
     */
    fun setupInsets() {
        activity.binding.coordinator.setOnApplyWindowInsetsListener { v, windowInsets ->
            publishKeyboardHeight(v, windowInsets)
            if (SDK_INT < 35) return@setOnApplyWindowInsetsListener windowInsets
            val insets = windowInsets.getInsets(WindowInsets.Type.systemBars())
            v.updateLayoutParams<ViewGroup.MarginLayoutParams> {
                leftMargin = insets.left; rightMargin = insets.right
                topMargin = insets.top; bottomMargin = insets.bottom
            }
            // Zeroed, not CONSUMED: WebView needs the rest, the keyboard above all.
            WindowInsets.Builder(windowInsets)
                .setInsets(WindowInsets.Type.systemBars() or WindowInsets.Type.displayCutout(), Insets.NONE)
                .build()
        }
    }

    /**
     * WebView leaves env(keyboard-inset-height) at 0, so the keyboard's overlap
     * with the page goes out on the event bus instead.
     */
    private fun publishKeyboardHeight(view: View, insets: WindowInsets) {
        val bars = view.rootWindowInsets?.getInsets(WindowInsets.Type.systemBars())?.bottom ?: 0
        val overlap = (insets.getInsets(WindowInsets.Type.ime()).bottom - bars).coerceAtLeast(0)
        val height = (overlap / view.resources.displayMetrics.density).roundToInt()
        if (height == keyboardHeight) return
        keyboardHeight = height
        activity.eventBus.busEmit("nativex:keyboard:height", height)
    }

    /** Fullscreen landscape hides the status bar; portrait restores it. */
    fun setFullscreen(value: Boolean) {
        if (value) {
            activity.window.attributes.layoutInDisplayCutoutMode =
                WindowManager.LayoutParams.LAYOUT_IN_DISPLAY_CUTOUT_MODE_SHORT_EDGES
            activity.window.insetsController?.apply {
                hide(WindowInsets.Type.statusBars())
                systemBarsBehavior = WindowInsetsController.BEHAVIOR_SHOW_TRANSIENT_BARS_BY_SWIPE
            }
        } else {
            activity.window.attributes.layoutInDisplayCutoutMode =
                WindowManager.LayoutParams.LAYOUT_IN_DISPLAY_CUTOUT_MODE_DEFAULT
            activity.window.insetsController?.apply { show(WindowInsets.Type.statusBars()) }
        }
    }

    fun applyOrientation(orientation: Int) {
        setFullscreen(orientation == Configuration.ORIENTATION_LANDSCAPE)
    }
}
