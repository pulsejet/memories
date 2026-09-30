package gallery.memories.server.controllers

import android.content.Context
import android.util.Log
import gallery.memories.data.local.media.MediaStoreDataSource
import gallery.memories.server.KtorRespond
import io.ktor.http.ContentType
import io.ktor.http.HttpStatusCode
import io.ktor.server.application.ApplicationCall
import io.ktor.server.request.httpMethod
import io.ktor.server.request.uri
import io.ktor.server.response.respond
import io.ktor.server.response.respondOutputStream
import java.io.FileInputStream
import java.io.InputStream
import java.io.OutputStream
import kotlinx.coroutines.Dispatchers
import kotlinx.coroutines.withContext

/**
 * Streams on-device video files to the WebView's <video> element.
 * Range-aware so seeking works; small enough to stay in ServerRouter.
 */
object VideoController {
    private val TAG = VideoController::class.java.simpleName

    /**
     * Only this exact pattern is served locally; anything else falls through
     * to the bridge/proxy. Must stay in sync with NativeX.API.VIDEO_FULL
     * and NAPI.VIDEO_FULL.
     */
    private val VIDEO_FULL = Regex("^/video/full/\\d+$")

    fun isVideo(rawPath: String): Boolean = VIDEO_FULL.matches(rawPath)

    suspend fun serveVideo(
        appCtx: Context,
        call: ApplicationCall,
        dataSource: MediaStoreDataSource,
    ) {
        if (call.request.httpMethod.value.uppercase() != "GET") {
            KtorRespond.plain(call, HttpStatusCode.MethodNotAllowed, "method")
            return
        }

        val rawPath = call.request.uri.substringBefore("?")
        val id = rawPath.trimEnd('/').substringAfterLast("/").toLongOrNull()
        if (id == null) {
            KtorRespond.plain(call, HttpStatusCode.NotFound, "not found")
            return
        }

        val sysImg = try {
            withContext(Dispatchers.IO) { dataSource.getByIds(listOf(id)).firstOrNull() }
        } catch (e: Exception) {
            Log.w(TAG, "Video lookup failed for $id", e)
            null
        }
        if (sysImg == null) {
            KtorRespond.plain(call, HttpStatusCode.NotFound, "not found")
            return
        }

        val contentType = KtorRespond.contentType(
            sysImg.mimeType.takeIf { it.isNotEmpty() },
            ContentType.Video.MP4,
        )
        val resolver = appCtx.applicationContext.contentResolver

        val total: Long = try {
            withContext(Dispatchers.IO) {
                resolver.openFileDescriptor(sysImg.uri, "r")?.use { fd ->
                    fd.statSize.takeIf { it > 0 }
                } ?: sysImg.size.takeIf { it > 0 } ?: -1L
            }
        } catch (e: Exception) {
            Log.w(TAG, "Video size failed for $id", e)
            sysImg.size.takeIf { it > 0 } ?: -1L
        }
        if (total <= 0) {
            KtorRespond.plain(call, HttpStatusCode.InternalServerError, "no size")
            return
        }

        // Full content by default; a Range header narrows it to one 206 slice.
        var start = 0L
        var length = total
        var status = HttpStatusCode.OK
        val rangeHeader = call.request.headers["Range"]
        if (rangeHeader != null) {
            val range = parseRange(rangeHeader, total)
            if (range == null) {
                call.response.headers.append("Content-Range", "bytes */$total")
                call.respond(HttpStatusCode.RequestedRangeNotSatisfiable)
                return
            }
            start = range.first
            length = range.second - range.first + 1
            status = HttpStatusCode.PartialContent
            call.response.headers.append("Content-Range", "bytes $start-${range.second}/$total")
        }
        call.response.headers.append("Accept-Ranges", "bytes")
        call.respondOutputStream(contentType, status, length) {
            withContext(Dispatchers.IO) {
                resolver.openAssetFileDescriptor(sysImg.uri, "r")?.use { afd ->
                    FileInputStream(afd.fileDescriptor).use { fis ->
                        fis.channel.position(afd.startOffset + start)
                        copyExact(fis, length, this@respondOutputStream)
                    }
                } ?: throw Exception("Null afd")
            }
        }
    }

    /** Single-range bytes=start-end, bytes=start-, bytes=-suffix. Null when unsatisfiable. */
    private fun parseRange(header: String, total: Long): Pair<Long, Long>? {
        val v = header.trim().lowercase()
        if (!v.startsWith("bytes=")) return null
        val spec = v.substringAfter("=").split(",").firstOrNull()?.trim() ?: return null
        return try {
            if (spec.startsWith("-")) {
                val suffix = spec.substring(1).toLongOrNull() ?: return null
                if (suffix <= 0) return null
                val start = (total - suffix).coerceAtLeast(0)
                Pair(start, total - 1)
            } else {
                val parts = spec.split("-")
                if (parts.size != 2) return null
                val start = parts[0].toLongOrNull() ?: return null
                val end = parts[1].takeIf { it.isNotEmpty() }?.toLongOrNull() ?: (total - 1)
                if (start < 0 || end < start || start >= total) return null
                Pair(start, end.coerceAtMost(total - 1))
            }
        } catch (_: Exception) {
            null
        }
    }

    private fun copyExact(input: InputStream, n: Long, out: OutputStream) {
        val buf = ByteArray(32768)
        var remaining = n
        while (remaining > 0) {
            val r = input.read(buf, 0, remaining.coerceAtMost(buf.size.toLong()).toInt())
            if (r < 0) break
            out.write(buf, 0, r)
            remaining -= r
        }
    }
}
