package gallery.memories.data.local.prefs

import android.content.Context
import android.content.SharedPreferences
import gallery.memories.R

/** Plain app settings. Credentials live in SecureCredentialStore, not here. */
class PreferencesStore(private val ctx: Context) {
    private val prefs: SharedPreferences by lazy {
        ctx.applicationContext.getSharedPreferences(ctx.getString(R.string.preferences_key), 0)
    }

    var themeColor: String?
        get() = prefs.getString(ctx.getString(R.string.preferences_theme_color), null)
        private set(v) = prefs.edit().putString(ctx.getString(R.string.preferences_theme_color), v).apply()

    var themeDark: Boolean
        get() = prefs.getBoolean(ctx.getString(R.string.preferences_theme_dark), false)
        private set(v) = prefs.edit().putBoolean(ctx.getString(R.string.preferences_theme_dark), v).apply()

    fun storeTheme(color: String?, isDark: Boolean) {
        if (color == null) return
        prefs.edit()
            .putString(ctx.getString(R.string.preferences_theme_color), color)
            .putBoolean(ctx.getString(R.string.preferences_theme_dark), isDark)
            .apply()
    }

    var enabledBucketIds: List<String>
        get() = prefs.getStringSet(ctx.getString(R.string.preferences_enabled_local_folders), null)?.toList() ?: listOf()
        set(v) = prefs.edit().putStringSet(ctx.getString(R.string.preferences_enabled_local_folders), v.toSet()).apply()

    var lastSyncTime: Long
        get() = prefs.getLong(ctx.getString(R.string.preferences_last_sync_time), 0L)
        set(v) = prefs.edit().putLong(ctx.getString(R.string.preferences_last_sync_time), v).apply()

    fun clearSyncTime() = prefs.edit().remove(ctx.getString(R.string.preferences_last_sync_time)).apply()

    var hasMediaPermission: Boolean
        get() = prefs.getBoolean(ctx.getString(R.string.preferences_has_media_permission), false)
        set(v) = prefs.edit().putBoolean(ctx.getString(R.string.preferences_has_media_permission), v).apply()

    var allowMedia: Boolean
        get() = prefs.getBoolean(ctx.getString(R.string.preferences_allow_media), false)
        set(v) = prefs.edit().putBoolean(ctx.getString(R.string.preferences_allow_media), v).apply()
}
