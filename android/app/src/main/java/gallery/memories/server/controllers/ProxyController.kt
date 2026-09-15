package gallery.memories.server.controllers

import gallery.memories.data.remote.http.AuthState
import gallery.memories.data.remote.http.HttpClients
import gallery.memories.server.KtorRespond
import gallery.memories.server.ServerConfig
import io.ktor.http.HttpStatusCode
import io.ktor.server.application.ApplicationCall
import io.ktor.server.request.httpMethod
import io.ktor.server.request.queryString
import io.ktor.server.request.receiveStream
import io.ktor.server.request.uri
import io.ktor.server.response.respondOutputStream
import java.io.IOException
import kotlinx.coroutines.Dispatchers
import kotlinx.coroutines.withContext
import okhttp3.MediaType.Companion.toMediaTypeOrNull
import okhttp3.Request
import okhttp3.RequestBody.Companion.toRequestBody

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
    suspend fun forward(call: ApplicationCall, config: ServerConfig) {
        val method = call.request.httpMethod.value.uppercase()
        val rawPath = call.request.uri.substringBefore("?")
        val query = call.request.queryString().ifEmpty { null }
        val builder = Request.Builder().url(config.serverOrigin + rawPath + (query?.let { "?$it" } ?: ""))
        for ((name, values) in call.request.headers.entries()) {
            if (name.lowercase() in SKIPPED_REQUEST_HEADERS) continue
            builder.header(name, values.joinToString(", "))
        }
        auth.authHeader()?.let { builder.header("Authorization", it) }
        builder.header("OCS-APIREQUEST", "true")
        val mediaType = call.request.headers["Content-Type"]?.toMediaTypeOrNull()
        val sentBytes = withContext(Dispatchers.IO) { call.receiveStream().readBytes() }
        val body =
            if (sentBytes.isNotEmpty()) {
                sentBytes.toRequestBody(mediaType)
            } else if (requiresBody(method)) {
                ByteArray(0).toRequestBody(mediaType)
            } else {
                null
            }
        builder.method(method, body)
        val response =
            try {
                withContext(Dispatchers.IO) { client.newCall(builder.build()).execute() }
            } catch (e: IOException) {
                throw UpstreamNetworkException(e)
            }
        response.use { res -> streamResponse(call, res, config) }
    }

    private suspend fun streamResponse(call: ApplicationCall, res: okhttp3.Response, config: ServerConfig) {
        for (i in 0 until res.headers.size) {
            val name = res.headers.name(i)
            val lower = name.lowercase()
            if (lower in SKIPPED_RESPONSE_HEADERS) continue
            if (lower == "location") {
                call.response.headers.append("Location", rewriteLocation(res.headers.value(i), config))
                continue
            }
            call.response.headers.append(name, res.headers.value(i))
        }
        val contentType = KtorRespond.contentType(res.body.contentType()?.toString())
        val status = KtorRespond.status(res.code)
        // Known length goes out as Content-Length, unknown as chunked.
        val length = res.body.contentLength().takeIf { it >= 0 }
        try {
            call.respondOutputStream(contentType, status, length) {
                withContext(Dispatchers.IO) {
                    res.body.byteStream().use { it.copyTo(this@respondOutputStream) }
                }
            }
        } catch (e: IOException) {
            // Upstream reset or client gone mid-stream: either way the
            // connection cannot complete, so signal a drop, not a 500.
            throw UpstreamNetworkException(e)
        }
    }

    /** Rewrites upstream redirects onto the local origin so the client stays same-origin. */
    private fun rewriteLocation(location: String, config: ServerConfig): String =
        if (location.startsWith(config.serverOrigin)) {
            originProvider() + location.substring(config.serverOrigin.length)
        } else {
            location
        }

    /** OkHttp rejects a null body for these methods, even when content-length is 0. */
    private fun requiresBody(method: String): Boolean =
        when (method) {
            "POST", "PUT", "PATCH", "PROPPATCH", "REPORT" -> true
            else -> false
        }
}
