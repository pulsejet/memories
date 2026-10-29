package gallery.memories.ui.image

import android.content.Context
import android.graphics.BitmapFactory
import android.graphics.Canvas
import android.graphics.PointF
import android.view.View
import androidx.exifinterface.media.ExifInterface
import com.davemorrissey.labs.subscaleview.ImageSource
import com.davemorrissey.labs.subscaleview.SubsamplingScaleImageView
import gallery.memories.MainActivity
import kotlinx.coroutines.Dispatchers
import kotlinx.coroutines.sync.Mutex
import kotlinx.coroutines.sync.withLock
import kotlinx.coroutines.suspendCancellableCoroutine
import kotlinx.coroutines.withContext
import kotlin.coroutines.resume
import kotlin.coroutines.resumeWithException

/**
 * Native image shown through the WebView hole, mirroring PhotoSwipe zoom/pan.
 * Bytes are staged to a tiled cache file, so any size renders. Hidden with INVISIBLE,
 * never GONE (a GONE view never fires onReady). [setRect]/[show] run on the
 * UI thread; [setImageBytes] may be called from any thread.
 */
class NativeHoleImage(private val mCtx: MainActivity) {
    companion object {
        private const val TAG = "NativeHoleImage"
    }

    /** Cached views + density for the per-frame rect path. */
    private val viewA: SubsamplingScaleImageView by lazy { mCtx.binding.nativeImageA }
    private val viewB: SubsamplingScaleImageView by lazy { mCtx.binding.nativeImageB }
    private val density: Float by lazy { mCtx.resources.displayMetrics.density }

    /** Active view shows the current frame; the idle one stages the next. */
    private var useA = true
    private fun activeView() = if (useA) viewA else viewB
    private fun idleView() = if (useA) viewB else viewA

    /** Serializes replacements so a stale load cannot orphan the next one. */
    private val imageMutex = Mutex()

    /** Highest upload counter rendered so far; lower ones are ignored. */
    @Volatile
    private var lastSeq = -1L

    /** Currently displayed file; deleted after replacement. */
    private var currentFile: java.io.File? = null
    private var lastRect: FloatArray? = null

    fun resetSequence() {
        lastSeq = -1L
    }

    fun show(show: Boolean) {
        if (!show) {
            viewA.visibility = View.INVISIBLE
            viewB.visibility = View.INVISIBLE
        } else {
            activeView().visibility = View.VISIBLE
        }
    }

    /**
     * Move/resize the image to the hole box (CSS px, unclamped). Uniform cover
     * scale, so zooming never distorts. Drops not-ready rects; the caller
     * re-sends after render.
     */
    fun setRect(x: Float, y: Float, w: Float, h: Float) {
        if (!x.isFinite() || !y.isFinite() || !w.isFinite() || !h.isFinite()) {
            viewA.visibility = View.INVISIBLE
            viewB.visibility = View.INVISIBLE
            return
        }
        // Clamp to a sane CSS px range; the hole may extend past the
        // viewport but never needs more than this.
        val cx = x.coerceIn(-1000000f, 1000000f)
        val cy = y.coerceIn(-1000000f, 1000000f)
        val cw = w.coerceIn(0f, 1000000f)
        val ch = h.coerceIn(0f, 1000000f)
        if (cw <= 0 || ch <= 0) {
            viewA.visibility = View.INVISIBLE
            viewB.visibility = View.INVISIBLE
            return
        }
        lastRect = floatArrayOf(cx, cy, cw, ch)
        val active = activeView()
        if (active.isReady) applyRect(active, cx, cy, cw, ch)
    }

    private fun ensureConfigured(view: SubsamplingScaleImageView) {
        if (!configured.add(view)) return
        view.setPanLimit(SubsamplingScaleImageView.PAN_LIMIT_OUTSIDE)
        view.setPanEnabled(false)
        view.setZoomEnabled(false)
        view.setMaxScale(20f)
        // setScaleAndCenter clamps into [minScale, maxScale]; the default
        // minimum (whole image inside the view) pins small hole boxes to the
        // fitted size so they crop instead of shrinking with the hole.
        view.setMinimumScaleType(SubsamplingScaleImageView.SCALE_TYPE_CUSTOM)
        view.setMinScale(0.01f)
    }

    private val configured = mutableSetOf<SubsamplingScaleImageView>()

