package gallery.memories.server.controllers

import android.net.Uri
import android.util.Log
import android.webkit.WebResourceResponse
import gallery.memories.server.HttpRequest
import gallery.memories.server.HttpWriter
import java.io.BufferedOutputStream

/** Adapts raw socket requests into the in-app bridge and writes the result back. */
class BridgeController(
    private val bridge: (method: String, url: Uri) -> WebResourceResponse,
) {
    companion object {
        private val TAG = BridgeController::class.java.simpleName
    }

    /** Bridge paths work before login; everything else goes through proxy/static/shell. */
    fun isBridge(req: HttpRequest): Boolean =
        req.path == "/api" || req.path.startsWith("/api/") ||
            req.path == "/image" || req.path.startsWith("/image/")

    /** Runs the in-app API and serializes it onto the socket. Never throws: failures become 500. */
    fun handle(req: HttpRequest, out: BufferedOutputStream) {
        val uri = Uri.parse("http://127.0.0.1${req.path}" + (req.query?.let { "?$it" } ?: ""))
        try {
            HttpWriter.writeBridgeResponse(bridge(req.method, uri), out)
        } catch (e: Exception) {
            Log.w(TAG, "Bridge failed: ${e.message}")
            HttpWriter.reply(out, 500, "Internal Error", "{}", mime = "application/json")
        }
        out.flush()
    }
}
