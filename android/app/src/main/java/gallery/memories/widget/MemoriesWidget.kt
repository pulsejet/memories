package gallery.memories.widget

import android.app.PendingIntent
import android.appwidget.AppWidgetManager
import android.appwidget.AppWidgetProvider
import android.content.Context
import android.content.Intent
import android.widget.RemoteViews
import androidx.work.*
import gallery.memories.MainActivity
import gallery.memories.R
import java.util.concurrent.TimeUnit

class MemoriesWidget : AppWidgetProvider() {

    override fun onUpdate(
        context: Context,
        appWidgetManager: AppWidgetManager,
        appWidgetIds: IntArray
    ) {
        for (appWidgetId in appWidgetIds) {
            updateAppWidget(context, appWidgetManager, appWidgetId)
        }
        scheduleWidgetUpdate(context)
    }

    override fun onEnabled(context: Context) {
        scheduleWidgetUpdate(context)
    }

    override fun onDisabled(context: Context) {
        WorkManager.getInstance(context).cancelUniqueWork(WORK_NAME)
    }

    override fun onReceive(context: Context, intent: Intent) {
        super.onReceive(context, intent)
        if (intent.action == ACTION_REFRESH) {
            // User tapped refresh — enqueue a one-time immediate update (debounced)
            val oneTimeRequest = OneTimeWorkRequestBuilder<WidgetWorker>().build()
            WorkManager.getInstance(context).enqueueUniqueWork(
                WORK_NAME_REFRESH,
                ExistingWorkPolicy.REPLACE,
                oneTimeRequest
            )
        }
    }

    private fun updateAppWidget(
        context: Context,
        appWidgetManager: AppWidgetManager,
        appWidgetId: Int
    ) {
        val views = RemoteViews(context.packageName, R.layout.widget_memories)

        // Click root to open app
        val openIntent = Intent(context, MainActivity::class.java)
        val openPending = PendingIntent.getActivity(
            context, 0, openIntent,
            PendingIntent.FLAG_UPDATE_CURRENT or PendingIntent.FLAG_IMMUTABLE
        )
        views.setOnClickPendingIntent(R.id.widget_root, openPending)

        // Refresh button
        val refreshIntent = Intent(context, MemoriesWidget::class.java).apply {
            action = ACTION_REFRESH
        }
        val refreshPending = PendingIntent.getBroadcast(
            context, 0, refreshIntent,
            PendingIntent.FLAG_UPDATE_CURRENT or PendingIntent.FLAG_IMMUTABLE
        )
        views.setOnClickPendingIntent(R.id.widget_refresh_btn, refreshPending)

        appWidgetManager.updateAppWidget(appWidgetId, views)
    }

    private fun scheduleWidgetUpdate(context: Context) {
        // Schedule the next update in UPDATE_INTERVAL_MINUTES minutes
        val workRequest = OneTimeWorkRequestBuilder<WidgetWorker>()
            .setInitialDelay(UPDATE_INTERVAL_MINUTES, TimeUnit.MINUTES)
            .setConstraints(
                Constraints.Builder()
                    .setRequiresBatteryNotLow(true)
                    .build()
            )
            .build()

        WorkManager.getInstance(context).enqueueUniqueWork(
            WORK_NAME,
            ExistingWorkPolicy.REPLACE,
            workRequest
        )

        // Also trigger an immediate one-time update
        val immediateRequest = OneTimeWorkRequestBuilder<WidgetWorker>().build()
        WorkManager.getInstance(context).enqueueUniqueWork(
            WORK_NAME_REFRESH,
            ExistingWorkPolicy.REPLACE,
            immediateRequest
        )
    }

    companion object {
        const val ACTION_REFRESH = "gallery.memories.widget.ACTION_REFRESH"
        private const val WORK_NAME = "MemoriesWidgetAutoUpdate"
        private const val WORK_NAME_REFRESH = "MemoriesWidgetRefresh"
        const val UPDATE_INTERVAL_MINUTES = 10L
    }
}