    /**
     * Replace the hole content with raw preview bytes. Resolves once rendered;
     * stale counters are ignored. True if rendered.
     */
    suspend fun setImageBytes(bytes: ByteArray, seq: Long): Boolean {
        if (bytes.isEmpty()) throw IllegalArgumentException("empty image")
        val bounds = BitmapFactory.Options().apply { inJustDecodeBounds = true }
        BitmapFactory.decodeByteArray(bytes, 0, bytes.size, bounds)
        if (bounds.outWidth < 1 || bounds.outHeight < 1) throw IllegalArgumentException("cannot decode image")
        val orientation = runCatching {
            ExifInterface(java.io.ByteArrayInputStream(bytes))
        }.getOrNull()?.getAttributeInt(
            ExifInterface.TAG_ORIENTATION, ExifInterface.ORIENTATION_NORMAL,
        ) ?: ExifInterface.ORIENTATION_NORMAL
        val file = withContext(Dispatchers.IO) {
            val dir = java.io.File(mCtx.cacheDir, "native-image").apply { mkdirs() }
            java.io.File(dir, "img-$seq").apply { writeBytes(bytes) }
        }
        imageMutex.withLock {
            if (seq < lastSeq) {
                file.delete()
                return false
            }
            lastSeq = seq
            try {
                val viewOrientation = exifToViewOrientation(orientation)
                val tiling = mCtx.shouldUseTiling(bounds.outWidth, bounds.outHeight)
                val source = ImageSource.uri(android.net.Uri.fromFile(file))
                    .tiling(tiling)
                displayLocked(source, viewOrientation)
            } catch (e: Exception) {
                runCatching { file.delete() }
                throw e
            }
            val old = currentFile
            currentFile = file
            old?.let { runCatching { it.delete() } }
            sweepNativeImageDir(file)
            return true
        }
    }

    /**
     * Show a MediaStore image in the hole view. The view decodes the URI
     * itself (tiled, any size). EXIF is read here because the view only
     * understands the deprecated MediaStore orientation column for
     * content URIs. Stale counters are ignored. True if rendered.
     */
    suspend fun setImageUri(uri: android.net.Uri, seq: Long): Boolean {
        val (orientation, width, height) = withContext(Dispatchers.IO) {
            val orientation = mCtx.contentResolver.openInputStream(uri)?.use {
                runCatching { ExifInterface(it) }.getOrNull()?.getAttributeInt(
                    ExifInterface.TAG_ORIENTATION, ExifInterface.ORIENTATION_NORMAL,
                )
            } ?: ExifInterface.ORIENTATION_NORMAL
            val bounds = BitmapFactory.Options().apply { inJustDecodeBounds = true }
            mCtx.contentResolver.openInputStream(uri)?.use {
                runCatching { BitmapFactory.decodeStream(it, null, bounds) }
            }
            Triple(orientation, bounds.outWidth, bounds.outHeight)
        }
        imageMutex.withLock {
            if (seq < lastSeq) return false
            lastSeq = seq
            val tiling = mCtx.shouldUseTiling(width, height)
            val source = ImageSource.uri(uri)
                .tiling(tiling)
            displayLocked(source, exifToViewOrientation(orientation))
            currentFile = null
            sweepNativeImageDir(null)
            return true
        }
    }

    /** View orientations are right angles only; flips stay unrotated, as the view does. */
    private fun exifToViewOrientation(orientation: Int): Int = when (orientation) {
        ExifInterface.ORIENTATION_ROTATE_90 -> SubsamplingScaleImageView.ORIENTATION_90
        ExifInterface.ORIENTATION_ROTATE_180 -> SubsamplingScaleImageView.ORIENTATION_180
        ExifInterface.ORIENTATION_ROTATE_270 -> SubsamplingScaleImageView.ORIENTATION_270
        else -> SubsamplingScaleImageView.ORIENTATION_0
    }

    /** Render a URI source; the view owns the decode. Caller must hold [imageMutex]. */
    private suspend fun displayLocked(source: ImageSource, orientation: Int) {
        awaitImage(source, orientation)
    }

    private fun sweepNativeImageDir(keep: java.io.File?) {
        runCatching {
            java.io.File(mCtx.cacheDir, "native-image").listFiles()?.forEach {
                val n = it.name.removePrefix("img-").toLongOrNull() ?: return@forEach
                if (it != keep && n < lastSeq) runCatching { it.delete() }
            }
        }
    }

