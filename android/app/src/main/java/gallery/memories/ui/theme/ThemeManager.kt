package gallery.memories.ui.theme

import android.os.Build.VERSION.SDK_INT
import android.util.Log
import android.view.WindowInsetsController
import androidx.core.graphics.toColorInt
import androidx.core.view.WindowCompat
import androidx.media3.common.util.UnstableApi
import gallery.memories.MainActivity
import gallery.memories.data.local.prefs.PreferencesStore

@UnstableApi
/** Server-driven theme (persisted) plus transparent edge-to-edge entry pages. */
class ThemeManager(
    private val activity: MainActivity,
    private val prefs: PreferencesStore,
) {
    /** Persists the server theme. A null color keeps the previous theme. */
    fun storeTheme(color: String?, isDark: Boolean) = prefs.storeTheme(color, isDark)

    /** Reapplies the last stored theme. No-op before first login. */
    fun restoreTheme() = applyTheme(prefs.themeColor, prefs.themeDark)

    /**
     * Paints system bars and the root backdrop in [color]. [isDark] selects
     * the system-icon contrast. A null color leaves the current theme in place.
     */
    fun applyTheme(color: String?, isDark: Boolean) {
        if (color == null) return
        activity.isTransparentBars = false
        if (SDK_INT < 35) {
            WindowCompat.setDecorFitsSystemWindows(activity.window, true)
        }
        if (SDK_INT >= 29) {
            activity.window.isStatusBarContrastEnforced = true
            activity.window.isNavigationBarContrastEnforced = true
        }
        val appearance = WindowInsetsController.APPEARANCE_LIGHT_STATUS_BARS or
            WindowInsetsController.APPEARANCE_LIGHT_NAVIGATION_BARS
        activity.window.insetsController?.setSystemBarsAppearance(if (isDark) 0 else appearance, appearance)
        try {
            val parsed = color.trim().toColorInt()
            activity.binding.root.setBackgroundColor(parsed)
            activity.window.navigationBarColor = parsed
            activity.window.statusBarColor = parsed
        } catch (_: Exception) {
            Log.w(MainActivity.TAG, "Invalid color: $color")
            return
        }
        if (SDK_INT >= 35) activity.binding.coordinator.requestApplyInsets()
    }
}
