package gallery.memories.mapper

import android.content.ContentUris
import android.content.Context
import android.icu.text.SimpleDateFormat
import android.icu.util.TimeZone
import android.net.Uri
import android.provider.MediaStore
import android.util.Log
import androidx.exifinterface.media.ExifInterface
import org.json.JSONObject
import java.io.IOException

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
        val TAG = SystemImage::class.java.simpleName
        val IMAGE_URI = MediaStore.Images.Media.EXTERNAL_CONTENT_URI
        val VIDEO_URI = MediaStore.Video.Media.EXTERNAL_CONTENT_URI

        /**
         * Iterate over all images/videos in the given collection
         * @param ctx Context - application context
         * @param collection Uri - either IMAGE_URI or VIDEO_URI
         * @param selection String? - selection string
         * @param selectionArgs Array<String>? - selection arguments
         * @param sortOrder String? - sort order
         * @return Sequence<SystemImage>
         */
        fun cursor(
            ctx: Context,
            collection: Uri,
            selection: String?,
            selectionArgs: Array<String>?,
            sortOrder: String?
        ) = sequence {
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
                    yield(image)
                }
            }
        }

        /**
         * Get image or video by a list of IDs
         * @param ctx Context - application context
         * @param ids List<Long> - list of IDs
         * @return List<SystemImage>
         */
        fun getByIds(ctx: Context, ids: List<Long>): List<SystemImage> {
            val selection = MediaStore.Images.Media._ID + " IN (" + ids.joinToString(",") + ")"
            val images = cursor(ctx, IMAGE_URI, selection, null, null).toList()
            if (images.size == ids.size) return images
            return images + cursor(ctx, VIDEO_URI, selection, null, null).toList()
        }
    }

    /**
     * JSON representation of the SystemImage.
     * This corresponds to IPhoto on the frontend.
     */
    val json
        get(): JSONObject {
            val obj = JSONObject()
                .put(Fields.Photo.FILEID, fileId)
                .put(Fields.Photo.BASENAME, baseName)
                .put(Fields.Photo.MIMETYPE, mimeType)
                .put(Fields.Photo.HEIGHT, height)
                .put(Fields.Photo.WIDTH, width)
                .put(Fields.Photo.SIZE, size)
                .put(Fields.Photo.ETAG, mtime.toString())
                .put(Fields.Photo.EPOCH, epoch)
                .put(Fields.Photo.AUID, auid)

            if (isVideo) {
                obj.put(Fields.Photo.ISVIDEO, 1)
                    .put(Fields.Photo.VIDEO_DURATION, videoDuration / 1000)
            }

            return obj
        }

    /** The epoch timestamp of the image. */
    val epoch
        get(): Long {
            return dateTaken / 1000
        }

    /** The UTC dateTaken timestamp of the image. */
    val utcDate
        get(): Long {
            // Get EXIF date using ExifInterface if image
            if (!isVideo) {
                try {
                    val exif = ExifInterface(dataPath)
                    val exifDate = exif.getAttribute(ExifInterface.TAG_DATETIME)
                        ?: throw IOException()
                    val sdf = SimpleDateFormat("yyyy:MM:dd HH:mm:ss")
                    sdf.timeZone = TimeZone.GMT_ZONE
                    sdf.parse(exifDate).let {
                        return it.time / 1000
                    }
                } catch (e: Exception) {
                    Log.e(TAG, "Failed to read EXIF data: " + e.message)
                }
            }

            // No way to get the actual local date, so just assume current timezone
            return (dateTaken + TimeZone.getDefault().getOffset(dateTaken).toLong()) / 1000
        }

    /** The auid of the image. */
    val auid
        get(): Long {
            val crc = java.util.zip.CRC32()

            // pass date taken + size as decimal string
            crc.update((epoch.toString() + size.toString()).toByteArray())

            return crc.value
        }

    /** The database Photo object corresponding to the SystemImage. */
    val photo
        get(): Photo {
            val dateCache = utcDate
            return Photo(
                localId = fileId,
                auid = auid,
                mtime = mtime,
                dateTaken = dateCache,
                dayId = dateCache / 86400,
                baseName = baseName,
                bucketId = bucketId,
                bucketName = bucketName,
                flag = 0,
                hasRemote = false
            )
        }
}