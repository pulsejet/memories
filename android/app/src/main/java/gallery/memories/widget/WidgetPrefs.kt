package gallery.memories.widget

import android.content.Context

/**
 * Lightweight wrapper for per-widget SharedPreferences.
 * Stores the user-chosen photo change interval for each widget instance.
 */
object WidgetPrefs {

    private const val PREFS_NAME = "memories_widget_prefs"
    private const val KEY_INTERVAL_PREFIX = "interval_"

    /** Default photo change interval in minutes. */
    const val DEFAULT_INTERVAL_MINUTES = 5L

    /** Preset interval options in minutes. */
    val INTERVAL_OPTIONS = longArrayOf(5, 15, 25)

    fun getIntervalMinutes(context: Context, appWidgetId: Int): Long {
        val prefs = context.getSharedPreferences(PREFS_NAME, Context.MODE_PRIVATE)
        return prefs.getLong("$KEY_INTERVAL_PREFIX$appWidgetId", DEFAULT_INTERVAL_MINUTES)
    }

    fun setIntervalMinutes(context: Context, appWidgetId: Int, minutes: Long) {
        context.getSharedPreferences(PREFS_NAME, Context.MODE_PRIVATE)
            .edit()
            .putLong("$KEY_INTERVAL_PREFIX$appWidgetId", minutes)
            .apply()
    }

    fun removeWidget(context: Context, appWidgetId: Int) {
        context.getSharedPreferences(PREFS_NAME, Context.MODE_PRIVATE)
            .edit()
            .remove("$KEY_INTERVAL_PREFIX$appWidgetId")
            .apply()
    }

    /**
     * Get the minimum interval across all active widgets.
     * Used by the auto-update worker to determine scheduling frequency.
     */
    fun getMinIntervalMinutes(context: Context): Long {
        val prefs = context.getSharedPreferences(PREFS_NAME, Context.MODE_PRIVATE)
        val intervals = prefs.all
            .filter { it.key.startsWith(KEY_INTERVAL_PREFIX) }
            .mapNotNull { (it.value as? Long) }

        return if (intervals.isEmpty()) DEFAULT_INTERVAL_MINUTES
        else intervals.min()
    }
}
