package gallery.memories.ui.theme

import android.os.Build.VERSION.SDK_INT
import android.util.Log
import androidx.core.graphics.toColorInt
import androidx.core.view.WindowCompat
import androidx.media3.common.util.UnstableApi
import gallery.memories.MainActivity
import gallery.memories.data.local.prefs.PreferencesStore

@UnstableApi
/** Server-driven theme (persisted). Entry pages use the main blue. */
class ThemeManager(
    private val activity: MainActivity,
    private val prefs: PreferencesStore,
) {
    companion object {
        /** Flat auth background, matching the welcome/waiting/setup pages. */
        const val ENTRY_THEME_COLOR = "#174a7d"
    }

    /** Persists the server theme. A null color keeps the previous theme. */
    fun storeTheme(color: String?, isDark: Boolean) = prefs.storeTheme(color, isDark)

    /** Reapplies the last stored theme. No-op before first login. */
    fun restoreTheme() = applyTheme(prefs.themeColor, prefs.themeDark)

    /**
     * Paints system bars and the root backdrop in [color]. [isDark] selects
     * the system-icon contrast. A null color leaves the current theme in place.
     */
    @Suppress("DEPRECATION")
    fun applyTheme(color: String?, isDark: Boolean) {
        if (color == null) return
        val parsed = try {
            color.trim().toColorInt()
        } catch (_: Exception) {
            Log.w(MainActivity.TAG, "Invalid color: $color")
            return
        }

        activity.binding.root.setBackgroundColor(parsed)

        // Compat wrapper around WindowInsetsController (platform API 30+, covered by minSdk).
        WindowCompat.getInsetsController(activity.window, activity.binding.root).apply {
            isAppearanceLightStatusBars = !isDark
            isAppearanceLightNavigationBars = !isDark
        }

        // Exact colors, no system scrim: icon contrast comes from [isDark] instead.
        activity.window.isStatusBarContrastEnforced = false
        activity.window.isNavigationBarContrastEnforced = false

        if (SDK_INT < 35) {
            // Opaque bars: opt out of edge-to-edge and paint them explicitly.
            // statusBarColor/navigationBarColor are deprecated and ignored on 35+.
            WindowCompat.setDecorFitsSystemWindows(activity.window, true)
            activity.window.statusBarColor = parsed
            activity.window.navigationBarColor = parsed
        } else {
            // Edge-to-edge is enforced: transparent bars show the root backdrop above.
            activity.binding.coordinator.requestApplyInsets()
        }
    }
}
