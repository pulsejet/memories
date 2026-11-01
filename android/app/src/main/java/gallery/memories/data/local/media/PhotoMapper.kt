package gallery.memories.data.local.media

import android.content.Context
import android.icu.text.SimpleDateFormat
import android.icu.util.TimeZone
import android.icu.util.ULocale
import android.media.MediaMetadataRetriever
import android.os.Build
import android.provider.MediaStore
import android.util.Log
import androidx.exifinterface.media.ExifInterface
import gallery.memories.data.local.db.PhotoEntity
import gallery.memories.timeline.TimelineJson
import org.json.JSONObject
import java.math.BigInteger
import java.security.MessageDigest

/** Maps MediaStore rows to index entities and timeline JSON, deriving content IDs. */
class PhotoMapper(private val ctx: Context) {
    companion object {
        private val TAG = PhotoMapper::class.java.simpleName

        /** Seconds per day; dayId is the epoch day index shared with the server. */
        private const val SECONDS_PER_DAY = 86400

        /** Same precedence as Exif::parseExifDate on the server; ModifyDate must stay out. */
        private val EXIF_DATE_TAGS = listOf(ExifInterface.TAG_DATETIME_ORIGINAL, ExifInterface.TAG_DATETIME_DIGITIZED)
    }

    /**
     * EXIF applies to photos only; videos carry no EXIF segment.
     * Reads via [MediaStore.setRequireOriginal] so GPS is unredacted when
     * ACCESS_MEDIA_LOCATION is granted, falling back to the redacted read
     * (datetime/unique ID still parse) otherwise.
     */
    fun exifOf(image: SystemImage): ExifInterface? {
        if (image.isVideo) return null
        if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.Q) {
            try {
                ctx.applicationContext.contentResolver.openInputStream(
                    MediaStore.setRequireOriginal(image.uri),
                )?.use { return ExifInterface(it) }
            } catch (e: SecurityException) {
                Log.w(TAG, "Location permission missing, EXIF redacted (${image.baseName})")
            } catch (e: UnsupportedOperationException) {
                Log.w(TAG, "setRequireOriginal unsupported (${image.baseName}): ${e.message}")
            } catch (e: Exception) {
                Log.w(TAG, "Failed to read original EXIF (${image.baseName}): ${e.message}")
            }
        }
        try {
            ctx.applicationContext.contentResolver.openInputStream(image.uri)?.use { return ExifInterface(it) }
        } catch (e: Exception) {
            Log.w(TAG, "Failed to read EXIF data: ${e.message}")
        }
        return try {
            ExifInterface(image.dataPath)
        } catch (e: Exception) {
            Log.w(TAG, "Failed to read EXIF data: ${e.message}")
            null
        }
    }

    /** Decimal [lat, lon] for photos (EXIF) and videos (container location). Null when redacted/absent. */
    fun latLong(image: SystemImage, exif: ExifInterface?): DoubleArray? {
        if (exif != null) {
            try {
                exif.latLong?.let { if (it.size >= 2) return it }
            } catch (e: Exception) {
                Log.w(TAG, "Failed to read EXIF location (${image.baseName}): ${e.message}")
            }
        }
        if (image.isVideo) return videoLatLong(image)
        return null
    }

    /**
     * EXIF capture time (UTC) falling back to MediaStore time shifted into UTC.
     * DATE_TAKEN is null for files with no capture date, so use mtime like the server.
     */
    fun utcDate(image: SystemImage, exif: ExifInterface?): Long {
        exif?.let { exifDate(it) }?.let { return it }
        val time = if (image.dateTaken != 0L) image.dateTaken else image.mtime * 1000
        return (time + TimeZone.getDefault().getOffset(time).toLong()) / 1000
    }

    private fun exifDate(exif: ExifInterface): Long? = try {
        EXIF_DATE_TAGS
            .firstNotNullOfOrNull { tag -> exif.getAttribute(tag)?.takeUnless { it.startsWith("0000:00:00") } }
            ?.let { date ->
                val sdf = SimpleDateFormat("yyyy:MM:dd HH:mm:ss", ULocale.ROOT)
                sdf.timeZone = TimeZone.GMT_ZONE
                sdf.parse(date)?.let { it.time / 1000 }
            }
    } catch (e: Exception) {
        Log.w(TAG, "Failed to read EXIF datetime: ${e.message}")
        null
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
            dayId = taken.floorDiv(SECONDS_PER_DAY),
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
            .put(TimelineJson.Photo.LOCAL_URI, image.uri.toString())
        if (image.isVideo) {
            obj.put(TimelineJson.Photo.ISVIDEO, 1)
                .put(TimelineJson.Photo.VIDEO_DURATION, image.videoDuration / 1000)
        }
        return obj
    }

    /** Container location (e.g. "+37.421998-122.084000/"), tried unredacted first. */
    private fun videoLatLong(image: SystemImage): DoubleArray? {
        val uris = if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.Q) {
            listOf(MediaStore.setRequireOriginal(image.uri), image.uri)
        } else {
            listOf(image.uri)
        }
        for (uri in uris) {
            val retriever = MediaMetadataRetriever()
            try {
                retriever.setDataSource(ctx.applicationContext, uri)
                val loc = retriever.extractMetadata(MediaMetadataRetriever.METADATA_KEY_LOCATION)
                    ?: continue
                val m = Regex("([+-]\\d+\\.\\d+)([+-]\\d+\\.\\d+)").find(loc) ?: continue
                return doubleArrayOf(m.groupValues[1].toDouble(), m.groupValues[2].toDouble())
            } catch (e: SecurityException) {
                Log.w(TAG, "Location permission missing, video location redacted (${image.baseName})")
            } catch (e: Exception) {
                Log.w(TAG, "Failed to read video location (${image.baseName}): ${e.message}")
                break
            } finally {
                try {
                    retriever.release()
                } catch (_: Exception) {
                }
            }
        }
        return null
    }

    private fun md5(input: String): String {
        val md = MessageDigest.getInstance("MD5")
        return BigInteger(1, md.digest(input.toByteArray())).toString(16).padStart(32, '0')
    }
}
