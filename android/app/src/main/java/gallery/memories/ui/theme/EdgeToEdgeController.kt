package gallery.memories.ui.theme

import android.content.res.Configuration
import android.graphics.Color
import android.os.Build.VERSION.SDK_INT
import android.view.ViewGroup
import android.view.WindowInsets
import android.view.WindowInsetsController
import android.view.WindowManager
import androidx.core.view.WindowCompat
import androidx.core.view.updateLayoutParams
import androidx.media3.common.util.UnstableApi
import gallery.memories.MainActivity

@UnstableApi
/** System bars and cutout handling. Transparent mode lets welcome/waiting draw underneath. */
class EdgeToEdgeController(private val activity: MainActivity) {
    companion object {
        /** Opaque backdrop behind transparent entry pages, matching the server theme. */
        private const val ENTRY_BACKGROUND = "#0d2038"
    }

    /**
     * Applies system-bar insets as coordinator margins. Below SDK 35 the
     * platform handles fitting, so there is nothing to do.
     */
    fun setupInsets() {
        if (SDK_INT >= 35) {
            activity.binding.coordinator.setOnApplyWindowInsetsListener { v, windowInsets ->
                if (activity.isTransparentBars) {
                    v.updateLayoutParams<ViewGroup.MarginLayoutParams> {
                        leftMargin = 0; rightMargin = 0; topMargin = 0; bottomMargin = 0
                    }
                } else {
                    val insets = windowInsets.getInsets(WindowInsets.Type.systemBars())
                    v.updateLayoutParams<ViewGroup.MarginLayoutParams> {
                        leftMargin = insets.left; rightMargin = insets.right
                        topMargin = insets.top; bottomMargin = insets.bottom
                    }
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

    /**
     * Makes system bars transparent for entry pages (restoring the server
     * theme when disabled). [isDark] picks the icon contrast: dark
     * backgrounds keep light icons.
     */
    fun setTransparentBars(transparent: Boolean, isDark: Boolean = true) {
        activity.isTransparentBars = transparent
        if (!transparent) {
            activity.restoreTheme()
            return
        }
        WindowCompat.setDecorFitsSystemWindows(activity.window, false)
        if (SDK_INT >= 29) {
            activity.window.isStatusBarContrastEnforced = false
            activity.window.isNavigationBarContrastEnforced = false
        }
        val appearance = WindowInsetsController.APPEARANCE_LIGHT_STATUS_BARS or
            WindowInsetsController.APPEARANCE_LIGHT_NAVIGATION_BARS
        activity.window.insetsController?.setSystemBarsAppearance(if (isDark) 0 else appearance, appearance)
        try {
            activity.window.statusBarColor = Color.TRANSPARENT
            activity.window.navigationBarColor = Color.TRANSPARENT
        } catch (_: Exception) {}
        try {
            activity.binding.root.setBackgroundColor(Color.parseColor(ENTRY_BACKGROUND))
        } catch (_: Exception) {}
        try {
            activity.binding.coordinator.updateLayoutParams<ViewGroup.MarginLayoutParams> {
                leftMargin = 0; rightMargin = 0; topMargin = 0; bottomMargin = 0
            }
        } catch (_: Exception) {}
    }
}
