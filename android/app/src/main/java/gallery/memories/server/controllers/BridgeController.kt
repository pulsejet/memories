package gallery.memories.server.controllers

import android.util.Log
import android.webkit.WebResourceResponse
import gallery.memories.server.HttpRequest
import gallery.memories.server.HttpWriter
import java.io.BufferedOutputStream

class BridgeController(
    private val bridge: (method: String, url: android.net.Uri) -> WebResourceResponse,
) {
    companion object {
        private val TAG = BridgeController::class.java.simpleName
    }

    fun isBridge(req: HttpRequest): Boolean =
        req.path == "/api" || req.path.startsWith("/api/") ||
            req.path == "/image" || req.path.startsWith("/image/")

    /** Runs the in-app API and serializes it onto the socket. Never throws: failures become 500. */
    fun handle(req: HttpRequest, out: BufferedOutputStream) {
        val uri = android.net.Uri.parse("http://127.0.0.1${req.path}" + (req.query?.let { "?$it" } ?: ""))
        try {
            HttpWriter.writeBridgeResponse(bridge(req.method, uri), out)
        } catch (e: Exception) {
            Log.w(TAG, "Bridge failed: ${e.message}")
            HttpWriter.reply(out, 500, "Internal Error", "{}", mime = "application/json")
        }
        out.flush()
    }
}
