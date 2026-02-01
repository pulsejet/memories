package gallery.memories.utility

import android.util.Log
import androidx.exifinterface.media.ExifInterface
import java.time.*
import java.time.format.DateTimeFormatter
import java.time.temporal.ChronoField
import java.util.Date
import java.util.TimeZone
import java.time.temporal.TemporalAccessor
import java.time.temporal.TemporalField
import kotlin.math.floor
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

        private val FILENAME_PATTERNS: List<RegexDateTimeFormatter> = listOf(
            RegexDateTimeFormatter(".*?(\\d{8})_(\\d{6}).*", listOf(DateTimeFormatter.BASIC_ISO_DATE, DateTimeFormatter.ofPattern("HHmmss"))), // Standard Camera/Android/Pixel (e.g., IMG_20230520_143055.jpg or 20230520_143055.mp4)
            RegexDateTimeFormatter(".*?(\\d{8}).*", DateTimeFormatter.BASIC_ISO_DATE), // WhatsApp Image/Video (e.g., IMG-20230520-WA0001.jpg)
            RegexDateTimeFormatter(".*?(\\d{4}-\\d{2}-\\d{2}).*?(\\d{2}\\.\\d{2}\\.\\d{2}).*", listOf(DateTimeFormatter.ISO_DATE, DateTimeFormatter.ofPattern("HH.mm.ss"))), // iOS / Screenshot standard (e.g., Screenshot 2023-05-20 at 14.30.55.png)
            RegexDateTimeFormatter(".*?(\\d{4}-\\d{2}-\\d{2}).*?(\\d{2}-\\d{2}-\\d{2}).*", listOf(DateTimeFormatter.ISO_DATE, DateTimeFormatter.ofPattern("HH-mm-ss"))), // Generic Separators (e.g., 2023-05-20 14-30-55.jpg)
            RegexDateTimeFormatter(".*?(\\d{4}-\\d{2}-\\d{2}).*", DateTimeFormatter.ISO_DATE) // 5. ISO Date Only (e.g., Report_2023-05-20.pdf)
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

        private class RegexDateTimeFormatter {
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
                            else if (e is IndexOutOfBoundsException) throw IllegalArgumentException("RegexDateTimeFormatter object has less capturing groups (${i}) then formatters (${dateFormatters.size})")
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