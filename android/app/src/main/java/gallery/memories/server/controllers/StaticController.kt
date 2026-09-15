package gallery.memories.server.controllers

import android.content.Context
import gallery.memories.server.KtorRespond
import io.ktor.http.HttpStatusCode
import io.ktor.server.application.ApplicationCall
import io.ktor.server.request.httpMethod
import io.ktor.server.request.uri
import io.ktor.server.response.respond
import io.ktor.server.response.respondBytes
import io.ktor.server.response.respondOutputStream
import java.io.File
import kotlinx.coroutines.Dispatchers
import kotlinx.coroutines.withContext

/** Serves bundled APK assets and snapshot files. All lookups are basename-only: no traversal. */
object StaticController {
    /** Basename-only lookup; rejects traversal and non-GET. Replies 405/404 itself, null means replied. */
    suspend fun staticName(call: ApplicationCall): String? {
        if (call.request.httpMethod.value.uppercase() != "GET") {
            KtorRespond.plain(call, HttpStatusCode.MethodNotAllowed, "method")
            return null
        }
        // substringAfterLast strips any directory, so "/" cannot survive; ".." is the traversal case.
        val name = call.request.uri.substringBefore("?").substringAfterLast("/")
        if (name.isEmpty() || name.contains("..")) {
            KtorRespond.plain(call, HttpStatusCode.NotFound, "not found")
            return null
        }
        return name
    }

    /** Serves a welcome/waiting page or override file bundled in the APK. Replies 404 itself when missing. */
    suspend fun serveApkFile(appCtx: Context, call: ApplicationCall) {
        val name = staticName(call) ?: return
        val mime = when (name.substringAfterLast(".", "")) {
            "html" -> "text/html; charset=utf-8"
            "css" -> "text/css; charset=utf-8"
            "svg" -> "image/svg+xml"
            "png" -> "image/png"
            "js" -> "text/javascript; charset=utf-8"
            else -> "application/octet-stream"
        }
        val bytes = try {
            withContext(Dispatchers.IO) { appCtx.assets.open(name).use { it.readBytes() } }
        } catch (_: Exception) {
            KtorRespond.plain(call, HttpStatusCode.NotFound, "not found")
            return
        }
        call.response.headers.append("Cache-Control", "no-store")
        call.respondBytes(bytes, KtorRespond.contentType(mime), HttpStatusCode.OK)
    }

    /**
     * Serves one file from a snapshot [dir] (js/css), ETag-validated so repeat
     * launches reuse the cache instead of reparsing. Replies 404 itself when missing.
     */
    suspend fun serveFile(call: ApplicationCall, dir: File, mime: String) {
        val name = staticName(call) ?: return
        val file = File(dir, name)
        val meta: Pair<Long, Long>? = try {
            withContext(Dispatchers.IO) {
                if (!file.isFile) null else file.length() to file.lastModified()
            }
        } catch (_: Exception) {
            null
        }
        if (meta == null) {
            KtorRespond.plain(call, HttpStatusCode.NotFound, "not found")
            return
        }
        val (length, lastModified) = meta
        val etag = "\"${lastModified.toString(16)}-${length.toString(16)}\""
        if (call.request.headers["If-None-Match"]?.split(",")?.any { it.trim() == etag || it.trim() == "*" } == true) {
            call.response.headers.append("ETag", etag)
            call.respond(HttpStatusCode.NotModified)
            return
        }
        call.response.headers.append("ETag", etag)
        call.response.headers.append("Cache-Control", "no-cache")
        call.respondOutputStream(KtorRespond.contentType(mime), HttpStatusCode.OK, length) {
            withContext(Dispatchers.IO) { file.inputStream().use { it.copyTo(this@respondOutputStream) } }
        }
    }
}
