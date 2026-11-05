package gallery.memories.data.local.media

import android.app.Activity
import android.net.Uri
import android.provider.MediaStore
import androidx.activity.result.ActivityResult
import androidx.activity.result.ActivityResultLauncher
import androidx.activity.result.IntentSenderRequest
import androidx.activity.result.contract.ActivityResultContracts
import gallery.memories.MainActivity
import java.util.concurrent.CountDownLatch

/**
 * Deletes device files via system trash request. Without media management
 * grant this shows an OS confirmation; with it the same call succeeds
 * silently. Blocks on a latch, so must be called off the UI thread.
 */
class MediaDeleter(private val activity: MainActivity) {
    /** True when silent trash/delete is allowed (no OS prompt). */
    fun canManageMedia(): Boolean = MediaStore.canManageMedia(activity)

    private var deleting = false
    private var deleteCallback: ((ActivityResult?) -> Unit)? = null
    private val deleteLauncher: ActivityResultLauncher<IntentSenderRequest> =
        activity.registerForActivityResult(ActivityResultContracts.StartIntentSenderForResult()) { result ->
            finish(result)
        }

    /** Takes the callback once, so a late result can't overwrite the first. */
    private fun finish(result: ActivityResult?) {
        synchronized(this) {
            deleteCallback?.invoke(result)
            deleteCallback = null
        }
    }

    /** Fails a pending delete; its result can't reach this activity anymore. */
    fun destroy() = finish(null)

    /** Deletes [uris]. Throws when already deleting or when the user cancels. */
    @Throws(Exception::class)
    fun deleteUris(uris: List<Uri>) {
        synchronized(this) {
            if (deleting) throw Exception("Already deleting another set of images")
            deleting = true
        }
        try {
            if (uris.isEmpty()) return
            val intent = MediaStore.createTrashRequest(activity.contentResolver, uris, true)
            val latch = CountDownLatch(1)
            var res: ActivityResult? = null
            synchronized(this) { deleteCallback = { r -> res = r; latch.countDown() } }
            try {
                deleteLauncher.launch(IntentSenderRequest.Builder(intent.intentSender).build())
            } catch (e: Exception) {
                finish(null)
                throw e
            }
            latch.await()
            val resultCode = res?.resultCode
            if (resultCode == null || resultCode != Activity.RESULT_OK) {
                throw Exception("Delete canceled or failed")
            }
        } finally {
            synchronized(this) { deleting = false }
        }
    }
}
