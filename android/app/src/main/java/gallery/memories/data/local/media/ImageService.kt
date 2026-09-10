package gallery.memories.data.local.media

import android.content.Context
import android.graphics.Bitmap
import android.graphics.ImageDecoder
import android.util.Size
import androidx.media3.common.util.UnstableApi
import gallery.memories.timeline.TimelineRepository
import java.io.ByteArrayOutputStream
import kotlin.math.max

@UnstableApi
/** Renders device photos for the web timeline, always as JPEG. */
class ImageService(private val ctx: Context, private val timeline: TimelineRepository) {
    /** Thumbnail aspect-fitted into x/y; x/y omitted means native size. */
    @Throws(Exception::class)
    fun getPreview(id: Long, x: Int?, y: Int?): ByteArray {
        val dataSource = MediaStoreDataSource(ctx.applicationContext)
        val sysImgs = dataSource.getByIds(listOf(id))
        if (sysImgs.isEmpty()) throw Exception("Image not found")
        var h = sysImgs[0].height.toInt()
        var w = sysImgs[0].width.toInt()
        if (w <= 0 || h <= 0) throw Exception("Invalid image dimensions")
        if (x != null && y != null && x > 0 && y > 0) {
            val aspect = w.toFloat() / h.toFloat()
            if (x.toFloat() / y.toFloat() < aspect) {
                w = x
                h = (x.toFloat() / aspect).toInt()
            } else {
                w = (y.toFloat() * aspect).toInt()
                h = y
            }
        }
        if (w <= 0 || h <= 0) throw Exception("Invalid preview dimensions")
        var bitmap = ctx.applicationContext.contentResolver.loadThumbnail(sysImgs[0].uri, Size(w, h), null)
        val stream = ByteArrayOutputStream()
        bitmap = Bitmap.createScaledBitmap(bitmap, w, h, true)
        bitmap.compress(Bitmap.CompressFormat.JPEG, 90, stream)
        return stream.toByteArray()
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
                bitmap = Bitmap.createScaledBitmap(bitmap, (bitmap.width * scale).toInt(), (bitmap.height * scale).toInt(), true)
            }
        }
        bitmap.compress(Bitmap.CompressFormat.JPEG, 90, stream)
        return stream.toByteArray()
    }
}
