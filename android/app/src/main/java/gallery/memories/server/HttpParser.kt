package gallery.memories.server

import java.io.ByteArrayOutputStream
import java.io.File
import java.io.FileOutputStream
import java.io.InputStream
import java.io.OutputStream

/**
 * Minimal HTTP/1.1 request parser: origin- and absolute-form targets,
 * headers lowercased and merged.
 */
object HttpParser {
    /** Bodies up to this size stay in memory; larger ones spill to [HttpRequest.bodyFile]. */
    const val MAX_BODY_MEMORY = 1024 * 1024

    /** Hard cap on any request body; larger requests are rejected. */
    const val MAX_BODY_TOTAL = 256 * 1024 * 1024

    @Throws(Exception::class)
    fun readRequest(input: InputStream, cacheDir: File, out: OutputStream? = null): HttpRequest {
        val head = readHead(input, 65536)
        val lines = String(head, Charsets.ISO_8859_1).split("\r\n")
        if (lines.isEmpty()) throw Exception("Empty request")
        val requestLine = lines[0].split(" ")
        if (requestLine.size < 3) throw Exception("Bad request line")
        val method = requestLine[0].uppercase()
        var target = requestLine[1]
        if (target.startsWith("http://") || target.startsWith("https://")) {
            val afterScheme = target.substringAfter("://").substringAfter("/", "")
            target = "/$afterScheme"
        }
        val path = target.substringBefore("?")
        val query = target.substringAfter("?", "").ifEmpty { null }
        val headers = mutableMapOf<String, String>()
        for (i in 1 until lines.size) {
            val line = lines[i]
            if (line.isEmpty()) continue
            val idx = line.indexOf(":")
            if (idx <= 0) continue
            val name = line.substring(0, idx).trim().lowercase()
            val value = line.substring(idx + 1).trim()
            headers[name] = if (headers.containsKey(name)) headers[name] + ", " + value else value
        }
        // Answer 100-continue before blocking on the body.
        if (out != null && headers["expect"]?.contains("100-continue") == true) {
            out.write("HTTP/1.1 100 Continue\r\n\r\n".toByteArray(Charsets.ISO_8859_1))
            out.flush()
        }
        var body: ByteArray? = null
        var bodyFile: File? = null
        val contentLength = headers["content-length"]?.toLongOrNull()
        if (contentLength != null && contentLength > 0) {
            if (contentLength > MAX_BODY_TOTAL) throw Exception("Body too large")
            if (contentLength > MAX_BODY_MEMORY) {
                bodyFile = streamExactToFile(input, contentLength, cacheDir)
            } else {
                body = readExact(input, contentLength)
            }
        } else if (headers["transfer-encoding"]?.contains("chunked") == true) {
            val spooled = readChunkedBody(input, cacheDir)
            body = spooled.first
            bodyFile = spooled.second
        }
        return HttpRequest(method, path, query, headers, body, bodyFile)
    }

    @Throws(Exception::class)
    private fun readHead(input: InputStream, max: Int): ByteArray {
        val buf = ByteArrayOutputStream()
        val tail = ByteArray(4)
        var tailLen = 0
        while (buf.size() < max) {
            val b = input.read()
            if (b < 0) break
            buf.write(b)
            tail[tailLen % 4] = b.toByte()
            tailLen++
            if (tailLen >= 4 &&
                tail[(tailLen - 4) % 4] == '\r'.code.toByte() &&
                tail[(tailLen - 3) % 4] == '\n'.code.toByte() &&
                tail[(tailLen - 2) % 4] == '\r'.code.toByte() &&
                tail[(tailLen - 1) % 4] == '\n'.code.toByte()
            ) return buf.toByteArray()
        }
        throw Exception("Header too large or stream ended")
    }

    @Throws(Exception::class)
    private fun readExact(input: InputStream, n: Long): ByteArray {
        val out = ByteArrayOutputStream(n.coerceAtMost(MAX_BODY_TOTAL.toLong()).toInt())
        val buf = ByteArray(32768)
        var remaining = n
        while (remaining > 0) {
            val r = input.read(buf, 0, remaining.coerceAtMost(buf.size.toLong()).toInt())
            if (r < 0) throw Exception("Stream ended early")
            out.write(buf, 0, r)
            remaining -= r
        }
        return out.toByteArray()
    }

    /**
     * Chunked body with overflow to disk: buffers in memory up to
     * MAX_BODY_MEMORY, then spills to a temp file instead of growing the heap.
     */
    @Throws(Exception::class)
    private fun readChunkedBody(input: InputStream, cacheDir: File): Pair<ByteArray?, File?> {
        var mem: ByteArrayOutputStream? = ByteArrayOutputStream()
        var file: FileOutputStream? = null
        var tmp: File? = null
        var total = 0L
        try {
            while (true) {
                val line = readLine(input)
                val size = line.substringBefore(";").trim().toLongOrNull(16)
                    ?: throw Exception("Bad chunk size")
                if (size == 0L) {
                    while (readLine(input).isNotEmpty()) {}
                    break
                }
                if (total + size > MAX_BODY_TOTAL) throw Exception("Body too large")
                if (file == null && total + size > MAX_BODY_MEMORY) {
                    tmp = File.createTempFile("proxy", ".body", cacheDir)
                    file = FileOutputStream(tmp)
                    mem?.let { file.write(it.toByteArray()) }
                    mem = null
                }
                if (file != null) {
                    copyExact(input, size, file)
                } else {
                    mem?.write(readExact(input, size))
                }
                total += size
                readLine(input)
            }
        } catch (e: Exception) {
            try { file?.close() } catch (_: Exception) {}
            tmp?.delete()
            throw e
        }
        return if (file != null) {
            try { file.close() } catch (_: Exception) {}
            null to tmp
        } else {
            (mem?.toByteArray() ?: ByteArray(0)) to null
        }
    }

    /** Streams exactly [n] bytes to a temp file without holding them on the heap. */
    @Throws(Exception::class)
    private fun streamExactToFile(input: InputStream, n: Long, cacheDir: File): File {
        val tmp = File.createTempFile("proxy", ".body", cacheDir)
        try {
            FileOutputStream(tmp).use { copyExact(input, n, it) }
        } catch (e: Exception) {
            tmp.delete()
            throw e
        }
        return tmp
    }

    @Throws(Exception::class)
    private fun copyExact(input: InputStream, n: Long, out: OutputStream) {
        val buf = ByteArray(32768)
        var remaining = n
        while (remaining > 0) {
            val r = input.read(buf, 0, remaining.coerceAtMost(buf.size.toLong()).toInt())
            if (r < 0) throw Exception("Stream ended early")
            out.write(buf, 0, r)
            remaining -= r
        }
    }

    @Throws(Exception::class)
    private fun readLine(input: InputStream): String {
        val sb = StringBuilder()
        while (true) {
            val b = input.read()
            if (b < 0) throw Exception("Stream ended in line")
            if (b == '\n'.code) break
            if (b != '\r'.code) sb.append(b.toChar())
        }
        return sb.toString()
    }
}
