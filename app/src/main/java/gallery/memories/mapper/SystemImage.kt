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
    var bucketId = 0L
    var bucketName = ""

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
                MediaStore.Images.Media.ORIENTATION,
                MediaStore.Images.Media.DATE_TAKEN,
                MediaStore.Images.Media.DATE_MODIFIED,
                MediaStore.Images.Media.DATA,
                MediaStore.Images.Media.BUCKET_ID,
                MediaStore.Images.Media.BUCKET_DISPLAY_NAME,
            )

            // Add video-specific fields
            if (collection == VIDEO_URI) {
                projection.add(MediaStore.Video.Media.DURATION)
            }

            // Get column indices
            val idColumn = projection.indexOf(MediaStore.Images.Media._ID)
            val nameColumn = projection.indexOf(MediaStore.Images.Media.DISPLAY_NAME)
            val mimeColumn = projection.indexOf(MediaStore.Images.Media.MIME_TYPE)
            val heightColumn = projection.indexOf(MediaStore.Images.Media.HEIGHT)
            val widthColumn = projection.indexOf(MediaStore.Images.Media.WIDTH)
            val sizeColumn = projection.indexOf(MediaStore.Images.Media.SIZE)
            val orientationColumn = projection.indexOf(MediaStore.Images.Media.ORIENTATION)
            val dateTakenColumn = projection.indexOf(MediaStore.Images.Media.DATE_TAKEN)
            val dateModifiedColumn = projection.indexOf(MediaStore.Images.Media.DATE_MODIFIED)
            val dataColumn = projection.indexOf(MediaStore.Images.Media.DATA)
            val bucketIdColumn = projection.indexOf(MediaStore.Images.Media.BUCKET_ID)
            val bucketNameColumn = projection.indexOf(MediaStore.Images.Media.BUCKET_DISPLAY_NAME)

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
                    image.fileId = cursor.getLong(idColumn)
                    image.baseName = cursor.getString(nameColumn)
                    image.mimeType = cursor.getString(mimeColumn)
                    image.height = cursor.getLong(heightColumn)
                    image.width = cursor.getLong(widthColumn)
                    image.size = cursor.getLong(sizeColumn)
                    image.dateTaken = cursor.getLong(dateTakenColumn)
                    image.mtime = cursor.getLong(dateModifiedColumn)
                    image.dataPath = cursor.getString(dataColumn)
                    image.bucketId = cursor.getLong(bucketIdColumn)
                    image.bucketName = cursor.getString(bucketNameColumn)
                    image.mCollection = collection

                    // Swap width/height if orientation is 90 or 270
                    val orientation = cursor.getInt(orientationColumn)
                    if (orientation == 90 || orientation == 270) {
                        image.width = image.height.also { image.height = image.width }
                    }

                    // Video specific fields
                    image.isVideo = collection == VIDEO_URI
                    if (image.isVideo) {
                        val durationColumn = projection.indexOf(MediaStore.Video.Media.DURATION)
                        image.videoDuration = cursor.getLong(durationColumn)
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