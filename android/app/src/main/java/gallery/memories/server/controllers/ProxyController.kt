package gallery.memories.server.controllers

import gallery.memories.data.remote.http.AuthState
import gallery.memories.server.HttpRequest
import gallery.memories.server.HttpWriter
import gallery.memories.server.ServerConfig
import gallery.memories.server.session.SessionManager
import okhttp3.MediaType.Companion.toMediaTypeOrNull
import okhttp3.Request
import okhttp3.RequestBody.Companion.asRequestBody
import okhttp3.RequestBody.Companion.toRequestBody
import java.io.BufferedOutputStream
import java.io.IOException

class ProxyController(
    private val auth: AuthState,
    private val session: SessionManager,
    private val originProvider: () -> String,
) {
    /** Session bootstrap traffic must not trigger itself. */
    fun recoverable(req: HttpRequest): Boolean =
        !req.path.endsWith("/login") && !req.path.endsWith("/csrftoken")

    /**
     * Proxies one request upstream, healing the session first when needed and
     * replaying once on 401/412 so the client sees the real upstream error.
     * Upstream network failures throw [UpstreamNetworkException] so the caller
     * can drop the connection (axios ERR_NETWORK) instead of faking an HTTP 5xx.
     */
    fun forward(req: HttpRequest, config: ServerConfig, out: BufferedOutputStream, retried: Boolean = false) {
        try {
            doForward(req, config, out, retried)
        } catch (e: UpstreamNetworkException) {
            throw e
        } catch (e: IOException) {
            throw UpstreamNetworkException(e)
        }
    }

    private fun doForward(req: HttpRequest, config: ServerConfig, out: BufferedOutputStream, retried: Boolean = false) {
        val url = config.serverOrigin + req.path + (req.query?.let { "?$it" } ?: "")
        if (!retried && session.csrfToken == null && recoverable(req) && auth.credentials() != null) {
            session.ensureSession()
        }
        val builder = Request.Builder().url(url)
        for ((name, value) in req.headers) {
            if (name == "host" || name == "content-length" || name == "transfer-encoding" ||
                name == "connection" || name == "cookie" || name == "accept-encoding"
            ) continue
            builder.header(name, value)
        }
        auth.authHeader()?.let { builder.header("Authorization", it) }
        val mediaType = req.headers["content-type"]?.toMediaTypeOrNull()
        var body = when {
            req.bodyFile != null -> req.bodyFile.asRequestBody(mediaType)
            req.body != null -> req.body.toRequestBody(mediaType)
            else -> null
        }
        if (body == null && requiresBody(req.method)) {
            body = ByteArray(0).toRequestBody(mediaType)
        }
        builder.method(req.method, body)
        val replay = session.client().newCall(builder.build()).execute().use { res ->
            if (!retried && (res.code == 401 || res.code == 412) && recoverable(req) && auth.credentials() != null) {
                true
            } else {
                streamResponse(res, config, out)
                false
            }
        }
        if (replay) {
            session.clearToken()
            session.ensureSession()
            forward(req, config, out, retried = true)
        }
    }

    private fun streamResponse(res: okhttp3.Response, config: ServerConfig, out: BufferedOutputStream) {
        val respHeaders = mutableMapOf<String, String>()
        for (i in 0 until res.headers.size) {
            val name = res.headers.name(i).lowercase()
            if (name == "content-encoding" || name == "transfer-encoding" ||
                name == "content-length" || name == "content-type" ||
                name == "connection" || name == "keep-alive" || name == "set-cookie"
            ) continue
            if (name == "location") {
                respHeaders["Location"] = rewriteLocation(res.headers.value(i), config)
                continue
            }
            respHeaders[res.headers.name(i)] = res.headers.value(i)
        }
        val ctype = res.body.contentType()?.toString() ?: "application/octet-stream"
        respHeaders.putIfAbsent("Content-Type", ctype)
        respHeaders["Connection"] = "close"
        val length = res.body.contentLength()
        if (length >= 0) {
            respHeaders["Content-Length"] = length.toString()
            HttpWriter.writeHeaders(out, res.code, HttpWriter.reason(res.code), respHeaders)
            res.body.byteStream().use { it.copyTo(out) }
        } else {
            respHeaders["Transfer-Encoding"] = "chunked"
            HttpWriter.writeHeaders(out, res.code, HttpWriter.reason(res.code), respHeaders)
            res.body.byteStream().use { HttpWriter.streamChunked(it, out) }
        }
    }

    /** Rewrites upstream redirects onto the local origin so the client stays same-origin. */
    private fun rewriteLocation(location: String, config: ServerConfig): String =
        if (location.startsWith(config.serverOrigin)) {
            originProvider() + location.substring(config.serverOrigin.length)
        } else location

    /** OkHttp rejects a null body for these methods, even when content-length is 0. */
    private fun requiresBody(method: String): Boolean = when (method) {
        "POST", "PUT", "PATCH", "PROPPATCH", "REPORT" -> true
        else -> false
    }
}
