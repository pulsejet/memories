package gallery.memories.service

import android.os.Build
import androidx.activity.result.ActivityResultLauncher
import androidx.activity.result.contract.ActivityResultContracts
import androidx.media3.common.util.UnstableApi
import gallery.memories.MainActivity
import gallery.memories.R
import java.util.concurrent.CountDownLatch

@UnstableApi class PermissionsService(private val activity: MainActivity) {
    var isGranted: Boolean = false
    var latch: CountDownLatch? = null
    lateinit var requestPermissionLauncher: ActivityResultLauncher<Array<String>>

    fun register(): PermissionsService {
        requestPermissionLauncher = activity.registerForActivityResult(
            ActivityResultContracts.RequestMultiplePermissions()
        ) { permissions ->
            // we need all of these
            isGranted = permissions.all { it.value }

            // Persist that we have it now
            setHasMediaPermission(isGranted)

            // Release latch
            latch?.countDown()
        }

        return this
    }

    /**
     * Requests media permission and blocks until it is granted
     */
    fun requestMediaPermissionSync(): Boolean {
        if (isGranted) return true

        // Wait for response
        latch = CountDownLatch(1)

        // Request media read permission
        if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.TIRAMISU) {
            requestPermissionLauncher.launch(
                arrayOf(
                    android.Manifest.permission.READ_MEDIA_IMAGES,
                    android.Manifest.permission.READ_MEDIA_VIDEO,
                )
            )
        } else {
            requestPermissionLauncher.launch(arrayOf(android.Manifest.permission.READ_EXTERNAL_STORAGE))
        }

        latch?.await()

        return isGranted
    }

    fun hasMediaPermission(): Boolean {
        return activity.getSharedPreferences(activity.getString(R.string.preferences_key), 0)
            .getBoolean(activity.getString(R.string.preferences_has_media_permission), false)
    }

    private fun setHasMediaPermission(v: Boolean) {
        activity.getSharedPreferences(activity.getString(R.string.preferences_key), 0).edit()
            .putBoolean(activity.getString(R.string.preferences_has_media_permission), v)
            .apply()
    }

    fun hasAllowMedia(): Boolean {
        return activity.getSharedPreferences(activity.getString(R.string.preferences_key), 0)
            .getBoolean(activity.getString(R.string.preferences_allow_media), false)
    }

    fun setAllowMedia(v: Boolean) {
        activity.getSharedPreferences(activity.getString(R.string.preferences_key), 0).edit()
            .putBoolean(activity.getString(R.string.preferences_allow_media), v)
            .apply()
    }
}