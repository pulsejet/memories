package gallery.memories.ui.web

import android.graphics.Bitmap
import android.graphics.Canvas
import android.net.Uri
import android.util.Log
import android.webkit.ConsoleMessage
import android.webkit.ValueCallback
import android.webkit.WebChromeClient
import android.webkit.WebView
import androidx.core.graphics.createBitmap
import gallery.memories.MainActivity

/**
 * Chrome client: delegates upload pickers, mirrors console errors to logcat.
 * Page permission requests stay denied (the default): the app uses none.
 */
class MemoriesWebChromeClient(
    private val activity: MainActivity,
    private val chooser: FileChooserHandler,
) : WebChromeClient() {
    companion object {
        private val TAG = MemoriesWebChromeClient::class.java.simpleName
    }

    /** Transparent 1x1 poster so videos never flash the default gray/white placeholder. */
    override fun getDefaultVideoPoster(): Bitmap {
        val bitmap = createBitmap(1, 1)
        Canvas(bitmap).drawARGB(0, 0, 0, 0)
        return bitmap
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
