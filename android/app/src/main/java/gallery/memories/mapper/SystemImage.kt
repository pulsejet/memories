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
import java.time.temporal.Temporal
import java.time.temporal.TemporalAccessor
import java.time.temporal.TemporalField
import kotlin.math.floor
import java.util.regex.Matcher
import java.util.regex.Pattern

data class Pair<K, V>(val key: K, val value: V)

class DateParser {
    companion object {

        /** utility class to merge multiple TemporalAccessors into one. It queries TemporalAccessors in order 
         *  until it finds one that supports the requested field (thus preserving priority if needed)
         */ 
        class MergedTemporalAccessor(
            private val parts: List<TemporalAccessor>
        ) : TemporalAccessor {

            override fun isSupported(field: TemporalField): Boolean =
                parts.any { it.isSupported(field) }

            override fun getLong(field: TemporalField): Long {
                val source = parts.firstOrNull { it.isSupported(field) } ?: throw UnsupportedOperationException("Field $field not supported")
                return source.getLong(field)
            }

        }

        val TAG = DateParser::class.java.simpleName

        private val VIDEO_MIME_RE = Regex("^video/\\w+", RegexOption.IGNORE_CASE)
        
        private val DATETIME_FIELDS = listOf(
            "SubSecDateTimeOriginal",
            ExifInterface.TAG_DATETIME_ORIGINAL,
            ExifInterface.TAG_DATETIME_DIGITIZED,
            ExifInterface.TAG_DATETIME,
            "SonyDateTime",
        )

        private val DATE_FIELDS = listOf(
            "SubSecCreateDate",
            "CreationDate",
            "CreationDateValue",
            "CreateDate",
            "TrackCreateDate",
            "MediaCreateDate",
            "FileCreateDate",
        )

        private val PAIRED_DATE_TIME_FIELDS = listOf(
            Pair(ExifInterface.TAG_GPS_DATESTAMP, ExifInterface.TAG_GPS_TIMESTAMP),
        )

        private val OFFSET_FIELDS = listOf(
            ExifInterface.TAG_OFFSET_TIME_ORIGINAL,
            ExifInterface.TAG_OFFSET_TIME_DIGITIZED,
            ExifInterface.TAG_OFFSET_TIME,
            "TimeZone",
            "LocationTZID"
        )

        private val DATETIME_FORMATTERS: List<DateTimeFormatter> = listOf(
            DateTimeFormatter.ofPattern("yyyy:MM:dd HH:mm:ss[.SSS][.SS][.S][XXXXX][XXXX][XXX][XX][X]"),
            DateTimeFormatter.ofPattern("yyyy-MM-dd HH:mm:ss[.SSS][.SS][.S][XXXXX][XXXX][XXX][XX][X]"),
            DateTimeFormatter.ISO_DATE_TIME,
            DateTimeFormatter.ISO_INSTANT,
            DateTimeFormatter.RFC_1123_DATE_TIME
        )

        private val DATE_FORMATTERS: List<DateTimeFormatter> = listOf(
            DateTimeFormatter.ofPattern("yyyy:MM:dd[XXXXX][XXXX][XXX][XX][X]"),
            DateTimeFormatter.ofPattern("yyyy-MM-dd[XXXXX][XXXX][XXX][XX][X]"),
            DateTimeFormatter.ISO_DATE,
            DateTimeFormatter.ISO_ORDINAL_DATE,
            DateTimeFormatter.ISO_WEEK_DATE
        )

        private val TIME_FORMATTERS: List<DateTimeFormatter> = listOf(
            DateTimeFormatter.ofPattern("HH:mm:ss[.SSS][.SS][.S][XXXXX][XXXX][XXX][XX][X]"),
            DateTimeFormatter.ISO_TIME
        )

        private val ZONE_FORMATTER: DateTimeFormatter = DateTimeFormatter.ofPattern("[XXXXX][XXXX][XXX][XX][X]")

        private val FILENAME_PATTERNS: List<DatePattern> = listOf(
            DatePattern(".*?(\\d{8})_(\\d{6}).*", listOf(DateTimeFormatter.BASIC_ISO_DATE, DateTimeFormatter.ofPattern("HHmmss"))), // Standard Camera/Android/Pixel (e.g., IMG_20230520_143055.jpg or 20230520_143055.mp4)
            DatePattern(".*?(\\d{8}).*", DateTimeFormatter.BASIC_ISO_DATE), // WhatsApp Image/Video (e.g., IMG-20230520-WA0001.jpg)
            DatePattern(".*?(\\d{4}-\\d{2}-\\d{2}).*?(\\d{2}\\.\\d{2}\\.\\d{2}).*", listOf(DateTimeFormatter.ISO_DATE, DateTimeFormatter.ofPattern("HH.mm.ss"))), // iOS / Screenshot standard (e.g., Screenshot 2023-05-20 at 14.30.55.png)
            DatePattern(".*?(\\d{4}-\\d{2}-\\d{2}).*?(\\d{2}-\\d{2}-\\d{2}).*", listOf(DateTimeFormatter.ISO_DATE, DateTimeFormatter.ofPattern("HH-mm-ss"))), // Generic Separators (e.g., 2023-05-20 14-30-55.jpg)
            DatePattern(".*?(\\d{4}-\\d{2}-\\d{2}).*", DateTimeFormatter.ISO_DATE) // 5. ISO Date Only (e.g., Report_2023-05-20.pdf)
        )

        fun inferEarliestDate(exif: ExifInterface?, mimeType: String?, dateTaken: Long?, filename: String, mtime: Long): ZonedDateTime {
            // Try to obtain explicit EXIF timezone from dedicated EXIF fields (if any)
            val exifZone: ZoneId? = exif?.let { e ->
                OFFSET_FIELDS.mapNotNull { e.getAttribute(it)}
                                .map { 
                                    try { parseZoneFromString(it) } 
                                    catch (_: Exception) { 
                                        Log.e(TAG, "Unable to parse zone from EXIF field containing: '$it'") 
                                        null 
                                    } 
                                }.firstOrNull { it != null }
            }

            var candidates: MutableList<Pair<String, TemporalAccessor>> = mutableListOf()

            // try to parse every field and add to the accessor list each successful one
            if (exif != null) {
                for (field in DATETIME_FIELDS) {
                    try {
                        exif.getAttribute(field)?.let { 
                            candidates += Pair("Exif $field", parseDateTimeFromString(it)) 
                        }
                    } catch (e: Exception) {
                        Log.e(TAG, "Unable to parse date time from EXIF field containing: '${exif.getAttribute(field) ?: ""}': ${e.message}")
                    }
                }

                for (field in DATE_FIELDS) {
                    try {
                        exif.getAttribute(field)?.let { 
                            candidates += Pair("Exif $field", parseDateFromString(it)) 
                        }
                    } catch (e: Exception) {
                        Log.e(TAG, "Unable to parse date from EXIF field containing: '${exif.getAttribute(field) ?: ""}': ${e.message}")
                    }
                }

                for ((key, v) in PAIRED_DATE_TIME_FIELDS) {
                    try {
                        val date = exif.getAttribute(key)
                        val time = exif.getAttribute(v)
                        
                        if (date != null && time != null) {
                            candidates += Pair("Exif $key and $v", MergedTemporalAccessor(listOf(parseDateFromString(date), parseTimeFromString(time))))
                        }
                    } catch (e: Exception) {
                        Log.e(TAG, "Unable to parse paired date time from EXIF fields containing: '${exif.getAttribute(key) ?: ""}' and '${exif.getAttribute(v) ?: ""}': ${e.message}")
                    }
                }
            }

            // add the fallback to filename, dateTaken and mtime
            try {
                candidates += Pair("Filename", parseDateTimeFromFilename(filename))
            } catch (e: Exception) {
                Log.e(TAG, "Unable to parse date time from filename: '${filename}': ${e.message}")
            }

            if (dateTaken != null) {
                candidates += Pair("MediaStore dateTaken", Instant.ofEpochSecond(dateTaken))
            }

            candidates += Pair("MediaStore mtime", Instant.ofEpochSecond(mtime))

            // find out the earliest date (>0) among all candidates by querying INSTANT_SECONDS, or building it from EPOCH_DAY and SECOND_OF_DAY if possible
            val bestAccessorPair: Pair<String, TemporalAccessor>? = candidates.minByOrNull { 
                if (it.value.isSupported(ChronoField.INSTANT_SECONDS)) {
                    val s = it.value.getLong(ChronoField.INSTANT_SECONDS)
                    if (s>0L) s else Long.MAX_VALUE
                }
                else if (it.value.isSupported(ChronoField.EPOCH_DAY)) {
                    val epochDay = it.value.getLong(ChronoField.EPOCH_DAY)

                    // use end of day for comparison when second of day is not available
                    // this prioritizes same-day dates with a defined time
                    val secondOfDay = if (it.value.isSupported(ChronoField.SECOND_OF_DAY)) it.value.getLong(ChronoField.SECOND_OF_DAY) else (86400L) 
                    val s = (epochDay * 86400L) + secondOfDay
                    if (s>0L) s else Long.MAX_VALUE
                } else {
                    Log.e(TAG, "Could not get or calculate INSTANT_SECONDS from accessor: '${it.key}'='${it.value}' does not support INSTANT_SECONDS or EPOCH_DAY")
                    Long.MAX_VALUE
                }
            }

            // try to cast the bestAccessor to OffsetDateTime, LocalDateTime, LocalDate or Instant and handle each one accordingly
            if (bestAccessorPair != null) {
                val zonedDateTime = resolveDateFromAccessor(bestAccessorPair.value, exifZone, mimeType)

                // finally log the best field and return the instant and the zone
                if (zonedDateTime != null) {
                    Log.v(TAG, "Date source: ${bestAccessorPair.key}, Correct inferred zone: ${exifZone != null}") 
                    return zonedDateTime
                }
            }

            // fallback that should never happen since mtime is always available
            Log.v(TAG, "Date source: none") 
            return ZonedDateTime.ofInstant(Instant.ofEpochSecond(0), ZoneOffset.UTC)
        }

        fun getDayId(zonedDateTime: ZonedDateTime): Long {
            // shift the zone to UTC keeping the local clock untouched, then calculate the day id using seconds since UTC epoch
            val midnightUtc = zonedDateTime.withZoneSameLocal(ZoneOffset.UTC)
            return floor(midnightUtc.toEpochSecond() / 86400.0).toLong()
        }

        fun resolveDateFromAccessor(accessor: TemporalAccessor, exifZone: ZoneId?, mimeType: String?): ZonedDateTime? {
            var zonedDateTime: ZonedDateTime? = null
            
            try {
                // supports both ZoneID and Zone Offset
                zonedDateTime = ZonedDateTime.from(accessor)
            } catch (_: Exception) {}

            if (zonedDateTime == null) {
                try {
                    // supports combining LocalDate and LocalTime
                    val localDateTime = LocalDateTime.from(accessor)

                    if (exifZone != null && mimeType?.matches(VIDEO_MIME_RE) == true) {
                        // videos: treat as UTC then convert to exifZone (shift local clock to keep the instant unchanged)
                        zonedDateTime = localDateTime.atZone(ZoneOffset.UTC).withZoneSameInstant(exifZone)
                    } else {
                        // photos: treat as local time in exifZone (no clock shift), or assume UTC as fallback both for photos and videos
                        zonedDateTime = localDateTime.atZone(exifZone ?: ZoneOffset.UTC)
                    }
                } catch (_: Exception) {}
            }

            if (zonedDateTime == null) {
                try {
                    val localDate = LocalDate.from(accessor)
                    zonedDateTime = localDate.atStartOfDay(exifZone ?: ZoneOffset.UTC)
                } catch (_: Exception) {}
            }

            if (zonedDateTime == null) {
                try {
                    val instant = Instant.from(accessor)
                    zonedDateTime = ZonedDateTime.ofInstant(instant, ZoneOffset.UTC)
                } catch (_: Exception) {}
            }

            return zonedDateTime
        }

        private class DatePattern {
            val pattern: Pattern
            val dateFormatters: List<DateTimeFormatter>

            constructor(regex: String, dateFormatters: List<DateTimeFormatter>) {
                this.pattern = Pattern.compile(regex, Pattern.CASE_INSENSITIVE)
                this.dateFormatters = dateFormatters
            }

            constructor(regex: String, dateFormatter: DateTimeFormatter) {
                this.pattern = Pattern.compile(regex, Pattern.CASE_INSENSITIVE)
                this.dateFormatters = listOf(dateFormatter)
            }

            fun match_parse(str: String): TemporalAccessor {
                val matcher = pattern.matcher(str)
                var accessors: MutableList<TemporalAccessor> = mutableListOf()
                if (matcher.find()) {
                    for ((i, formatter) in dateFormatters.withIndex()) {
                        try {
                            val match = matcher.group(i+1) // group 0 is the entire sequence
                            accessors += formatter.parse(match)
                        } catch(e: Exception) {
                            if (e is IllegalStateException) throw e
                            else if (e is IndexOutOfBoundsException) throw IllegalArgumentException("DatePattern object has less capturing groups (${i}) then formatters (${dateFormatters.size})")
                            else throw IllegalArgumentException("Could not parse a group of string '$str' with formatter '${formatter.toString()}'")
                        }
                    }
                }

                if (accessors.isEmpty()) throw IllegalArgumentException("No date information found in string '$str'")

                // merge the information from all accessors
                return MergedTemporalAccessor(accessors)
            }
        } 

        fun parseDateTimeFromString(str: String): TemporalAccessor {
            val cleanStr = str.trim().replace("\\0", "")
            if (cleanStr.isNotEmpty()) {
                for (formatter in DATETIME_FORMATTERS) {
                    try {
                        return formatter.parseBest(cleanStr, 
                            OffsetDateTime::from, 
                            LocalDateTime::from
                        )
                    } catch(_: Exception) {}
                }
            }
        
            throw IllegalArgumentException("Unable to parse date time: '$str'")
        }

        fun parseDateFromString(str: String): TemporalAccessor {
            val cleanStr = str.trim().replace("\\0", "")
            if (cleanStr.isNotEmpty()) {
                for (formatter in DATE_FORMATTERS) {
                    try {
                        return LocalDate.parse(cleanStr, formatter)
                    } catch(_: Exception) {}
                }
            }
        
            throw IllegalArgumentException("Unable to parse date: '$str'")
        }

        fun parseTimeFromString(str: String): TemporalAccessor {
            val cleanStr = str.trim().replace("\\0", "")
            if (cleanStr.isNotEmpty()) {
                for (formatter in TIME_FORMATTERS) {
                    try {
                        return formatter.parseBest(cleanStr, 
                            OffsetTime::from, 
                            LocalTime::from
                        )
                    } catch(_: Exception) {}
                }
            }
        
            throw IllegalArgumentException("Unable to parse time: '$str'")
        }

        fun parseZoneFromString(str: String): ZoneId {
            val cleanStr = str.trim().replace("\\0", "")
            if (cleanStr.isNotEmpty()) {
                try {
                    return ZoneId.of(cleanStr)
                } catch (_: Exception) {}

                try {
                    return ZoneId.from(ZONE_FORMATTER.parse(cleanStr))
                } catch (_: Exception) {}
            }
        
            throw IllegalArgumentException("Unable to parse zone: '$str'")
        }

        fun parseDateTimeFromFilename(str: String): TemporalAccessor {
            val cleanStr = str.trim().replace("\\0", "")
            for (dp in FILENAME_PATTERNS) {
                try {
                    return dp.match_parse(cleanStr)
                } catch(_: Exception) {}
            }
            
            throw IllegalArgumentException("Unable to parse date from filename: $str")
        }
    }
}

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
