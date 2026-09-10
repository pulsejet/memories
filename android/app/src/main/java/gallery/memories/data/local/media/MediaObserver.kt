package gallery.memories.data.local.media

import android.content.Context
import android.database.ContentObserver
import android.net.Uri

/**
 * Watches MediaStore for device media changes and debounces bursts into one
 * [onChange] callback. Register once after the first sync; unregister on destroy.
 */
class MediaObserver(
    private val ctx: Context,
    private val onChange: () -> Unit,
) {
    private var imageObserver: ContentObserver? = null
    private var videoObserver: ContentObserver? = null
    private var refreshPending = false
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
                synchronized(lock) {
                    if (refreshPending) return
                    refreshPending = true
                }
                Thread {
                    Thread.sleep(750)
                    synchronized(lock) { refreshPending = false }
                    onChange()
                }.start()
            }
        }
        ctx.applicationContext.contentResolver.registerContentObserver(uri, true, observer)
        return observer
    }
}
