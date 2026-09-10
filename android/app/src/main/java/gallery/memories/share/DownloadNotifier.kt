package gallery.memories.share

import android.Manifest
import android.app.NotificationChannel
import android.app.NotificationManager
import android.app.PendingIntent
import android.content.Context
import android.content.Intent
import android.content.pm.PackageManager
import android.net.Uri
import android.os.Build
import android.os.Handler
import android.os.Looper
import android.widget.Toast
import androidx.core.app.NotificationCompat
import androidx.core.app.NotificationManagerCompat
import androidx.core.content.ContextCompat
import java.util.concurrent.atomic.AtomicInteger

/** Status-bar completion for downloads; falls back to toast when notifications are blocked. */
class DownloadNotifier(private val ctx: Context) {
    companion object {
        private const val CHANNEL = "downloads"
        private val ids = AtomicInteger(1000)
    }

    fun success(name: String, uri: Uri, mime: String) {
        if (!canNotify()) {
            toast("Download complete: $name", false)
            return
        }
        ensureChannel()
        val id = ids.getAndIncrement()
        val view = Intent(Intent.ACTION_VIEW).apply {
            setDataAndType(uri, mime)
            addFlags(Intent.FLAG_GRANT_READ_URI_PERMISSION or Intent.FLAG_ACTIVITY_NEW_TASK)
        }
        val tap = PendingIntent.getActivity(ctx, id, view, PendingIntent.FLAG_UPDATE_CURRENT or PendingIntent.FLAG_IMMUTABLE)
        val n = NotificationCompat.Builder(ctx, CHANNEL)
            .setSmallIcon(android.R.drawable.stat_sys_download_done)
            .setContentTitle(name)
            .setContentText("Download complete")
            .setContentIntent(tap)
            .setAutoCancel(true)
            .build()
        NotificationManagerCompat.from(ctx).notify(id, n)
    }

    fun failure(detail: String) {
        val text = if (detail.length > 200) detail.take(200) else detail
        if (!canNotify()) {
            toast("Download failed: $text", true)
            return
        }
        ensureChannel()
        val n = NotificationCompat.Builder(ctx, CHANNEL)
            .setSmallIcon(android.R.drawable.stat_sys_warning)
            .setContentTitle("Download failed")
            .setContentText(text)
            .setAutoCancel(true)
            .build()
        NotificationManagerCompat.from(ctx).notify(ids.getAndIncrement(), n)
    }

    private fun canNotify(): Boolean {
        if (Build.VERSION.SDK_INT >= 33 &&
            ContextCompat.checkSelfPermission(ctx, Manifest.permission.POST_NOTIFICATIONS) != PackageManager.PERMISSION_GRANTED
        ) return false
        return NotificationManagerCompat.from(ctx).areNotificationsEnabled()
    }

    /** Toasts must post on the main thread; callers run on a worker pool. */
    private fun toast(message: String, long: Boolean) {
        Handler(Looper.getMainLooper()).post {
            Toast.makeText(ctx, message, if (long) Toast.LENGTH_LONG else Toast.LENGTH_SHORT).show()
        }
    }

    private fun ensureChannel() {
        val manager = ctx.getSystemService(Context.NOTIFICATION_SERVICE) as NotificationManager
        if (manager.getNotificationChannel(CHANNEL) == null) {
            manager.createNotificationChannel(
                NotificationChannel(CHANNEL, "Downloads", NotificationManager.IMPORTANCE_DEFAULT),
            )
        }
    }
}
