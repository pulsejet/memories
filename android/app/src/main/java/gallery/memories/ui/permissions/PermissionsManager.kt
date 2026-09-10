package gallery.memories.ui.permissions

import android.os.Build
import androidx.activity.result.ActivityResultLauncher
import androidx.activity.result.contract.ActivityResultContracts
import androidx.media3.common.util.UnstableApi
import gallery.memories.MainActivity
import gallery.memories.data.local.prefs.PreferencesStore
import java.util.concurrent.CountDownLatch

@UnstableApi
/**
 * Media permission plus the user's opt-in to show device files. The sync
 * variant blocks on a latch, so it must be called off the UI thread.
 *
 * Permission truth lives in two places: [isGranted] reflects the live OS
 * grant for this process, while [hasMediaPermission] persists it for boot.
 * They diverge when the user revokes mid-session until the next request.
 */
class PermissionsManager(
    private val activity: MainActivity,
    private val prefs: PreferencesStore,
) {
    private var isGranted: Boolean = false
    private var latch: CountDownLatch? = null
    private lateinit var requestPermissionLauncher: ActivityResultLauncher<Array<String>>

    /** Registers the permission launcher. Must be called during activity creation. */
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

    /**
     * Requests the OS media permission, blocking until the user answers.
     * Must be called off the UI thread. True when all requested permissions
     * were granted.
     */
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

    /** Persisted OS grant from the last request. See the class docs on staleness. */
    fun hasMediaPermission(): Boolean = prefs.hasMediaPermission

    /** Whether the user opted into showing device files (setup UI). */
    fun hasAllowMedia(): Boolean = prefs.allowMedia

    fun setAllowMedia(v: Boolean) {
        prefs.allowMedia = v
    }
}
