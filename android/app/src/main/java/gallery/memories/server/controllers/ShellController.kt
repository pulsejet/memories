package gallery.memories.server.controllers

import android.util.Log
import gallery.memories.server.ServerConfig
import gallery.memories.server.shell.ShellPage
import io.ktor.http.ContentType
import io.ktor.http.HttpStatusCode
import io.ktor.http.withCharset
import io.ktor.server.application.ApplicationCall
import io.ktor.server.response.respondText
import java.util.UUID
import org.json.JSONObject

/** Renders the offline app shell for app routes and the local entry page. */
object ShellController {
    private val TAG = ShellController::class.java.simpleName

    /** Serves the locally built app shell; false when no snapshot describe is available. */
    suspend fun serveShell(
        config: ServerConfig,
        call: ApplicationCall,
        describe: JSONObject?,
        user: String?,
    ): Boolean {
        if (describe == null) return false
        return try {
            val nonce = UUID.randomUUID().toString()
            val html = ShellPage.build(describe, config.webRoot, user, nonce)
            call.response.headers.append("Content-Security-Policy", ShellPage.csp(nonce))
            call.response.headers.append("Cache-Control", "no-store")
            call.respondText(html, ContentType.Text.Html.withCharset(Charsets.UTF_8), HttpStatusCode.OK)
            true
        } catch (e: Exception) {
            Log.w(TAG, "serveShell failed: ${e.message}")
            false
        }
    }
}
