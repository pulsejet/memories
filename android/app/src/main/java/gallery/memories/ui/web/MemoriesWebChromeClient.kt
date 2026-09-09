package gallery.memories.ui.web

import android.net.Uri
import android.util.Log
import android.webkit.ConsoleMessage
import android.webkit.PermissionRequest
import android.webkit.ValueCallback
import android.webkit.WebChromeClient
import android.webkit.WebView
import androidx.media3.common.util.UnstableApi
import gallery.memories.MainActivity

@UnstableApi
class MemoriesWebChromeClient(
    private val activity: MainActivity,
    private val chooser: FileChooserHandler,
) : WebChromeClient() {
    companion object {
        private val TAG = MemoriesWebChromeClient::class.java.simpleName
    }

    override fun onPermissionRequest(request: PermissionRequest) {
        request.grant(request.resources)
    }

    /** Mirror page errors into logcat; WebView debugging is off in release builds. */
    override fun onConsoleMessage(msg: ConsoleMessage): Boolean {
        val line = "${msg.sourceId()}:${msg.lineNumber()} ${msg.message()}"
        when (msg.messageLevel()) {
            ConsoleMessage.MessageLevel.ERROR -> Log.e(TAG, line)
            ConsoleMessage.MessageLevel.WARNING -> Log.w(TAG, line)
            else -> {}
        }
        return false
    }

    override fun onShowFileChooser(
        vw: WebView,
        filePathCallback: ValueCallback<Array<Uri>>,
        fileChooserParams: FileChooserParams,
    ): Boolean = chooser.open(filePathCallback, fileChooserParams.createIntent())
}
