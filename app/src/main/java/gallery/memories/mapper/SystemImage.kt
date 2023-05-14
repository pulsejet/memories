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
    var mtime = 0L
    var dataPath = ""

    var isVideo = false
    var videoDuration = 0L

    val uri: Uri
    get() {
        return ContentUris.withAppendedId(mCollection, fileId)
    }

    private var mCollection: Uri = IMAGE_URI

    companion object {
        val IMAGE_URI = MediaStore.Images.Media.EXTERNAL_CONTENT_URI
        val VIDEO_URI = MediaStore.Video.Media.EXTERNAL_CONTENT_URI

        fun query(
            ctx: Context,
            collection: Uri,
            selection: String?,
            selectionArgs: Array<String>?,
            sortOrder: String?
        ): List<SystemImage> {
            val list = mutableListOf<SystemImage>()

            // Base fields common for videos and images
            val projection = arrayListOf(
                MediaStore.Images.Media._ID,
                MediaStore.Images.Media.DISPLAY_NAME,
                MediaStore.Images.Media.MIME_TYPE,
                MediaStore.Images.Media.HEIGHT,
                MediaStore.Images.Media.WIDTH,
                MediaStore.Images.Media.SIZE,
                MediaStore.Images.Media.DATE_TAKEN,
                MediaStore.Images.Media.DATE_MODIFIED,
                MediaStore.Images.Media.DATA
            )

            // Add video-specific fields
            if (collection == VIDEO_URI) {
                projection.add(MediaStore.Video.Media.DURATION)
            }

            // Query content resolver
            ctx.contentResolver.query(
                collection,
                projection.toTypedArray(),
                selection,
                selectionArgs,
                sortOrder
            ).use { cursor ->
                while (cursor!!.moveToNext()) {
                    val image = SystemImage()

                    // Common fields
                    image.fileId = cursor.getLong(0)
                    image.baseName = cursor.getString(1)
                    image.mimeType = cursor.getString(2)
                    image.height = cursor.getLong(3)
                    image.width = cursor.getLong(4)
                    image.size = cursor.getLong(5)
                    image.dateTaken = cursor.getLong(6)
                    image.mtime = cursor.getLong(7)
                    image.dataPath = cursor.getString(8)
                    image.mCollection = collection

                    // Video specific fields
                    image.isVideo = collection == VIDEO_URI
                    if (image.isVideo) {
                        image.videoDuration = cursor.getLong(9)
                    }

                    // Add to main list
                    list.add(image)
                }
            }

            return list
        }

        fun getByIds(ctx: Context, ids: List<Long>): List<SystemImage> {
            val selection = MediaStore.Images.Media._ID + " IN (" + ids.joinToString(",") + ")"
            val images = query(ctx, IMAGE_URI, selection, null, null)
            if (images.size == ids.size) return images
            return images + query(ctx, VIDEO_URI, selection, null, null)
        }
    }
}