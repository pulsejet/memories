package gallery.memories.ui.player

import android.net.Uri
import android.view.MotionEvent
import androidx.media3.common.util.UnstableApi
import gallery.memories.MainActivity
import gallery.memories.data.remote.http.AuthState

@UnstableApi
/**
 * Stub: native ExoPlayer overlay removed, web handles video.
 * Kept for bridge compat; all calls are no-ops.
 */
class VideoPlayerManager(
    private val activity: MainActivity,
    private val auth: AuthState,
) {
    val hasPlayer: Boolean get() = false

    fun dispatchTouch(event: MotionEvent) = Unit

    fun hideInitial() = Unit

    fun initializePlayer(uris: Array<Uri>, uid: Long, loop: Boolean = false) = Unit

    fun destroyPlayer(uid: Long) = Unit

    fun releasePlayer() = Unit

    fun restoreIfNeeded(uris: Array<Uri>?, uid: Long?) = Unit

    fun urisForRestore(): Array<Uri>? = null

    fun uidForRestore(): Long? = null
}
