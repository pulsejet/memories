package gallery.memories.data.local.media

import android.app.Activity
import android.net.Uri
import android.os.Build
import android.provider.MediaStore
import androidx.activity.result.ActivityResult
import androidx.activity.result.ActivityResultLauncher
import androidx.activity.result.IntentSenderRequest
import androidx.activity.result.contract.ActivityResultContracts
import androidx.media3.common.util.UnstableApi
import gallery.memories.MainActivity
import java.util.concurrent.CountDownLatch

@UnstableApi
/**
 * Deletes device files. Android R+ requires user confirmation via system
 * dialog, so the call blocks on a latch until the picker result returns.
 */
class MediaDeleter(private val activity: MainActivity) {
    private var deleting = false
    private var deleteCallback: ((ActivityResult?) -> Unit)? = null
    val deleteLauncher: ActivityResultLauncher<IntentSenderRequest> =
        activity.registerForActivityResult(ActivityResultContracts.StartIntentSenderForResult()) { result ->
            synchronized(this) { deleteCallback?.invoke(result) }
        }

    @Throws(Exception::class)
    fun deleteUris(uris: List<Uri>) {
        synchronized(this) {
            if (deleting) throw Exception("Already deleting another set of images")
            deleting = true
        }
        try {
            if (uris.isEmpty()) return
            if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.R) {
                val intent = MediaStore.createTrashRequest(activity.contentResolver, uris, true)
                val latch = CountDownLatch(1)
                var res: ActivityResult? = null
                deleteCallback = { r -> res = r; latch.countDown() }
                try {
                    deleteLauncher.launch(IntentSenderRequest.Builder(intent.intentSender).build())
                } catch (e: Exception) {
                    deleteCallback = null
                    throw e
                }
                latch.await()
                deleteCallback = null
                if (res == null || res!!.resultCode != Activity.RESULT_OK) {
                    throw Exception("Delete canceled or failed")
                }
            } else {
                for (uri in uris) activity.contentResolver.delete(uri, null, null)
            }
        } finally {
            synchronized(this) { deleting = false }
        }
    }
}
