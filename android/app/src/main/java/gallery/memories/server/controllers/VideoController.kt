package gallery.memories.server.controllers

import android.content.Context
import android.util.Log
import gallery.memories.data.local.media.MediaStoreDataSource
import gallery.memories.server.HttpRequest
import gallery.memories.server.HttpWriter
import java.io.BufferedOutputStream
import java.io.FileInputStream
import java.io.InputStream

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
    private val VIDEO_FULL = Regex("^/video/full/\\d+/?$")

    fun isVideo(req: HttpRequest): Boolean = VIDEO_FULL.matches(req.path)

    fun serveVideo(
        appCtx: Context,
        req: HttpRequest,
        out: BufferedOutputStream,
        dataSource: MediaStoreDataSource,
    ) {
        if (req.method != "GET" && req.method != "HEAD") {
            HttpWriter.reply(out, 405, "Method Not Allowed", "method")
            return
        }
        val isHead = req.method == "HEAD"

        val id = req.path.trimEnd('/').substringAfterLast("/").toLongOrNull()
        if (id == null) {
            HttpWriter.reply(out, 404, "Not Found", "not found")
            return
        }

        val sysImg = try {
            dataSource.getByIds(listOf(id)).firstOrNull()
        } catch (e: Exception) {
            Log.w(TAG, "Video lookup failed for $id", e)
            null
        }
        if (sysImg == null) {
            HttpWriter.reply(out, 404, "Not Found", "not found")
            return
        }

        val mime = sysImg.mimeType.takeIf { it.isNotEmpty() } ?: "video/mp4"
        val resolver = appCtx.applicationContext.contentResolver

        val total: Long = try {
            resolver.openFileDescriptor(sysImg.uri, "r")?.use { fd ->
                fd.statSize.takeIf { it > 0 }
            } ?: sysImg.size.takeIf { it > 0 } ?: -1L
        } catch (e: Exception) {
            Log.w(TAG, "Video size failed for $id", e)
            sysImg.size.takeIf { it > 0 } ?: -1L
        }
        if (total <= 0) {
            HttpWriter.reply(out, 500, "Internal Error", "no size")
            return
        }

        val rangeHeader = req.headers["range"]
        if (rangeHeader == null) {
            val headers = mapOf(
                "Content-Type" to mime,
                "Content-Length" to total.toString(),
                "Accept-Ranges" to "bytes",
            )
            HttpWriter.writeHeaders(out, 200, "OK", headers)
            if (isHead) {
                out.flush()
                return
            }
            try {
                resolver.openInputStream(sysImg.uri)?.use { it.copyTo(out) }
                    ?: throw Exception("Null stream")
            } catch (e: Exception) {
                Log.w(TAG, "Video stream failed for $id", e)
                return
            }
            out.flush()
            return
        }

        val range = parseRange(rangeHeader, total)
        if (range == null) {
            HttpWriter.writeHeaders(
                out, 416, "Range Not Satisfiable",
                mapOf("Content-Range" to "bytes */$total"),
            )
            out.flush()
            return
        }
        val (start, end) = range
        val length = end - start + 1
        val headers = mapOf(
            "Content-Type" to mime,
            "Content-Length" to length.toString(),
            "Content-Range" to "bytes $start-$end/$total",
            "Accept-Ranges" to "bytes",
        )
        HttpWriter.writeHeaders(out, 206, "Partial Content", headers)
        if (isHead) {
            out.flush()
            return
        }
        try {
            resolver.openAssetFileDescriptor(sysImg.uri, "r")?.use { afd ->
                FileInputStream(afd.fileDescriptor).use { fis ->
                    fis.channel.position(afd.startOffset + start)
                    copyExact(fis, length, out)
                }
            } ?: throw Exception("Null afd")
        } catch (e: Exception) {
            Log.w(TAG, "Video range stream failed for $id", e)
            return
        }
        out.flush()
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

    private fun copyExact(input: InputStream, n: Long, out: BufferedOutputStream) {
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
