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
import kotlinx.coroutines.withContext
import kotlinx.coroutines.withTimeout
import org.json.JSONObject

/**
 * Receives images for the hole image below the WebView.
 * POST-only; the response is sent only after the image view has
 * rendered, so the caller can punch the hole right away.
 * Must stay in sync with NativeX.API.VIEW_IMAGE and NAPI.VIEW_IMAGE
 * (plus the VIEW_IMAGE_LOCAL variants).
 */
object ViewImageController {
    private val TAG = ViewImageController::class.java.simpleName

    /** Max accepted body; previews are small, large originals still fit. */
    private const val MAX_BYTES = 200 * 1024 * 1024

    /** Render wait: decode is local, this only guards against a stuck view. */
    private const val RENDER_TIMEOUT_MS = 15_000L

    fun isViewImage(rawPath: String): Boolean = rawPath == "/api/view/image"

    fun isViewImageLocal(rawPath: String): Boolean = rawPath == "/api/view/image/local"

    /**
     * Show the on-device copy of an indexed photo. POSTs JSON
     * {"uri": ...} with the MediaStore URI from the day response;
     * resolves after render.
     */
    suspend fun serveLocalImage(call: ApplicationCall, holeImage: NativeHoleImage) {
        if (call.request.httpMethod.value.uppercase() != "POST") {
            KtorRespond.plain(call, HttpStatusCode.MethodNotAllowed, "method")
            return
        }
        val body = try {
            withContext(Dispatchers.IO) { call.receiveStream().readBytes().toString(Charsets.UTF_8) }
            } catch (e: Exception) {
            KtorRespond.plain(call, HttpStatusCode.BadRequest, "bad request")
            return
        }
        val uriStr = runCatching { JSONObject(body).optString("uri") }.getOrNull().orEmpty()
        val uri = runCatching { android.net.Uri.parse(uriStr) }.getOrNull()
        if (uri == null || uri.scheme != android.content.ContentResolver.SCHEME_CONTENT ||
            uri.authority != android.provider.MediaStore.AUTHORITY
        ) {
            KtorRespond.plain(call, HttpStatusCode.BadRequest, "bad request")
            return
        }
        renderAndRespond(call) { seq -> holeImage.setImageUri(uri, seq) }
    }

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
        renderAndRespond(call) { seq -> holeImage.setImageBytes(bytes, seq) }
    }

    /** Await render for the query seq and answer {"ok": ...}. */
    private suspend fun renderAndRespond(call: ApplicationCall, render: suspend (Long) -> Boolean) {
        val seq = call.parameters["seq"]?.toLongOrNull() ?: 0L
        val ok = try {
            withTimeout(RENDER_TIMEOUT_MS) { render(seq) }
        } catch (e: Exception) {
            Log.w(TAG, "View image render failed", e)
            KtorRespond.plain(call, HttpStatusCode.InternalServerError, "error")
            return
        }
        call.respondText(
            JSONObject(mapOf("ok" to ok)).toString(),
            ContentType.Application.Json,
            HttpStatusCode.OK,
        )
    }
}
