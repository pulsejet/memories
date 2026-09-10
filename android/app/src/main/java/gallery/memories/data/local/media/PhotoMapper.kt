package gallery.memories.data.local.media

import android.icu.text.SimpleDateFormat
import android.icu.util.TimeZone
import android.util.Log
import androidx.exifinterface.media.ExifInterface
import gallery.memories.data.local.db.PhotoEntity
import gallery.memories.timeline.TimelineJson
import org.json.JSONObject
import java.math.BigInteger
import java.security.MessageDigest

/** Maps MediaStore rows to index entities and timeline JSON, deriving content IDs. */
class PhotoMapper {
    companion object {
        private val TAG = PhotoMapper::class.java.simpleName

        /** Seconds per day; dayId is the epoch day index shared with the server. */
        private const val SECONDS_PER_DAY = 86400
    }

    /** EXIF applies to photos only; videos carry no EXIF segment. */
    fun exifOf(image: SystemImage): ExifInterface? {
        if (image.isVideo) return null
        return try {
            ExifInterface(image.dataPath)
        } catch (e: Exception) {
            Log.w(TAG, "Failed to read EXIF data: ${e.message}")
            null
        }
    }

    /** EXIF capture time (UTC) falling back to MediaStore time shifted into UTC. */
    fun utcDate(image: SystemImage, exif: ExifInterface?): Long {
        if (exif != null) {
            val exifDate = try {
                exif.getAttribute(ExifInterface.TAG_DATETIME)
            } catch (e: Exception) {
                Log.w(TAG, "Failed to read EXIF datetime: ${e.message}")
                null
            }
            if (exifDate != null) {
                try {
                    val sdf = SimpleDateFormat("yyyy:MM:dd HH:mm:ss")
                    sdf.timeZone = TimeZone.GMT_ZONE
                    sdf.parse(exifDate)?.let { return it.time / 1000 }
                } catch (e: Exception) {
                    Log.w(TAG, "Failed to parse EXIF datetime: ${e.message}")
                }
            }
        }
        return (image.dateTaken + TimeZone.getDefault().getOffset(image.dateTaken).toLong()) / 1000
    }

    /** Content ID: identical file bytes share an auid wherever they appear. */
    fun auid(image: SystemImage): String = md5("${image.epoch}${image.size}")

    /** Backup ID keyed on filename, disambiguated by EXIF unique ID when present. */
    fun buid(image: SystemImage, exif: ExifInterface?): String {
        var sfx = "size=${image.size}"
        if (exif != null) {
            try {
                val iuid = exif.getAttribute(ExifInterface.TAG_IMAGE_UNIQUE_ID)
                if (iuid != null) {
                    sfx = "iuid=$iuid"
                } else {
                    Log.w(TAG, "Missing EXIF unique ID (${image.baseName})")
                }
            } catch (e: Exception) {
                Log.w(TAG, "Failed to read EXIF unique ID (${image.baseName}): ${e.message}")
            }
        }
        return md5("${image.baseName}$sfx")
    }

    /** Index entity for a MediaStore row. */
    fun toPhoto(image: SystemImage): PhotoEntity {
        val exif = exifOf(image)
        val taken = utcDate(image, exif)
        return PhotoEntity(
            localId = image.fileId,
            auid = auid(image),
            buid = buid(image, exif),
            mtime = image.mtime,
            dateTaken = taken,
            dayId = taken / SECONDS_PER_DAY,
            baseName = image.baseName,
            bucketId = image.bucketId,
            bucketName = image.bucketName,
            flag = 0,
            hasRemote = false,
        )
    }

    /** Timeline JSON for a MediaStore row; auid/buid/dayid are joined in by the caller. */
    fun toJson(image: SystemImage): JSONObject {
        val obj = JSONObject()
            .put(TimelineJson.Photo.FILEID, image.fileId)
            .put(TimelineJson.Photo.BASENAME, image.baseName)
            .put(TimelineJson.Photo.MIMETYPE, image.mimeType)
            .put(TimelineJson.Photo.HEIGHT, image.height)
            .put(TimelineJson.Photo.WIDTH, image.width)
            .put(TimelineJson.Photo.SIZE, image.size)
            .put(TimelineJson.Photo.ETAG, image.mtime.toString())
            .put(TimelineJson.Photo.EPOCH, image.epoch)
        if (image.isVideo) {
            obj.put(TimelineJson.Photo.ISVIDEO, 1)
                .put(TimelineJson.Photo.VIDEO_DURATION, image.videoDuration / 1000)
        }
        return obj
    }

    private fun md5(input: String): String {
        val md = MessageDigest.getInstance("MD5")
        return BigInteger(1, md.digest(input.toByteArray())).toString(16).padStart(32, '0')
    }
}
