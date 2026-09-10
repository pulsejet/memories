package gallery.memories.ui.permissions

import android.Manifest
import android.content.pm.PackageManager
import android.os.Build
import androidx.activity.result.ActivityResultLauncher
import androidx.activity.result.contract.ActivityResultContracts
import androidx.core.content.ContextCompat
import androidx.media3.common.util.UnstableApi
import gallery.memories.MainActivity
import gallery.memories.data.local.prefs.PreferencesStore
import java.util.concurrent.CountDownLatch

@UnstableApi
/**
 * Media permission plus the user's opt-in to show device files. The sync
 * variant blocks on a latch, so it must be called off the UI thread.
 *
 * [ACCESS_MEDIA_LOCATION][Manifest.permission.ACCESS_MEDIA_LOCATION] is
 * requested alongside the media permissions so EXIF GPS is unredacted
 * (Android 10+ strips it otherwise). It is optional: a denial only leaves
 * GPS null, media sync still proceeds.
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

    companion object {
        private const val LOCATION_PERM = Manifest.permission.ACCESS_MEDIA_LOCATION
    }

    /** Registers the permission launcher. Must be called during activity creation. */
    fun register(): PermissionsManager {
        requestPermissionLauncher = activity.registerForActivityResult(
            ActivityResultContracts.RequestMultiplePermissions(),
        ) { permissions ->
            // Location is optional, so only media results drive the grant.
            val media = permissions.filterKeys { it != LOCATION_PERM }
            if (media.isNotEmpty()) {
                isGranted = media.all { it.value }
                prefs.hasMediaPermission = isGranted
            }
            latch?.countDown()
        }
        return this
    }

    /**
     * Requests the OS media permission, blocking until the user answers.
     * Must be called off the UI thread. True when all requested media
     * permissions were granted; location denial does not affect the result.
     */
    fun requestMediaPermissionSync(): Boolean {
        val perms = mutableListOf<String>()
        if (!isGranted && !hasMediaPermissionLive()) perms.addAll(mediaPerms())
        if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.Q && !hasLocationPermission()) {
            perms.add(LOCATION_PERM)
        }
        if (perms.isEmpty()) {
            isGranted = true
            prefs.hasMediaPermission = true
            return true
        }
        latch = CountDownLatch(1)
        requestPermissionLauncher.launch(perms.toTypedArray())
        latch?.await()
        // Reconcile with the live grant: covers location-only requests,
        // where the callback leaves isGranted untouched.
        if (hasMediaPermissionLive()) {
            isGranted = true
            prefs.hasMediaPermission = true
        } else {
            isGranted = false
            prefs.hasMediaPermission = false
        }
        return isGranted
    }

    /** Live OS grant for location EXIF. Always true below Android 10 (no redaction there). */
    fun hasLocationPermission(): Boolean {
        if (Build.VERSION.SDK_INT < Build.VERSION_CODES.Q) return true
        return ContextCompat.checkSelfPermission(activity, LOCATION_PERM) == PackageManager.PERMISSION_GRANTED
    }

    /** Persisted OS grant from the last request. See the class docs on staleness. */
    fun hasMediaPermission(): Boolean = prefs.hasMediaPermission

    /** Whether the user opted into showing device files (setup UI). */
    fun hasAllowMedia(): Boolean = prefs.allowMedia

    fun setAllowMedia(v: Boolean) {
        prefs.allowMedia = v
    }

    private fun mediaPerms(): Array<String> =
        if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.TIRAMISU) {
            arrayOf(
                Manifest.permission.READ_MEDIA_IMAGES,
                Manifest.permission.READ_MEDIA_VIDEO,
            )
        } else {
            arrayOf(Manifest.permission.READ_EXTERNAL_STORAGE)
        }

    private fun hasMediaPermissionLive(): Boolean =
        mediaPerms().all {
            ContextCompat.checkSelfPermission(activity, it) == PackageManager.PERMISSION_GRANTED
        }
}
