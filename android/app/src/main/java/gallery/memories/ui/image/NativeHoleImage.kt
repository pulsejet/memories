package gallery.memories.ui.image

import android.graphics.BitmapFactory
import android.graphics.Matrix
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
 * Preview-sized uploads only; larger ones are rejected. Hidden with INVISIBLE,
 * never GONE (a GONE view never fires onReady). [setRect]/[show] run on the
 * UI thread; [setImageBytes] may be called from any thread.
 */
class NativeHoleImage(private val mCtx: MainActivity) {
    companion object {
        /** Untiled cap: fits any GPU texture and stays ~48MB max as ARGB_8888. */
        private const val MAX_UNTILED_DIMEN = 4096
        private const val MAX_UNTILED_PX = 12_000_000
        private const val TAG = "NativeHoleImage"
        private const val DEBUG_MODE = false
    }

    /** Cached view + density for the per-frame rect path. */
    private val view: SubsamplingScaleImageView by lazy { mCtx.binding.nativeImage }
    private val density: Float by lazy { mCtx.resources.displayMetrics.density }

    /** Serializes replacements so a stale load cannot orphan the next one. */
    private val imageMutex = Mutex()

    /** Highest upload counter rendered so far; lower ones are ignored. */
    private var lastSeq = 0L

    /** Currently displayed bitmap; recycled after replacement. */
    private var currentBmp: android.graphics.Bitmap? = null

    fun show(show: Boolean) {
        view.visibility = if (show) View.VISIBLE else View.INVISIBLE
    }

    /**
     * Move/resize the image to the hole box (CSS px, unclamped). Uniform cover
     * scale, so zooming never distorts. Drops not-ready rects; the caller
     * re-sends after render.
     */
    fun setRect(x: Float, y: Float, w: Float, h: Float) {
        if (!x.isFinite() || !y.isFinite() || !w.isFinite() || !h.isFinite()) {
            view.visibility = View.INVISIBLE
            return
        }
        // Clamp to a sane CSS px range; the hole may extend past the
        // viewport but never needs more than this.
        val cx = x.coerceIn(-1000000f, 1000000f)
        val cy = y.coerceIn(-1000000f, 1000000f)
        val cw = w.coerceIn(0f, 1000000f)
        val ch = h.coerceIn(0f, 1000000f)
        if (cw <= 0 || ch <= 0) {
            view.visibility = View.INVISIBLE
            return
        }
        if (view.isReady) applyRect(cx, cy, cw, ch)
    }

