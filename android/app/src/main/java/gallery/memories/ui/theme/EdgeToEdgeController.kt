package gallery.memories.ui.theme

import android.content.res.Configuration
import android.os.Build.VERSION.SDK_INT
import android.view.ViewGroup
import android.view.WindowInsets
import android.view.WindowInsetsController
import android.view.WindowManager
import androidx.core.view.updateLayoutParams
import androidx.media3.common.util.UnstableApi
import gallery.memories.MainActivity

@UnstableApi
/** System bars and cutout handling. */
class EdgeToEdgeController(private val activity: MainActivity) {
    /**
     * Applies system-bar insets as coordinator margins. Below SDK 35 the
     * platform handles fitting, so there is nothing to do.
     */
    fun setupInsets() {
        if (SDK_INT >= 35) {
            activity.binding.coordinator.setOnApplyWindowInsetsListener { v, windowInsets ->
                val insets = windowInsets.getInsets(WindowInsets.Type.systemBars())
                v.updateLayoutParams<ViewGroup.MarginLayoutParams> {
                    leftMargin = insets.left; rightMargin = insets.right
                    topMargin = insets.top; bottomMargin = insets.bottom
                }
                WindowInsets.CONSUMED
            }
        }
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
