package gallery.memories.server.controllers

import gallery.memories.data.remote.http.AuthState
import gallery.memories.data.remote.http.HttpClients
import gallery.memories.server.HttpRequest
import gallery.memories.server.HttpWriter
import gallery.memories.server.ServerConfig
import okhttp3.MediaType.Companion.toMediaTypeOrNull
import okhttp3.Request
import okhttp3.RequestBody.Companion.asRequestBody
import okhttp3.RequestBody.Companion.toRequestBody
import java.io.BufferedOutputStream
import java.io.IOException

/** Forwards non-local requests to Nextcloud with stored basic auth, streaming both ways. */
class ProxyController(
    private val auth: AuthState,
    clients: HttpClients,
    private val originProvider: () -> String,
) {
    companion object {
        /** Hop-by-hop and client-specific headers that must not be forwarded upstream. */
        private val SKIPPED_REQUEST_HEADERS = setOf(
            "host", "content-length", "transfer-encoding",
            "connection", "cookie", "accept-encoding", "expect",
        )

        /** Framing and server-owned headers regenerated locally on the way back. */
        private val SKIPPED_RESPONSE_HEADERS = setOf(
            "content-encoding", "transfer-encoding",
            "content-length", "content-type",
            "connection", "keep-alive", "set-cookie",
        )
    }

    private val client by lazy { clients.newProxyClient() }

    /**
     * Proxies one request upstream with basic auth. The OCS-APIREQUEST header
     * exempts the call from CSRF checks, so no cookie session is needed.
     * Upstream network failures throw [UpstreamNetworkException] so the caller
     * can drop the connection (axios ERR_NETWORK) instead of faking an HTTP 5xx.
     */
    fun forward(req: HttpRequest, config: ServerConfig, out: BufferedOutputStream) {
        try {
            doForward(req, config, out)
        } catch (e: UpstreamNetworkException) {
            throw e
        } catch (e: IOException) {
            throw UpstreamNetworkException(e)
        }
    }

    private fun doForward(req: HttpRequest, config: ServerConfig, out: BufferedOutputStream) {
        val url = config.serverOrigin + req.path + (req.query?.let { "?$it" } ?: "")
        val builder = Request.Builder().url(url)
        for ((name, value) in req.headers) {
            if (name in SKIPPED_REQUEST_HEADERS) continue
            builder.header(name, value)
        }
        auth.authHeader()?.let { builder.header("Authorization", it) }
        builder.header("OCS-APIREQUEST", "true")
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
        client.newCall(builder.build()).execute().use { res ->
            streamResponse(res, config, out)
        }
    }

    private fun streamResponse(res: okhttp3.Response, config: ServerConfig, out: BufferedOutputStream) {
        val respHeaders = mutableMapOf<String, String>()
        for (i in 0 until res.headers.size) {
            val name = res.headers.name(i).lowercase()
            if (name in SKIPPED_RESPONSE_HEADERS) continue
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
