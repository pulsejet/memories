package gallery.memories.ui.web

import android.content.Intent
import android.net.Uri
import android.webkit.ValueCallback
import android.webkit.WebChromeClient
import androidx.activity.result.ActivityResult
import androidx.activity.result.ActivityResultLauncher
import androidx.activity.result.contract.ActivityResultContracts
import androidx.media3.common.util.UnstableApi
import gallery.memories.MainActivity

@UnstableApi
/** Upload file picker. A text MIME entry is mixed in so the system picker opens instead of the metadata-stripping photo picker. */
class FileChooserHandler(private val activity: MainActivity) {
    private var chooseFileCallback: ValueCallback<Array<Uri>>? = null
    private lateinit var launcher: ActivityResultLauncher<Intent>

    /** Registers the picker launcher. Must be called during activity creation. */
    fun register() {
        launcher = activity.registerForActivityResult(ActivityResultContracts.StartActivityForResult()) { result: ActivityResult ->
            val intent = result.data
            var uris = WebChromeClient.FileChooserParams.parseResult(result.resultCode, intent)
            val clipData = intent?.clipData
            if (uris.isNullOrEmpty() && clipData != null) {
                uris = Array(clipData.itemCount) { i -> clipData.getItemAt(i).uri }
            }
            chooseFileCallback?.onReceiveValue(uris)
            chooseFileCallback = null
        }
    }

    /**
     * Opens the picker for one upload request. A superseded pending callback
     * is cancelled first so the page never waits on it.
     */
    fun open(callback: ValueCallback<Array<Uri>>?, intent: Intent): Boolean {
        chooseFileCallback?.onReceiveValue(null)
        chooseFileCallback = callback
        intent.putExtra(Intent.EXTRA_MIME_TYPES, arrayOf("image/*", "video/*", "text/*"))
        launcher.launch(intent)
        return true
    }
}
