package gallery.memories.server

import android.webkit.WebResourceResponse
import java.io.BufferedOutputStream
import java.io.InputStream

object HttpWriter {
    fun reply(out: BufferedOutputStream, code: Int, phrase: String, body: String, mime: String = "text/plain") {
        writeStatus(out, code, phrase, mime, body.toByteArray())
        out.flush()
    }

    fun writeStatus(
        out: BufferedOutputStream,
        code: Int,
        phrase: String,
        mime: String,
        body: ByteArray,
        noStore: Boolean = false,
        extraHeaders: Map<String, String> = emptyMap(),
    ) {
        val headers = mutableMapOf(
            "Content-Type" to mime,
            "Content-Length" to body.size.toString(),
            "Connection" to "close",
        )
        if (noStore) headers["Cache-Control"] = "no-store"
        headers.putAll(extraHeaders)
        writeHeaders(out, code, phrase, headers)
        out.write(body)
        out.flush()
    }

    fun writeHeaders(out: BufferedOutputStream, code: Int, phrase: String, headers: Map<String, String>) {
        val sb = StringBuilder()
        sb.append("HTTP/1.1 ").append(code).append(' ').append(phrase).append("\r\n")
        for ((name, value) in headers) sb.append(name).append(": ").append(value).append("\r\n")
        sb.append("\r\n")
        out.write(sb.toString().toByteArray(Charsets.ISO_8859_1))
    }

    fun streamChunked(input: InputStream, out: BufferedOutputStream) {
        val buf = ByteArray(32768)
        while (true) {
            val r = input.read(buf)
            if (r < 0) break
            out.write(Integer.toHexString(r).toByteArray(Charsets.US_ASCII))
            out.write("\r\n".toByteArray(Charsets.US_ASCII))
            out.write(buf, 0, r)
            out.write("\r\n".toByteArray(Charsets.US_ASCII))
        }
        out.write("0\r\n\r\n".toByteArray(Charsets.US_ASCII))
        out.flush()
    }

    fun writeBridgeResponse(res: WebResourceResponse, out: BufferedOutputStream) {
        val headers = mutableMapOf<String, String>()
        var mime = "application/octet-stream"
        try {
            if (res.mimeType != null) {
                mime = res.mimeType
                if (res.encoding != null) mime += "; charset=${res.encoding}"
            }
            res.responseHeaders?.let { headers.putAll(it) }
        } catch (_: Exception) {}
        headers.putIfAbsent("Content-Type", mime)
        headers["Connection"] = "close"
        // WebResourceResponse defaults to status 0; never write that raw onto the wire.
        val code = try { res.statusCode.takeIf { it in 100..599 } ?: 200 } catch (_: Exception) { 200 }
        val phrase = try { res.reasonPhrase ?: reason(code) } catch (_: Exception) { reason(code) }
        val body = try { res.data } catch (_: Exception) { null }
        if (body != null) {
            headers["Transfer-Encoding"] = "chunked"
            writeHeaders(out, code, phrase, headers)
            body.use { streamChunked(it, out) }
        } else {
            headers["Content-Length"] = "0"
            writeHeaders(out, code, phrase, headers)
        }
        out.flush()
    }

    fun sendStream(out: BufferedOutputStream, mime: String, length: Long, open: () -> InputStream) {
        writeHeaders(
            out, 200, "OK",
            mapOf("Content-Type" to mime, "Content-Length" to length.toString(), "Cache-Control" to "no-store", "Connection" to "close"),
        )
        open().use { it.copyTo(out) }
        out.flush()
    }

    fun reason(code: Int): String = when (code) {
        200 -> "OK"
        201 -> "Created"
        204 -> "No Content"
        206 -> "Partial Content"
        207 -> "Multi-Status"
        301 -> "Moved Permanently"
        302 -> "Found"
        303 -> "See Other"
        304 -> "Not Modified"
        307 -> "Temporary Redirect"
        308 -> "Permanent Redirect"
        400 -> "Bad Request"
        401 -> "Unauthorized"
        403 -> "Forbidden"
        404 -> "Not Found"
        405 -> "Method Not Allowed"
        409 -> "Conflict"
        412 -> "Precondition Failed"
        415 -> "Unsupported Media Type"
        423 -> "Locked"
        500 -> "Internal Error"
        501 -> "Not Implemented"
        502 -> "Bad Gateway"
        503 -> "Unavailable"
        507 -> "Insufficient Storage"
        else -> "Status"
    }
}
