package gallery.memories.ui.web

import android.net.Uri
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
    override fun onPermissionRequest(request: PermissionRequest) {
        request.grant(request.resources)
    }

    override fun onShowFileChooser(
        vw: WebView,
        filePathCallback: ValueCallback<Array<Uri>>,
        fileChooserParams: FileChooserParams,
    ): Boolean = chooser.open(filePathCallback, fileChooserParams.createIntent())
}
