package gallery.memories.server.controllers

import android.content.Context
import gallery.memories.server.HttpRequest
import gallery.memories.server.HttpWriter
import java.io.BufferedOutputStream
import java.io.File

/** Serves bundled APK assets and snapshot files. All lookups are basename-only: no traversal. */
object StaticController {
    /** Basename-only lookup; rejects traversal and non-GET. Replies 405/404 itself, null means replied. */
    fun staticName(req: HttpRequest, out: BufferedOutputStream): String? {
        if (req.method != "GET") {
            HttpWriter.reply(out, 405, "Method Not Allowed", "method")
            return null
        }
        // substringAfterLast strips any directory, so "/" cannot survive; ".." is the traversal case.
        val name = req.path.substringAfterLast("/")
        if (name.isEmpty() || name.contains("..")) {
            HttpWriter.reply(out, 404, "Not Found", "not found")
            return null
        }
        return name
    }

    /** Serves a welcome/waiting page or override file bundled in the APK. Replies 404 itself when missing. */
    fun serveApkFile(appCtx: Context, req: HttpRequest, out: BufferedOutputStream) {
        val name = staticName(req, out) ?: return
        val mime = when (name.substringAfterLast(".", "")) {
            "html" -> "text/html; charset=utf-8"
            "css" -> "text/css; charset=utf-8"
            "svg" -> "image/svg+xml"
            "png" -> "image/png"
            "js" -> "text/javascript; charset=utf-8"
            else -> "application/octet-stream"
        }
        val bytes = try {
            appCtx.assets.open(name).use { it.readBytes() }
        } catch (_: Exception) {
            HttpWriter.reply(out, 404, "Not Found", "not found")
            return
        }
        HttpWriter.writeStatus(out, 200, "OK", mime, bytes, noStore = true)
        out.flush()
    }

    /** Serves one file from a snapshot [dir] (js/css). Replies 404 itself when missing. */
    fun serveFile(req: HttpRequest, out: BufferedOutputStream, dir: File, mime: String) {
        val name = staticName(req, out) ?: return
        val file = File(dir, name)
        if (!file.isFile) {
            HttpWriter.reply(out, 404, "Not Found", "not found")
            return
        }
        HttpWriter.sendStream(out, mime, file.length()) { file.inputStream() }
    }
}
