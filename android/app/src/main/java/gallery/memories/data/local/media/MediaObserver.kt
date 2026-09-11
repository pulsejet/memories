package gallery.memories.data.local.media

import android.content.Context
import android.database.ContentObserver
import android.net.Uri

/**
 * Watches MediaStore for device media changes and debounces bursts into one
 * [onChange] callback. Changed item URIs are collected across the debounce
 * window so callers can evict exactly those rows instead of sweeping the index.
 */
class MediaObserver(
    private val ctx: Context,
    private val onChange: (uris: List<Uri>) -> Unit,
) {
    private var imageObserver: ContentObserver? = null
    private var videoObserver: ContentObserver? = null
    private var refreshPending = false
    private val pendingUris = mutableListOf<Uri>()
    private val lock = Any()

    /** Starts watching images and videos. Idempotent. */
    fun register() {
        if (imageObserver == null) imageObserver = observe(SystemImage.IMAGE_URI)
        if (videoObserver == null) videoObserver = observe(SystemImage.VIDEO_URI)
    }

    /** Stops watching. Idempotent. */
    fun unregister() {
        imageObserver?.let { ctx.applicationContext.contentResolver.unregisterContentObserver(it) }
        videoObserver?.let { ctx.applicationContext.contentResolver.unregisterContentObserver(it) }
        imageObserver = null
        videoObserver = null
    }

    /** MediaStore fires bursts per change; debounce 750ms so one refresh covers the burst. */
    private fun observe(uri: Uri): ContentObserver {
        val observer = object : ContentObserver(null) {
            override fun onChange(selfChange: Boolean) {
                onMediaChanged(null)
            }

            override fun onChange(selfChange: Boolean, uri: Uri?) {
                onMediaChanged(uri)
            }
        }
        ctx.applicationContext.contentResolver.registerContentObserver(uri, true, observer)
        return observer
    }

    private fun onMediaChanged(uri: Uri?) {
        synchronized(lock) {
            if (uri != null) pendingUris.add(uri)
            if (refreshPending) return
            refreshPending = true
        }
        Thread {
            Thread.sleep(750)
            val uris: List<Uri>
            synchronized(lock) {
                refreshPending = false
                uris = pendingUris.toList()
                pendingUris.clear()
            }
            onChange(uris)
        }.start()
    }
}
