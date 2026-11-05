package gallery.memories.data.local.media

import android.content.Context
import android.graphics.Bitmap
import android.graphics.ImageDecoder
import android.util.Size
import androidx.core.graphics.scale
import gallery.memories.timeline.TimelineRepository
import java.io.ByteArrayOutputStream
import kotlin.math.max

/** Renders device photos for the web timeline, always as JPEG. */
class ImageService(private val ctx: Context, private val timeline: TimelineRepository) {
    private companion object {
        /** Thumbnail request when neither MediaStore nor the caller gives a size. */
        const val UNKNOWN_SIZE = 1024
    }

    /** Thumbnail aspect-fitted into x/y; x/y omitted means native size. Missing MediaStore dimensions come from the thumbnail. */
    @Throws(Exception::class)
    fun getPreview(id: Long, x: Int?, y: Int?): ByteArray {
        val dataSource = MediaStoreDataSource(ctx.applicationContext)
        val sysImgs = dataSource.getByIds(listOf(id))
        if (sysImgs.isEmpty()) throw Exception("Image not found")
        val img = sysImgs[0]
        val w = img.width.toInt()
        val h = img.height.toInt()
        val known = w > 0 && h > 0
        val request = when {
            known -> fit(w, h, x, y)
            x != null && y != null && x > 0 && y > 0 -> Size(x, y)
            else -> Size(UNKNOWN_SIZE, UNKNOWN_SIZE)
        }
        val thumb = ctx.applicationContext.contentResolver.loadThumbnail(img.uri, request, null)
        val size = if (known) request else fit(thumb.width, thumb.height, x, y)
        val stream = ByteArrayOutputStream()
        thumb.scale(size.width, size.height)
            .compress(Bitmap.CompressFormat.JPEG, 90, stream)
        return stream.toByteArray()
    }

    /** Aspect-fits w/h into x/y, keeping both sides at least 1px; x/y omitted means w/h. */
    private fun fit(w: Int, h: Int, x: Int?, y: Int?): Size {
        if (x == null || y == null || x <= 0 || y <= 0) return Size(w, h)
        val aspect = w.toFloat() / h.toFloat()
        return if (x.toFloat() / y.toFloat() < aspect) {
            Size(x, (x.toFloat() / aspect).toInt().coerceAtLeast(1))
        } else {
            Size((y.toFloat() * aspect).toInt().coerceAtLeast(1), y)
        }
    }

    /** Full image, downscaled only (never upscaled) to fit size on the long edge. */
    @Throws(Exception::class)
    fun getFull(auid: String, size: Int?): ByteArray {
        val sysImgs = timeline.getSystemImagesByAUIDs(listOf(auid))
        if (sysImgs.isEmpty()) throw Exception("Image not found")
        val uri = sysImgs[0].uri
        var bitmap = ImageDecoder.decodeBitmap(ImageDecoder.createSource(ctx.applicationContext.contentResolver, uri))
        val stream = ByteArrayOutputStream()
        if (size != null) {
            val scale = size.toFloat() / max(bitmap.width, bitmap.height)
            if (scale < 1) {
                bitmap = bitmap.scale((bitmap.width * scale).toInt(), (bitmap.height * scale).toInt())
            }
        }
        bitmap.compress(Bitmap.CompressFormat.JPEG, 90, stream)
        return stream.toByteArray()
    }
}
