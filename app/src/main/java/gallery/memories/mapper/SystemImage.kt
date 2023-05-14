package gallery.memories.mapper

import android.content.ContentUris
import android.content.Context
import android.net.Uri
import android.provider.MediaStore

class SystemImage {
    var fileId = 0L;
    var baseName = ""
    var mimeType = ""
    var dateTaken = 0L
    var height = 0L
    var width = 0L
    var size = 0L
    var dataPath = ""
    var isVideo = false

    private var mCollection: Uri = IMAGE_URI

    val uri: Uri
    get() {
        return ContentUris.withAppendedId(mCollection, fileId)
    }

    companion object {
        val IMAGE_URI = MediaStore.Images.Media.EXTERNAL_CONTENT_URI
        val VIDEO_URI = MediaStore.Video.Media.EXTERNAL_CONTENT_URI

        fun getByIds(ctx: Context, ids: List<Long>): List<SystemImage> {
            val images = getByIdsAndCollection(ctx, IMAGE_URI, ids)
            if (images.size == ids.size) return images
            return images + getByIdsAndCollection(ctx, VIDEO_URI, ids)
        }

        fun getByIdsAndCollection(ctx: Context, collection: Uri, ids: List<Long>): List<SystemImage> {
            val selection = MediaStore.Images.Media._ID + " IN (" + ids.joinToString(",") + ")"

            val list = mutableListOf<SystemImage>()

            ctx.contentResolver.query(
                collection,
                arrayOf(
                    MediaStore.Images.Media._ID,
                    MediaStore.Images.Media.DISPLAY_NAME,
                    MediaStore.Images.Media.MIME_TYPE,
                    MediaStore.Images.Media.HEIGHT,
                    MediaStore.Images.Media.WIDTH,
                    MediaStore.Images.Media.SIZE,
                    MediaStore.Images.Media.DATE_TAKEN,
                    MediaStore.Images.Media.DATA
                ),
                selection,
                null,
                null
            ).use { cursor ->
                while (cursor!!.moveToNext()) {
                    val image = SystemImage()
                    image.fileId = cursor.getLong(0)
                    image.baseName = cursor.getString(1)
                    image.mimeType = cursor.getString(2)
                    image.height = cursor.getLong(3)
                    image.width = cursor.getLong(4)
                    image.size = cursor.getLong(5)
                    image.dateTaken = cursor.getLong(6)
                    image.dataPath = cursor.getString(7)
                    image.isVideo = collection == VIDEO_URI
                    image.mCollection = collection
                    list.add(image)
                }
            }

            return list
        }
    }
}