    /** Set the source on the UI thread; resume once rendered (and promoted) or failed. */
    private suspend fun awaitImage(source: ImageSource, orientation: Int) =
        suspendCancellableCoroutine { cont ->
            var targetView: SubsamplingScaleImageView? = null
            cont.invokeOnCancellation {
                mCtx.runOnUiThread {
                    targetView?.let {
                        it.setOnImageEventListener(null)
                        it.visibility = View.INVISIBLE
                    }
                }
            }
            mCtx.runOnUiThread {
                if (!cont.isActive) return@runOnUiThread
                // Ping-pong only when the active view is already showing;
                // otherwise load straight into it. Selection stays on the
                // UI thread so readiness/visibility reads are safe.
                val front = activeView()
                val pingPong = front.isReady && front.visibility == View.VISIBLE
                val target = if (pingPong) idleView() else front
                targetView = target
                try {
                    ensureConfigured(target)
                    // Visible while decoding so the draw pass fires onReady.
                    // The old frame stays painted until promotion below.
                    target.visibility = View.VISIBLE
                    // Free the previous image; throws when empty.
                    runCatching { target.recycle() }
                    target.setOnImageEventListener(object : SubsamplingScaleImageView.DefaultOnImageEventListener() {
                        override fun onReady() {
                            if (!cont.isActive) return
                            lastRect?.let { r -> applyRect(target, r[0], r[1], r[2], r[3]) }
                            if (pingPong) promote(target)
                            cont.resume(Unit)
                        }

                        override fun onImageLoadError(e: Exception) {
                            android.util.Log.w(TAG, "onImageLoadError", e)
                            target.visibility = View.INVISIBLE
                            if (cont.isActive) cont.resumeWithException(e)
                        }
                    })
                    // Orientation before source so EXIF tiles load correctly.
                    target.setOrientation(orientation)
                    target.setImage(source)
                } catch (e: Exception) {
                    target.visibility = View.INVISIBLE
                    if (cont.isActive) cont.resumeWithException(e)
                }
            }
        }

    /** Swap the ready target to front and destroy the old frame. Runs on UI thread. */
    private fun promote(target: SubsamplingScaleImageView) {
        val old = activeView()
        if (old !== target) {
            useA = target === viewA
            old.visibility = View.INVISIBLE
            runCatching { old.recycle() }
        }
        target.visibility = View.VISIBLE
    }

    /** Mirror a hole box (CSS px) onto the view with cover scale + center. */
    private fun applyRect(img: SubsamplingScaleImageView, x: Float, y: Float, w: Float, h: Float) {
        // sWidth/sHeight ignore rotation; swap for the displayed dims.
        val swap = img.appliedOrientation == SubsamplingScaleImageView.ORIENTATION_90 ||
            img.appliedOrientation == SubsamplingScaleImageView.ORIENTATION_270
        val sw = (if (swap) img.sHeight else img.sWidth).toFloat()
        val sh = (if (swap) img.sWidth else img.sHeight).toFloat()
        if (sw <= 0 || sh <= 0) return
        val d = density
        val wd = w * d
        val hd = h * d
        // Uniform cover scale into the hole box: never distorts, and the hole stays painted.
        // Clamped to the view's own floor: it clamps on draw, so the center
        // below must use the clamped scale or the image snaps while tiny.
        val scale = maxOf(maxOf(wd / sw, hd / sh), img.minScale)
        if (!scale.isFinite() || scale <= 0) return
        val vw = img.width.toFloat()
        val vh = img.height.toFloat()
        if (vw <= 0 || vh <= 0) return
        // Center-crop the source onto the hole, then locate the hole center in the fullscreen view.
        val hcx = x * d + wd / 2f
        val hcy = y * d + hd / 2f
        if (!hcx.isFinite() || !hcy.isFinite()) return
        val center = PointF(
            sw / 2f + (vw / 2f - hcx) / scale,
            sh / 2f + (vh / 2f - hcy) / scale,
        )
        if (!center.x.isFinite() || !center.y.isFinite()) return
        img.setScaleAndCenter(scale, center)
        img.visibility = View.VISIBLE
    }

    private fun Context.shouldUseTiling(
        imageWidth: Int,
        imageHeight: Int,
        bytesPerPixel: Int = 4,
    ): Boolean {
        // Single draw call cannot exceed the GPU texture size.
        val maxTextureSize = Canvas().maximumBitmapWidth.coerceAtLeast(4096)
        if (imageWidth > maxTextureSize || imageHeight > maxTextureSize) return true

        // Single bitmap must fit under the memory ceiling.
        val bitmapBytes = imageWidth.toLong() * imageHeight * bytesPerPixel
        if (bitmapBytes > 80L * 1024 * 1024) return true
        return false
    }
}
