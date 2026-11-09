package gallery.memories.server.controllers

import android.util.Log
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
import okhttp3.Call
import okhttp3.MediaType
import okhttp3.Request
import okhttp3.RequestBody
import okhttp3.RequestBody.Companion.toRequestBody
import okio.BufferedSink
import okio.source
import java.io.InputStream
import java.util.concurrent.ConcurrentHashMap

/** Forwards non-local requests to Nextcloud with stored basic auth, streaming both ways. */
class ProxyController(
    private val auth: AuthState,
    private val clients: HttpClients,
    private val originProvider: () -> String,
) {
    companion object {
        private val TAG = ProxyController::class.java.simpleName

        /** Hop-by-hop and client-specific headers that must not be forwarded upstream. */
        private val SKIPPED_REQUEST_HEADERS = setOf(
            "host", "content-length", "transfer-encoding",
            "connection", "cookie", "accept-encoding", "expect", "authorization",
        )

        /** Framing and server-owned headers regenerated locally on the way back. */
        private val SKIPPED_RESPONSE_HEADERS = setOf(
            "content-encoding", "transfer-encoding",
            "content-length", "content-type",
            "connection", "keep-alive", "set-cookie", "upgrade",
        )

        /** Bodies up to this size are buffered so OkHttp can retry them on a stale connection. */
        private const val MAX_BUFFERED_BYTES = 1024 * 1024L
    }

    private val inFlight: MutableSet<Call> = ConcurrentHashMap.newKeySet()

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
        builder.method(method, requestBody(call, method))
        val upstream = clients.proxyClient().newCall(builder.build())
        inFlight.add(upstream)
        try {
            val response =
                try {
                    withContext(Dispatchers.IO) { upstream.execute() }
                } catch (e: IOException) {
                    throw UpstreamNetworkException(e)
                }
            response.use { res -> streamResponse(call, res, config) }
        } finally {
            inFlight.remove(upstream)
        }
    }

    /** Aborts in-flight upstream calls; blocked reads and writes then fail fast. */
    fun cancelAll() = inFlight.forEach { it.cancel() }

    /** Buffers small bodies and streams the rest; null when there is no body to send. */
    private suspend fun requestBody(call: ApplicationCall, method: String): RequestBody? {
        val mediaType = call.request.headers["Content-Type"]?.toMediaTypeOrNull()
        val length = call.request.headers["Content-Length"]?.toLongOrNull()
        val hasBody = (length ?: 0L) > 0 || call.request.headers["Transfer-Encoding"] != null
        return when {
            !hasBody -> if (requiresBody(method)) ByteArray(0).toRequestBody(mediaType) else null
            length != null && length <= MAX_BUFFERED_BYTES ->
                withContext(Dispatchers.IO) { call.receiveStream().readBytes() }.toRequestBody(mediaType)
            else -> StreamedBody(call.receiveStream(), mediaType, length ?: -1)
        }
    }

    /** One-shot: the stream can't rewind, so OkHttp must not retry it. */
    private class StreamedBody(
        private val stream: InputStream,
        private val mediaType: MediaType?,
        private val length: Long,
    ) : RequestBody() {
        override fun contentType() = mediaType
        override fun contentLength() = length
        override fun isOneShot() = true
        override fun writeTo(sink: BufferedSink) {
            stream.source().use { sink.writeAll(it) }
        }
    }

    private suspend fun streamResponse(call: ApplicationCall, res: okhttp3.Response, config: ServerConfig) {
        if (res.code >= 400) Log.w(TAG, "Upstream ${res.code} for ${call.request.httpMethod.value} ${res.request.url.encodedPath}")
        for (i in 0 until res.headers.size) {
            val name = res.headers.name(i)
            val lower = name.lowercase()
            if (lower in SKIPPED_RESPONSE_HEADERS) continue
            val value = if (lower == "location") rewriteLocation(res.headers.value(i), config) else res.headers.value(i)
            // A header Ktor refuses must not fail the whole response.
            try {
                call.response.headers.append(name, value)
            } catch (e: Exception) {
                Log.w(TAG, "Dropped upstream header $name: ${e.message}")
            }
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
