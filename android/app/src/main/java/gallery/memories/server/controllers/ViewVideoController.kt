package gallery.memories.server.controllers

import android.net.Uri
import android.util.Log
import gallery.memories.server.KtorRespond
import gallery.memories.ui.video.NativeHoleVideo
import io.ktor.http.ContentType
import io.ktor.http.HttpStatusCode
import io.ktor.server.application.ApplicationCall
import io.ktor.server.request.httpMethod
import io.ktor.server.request.receiveStream
import io.ktor.server.response.respondText
import kotlinx.coroutines.Dispatchers
import kotlinx.coroutines.withContext
import org.json.JSONObject

/**
 * Points the hole video below the WebView at a URL (remote https or local
 * content://). Playback starts immediately; the response is sent at once
 * without waiting for the first frame, so the caller can punch the hole
 * right away. The view itself appears once frames render.
 * Must stay in sync with NativeX.API.VIEW_VIDEO and NAPI.VIEW_VIDEO.
 */
object ViewVideoController {
    private val TAG = ViewVideoController::class.java.simpleName

    fun isViewVideo(rawPath: String): Boolean = rawPath == "/api/view/video"

    // Remote streams go through the authed HTTP stack; local files arrive as content://.
    private fun isAllowed(url: String, scheme: String?): Boolean =
        url.isNotEmpty() && (scheme == "http" || scheme == "https" || scheme == "content")

    suspend fun serveVideo(call: ApplicationCall, holeVideo: NativeHoleVideo) {
        // POST only; the URL rides in a JSON body so auth cookies stay out of logs.
        if (call.request.httpMethod.value.uppercase() != "POST") {
            KtorRespond.plain(call, HttpStatusCode.MethodNotAllowed, "method")
            return
        }

        // Stream the body off the request thread.
        val body = try {
            withContext(Dispatchers.IO) { call.receiveStream().readBytes().toString(Charsets.UTF_8) }
        } catch (e: Exception) {
            Log.w(TAG, "View video read failed", e)
            KtorRespond.plain(call, HttpStatusCode.BadRequest, "read")
            return
        }

        // Parse defensively; a malformed body is a 400, never a crash.
        val url = runCatching { JSONObject(body).optString("url") }.getOrNull().orEmpty()
        val scheme = runCatching { Uri.parse(url).scheme?.lowercase() }.getOrNull()
        if (!isAllowed(url, scheme)) {
            KtorRespond.plain(call, HttpStatusCode.BadRequest, "bad url")
            return
        }

        // Monotonic per-slide counter; stale requests lose to newer ones.
        val seq = call.parameters["seq"]?.toLongOrNull() ?: 0L

        // Hand off to the UI thread and ack at once. The caller punches the
        // hole immediately; the view appears on the first rendered frame.
        holeVideo.setVideoUrl(url, seq)

        call.respondText(
            JSONObject(mapOf("ok" to true)).toString(),
            ContentType.Application.Json,
            HttpStatusCode.OK,
        )
    }
}
