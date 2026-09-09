package gallery.memories.share

import android.content.BroadcastReceiver
import android.content.Context
import android.content.Intent
import androidx.media3.common.util.UnstableApi
import gallery.memories.NativeX

@UnstableApi
/** Manifest-registered; forwards system download completion into ShareManager. */
class DownloadCompleteReceiver : BroadcastReceiver() {
    override fun onReceive(context: Context, intent: Intent) {
        NativeX.shareManager?.runDownloadCallback(intent)
    }
}
