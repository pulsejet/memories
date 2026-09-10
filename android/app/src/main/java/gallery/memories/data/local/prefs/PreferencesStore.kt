package gallery.memories.data.local.prefs

import android.content.Context
import android.content.SharedPreferences
import gallery.memories.R

/** Plain app settings. Credentials live in SecureCredentialStore, not here. */
class PreferencesStore(private val ctx: Context) {
    private val prefs: SharedPreferences by lazy {
        ctx.applicationContext.getSharedPreferences(ctx.getString(R.string.preferences_key), 0)
    }

    /** Last-applied server theme color, null before first login. */
    var themeColor: String?
        get() = prefs.getString(ctx.getString(R.string.preferences_theme_color), null)
        private set(v) = prefs.edit().putString(ctx.getString(R.string.preferences_theme_color), v).apply()

    /** Whether the last-applied server theme was dark. */
    var themeDark: Boolean
        get() = prefs.getBoolean(ctx.getString(R.string.preferences_theme_dark), false)
        private set(v) = prefs.edit().putBoolean(ctx.getString(R.string.preferences_theme_dark), v).apply()

    /** Persists the server theme. A null color keeps the previous theme. */
    fun storeTheme(color: String?, isDark: Boolean) {
        if (color == null) return
        prefs.edit()
            .putString(ctx.getString(R.string.preferences_theme_color), color)
            .putBoolean(ctx.getString(R.string.preferences_theme_dark), isDark)
            .apply()
    }

    /** Media folders the user enabled. Empty means nothing is shown, not everything. */
    var enabledBucketIds: List<String>
        get() = prefs.getStringSet(ctx.getString(R.string.preferences_enabled_local_folders), null)?.toList() ?: listOf()
        set(v) = prefs.edit().putStringSet(ctx.getString(R.string.preferences_enabled_local_folders), v.toSet()).apply()

    /** Lower bound (seconds) for the next delta sync. Advanced on every sync, even partial ones. */
    var lastSyncTime: Long
        get() = prefs.getLong(ctx.getString(R.string.preferences_last_sync_time), 0L)
        set(v) = prefs.edit().putLong(ctx.getString(R.string.preferences_last_sync_time), v).apply()

    /** Resets the delta baseline so the next sync reindexes everything. */
    fun clearSyncTime() = prefs.edit().remove(ctx.getString(R.string.preferences_last_sync_time)).apply()

    /** Whether the OS media permission was granted. Mirrors PermissionsManager state. */
    var hasMediaPermission: Boolean
        get() = prefs.getBoolean(ctx.getString(R.string.preferences_has_media_permission), false)
        set(v) = prefs.edit().putBoolean(ctx.getString(R.string.preferences_has_media_permission), v).apply()

    /** Whether the user opted into showing device files. Set from the setup UI. */
    var allowMedia: Boolean
        get() = prefs.getBoolean(ctx.getString(R.string.preferences_allow_media), false)
        set(v) = prefs.edit().putBoolean(ctx.getString(R.string.preferences_allow_media), v).apply()
}
