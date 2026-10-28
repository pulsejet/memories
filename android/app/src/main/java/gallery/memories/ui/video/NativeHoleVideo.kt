package gallery.memories.ui.video

import android.net.Uri
import android.util.Log
import android.view.View
import android.webkit.CookieManager
import androidx.media3.common.MediaItem
import androidx.media3.common.Player
import androidx.media3.common.VideoSize
import androidx.media3.common.util.UnstableApi
import androidx.media3.datasource.DefaultDataSource
import androidx.media3.datasource.DefaultHttpDataSource
import androidx.media3.exoplayer.ExoPlayer
import androidx.media3.exoplayer.hls.HlsMediaSource
import androidx.media3.exoplayer.source.ProgressiveMediaSource
import androidx.media3.ui.AspectRatioFrameLayout
import androidx.media3.ui.PlayerView
import gallery.memories.MainActivity
import gallery.memories.data.remote.http.AuthState
import gallery.memories.data.remote.http.TlsTrust

@UnstableApi
/**
 * Native video shown through the WebView hole, mirroring PhotoSwipe zoom/pan.
 * Unlike the image hole (which uploads bytes and waits for render), this one
 * takes a URL and starts playback immediately; the view appears on the first
 * frame. The PlayerView is fullscreen and the hole box is mirrored with a
 * cover scale + translate, same visual as NativeHoleImage.
 * [show]/[setRect] run on the UI thread; [setVideoUrl] may be called from
 * any thread.
 */
