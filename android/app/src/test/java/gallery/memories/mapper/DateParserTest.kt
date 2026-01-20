package gallery.memories.mapper

import androidx.exifinterface.media.ExifInterface
import io.mockk.every
import io.mockk.mockk
import io.mockk.mockkStatic
import io.mockk.unmockkAll
import android.util.Log
import org.junit.jupiter.api.AfterEach
import org.junit.jupiter.api.Assertions.*
import org.junit.jupiter.api.BeforeEach
import org.junit.jupiter.api.Test
import java.time.*

class DateParserTest {

    @BeforeEach
    fun setUp() {
        mockkStatic(Log::class)

        val logCaptor = { tag: String, msg: String ->
            println("[$tag]: $msg")
            0
        }

        every { Log.v(any(), any()) } answers { logCaptor(arg(0), arg(1)) }
        every { Log.d(any(), any()) } answers { logCaptor(arg(0), arg(1)) }
        every { Log.i(any(), any()) } answers { logCaptor(arg(0), arg(1)) }
        every { Log.w(any(), any<String>()) } answers { logCaptor(arg(0), arg(1)) }
        
        // Handling Log.e which often has an optional Throwable
        every { Log.e(any(), any()) } answers { logCaptor(arg(0), arg(1)) }
        every { Log.e(any(), any(), any()) } answers {
            println("[${arg<String>(0)}] ERROR: ${arg<String>(1)} - ${arg<Throwable>(2).message}")
            0
        }
    }

    @AfterEach
    fun tearDown() {
        unmockkAll()
    }

    @Test
    fun `inferEarliestDate should use TAG_DATETIME_ORIGINAL with TAG_OFFSET_TIME_ORIGINAL from Exif`() {
        val exif = mockk<ExifInterface>()
        every { exif.getAttribute(any()) } returns null
        every { exif.getAttribute(ExifInterface.TAG_OFFSET_TIME_ORIGINAL) } returns "+05:00"
        every { exif.getAttribute(ExifInterface.TAG_DATETIME_ORIGINAL) } returns "2023:01:01 12:00:00"

        val result = DateParser.inferEarliestDate(
            exif = exif,
            mimeType = "image/jpeg",
            dateTaken = Instant.now().getEpochSecond(),
            filename = "IMG.jpg",
            mtime = Instant.now().getEpochSecond()
        )

        val expectedEpoch = ZonedDateTime.parse("2023-01-01T07:00:00Z").toEpochSecond()
        assertEquals(expectedEpoch, result.toEpochSecond())
        
        val dayId = DateParser.getDayId(result)
        assertEquals(19358L, dayId)
    }

    @Test
    fun `inferEarliestDate should use Filename - datetime`() {
        val exif = mockk<ExifInterface>()
        every { exif.getAttribute(any()) } returns null
        every { exif.getAttribute(ExifInterface.TAG_DATETIME_ORIGINAL) } returns "2025:01:01 12:00:00"

        val filename = "IMG_20230520_143055.jpg"
        
        val result = DateParser.inferEarliestDate(
            exif = null,
            mimeType = "image/jpeg",
            dateTaken = 0L,
            filename = filename,
            mtime = Instant.now().getEpochSecond()
        )
        
        val expected = ZonedDateTime.parse("2023-05-20T14:30:55Z")
        assertEquals(expected.toEpochSecond(), result.toEpochSecond())
        
        val dayId = DateParser.getDayId(result)
        // 2023-05-20
        assertEquals(19497L, dayId)
    }

    @Test
    fun `inferEarliestDate should use Filename - date`() {
        val exif = mockk<ExifInterface>()
        every { exif.getAttribute(any()) } returns null
        every { exif.getAttribute(ExifInterface.TAG_DATETIME_ORIGINAL) } returns "2025:01:01 12:00:00"

        val filename = "IMG_20230520.jpg"
        
        val result = DateParser.inferEarliestDate(
            exif = null,
            mimeType = "image/jpeg",
            dateTaken = 0L,
            filename = filename,
            mtime = Instant.now().getEpochSecond()
        )
        
        val expected = ZonedDateTime.parse("2023-05-20T00:00:00Z")
        assertEquals(expected.toEpochSecond(), result.toEpochSecond())
        
        val dayId = DateParser.getDayId(result)
        // 2023-05-20
        assertEquals(19497L, dayId)
    }

