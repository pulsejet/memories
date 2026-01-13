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
import java.util.Calendar
import java.time.*
import java.time.format.DateTimeFormatter
import java.time.format.DateTimeFormatterBuilder
import java.time.format.ResolverStyle
import java.time.temporal.ChronoField
import java.util.Date
import java.util.TimeZone
import java.time.temporal.TemporalAccessor
import kotlin.math.floor

data class InstantZone(val instant: Instant, val zoneId: ZoneId?)

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

        val DATE_FIELDS = listOf(
            "SubSecDateTimeOriginal",
            ExifInterface.TAG_DATETIME_ORIGINAL,
            ExifInterface.TAG_DATETIME_DIGITIZED,
            ExifInterface.TAG_DATETIME,
            "SonyDateTime",

            "SubSecCreateDate",
            "CreationDate",
            "CreationDateValue",
            "CreateDate",
            "TrackCreateDate",
            "MediaCreateDate",
            "FileCreateDate",

            "SubSecModifyDate",
            "ModifyDate",
            "TrackModifyDate",
            "MediaModifyDate",
            "FileModifyDate",
        )

        // Flexible formatter: optional seconds and optional offset
        private val DATE_TIME_FORMATTER: DateTimeFormatter = DateTimeFormatter.ofPattern(
            "yyyy-MM-dd['T'HH:mm[:ss][XXX]]"
        )

        // Precompiled regexes for performance
        private val OFFSET_RE = Regex("([+-]\\d{2}:?\\d{2}|Z)$")
        private val FRAC_RE = Regex("""\.\d+""")
        private val TIME_SEC_RE = Regex("""\d{2}:\d{2}:\d{2}""")
        private val TRAILING_Z_RE = Regex("Z$")
        private val OFFSET_NO_COLON_RE = Regex("([+-])(\\d{2})(\\d{2})$")
        private val DATE_CLEANUP_RE = Regex("""^(\d{4}):(\d{2}):(\d{2})""")
        private val VIDEO_MIME_RE = Regex("^video/\\w+", RegexOption.IGNORE_CASE)

        /**
         * Normalize a raw exif date string:
         *  - Replace trailing Z with +00:00
         *  - Replace comma decimal separator with dot
         *  - Normalize +HHMM / -HHMM to +HH:MM / -HH:MM
         */
        private fun normalizeRaw(s: String): String {
            var x = s.trim()
            if (x.isEmpty()) return x
            if (TRAILING_Z_RE.containsMatchIn(x)) {
                x = x.replace(TRAILING_Z_RE, "+00:00")
            }
            // comma fractional -> dot
            x = x.replace(',', '.')
            // normalize +HHMM / -HHMM -> +HH:MM
            if (OFFSET_NO_COLON_RE.containsMatchIn(x)) {
                x = x.replace(OFFSET_NO_COLON_RE, "$1$2:$3")
            }
            return x
        }

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
         * Cursor sequence over media store entries.
         * ctx is used to open InputStream for EXIF reading so we can support scoped storage.
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

                    // Parse EXIF date using ExifInterface, otherwise fallback to MediaStore dateTaken or mtime
                    var instantZone = parseExifDate(image.exifInterface, image.mimeType, dateTaken, image.mtime)

                    var dateTakenInstant = instantZone.instant

                    image.dateTaken = dateTakenInstant.getEpochSecond()

                    val dateTakenZdt = instantZone.zoneId.let { dateTakenInstant.atZone(it)  }
                    val midnightUtc = dateTakenZdt.toLocalDate().atStartOfDay(ZoneOffset.UTC)

                    image.dayId = floor(midnightUtc.toEpochSecond() / 86400.0).toLong()

                    // Swap width/height if orientation is 90 or 270
                    val orientation = cursor.getInt(orientationColumn)
                    if (orientation == 90 || orientation == 270) {
                        image.width = image.height.also { image.height = image.width }
                    }

                    yield(image)
                }
            }
        }

        fun parseExifDate(exif: ExifInterface?, mimeType: String?, dateTaken: Long?, mtime: Long): InstantZone {
            val candidates = mutableMapOf<String, String>()

            if (exif != null) {
                for (field in DATE_FIELDS) {
                    val v = exif.getAttribute(field)
                    if (!v.isNullOrEmpty() && !v.startsWith("0000:00:00")) {
                        candidates[field] = v
                    }
                }

                // Add GPS date/time if available
                val gpsDate = exif.getAttribute(ExifInterface.TAG_GPS_DATESTAMP)
                val gpsTime = exif.getAttribute(ExifInterface.TAG_GPS_TIMESTAMP)
                if (!gpsDate.isNullOrEmpty() && !gpsTime.isNullOrEmpty()) {
                    candidates["GPS"] = gpsDate.replace(':', '-') + "T" + gpsTime
                }
            }

            var bestAdjustedEpoch: Long? = null   // epochSecond - precision
            var bestInstant: Instant? = null
            var bestZone: ZoneId? = null

            // Try to obtain explicit EXIF timezone from dedicated EXIF fields (if any)
            val exifZone: ZoneId? = exif?.let { e ->
                try {
                    val tzStr = e.getAttribute("OffsetTimeOriginal")
                        ?: e.getAttribute("OffsetTime")
                        ?: e.getAttribute("OffsetTimeDigitized")
                        ?: e.getAttribute("TimeZone")
                        ?: e.getAttribute("LocationTZID")
                    if (tzStr != null) ZoneId.of(tzStr) else null
                } catch (_: Exception) {
                    Log.w(TAG, "Failed to parse EXIF timezone")
                    null
                }
            }

            var bestField: String? = null

            for ((field, raw) in candidates) {
                var str = normalizeRaw(raw)

                str = str.replaceFirst(DATE_CLEANUP_RE, "$1-$2-$3")
                str = str.replaceFirst(' ', 'T')

                try {
                    val parsed: TemporalAccessor = try {
                        // parseBest tries OffsetDateTime first, then LocalDateTime, then LocalDate
                        DATE_TIME_FORMATTER.parseBest(
                            str,
                            { OffsetDateTime.from(it) },
                            { LocalDateTime.from(it) },
                            { LocalDate.from(it) }
                        )
                    } catch (e: Exception) {
                        throw IllegalArgumentException("Failed to parse datetime: $str", e)
                    }

                    var instant: Instant
                    var parsedZoneFromString: ZoneId? = null

                    when (parsed) {
                        is OffsetDateTime -> {
                            // string had explicit offset
                            instant = parsed.toInstant()
                            parsedZoneFromString = parsed.offset
                        }
                        is LocalDateTime -> {
                            instant = when {
                                exifZone != null && mimeType?.matches(VIDEO_MIME_RE) == true -> {
                                    // videos: treat as UTC then convert to exifZone (shift clock)
                                    parsed.atZone(ZoneOffset.UTC).toInstant()
                                }
                                exifZone != null -> {
                                    // photos: treat as local time in exifZone (no clock shift)
                                    parsed.atZone(exifZone).toInstant()
                                }
                                else -> {
                                    // fallback: assume UTC
                                    parsed.atZone(ZoneOffset.UTC).toInstant()
                                }
                            }
                        }
                        is LocalDate -> {
                            // only a date, assume start of day in exifZone or UTC
                            instant = (exifZone ?: ZoneOffset.UTC).let { parsed.atStartOfDay(it).toInstant() }
                        }
                        else -> throw IllegalArgumentException("Unsupported datetime format: $str")
                    }

                    // Filter out QuickTime bogus timestamp (1904-01-01) or timestamps way before 1800
                    val ts = instant.getEpochSecond()
                    if (ts == -2082844800L || ts <= -5_364_662_400L) {
                        continue
                    }

                    // determine precision: fractional seconds > seconds > minutes
                    val precision = when {
                        FRAC_RE.containsMatchIn(str) -> 3
                        TIME_SEC_RE.containsMatchIn(str) -> 2
                        else -> 1
                    }

                    val adjusted = ts - precision

                    if (adjusted > 0 && (bestAdjustedEpoch == null || adjusted < bestAdjustedEpoch)) {
                        bestAdjustedEpoch = adjusted
                        bestInstant = instant
                        bestZone = (parsedZoneFromString ?: exifZone)
                        bestField = field
                    }
                } catch (ex: Exception) {
                    Log.v(TAG, "parse failed for field=$field value=$str: ${ex.message}")
                    // continue to next candidate
                }
            }

            if (bestInstant == null) {
                if (dateTaken != null && dateTaken > 0) {
                    Log.v(TAG, "Date source: MediaStore dateTaken")
                    return InstantZone(Instant.ofEpochSecond(dateTaken), ZoneOffset.UTC)
                } else {
                    Log.v(TAG, "Date source: MediaStore mtime")
                    return InstantZone(Instant.ofEpochSecond(mtime), ZoneOffset.UTC)
                }
            }

            Log.v(TAG, "Date source: EXIF $bestField")
            return InstantZone(bestInstant, bestZone)
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
