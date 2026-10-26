package gallery.memories.server.controllers

import android.util.Log
import gallery.memories.server.KtorRespond
import gallery.memories.ui.image.NativeHoleImage
import io.ktor.http.ContentType
import io.ktor.http.HttpStatusCode
import io.ktor.server.application.ApplicationCall
import io.ktor.server.request.httpMethod
import io.ktor.server.request.receiveStream
import io.ktor.server.response.respondText
import kotlinx.coroutines.Dispatchers
import kotlinx.coroutines.TimeoutCancellationException
import kotlinx.coroutines.withContext
import kotlinx.coroutines.withTimeout
import org.json.JSONObject

/**
 * Receives raw image bytes for the hole image below the WebView.
 * POST-only; the response is sent only after the image view has
 * rendered the bytes, so the caller can punch the hole right away.
 * Must stay in sync with NativeX.API.VIEW_IMAGE and NAPI.VIEW_IMAGE.
 */
object ViewImageController {
    private val TAG = ViewImageController::class.java.simpleName

    /** Max accepted body; previews are small, large originals still fit. */
    private const val MAX_BYTES = 200 * 1024 * 1024

    /** Render wait: decode is local, this only guards against a stuck view. */
    private const val RENDER_TIMEOUT_MS = 15_000L

    fun isViewImage(rawPath: String): Boolean = rawPath == "/api/view/image"

    suspend fun serveImage(call: ApplicationCall, holeImage: NativeHoleImage) {
        if (call.request.httpMethod.value.uppercase() != "POST") {
            KtorRespond.plain(call, HttpStatusCode.MethodNotAllowed, "method")
            return
        }
        val bytes = try {
            withContext(Dispatchers.IO) { call.receiveStream().readBytes() }
        } catch (e: Exception) {
            Log.w(TAG, "View image read failed", e)
            KtorRespond.plain(call, HttpStatusCode.BadRequest, "read: ${e.message}")
            return
        }
        if (bytes.isEmpty() || bytes.size > MAX_BYTES) {
            KtorRespond.plain(call, HttpStatusCode.BadRequest, "size: ${bytes.size}")
            return
        }
        val seq = call.parameters["seq"]?.toLongOrNull() ?: 0L
        val rendered = try {
            withTimeout(RENDER_TIMEOUT_MS) {
                 holeImage.setImageBytes(bytes, seq)
            }
        } catch (e: TimeoutCancellationException) {
            Log.w(TAG, "View image render timed out", e)
            KtorRespond.plain(call, HttpStatusCode.InternalServerError, "timeout: ${e.message}")
            return
        } catch (e: Exception) {
            Log.w(TAG, "View image render failed", e)
            KtorRespond.plain(call, HttpStatusCode.InternalServerError, "render: ${e::class.java.simpleName}: ${e.message}")
            return
        }
        call.respondText(
            JSONObject(mapOf("ok" to rendered)).toString(),
            ContentType.Application.Json,
            HttpStatusCode.OK,
        )
    }
}
