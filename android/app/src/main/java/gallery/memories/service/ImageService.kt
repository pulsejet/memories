package gallery.memories.service

import android.content.ContentUris
import android.content.Context
import android.graphics.Bitmap
import android.graphics.ImageDecoder
import android.os.Build
import android.provider.MediaStore
import androidx.media3.common.util.UnstableApi
import gallery.memories.mapper.SystemImage
import java.io.ByteArrayOutputStream

@UnstableApi class ImageService(private val mCtx: Context, private val query: TimelineQuery) {
    /**
     * Get a preview image for a given image ID
     * @param id The image ID
     * @return The preview image as a JPEG byte array
     */
    @Throws(Exception::class)
    fun getPreview(id: Long): ByteArray {
        val bitmap =
            if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.Q) {
                val sysImgs = SystemImage.getByIds(mCtx, listOf(id))
                if (sysImgs.isEmpty()) {
                    throw Exception("Image not found")
                }

                val uri = sysImgs[0].uri

                mCtx.contentResolver.loadThumbnail(
                    uri,
                    android.util.Size(2048, 2048),
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
        bitmap.compress(Bitmap.CompressFormat.JPEG, 90, stream)
        return stream.toByteArray()
    }

    /**
     * Get a full image for a given image ID
     * @param id The image ID
     * @return The full image as a JPEG byte array
     */
    @Throws(Exception::class)
    fun getFull(auid: String): ByteArray {
        val sysImgs = query.getSystemImagesByAUIDs(listOf(auid))
        if (sysImgs.isEmpty()) {
            throw Exception("Image not found")
        }

        val uri = sysImgs[0].uri

        val bitmap =
            if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.P) {
                ImageDecoder.decodeBitmap(ImageDecoder.createSource(mCtx.contentResolver, uri))
            } else {
                MediaStore.Images.Media.getBitmap(mCtx.contentResolver, uri)
                    ?: throw Exception("Image not found")
            }
        val stream = ByteArrayOutputStream()
        bitmap.compress(Bitmap.CompressFormat.JPEG, 90, stream)
        return stream.toByteArray()
    }
}