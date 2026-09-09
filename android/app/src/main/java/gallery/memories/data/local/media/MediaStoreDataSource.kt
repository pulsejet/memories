package gallery.memories.data.local.media

import android.content.Context
import android.net.Uri
import android.provider.MediaStore

class MediaStoreDataSource(private val ctx: Context) {
    private val resolver get() = ctx.applicationContext.contentResolver

    /** Lazily yields device media rows; swaps w/h for portrait-orientation photos. */
    fun cursor(
        collection: Uri,
        selection: String?,
        selectionArgs: Array<String>?,
        sortOrder: String?,
    ): Sequence<SystemImage> = sequence {
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
        if (collection == SystemImage.VIDEO_URI) projection.add(MediaStore.Video.Media.DURATION)

        val idCol = projection.indexOf(MediaStore.Images.Media._ID)
        val nameCol = projection.indexOf(MediaStore.Images.Media.DISPLAY_NAME)
        val mimeCol = projection.indexOf(MediaStore.Images.Media.MIME_TYPE)
        val hCol = projection.indexOf(MediaStore.Images.Media.HEIGHT)
        val wCol = projection.indexOf(MediaStore.Images.Media.WIDTH)
        val sizeCol = projection.indexOf(MediaStore.Images.Media.SIZE)
        val orientCol = projection.indexOf(MediaStore.Images.Media.ORIENTATION)
        val takenCol = projection.indexOf(MediaStore.Images.Media.DATE_TAKEN)
        val modCol = projection.indexOf(MediaStore.Images.Media.DATE_MODIFIED)
        val dataCol = projection.indexOf(MediaStore.Images.Media.DATA)
        val bucketIdCol = projection.indexOf(MediaStore.Images.Media.BUCKET_ID)
        val bucketNameCol = projection.indexOf(MediaStore.Images.Media.BUCKET_DISPLAY_NAME)

        resolver.query(collection, projection.toTypedArray(), selection, selectionArgs, sortOrder).use { c ->
            if (c == null) return@sequence
            while (c.moveToNext()) {
                val img = SystemImage()
                img.fileId = c.getLong(idCol)
                img.baseName = c.getString(nameCol) ?: ""
                img.mimeType = c.getString(mimeCol) ?: ""
                img.height = c.getLong(hCol)
                img.width = c.getLong(wCol)
                img.size = c.getLong(sizeCol)
                img.dateTaken = c.getLong(takenCol)
                img.mtime = c.getLong(modCol)
                img.dataPath = c.getString(dataCol) ?: ""
                img.bucketId = c.getLong(bucketIdCol)
                img.bucketName = c.getString(bucketNameCol) ?: ""
                img.collection = collection
                val orientation = c.getInt(orientCol)
                if (orientation == 90 || orientation == 270) {
                    img.width = img.height.also { img.height = img.width }
                }
                img.isVideo = collection == SystemImage.VIDEO_URI
                if (img.isVideo) {
                    val durCol = projection.indexOf(MediaStore.Video.Media.DURATION)
                    img.videoDuration = c.getLong(durCol)
                }
                yield(img)
            }
        }
    }

    /** Looks up IDs in images first, then videos for whatever is missing. */
    fun getByIds(ids: List<Long>): List<SystemImage> {
        if (ids.isEmpty()) return listOf()
        val sel = MediaStore.Images.Media._ID + " IN (" + ids.joinToString(",") + ")"
        val images = cursor(SystemImage.IMAGE_URI, sel, null, null).toList()
        if (images.size == ids.size) return images
        return images + cursor(SystemImage.VIDEO_URI, sel, null, null).toList()
    }
}