    @Test
    fun `inferEarliestDate should use dateTaken`() {
        val exif = mockk<ExifInterface>()
        every { exif.getAttribute(any()) } returns null

        val dateTaken = 1672531200L // 2023-01-01 00:00:00 UTC
        
        val result = DateParser.inferEarliestDate(
            exif = exif,
            mimeType = "image/jpeg",
            dateTaken = dateTaken,
            filename = "random_name.jpg",
            mtime = Instant.now().getEpochSecond()
        )

        assertEquals(dateTaken, result.toEpochSecond())
        
        val dayId = DateParser.getDayId(result)
        assertEquals(19358L, dayId)
    }

    @Test
    fun `inferEarliestDate should use GPS paired datetime`() {
        val exif = mockk<ExifInterface>()
        every { exif.getAttribute(any()) } returns null
        every { exif.getAttribute(ExifInterface.TAG_GPS_DATESTAMP) } returns "2023:01:01"
        every { exif.getAttribute(ExifInterface.TAG_GPS_TIMESTAMP) } returns "12:00:00"

        val result = DateParser.inferEarliestDate(
            exif = exif,
            mimeType = "image/jpeg",
            dateTaken = Instant.now().getEpochSecond(),
            filename = "IMG.jpg",
            mtime = Instant.now().getEpochSecond()
        )

        // GPS time is UTC.
        val expected = ZonedDateTime.parse("2023-01-01T12:00:00Z")
        assertEquals(expected.toEpochSecond(), result.toEpochSecond())
    }

    @Test
    fun `inferEarliestDate should treat video as UTC and shift to Exif Zone`() {        
        val exif = mockk<ExifInterface>()
        every { exif.getAttribute(any()) } returns null
        every { exif.getAttribute(ExifInterface.TAG_DATETIME_ORIGINAL) } returns "2023:01:01 12:00:00"
        every { exif.getAttribute(ExifInterface.TAG_OFFSET_TIME_ORIGINAL) } returns "+02:00"

        val result = DateParser.inferEarliestDate(
            exif = exif,
            mimeType = "video/mp4",
            dateTaken = Instant.now().getEpochSecond(),
            filename = "VID.mp4",
            mtime = Instant.now().getEpochSecond()
        )

        // 2023-01-01 12:00:00 treated as UTC.
        val expected = ZonedDateTime.parse("2023-01-01T12:00:00Z")
        
        assertEquals(expected.toEpochSecond(), result.toEpochSecond())
        assertEquals(ZoneId.of("+02:00"), result.zone)
        
        // Day ID should be calculated from UTC
        val dayId = DateParser.getDayId(result)
        assertEquals(19358L, dayId) // 2023-01-01
        // (12:00 UTC is still 2023-01-01)
    }

    @Test
    fun `inferEarliestDate should prioritize fields with defined time of day, among fields with the same date`() {
        val exif = mockk<ExifInterface>()
        every { exif.getAttribute(any()) } returns null
        every { exif.getAttribute(ExifInterface.TAG_DATETIME_ORIGINAL) } returns "2023-05-20 12:00:00"

        val filename = "IMG_20230520.jpg"
        
        val result = DateParser.inferEarliestDate(
            exif = exif,
            mimeType = "image/jpeg",
            dateTaken = 0L,
            filename = filename,
            mtime = Instant.now().getEpochSecond()
        )
        
        val expected = ZonedDateTime.parse("2023-05-20T12:00:00Z")
        assertEquals(expected.toEpochSecond(), result.toEpochSecond())
        
        val dayId = DateParser.getDayId(result)
        // 2023-05-20
        assertEquals(19497L, dayId)
    }
}
