package gallery.memories.ui.player

import android.net.Uri
import android.util.Log
import android.view.View
import android.webkit.CookieManager
import androidx.media3.common.MediaItem
import androidx.media3.common.PlaybackException
import androidx.media3.common.Player
import androidx.media3.common.util.UnstableApi
import androidx.media3.datasource.DefaultDataSource
import androidx.media3.datasource.DefaultHttpDataSource
import androidx.media3.exoplayer.ExoPlayer
import androidx.media3.exoplayer.hls.HlsMediaSource
import androidx.media3.exoplayer.source.ProgressiveMediaSource
import gallery.memories.MainActivity
import gallery.memories.data.remote.http.AuthState
import gallery.memories.data.remote.http.TlsTrust

@UnstableApi
/**
 * ExoPlayer overlay for viewer videos. Position survives pause/resume;
 * a new video resets it. Dead sources are skipped until none remain.
 */
class VideoPlayerManager(
    private val activity: MainActivity,
    private val auth: AuthState,
) {
    companion object {
        private val TAG = VideoPlayerManager::class.java.simpleName
    }

    private var player: ExoPlayer? = null
    private var playerUris: Array<Uri>? = null
    private var playerUid: Long? = null
    private var playWhenReady = true
    private var mediaItemIndex = 0
    private var playbackPosition = 0L
    private var looping = false

    val hasPlayer: Boolean get() = player != null

    fun dispatchTouch(event: android.view.MotionEvent) {
        if (player != null) activity.binding.videoView.dispatchTouchEvent(event)
    }

    /** Works around the surface staying visible on first load. */
    fun hideInitial() {
        activity.binding.videoView.postDelayed({
            activity.binding.videoView.alpha = 1.0f
            activity.binding.videoView.visibility = View.GONE
        }, 1000)
    }

    /** Remote URLs reuse the WebView session cookies; .m3u8 plays as HLS. */
    fun initializePlayer(uris: Array<Uri>, uid: Long, loop: Boolean = false) {
        if (player != null) {
            if (playerUid == uid) return
            player?.release()
            player = null
            playWhenReady = true
            mediaItemIndex = 0
            playbackPosition = 0L
        }
        playerUris = uris
        playerUid = uid
        looping = loop
        if (auth.isTrustingAllCertificates) TlsTrust.setDefaultInsecureTLS()
        player = ExoPlayer.Builder(activity).build().also { exoPlayer ->
            activity.binding.videoView.player = exoPlayer
            activity.binding.videoView.visibility = View.VISIBLE
            activity.binding.videoView.setShowNextButton(false)
            activity.binding.videoView.setShowPreviousButton(false)
            for (uri in uris) {
                val mediaItem = MediaItem.fromUri(uri)
                if (uri.toString().contains("http")) {
                    val httpFactory = DefaultHttpDataSource.Factory().setAllowCrossProtocolRedirects(true)
                    CookieManager.getInstance().getCookie(uri.toString())?.let { cookies ->
                        httpFactory.setDefaultRequestProperties(mapOf("cookie" to cookies))
                    }
                    val dataSourceFactory = DefaultDataSource.Factory(activity, httpFactory)
                    exoPlayer.addMediaSource(
                        if (uri.toString().contains(".m3u8")) {
                            HlsMediaSource.Factory(dataSourceFactory).createMediaSource(mediaItem)
                        } else {
                            ProgressiveMediaSource.Factory(dataSourceFactory).createMediaSource(mediaItem)
                        },
                    )
                } else {
                    exoPlayer.addMediaItem(mediaItem)
                }
            }
            if (exoPlayer.mediaItemCount > 0) {
                exoPlayer.seekTo(mediaItemIndex.coerceAtMost(exoPlayer.mediaItemCount - 1), playbackPosition)
            }
            var playerErrorCount = 0
            exoPlayer.addListener(object : Player.Listener {
                override fun onPlayerError(error: PlaybackException) {
                    Log.w(TAG, "Player error, skipping source", error)
                    playerErrorCount++
                    if (exoPlayer.hasNextMediaItem() && playerErrorCount < exoPlayer.mediaItemCount) {
                        exoPlayer.seekToNext()
                        exoPlayer.playWhenReady = true
                        exoPlayer.play()
                    } else {
                        destroyPlayer(playerUid ?: return)
                    }
                }
            })
            exoPlayer.repeatMode = if (loop) Player.REPEAT_MODE_ONE else Player.REPEAT_MODE_OFF
            exoPlayer.playWhenReady = playWhenReady
            exoPlayer.prepare()
        }
    }

    fun destroyPlayer(uid: Long) {
        if (playerUid == uid) {
            releasePlayer()
            playWhenReady = true
            mediaItemIndex = 0
            playbackPosition = 0L
            playerUris = null
            playerUid = null
            looping = false
        }
    }

    fun releasePlayer() {
        player?.let { exoPlayer ->
            playbackPosition = exoPlayer.currentPosition
            mediaItemIndex = exoPlayer.currentMediaItemIndex
            playWhenReady = exoPlayer.playWhenReady
            exoPlayer.release()
        }
        player = null
        activity.binding.videoView.visibility = View.GONE
    }

    fun restoreIfNeeded(uris: Array<Uri>?, uid: Long?) {
        if (uris != null && uid != null && player == null) initializePlayer(uris, uid, looping)
    }

    fun urisForRestore(): Array<Uri>? = playerUris
    fun uidForRestore(): Long? = playerUid
}
