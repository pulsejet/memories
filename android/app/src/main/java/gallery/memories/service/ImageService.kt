package gallery.memories.service

import android.content.Context
import android.graphics.Bitmap
import android.graphics.ImageDecoder
import android.os.Build
import android.provider.MediaStore
import androidx.media3.common.util.UnstableApi
import gallery.memories.mapper.SystemImage
import java.io.ByteArrayOutputStream

@UnstableApi
class ImageService(private val mCtx: Context, private val query: TimelineQuery) {
    /**
     * Get a preview image for a given image ID
     * @param id The image ID
     * @return The preview image as a JPEG byte array
     */
    @Throws(Exception::class)
    fun getPreview(id: Long, x: Int?, y: Int?): ByteArray {
        val sysImgs = SystemImage.getByIds(mCtx, listOf(id))
        if (sysImgs.isEmpty()) {
            throw Exception("Image not found")
        }

        // get the image dimensions
        var h = sysImgs[0].height.toInt()
        var w = sysImgs[0].width.toInt()

        // cap to x/y if provided, keeping aspect ratio
        if (x != null && y != null) {
            // calculate the aspect ratio
            val aspect = w.toFloat() / h.toFloat()
            if (x.toFloat() / y.toFloat() < aspect) {
                w = x
                h = (x.toFloat() / aspect).toInt()
            } else {
                w = (y.toFloat() * aspect).toInt()
                h = y
            }
        }

        var bitmap =
            if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.Q) {
                val uri = sysImgs[0].uri
                mCtx.contentResolver.loadThumbnail(
                    uri,
                    android.util.Size(w, h),
                    null
                )
            } else {
                MediaStore.Images.Thumbnails.getThumbnail(
                    mCtx.contentResolver, id, MediaStore.Images.Thumbnails.FULL_SCREEN_KIND, null
                )
                    ?: MediaStore.Video.Thumbnails.getThumbnail(
                        mCtx.contentResolver, id, MediaStore.Video.Thumbnails.FULL_SCREEN_KIND, null
                    )
                    ?: throw Exception("Thumbnail not found")
            }

        val stream = ByteArrayOutputStream()

        // resize to the desired dimensions
        bitmap = Bitmap.createScaledBitmap(bitmap, w, h, true)

        // compress to JPEG
        bitmap.compress(Bitmap.CompressFormat.JPEG, 90, stream)

        return stream.toByteArray()
    }

    /**
     * Get a full image for a given image ID
     * @param id The image ID
     * @return The full image as a JPEG byte array
     */
    @Throws(Exception::class)
    fun getFull(auid: String, size: Int?): ByteArray {
        val sysImgs = query.getSystemImagesByAUIDs(listOf(auid))
        if (sysImgs.isEmpty()) {
            throw Exception("Image not found")
        }

        val uri = sysImgs[0].uri

        var bitmap =
            if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.P) {
                ImageDecoder.decodeBitmap(ImageDecoder.createSource(mCtx.contentResolver, uri))
            } else {
                MediaStore.Images.Media.getBitmap(mCtx.contentResolver, uri)
                    ?: throw Exception("Image not found")
            }

        val stream = ByteArrayOutputStream()

        // resize to the desired dimensions if provided, keeping aspect ratio
        if (size != null) {
            val scale = size.toFloat() / Math.max(bitmap.width, bitmap.height)
            if (scale < 1) {
                val w = (bitmap.width * scale).toInt()
                val h = (bitmap.height * scale).toInt()
                bitmap = Bitmap.createScaledBitmap(bitmap, w, h, true)
            }
        }

        bitmap.compress(Bitmap.CompressFormat.JPEG, 90, stream)
        return stream.toByteArray()
    }
}