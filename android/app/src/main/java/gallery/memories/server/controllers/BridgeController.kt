package gallery.memories.server.controllers

import android.net.Uri
import android.util.Log
import android.webkit.WebResourceResponse
import gallery.memories.server.KtorRespond
import io.ktor.http.ContentType
import io.ktor.http.HttpStatusCode
import io.ktor.server.application.ApplicationCall
import io.ktor.server.request.httpMethod
import io.ktor.server.request.uri
import io.ktor.server.response.respond
import io.ktor.server.response.respondOutputStream
import io.ktor.server.response.respondText
import kotlinx.coroutines.Dispatchers
import kotlinx.coroutines.withContext

/** Adapts Ktor calls into the in-app bridge and writes the result back. */
class BridgeController(
    private val bridge: (method: String, url: Uri) -> WebResourceResponse,
) {
    companion object {
        private val TAG = BridgeController::class.java.simpleName

        /** Framing headers owned by the engine; the bridge must not set these. */
        private val SKIPPED_BRIDGE_HEADERS = setOf(
            "connection", "transfer-encoding", "content-length", "content-type",
        )

        /** Bridge paths work before login; everything else goes through proxy/static/shell. */
        fun isBridge(rawPath: String): Boolean =
            rawPath == "/api" || rawPath.startsWith("/api/") ||
                rawPath == "/image" || rawPath.startsWith("/image/")
    }

    /** Runs the in-app API and responds. Never throws: failures become 500. */
    suspend fun handle(call: ApplicationCall) {
        // Same construction as before: raw target on a dummy origin, so the
        // bridge keeps seeing exactly the paths it used to.
        val uri = Uri.parse("http://127.0.0.1" + call.request.uri)
        val res = try {
            withContext(Dispatchers.IO) { bridge(call.request.httpMethod.value.uppercase(), uri) }
        } catch (e: Exception) {
            Log.w(TAG, "Bridge failed: ${e.message}")
            call.respondText("{}", ContentType.Application.Json, HttpStatusCode.InternalServerError)
            return
        }
        var mime = "application/octet-stream"
        try {
            if (res.mimeType != null) {
                mime = res.mimeType
                if (res.encoding != null) mime += "; charset=${res.encoding}"
            }
        } catch (_: Exception) {}
        val code = try {
            res.statusCode.takeIf { it in 100..599 } ?: 200
        } catch (_: Exception) {
            200
        }
        // WebResourceResponse defaults to status 0; never write that raw onto the wire.
        val status = KtorRespond.status(code)
        try {
            res.responseHeaders?.forEach { (name, value) ->
                if (name.lowercase() !in SKIPPED_BRIDGE_HEADERS) call.response.headers.append(name, value)
            }
        } catch (_: Exception) {}
        val body = try {
            res.data
        } catch (_: Exception) {
            null
        }
        if (body != null) {
            // Bridge streams have no known length; the engine chunks them.
            call.respondOutputStream(KtorRespond.contentType(mime), status) {
                withContext(Dispatchers.IO) { body.use { it.copyTo(this@respondOutputStream) } }
            }
        } else {
            call.respond(status)
        }
    }
}
