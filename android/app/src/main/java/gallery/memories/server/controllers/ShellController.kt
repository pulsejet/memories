package gallery.memories.server.controllers

import android.util.Log
import gallery.memories.server.HttpRequest
import gallery.memories.server.HttpWriter
import gallery.memories.server.ServerConfig
import gallery.memories.server.shell.ShellPage
import org.json.JSONObject
import java.io.BufferedOutputStream

object ShellController {
    private val TAG = ShellController::class.java.simpleName

    fun isDebug(req: HttpRequest): Boolean =
        req.query?.split("&")?.any { it == "shelldebug" || it.startsWith("shelldebug=") } == true

    /** Serves the locally built app shell; false when no snapshot describe is available. */
    fun serveShell(
        config: ServerConfig,
        req: HttpRequest,
        out: BufferedOutputStream,
        describe: JSONObject?,
        csrfToken: String?,
        user: String?,
    ): Boolean {
        if (describe == null) return false
        return try {
            val nonce = java.util.UUID.randomUUID().toString()
            val html = ShellPage.build(describe, config.webRoot, config.baseUrl, isDebug(req), csrfToken, user, nonce)
            HttpWriter.writeStatus(
                out, 200, "OK", "text/html; charset=utf-8", html.toByteArray(),
                noStore = true, extraHeaders = mapOf("Content-Security-Policy" to ShellPage.csp(nonce)),
            )
            out.flush()
            true
        } catch (e: Exception) {
            Log.w(TAG, "serveShell failed: ${e.message}")
            false
        }
    }
}