class NativeHoleVideo(
    private val mCtx: MainActivity,
    private val auth: AuthState,
) {
    companion object {
        private val TAG = NativeHoleVideo::class.java.simpleName
    }

    /** Fullscreen hole view; surface below the WebView, shown through the hole. */
    private val view: PlayerView by lazy { mCtx.binding.nativeVideo }

    /** TEMP: red tint riding the same rect as the video, so the hole is obvious. */
    private val tint: View by lazy { mCtx.binding.nativeVideoTint }
    private val density: Float by lazy { mCtx.resources.displayMetrics.density }

    private var player: ExoPlayer? = null

    /** Highest upload counter rendered so far; lower ones are ignored. */
    private var lastSeq = 0L

    private var lastRect: FloatArray? = null
    private var videoW = 0
    private var videoH = 0
    private var hasFrame = false
    private var wantShow = false
    private var currentUrl: String? = null

    fun show(show: Boolean) {
        wantShow = show
        val exo = player
        if (!show) {
            setVisible(false)
            exo?.playWhenReady = false
        } else {
            exo?.playWhenReady = true
            // Visible immediately; the first frame paints over black.
            setVisible(true)
            reapplyRect()
        }
    }

    /**
     * Move/resize the video to the hole box (CSS px, unclamped). Uniform cover
     * scale, so zooming never distorts. Not-ready rects are stored; the caller
     * re-sends after the first frame.
     */
    fun setRect(x: Float, y: Float, w: Float, h: Float) {
        if (!x.isFinite() || !y.isFinite() || !w.isFinite() || !h.isFinite()) {
            setVisible(false)
            return
        }
        // Clamp to a sane CSS px range; the hole may extend past the
        // viewport but never needs more than this.
        val cx = x.coerceIn(-1000000f, 1000000f)
        val cy = y.coerceIn(-1000000f, 1000000f)
        val cw = w.coerceIn(0f, 1000000f)
        val ch = h.coerceIn(0f, 1000000f)
        if (cw <= 0 || ch <= 0) {
            setVisible(false)
            return
        }
        lastRect = floatArrayOf(cx, cy, cw, ch)
        if (videoW > 0 && videoH > 0) applyRect(cx, cy, cw, ch)
    }

    /** Play the given URL immediately; the view appears on the first frame. */
    fun setVideoUrl(url: String, seq: Long) {
        mCtx.runOnUiThread { setVideoUrlLocked(url, seq) }
    }

    fun pause() {
        player?.playWhenReady = false
    }

    fun resume() {
        if (wantShow) player?.playWhenReady = true
    }

    fun release() {
        runCatching { player?.release() }
        player = null
        currentUrl = null
        hasFrame = false
        videoW = 0
        videoH = 0
        wantShow = false
        lastRect = null
        resetViewTransform()
        runCatching { setVisible(false) }
    }

    private fun setVideoUrlLocked(url: String, seq: Long) {
        if (seq < lastSeq) return
        lastSeq = seq
        if (url == currentUrl && player != null) {
            if (wantShow) player?.playWhenReady = true
            return
        }
        currentUrl = url
        hasFrame = false
        videoW = 0
        videoH = 0
        setVisible(false)
        resetViewTransform()
        val exo = ensurePlayer()
        val uri = Uri.parse(url)
        val mediaItem = MediaItem.fromUri(uri)
        if (isRemote(uri)) {
            if (auth.isTrustingAllCertificates) TlsTrust.setDefaultInsecureTLS()
            val httpFactory = DefaultHttpDataSource.Factory().setAllowCrossProtocolRedirects(true)
            CookieManager.getInstance().getCookie(url)?.let { cookies ->
                httpFactory.setDefaultRequestProperties(mapOf("Cookie" to cookies))
            }
            val dataSourceFactory = DefaultDataSource.Factory(mCtx, httpFactory)
            exo.setMediaSource(
                if (isHls(uri)) {
                    HlsMediaSource.Factory(dataSourceFactory).createMediaSource(mediaItem)
                } else {
                    ProgressiveMediaSource.Factory(dataSourceFactory).createMediaSource(mediaItem)
                },
            )
        } else {
            exo.setMediaItem(mediaItem)
        }
        exo.playWhenReady = wantShow
        exo.prepare()
    }

    private fun ensurePlayer(): ExoPlayer {
        player?.let { return it }
        return ExoPlayer.Builder(mCtx).build().also { exo ->
            view.useController = false
            view.setResizeMode(AspectRatioFrameLayout.RESIZE_MODE_FIT)
            view.player = exo
            exo.repeatMode = Player.REPEAT_MODE_ONE
            exo.addListener(object : Player.Listener {
                override fun onVideoSizeChanged(videoSize: VideoSize) {
                    if (videoSize.width > 0 && videoSize.height > 0) {
                        videoW = videoSize.width
                        videoH = videoSize.height
                        reapplyRect()
                    }
                }

                override fun onRenderedFirstFrame() {
                    hasFrame = true
                    reapplyRect()
                }

                override fun onPlayerError(error: androidx.media3.common.PlaybackException) {
                    Log.w(TAG, "Hole video error", error)
                    setVisible(false)
                }
            })
            player = exo
        }
    }

    /** Mirror a hole box (CSS px) onto the fullscreen view with cover scale + center. */
    private fun applyRect(cssX: Float, cssY: Float, cssW: Float, cssH: Float) {
        // Decoded video size; unknown until the first size callback.
        val srcW = videoW.toFloat()
        val srcH = videoH.toFloat()
        if (srcW <= 0 || srcH <= 0) return

        // Fullscreen PlayerView size; zero before layout.
        val dstW = view.width.toFloat()
        val dstH = view.height.toFloat()
        if (dstW <= 0 || dstH <= 0) return

        val holeW = cssW * density
        val holeH = cssH * density
        val scale = coverScale(holeW, holeH, srcW, srcH, dstW, dstH) ?: return

        // Hole center in px, offset from the fullscreen view center.
        val holeCx = cssX * density + holeW / 2f
        val holeCy = cssY * density + holeH / 2f
        if (!holeCx.isFinite() || !holeCy.isFinite()) return
        val tx = holeCx - dstW / 2f
        val ty = holeCy - dstH / 2f

        view.applyTransform(dstW / 2f, dstH / 2f, scale, tx, ty)
        // TEMP: tint rides the exact same rect as the video.
        tint.applyTransform(dstW / 2f, dstH / 2f, scale, tx, ty)
        if (wantShow) setVisible(true)
    }

    /** Cover scale from the FIT-based fullscreen view into the hole box. Null when degenerate. */
    private fun coverScale(holeW: Float, holeH: Float, srcW: Float, srcH: Float, dstW: Float, dstH: Float): Float? {
        // FIT baseline: whole video inside the fullscreen view.
        val base = minOf(dstW / srcW, dstH / srcH)
        if (!base.isFinite() || base <= 0) return null
        // Cover into the hole box: never distorts.
        val cover = maxOf(holeW / srcW, holeH / srcH)
        if (!cover.isFinite() || cover <= 0) return null
        return (cover / base).takeIf { it.isFinite() && it > 0 }
    }

    private fun setVisible(visible: Boolean) {
        val v = if (visible) View.VISIBLE else View.INVISIBLE
        view.visibility = v
        tint.visibility = v
    }

    private fun reapplyRect() {
        lastRect?.let { r -> applyRect(r[0], r[1], r[2], r[3]) }
    }

    private fun View.applyTransform(px: Float, py: Float, scale: Float, tx: Float, ty: Float) {
        pivotX = px
        pivotY = py
        scaleX = scale
        scaleY = scale
        translationX = tx
        translationY = ty
    }

    private fun View.resetTransform() {
        scaleX = 1f
        scaleY = 1f
        translationX = 0f
        translationY = 0f
    }

    private fun resetViewTransform() {
        view.resetTransform()
        tint.resetTransform()
    }

    private fun isRemote(uri: Uri): Boolean = uri.scheme == "http" || uri.scheme == "https"

    private fun isHls(uri: Uri): Boolean = uri.path?.endsWith(".m3u8") == true
}
