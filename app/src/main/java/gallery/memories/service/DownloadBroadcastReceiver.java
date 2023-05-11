package gallery.memories.service;

import android.app.DownloadManager;
import android.content.BroadcastReceiver;
import android.content.Context;
import android.content.Intent;
import android.widget.Toast;

import gallery.memories.NativeX;

public class DownloadBroadcastReceiver extends BroadcastReceiver {
    @Override
    public void onReceive(Context context, Intent intent) {
        if (NativeX.mDlService != null) {
            NativeX.mDlService.runDownloadCallback(intent);
        }
    }
}
