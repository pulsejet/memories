package gallery.memories.service

import android.content.BroadcastReceiver
import android.content.Context
import android.content.Intent
import gallery.memories.NativeX

class DownloadBroadcastReceiver : BroadcastReceiver() {
    override fun onReceive(context: Context, intent: Intent) {
        NativeX.dlService?.runDownloadCallback(intent)
    }
}