    private fun ensureConfigured() {
        if (configured) return
        configured = true
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

    private var configured = false

    /**
     * Replace the hole content with raw preview bytes. Resolves once rendered;
     * stale counters are ignored. True if rendered.
     */
    suspend fun setImageBytes(bytes: ByteArray, seq: Long): Boolean {
        if (bytes.isEmpty()) throw IllegalArgumentException("empty image")
        imageMutex.withLock {
            if (seq < lastSeq) return false
            lastSeq = seq
            val bounds = BitmapFactory.Options().apply { inJustDecodeBounds = true }
            BitmapFactory.decodeByteArray(bytes, 0, bytes.size, bounds)
            if (bounds.outWidth !in 1..MAX_UNTILED_DIMEN ||
                bounds.outHeight !in 1..MAX_UNTILED_DIMEN ||
                bounds.outWidth.toLong() * bounds.outHeight.toLong() > MAX_UNTILED_PX
            ) {
                throw IllegalArgumentException("image too large")
            }
            // Rotation only swaps axes, so the bounds check above still holds.
            val orientation = runCatching {
                ExifInterface(java.io.ByteArrayInputStream(bytes))
            }.getOrNull()?.getAttributeInt(
                ExifInterface.TAG_ORIENTATION, ExifInterface.ORIENTATION_NORMAL,
            ) ?: ExifInterface.ORIENTATION_NORMAL
            var bmp = BitmapFactory.decodeByteArray(bytes, 0, bytes.size)
                ?: throw IllegalArgumentException("cannot decode image")
            bmp = applyOrientation(bmp, orientation)
            bmp = maybeTint(bmp)
            displayLocked(bmp, SubsamplingScaleImageView.ORIENTATION_0)
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
        val orientation = withContext(Dispatchers.IO) {
            mCtx.contentResolver.openInputStream(uri)?.use {
                runCatching { ExifInterface(it) }.getOrNull()?.getAttributeInt(
                    ExifInterface.TAG_ORIENTATION, ExifInterface.ORIENTATION_NORMAL,
                )
            } ?: ExifInterface.ORIENTATION_NORMAL
        }.let(::exifToViewOrientation)
        imageMutex.withLock {
            if (seq < lastSeq) return false
            lastSeq = seq
            displayLocked(ImageSource.uri(uri), orientation)
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

    /** Rotate/flip per EXIF orientation; recycles the source on copy. */
    private fun applyOrientation(bmp: android.graphics.Bitmap, orientation: Int): android.graphics.Bitmap {
        val matrix = Matrix().apply {
            when (orientation) {
                ExifInterface.ORIENTATION_FLIP_HORIZONTAL -> preScale(-1f, 1f)
                ExifInterface.ORIENTATION_ROTATE_180 -> preRotate(180f)
                ExifInterface.ORIENTATION_FLIP_VERTICAL -> preScale(1f, -1f)
                ExifInterface.ORIENTATION_TRANSPOSE -> {
                    preRotate(90f)
                    preScale(-1f, 1f)
                }
                ExifInterface.ORIENTATION_ROTATE_90 -> preRotate(90f)
                ExifInterface.ORIENTATION_TRANSVERSE -> {
                    preRotate(270f)
                    preScale(-1f, 1f)
                }
                ExifInterface.ORIENTATION_ROTATE_270 -> preRotate(270f)
                else -> return bmp
            }
        }
        val rotated = android.graphics.Bitmap.createBitmap(bmp, 0, 0, bmp.width, bmp.height, matrix, true)
        if (rotated !== bmp) bmp.recycle()
        return rotated
    }

    /** Red-tint a bitmap when debugging; recycles the original on copy. */
    private fun maybeTint(bmp: android.graphics.Bitmap): android.graphics.Bitmap {
        if (!DEBUG_MODE) return bmp
        val tinted = bmp.copy(android.graphics.Bitmap.Config.ARGB_8888, true) ?: return bmp
        if (tinted !== bmp) bmp.recycle()
        android.graphics.Canvas(tinted).drawColor(android.graphics.Color.argb(80, 255, 0, 0))
        return tinted
    }

    /** Render [bmp] and swap it in; caller must hold [imageMutex]. */
    private suspend fun displayLocked(bmp: android.graphics.Bitmap, orientation: Int) {
        try {
            awaitImage(ImageSource.bitmap(bmp).tilingDisabled(), orientation)
        } catch (e: Exception) {
            runCatching { bmp.recycle() }
            throw e
        }
        val old = currentBmp
        currentBmp = bmp
        old?.let { runCatching { it.recycle() } }
    }

    /** Render a URI source; the view owns the decode. Caller must hold [imageMutex]. */
    private suspend fun displayLocked(source: ImageSource, orientation: Int) {
        awaitImage(source, orientation)
        val old = currentBmp
        currentBmp = null
        old?.let { runCatching { it.recycle() } }
    }

    /** Set the source on the UI thread; resume once rendered or failed. */
    private suspend fun awaitImage(source: ImageSource, orientation: Int) =
        suspendCancellableCoroutine { cont ->
            mCtx.runOnUiThread {
                try {
                    ensureConfigured()
                    // Visible while decoding so the draw pass fires onReady.
                    view.visibility = View.VISIBLE
                    // Free the previous image; throws when empty.
                    runCatching { view.recycle() }
                    view.setOnImageEventListener(object : SubsamplingScaleImageView.DefaultOnImageEventListener() {
                        override fun onReady() {
                            if (cont.isActive) cont.resume(Unit)
                        }

                        override fun onImageLoadError(e: Exception) {
                            android.util.Log.w(TAG, "onImageLoadError", e)
                            view.visibility = View.INVISIBLE
                            if (cont.isActive) cont.resumeWithException(e)
                        }
                    })
                    // Orientation before source so EXIF tiles load correctly.
                    view.setOrientation(orientation)
                    view.setImage(source)
                } catch (e: Exception) {
                    view.visibility = View.INVISIBLE
                    if (cont.isActive) cont.resumeWithException(e)
                }
            }
        }

    /** Mirror a hole box (CSS px) onto the view with cover scale + center. */
    private fun applyRect(x: Float, y: Float, w: Float, h: Float) {
        val img = view
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
}
