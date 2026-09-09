package gallery.memories.ui.permissions

import android.os.Build
import androidx.activity.result.ActivityResultLauncher
import androidx.activity.result.contract.ActivityResultContracts
import androidx.media3.common.util.UnstableApi
import gallery.memories.MainActivity
import gallery.memories.data.local.prefs.PreferencesStore
import java.util.concurrent.CountDownLatch

@UnstableApi
/** Media permission plus the user's opt-in to show device files. Sync variant blocks on a latch. */
class PermissionsManager(
    private val activity: MainActivity,
    private val prefs: PreferencesStore,
) {
    var isGranted: Boolean = false
    var latch: CountDownLatch? = null
    lateinit var requestPermissionLauncher: ActivityResultLauncher<Array<String>>

    fun register(): PermissionsManager {
        requestPermissionLauncher = activity.registerForActivityResult(
            ActivityResultContracts.RequestMultiplePermissions(),
        ) { permissions ->
            isGranted = permissions.all { it.value }
            prefs.hasMediaPermission = isGranted
            latch?.countDown()
        }
        return this
    }

    fun requestMediaPermissionSync(): Boolean {
        if (isGranted) return true
        latch = CountDownLatch(1)
        if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.TIRAMISU) {
            requestPermissionLauncher.launch(
                arrayOf(
                    android.Manifest.permission.READ_MEDIA_IMAGES,
                    android.Manifest.permission.READ_MEDIA_VIDEO,
                ),
            )
        } else {
            requestPermissionLauncher.launch(arrayOf(android.Manifest.permission.READ_EXTERNAL_STORAGE))
        }
        latch?.await()
        return isGranted
    }

    fun hasMediaPermission(): Boolean = prefs.hasMediaPermission

    fun hasAllowMedia(): Boolean = prefs.allowMedia

    fun setAllowMedia(v: Boolean) {
        prefs.allowMedia = v
    }
}
