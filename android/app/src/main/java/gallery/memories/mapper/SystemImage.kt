package gallery.memories.mapper

import android.content.ContentUris
import android.content.Context
import android.net.Uri
import android.provider.MediaStore
import android.util.Log
import androidx.exifinterface.media.ExifInterface
import org.json.JSONObject
import java.io.IOException
import java.io.InputStream
import java.math.BigInteger
import java.security.MessageDigest
import gallery.memories.utility.DateParser

class SystemImage {
    var fileId = 0L
    var baseName = ""
    var mimeType = ""
    var dateTaken = 0L               // seconds
    var dayId: Long = 0L
    var exifInterface: ExifInterface? = null
    var height = 0L
    var width = 0L
    var size = 0L
    var mtime = 0L                   // seconds
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
         * Create ExifInterface from Uri if possible (prefers InputStream for scoped storage),
         * falls back to dataPath (file path) if provided.
         */
        private fun createExifInterfaceFromUri(ctx: Context, uri: Uri, dataPath: String?): ExifInterface? {
            try {
                // Try input stream first (works on scoped storage)
                ctx.contentResolver.openInputStream(uri)?.use { input ->
                    return ExifInterface(input)
                }
            } catch (e: Exception) {
                Log.v(TAG, "openInputStream failed for $uri: ${e.message}")
            }

            // Fallback to file path (DATA) if available
            if (!dataPath.isNullOrEmpty()) {
                try {
                    return ExifInterface(dataPath)
                } catch (e: Exception) {
                    Log.w(TAG, "ExifInterface(file) failed for $dataPath: ${e.message}")
                }
            }
            return null
        }

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
            val durationColumn = if (collection == VIDEO_URI) projection.indexOf(MediaStore.Video.Media.DURATION) else -1

            // Query content resolver
            ctx.contentResolver.query(
                collection,
                projection.toTypedArray(),
                selection,
                selectionArgs,
                sortOrder
            ).use { cursor ->
                if (cursor == null) {
                    Log.w(TAG, "ContentResolver.query returned null for $collection")
                    return@sequence
                }

                while (cursor.moveToNext()) {
                    val image = SystemImage()

                    image.fileId = cursor.getLong(idColumn)
                    image.baseName = cursor.getString(nameColumn) ?: ""
                    image.mimeType = cursor.getString(mimeColumn) ?: ""
                    image.height = cursor.getLong(heightColumn)
                    image.width = cursor.getLong(widthColumn)
                    image.size = cursor.getLong(sizeColumn)
                    image.mtime = cursor.getLong(dateModifiedColumn)

                    image.dataPath = cursor.getString(dataColumn) ?: ""
                    image.bucketId = cursor.getLong(bucketIdColumn)
                    image.bucketName = cursor.getString(bucketNameColumn) ?: ""
                    image.mCollection = collection
                    image.exifInterface = createExifInterfaceFromUri(ctx, image.uri, image.dataPath)

                    image.isVideo = collection == VIDEO_URI
                    if (image.isVideo && durationColumn >= 0) {
                        image.videoDuration = cursor.getLong(durationColumn)
                    }

                    val dateTaken = if (!cursor.isNull(dateTakenColumn)) cursor.getLong(dateTakenColumn) / 1000 else null

                    // Infer the earliest date from any source
                    var zonedDateTime = DateParser.inferEarliestDate(image.exifInterface, image.mimeType, dateTaken, image.baseName, image.mtime)

                    // store the date taken in seconds since epoch (UTC)
                    image.dateTaken = zonedDateTime.toEpochSecond()

                    image.dayId = DateParser.getDayId(zonedDateTime)

                    // Swap width/height if orientation is 90 or 270
                    val orientation = cursor.getInt(orientationColumn)
                    if (orientation == 90 || orientation == 270) {
                        image.width = image.height.also { image.height = image.width }
                    }

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
                .put(Fields.Photo.EPOCH, dateTaken)

            if (isVideo) {
                obj.put(Fields.Photo.ISVIDEO, 1)
                    .put(Fields.Photo.VIDEO_DURATION, videoDuration / 1000)
            }

            return obj
        }

    fun auid(): String {
        return md5("$dateTaken$size")
    }

    fun buid(exif: ExifInterface?): String {
        var sfx = "size=$size"
        if (exif != null) {
            try {
                val iuid = exif.getAttribute(ExifInterface.TAG_IMAGE_UNIQUE_ID)
                    ?: throw IOException()
                sfx = "iuid=$iuid"
            } catch (e: Exception) {
                Log.w(TAG, "Failed to read EXIF unique ID ($baseName): ${e.message}")
            }
        }
        return md5("$baseName$sfx")
    }

    /**
     * The database Photo object corresponding to the SystemImage.
     * This should ONLY be used for insertion into the database.
     */
    val photo
        get(): Photo {
            return Photo(
                localId = fileId,
                auid = auid(),
                buid = buid(exifInterface),
                mtime = mtime,
                dateTaken = dateTaken,
                dayId = dayId,
                baseName = baseName,
                bucketId = bucketId,
                bucketName = bucketName,
                flag = 0,
                hasRemote = false
            )
        }

    private fun md5(input: String): String {
        val md = MessageDigest.getInstance("MD5")
        return BigInteger(1, md.digest(input.toByteArray())).toString(16).padStart(32, '0')
    }
}
