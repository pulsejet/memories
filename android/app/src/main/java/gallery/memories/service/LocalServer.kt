package gallery.memories.service

import android.content.Context
import android.util.Log
import okhttp3.MediaType.Companion.toMediaTypeOrNull
import okhttp3.Cookie
import okhttp3.CookieJar
import okhttp3.FormBody
import okhttp3.HttpUrl
import okhttp3.OkHttpClient
import okhttp3.Request
import okhttp3.RequestBody.Companion.asRequestBody
import okhttp3.RequestBody.Companion.toRequestBody
import org.json.JSONObject
import java.io.BufferedOutputStream
import java.io.ByteArrayOutputStream
import java.io.File
import java.io.InputStream
import java.net.InetSocketAddress
import java.net.ServerSocket
import java.net.Socket
import java.util.concurrent.Executors

/**
 * Localhost HTTP server that serves the offline app shell and downloaded
 * assets, and forwards everything else to the Nextcloud server with the
 * stored credentials. Lets the locally served page keep its API working
 * same-origin.
 */
class LocalServer(
    private val appCtx: Context,
    private val mHttp: HttpService,
    private val mAssets: AssetService,
    private val bridge: (method: String, url: android.net.Uri) -> android.webkit.WebResourceResponse,
) {
    companion object {
        val TAG: String = LocalServer::class.java.simpleName
        const val HOST = "127.0.0.1"
        const val MAX_BODY_MEMORY = 1024 * 1024
        const val MAX_BODY_TOTAL = 256 * 1024 * 1024
    }

    data class Config(
        val serverOrigin: String,
        val webRoot: String,
        val assetDir: File,
        val baseUrl: String,
    )

    @Volatile private var cfg: Config? = null
    @Volatile private var server: ServerSocket? = null
    @Volatile var port = 0
        private set

    /** Per-process secret: only our WebView/ExoPlayer get it (via cookie). */
    val secret: String = java.util.UUID.randomUUID().toString()

    /** Session cookies for the upstream server (drives CSRF tokens). */
    private val jar = MemoryCookieJar()
    private var proxyClient: OkHttpClient? = null

    /** Last known session-bound CSRF token, also seeded into the shell. */
    @Volatile private var csrfToken: String? = null
    /** Last failed bootstrap; suppresses immediate retries (log spam). */
    @Volatile private var bootstrapBlockedUntil = 0L
    private val sessionLock = Any()

    private fun client(): OkHttpClient {
        synchronized(sessionLock) {
            if (proxyClient == null) proxyClient = mHttp.newProxyClient(jar)
            return proxyClient!!
        }
    }

    fun configure(serverOrigin: String, webRoot: String, assetDir: File, baseUrl: String) {
        val prev = cfg
        cfg = Config(serverOrigin, webRoot, assetDir, baseUrl)
        if (prev != null && prev.serverOrigin != serverOrigin) resetSession()
    }

    /** Drop the upstream session (account switch / logout). */
    fun resetSession() {
        synchronized(sessionLock) {
            csrfToken = null
            try {
                proxyClient?.dispatcher?.executorService?.shutdown()
                proxyClient?.connectionPool?.evictAll()
            } catch (_: Exception) {
            }
            proxyClient = null
        }
    }

    @Throws(Exception::class)
    @Synchronized
    fun ensureStarted(): Int {
        if (server == null) {
            val s = ServerSocket()
            s.reuseAddress = true
            s.bind(InetSocketAddress(HOST, 0))
            server = s
            port = s.localPort
            val pool = Executors.newCachedThreadPool()
            Thread {
                try {
                    while (!s.isClosed) {
                        val sock = s.accept()
                        pool.submit {
                            try {
                                handle(sock)
                            } catch (e: Exception) {
                                Log.w(TAG, "Connection failed: ${e.message}")
                                try {
                                    sock.close()
                                } catch (_: Exception) {
                                }
                            }
                        }
                    }
                } catch (_: Exception) {
                } finally {
                    pool.shutdownNow()
                    // Let the next ensureStarted() rebind instead of
                    // handing out a dead port forever.
                    if (server === s) {
                        server = null
                        port = 0
                    }
                }
            }.start()
            Log.i(TAG, "Listening on $HOST:$port")
        }
        return port
    }

    fun stop() {
        try {
            server?.close()
        } catch (_: Exception) {
        }
        server = null
        port = 0
    }

    fun origin(): String {
        return "http://$HOST:$port"
    }

    private data class HttpRequest(
        val method: String,
        val path: String,
        val query: String?,
        val headers: Map<String, String>,
        val body: ByteArray?,
        val bodyFile: File?,
    )

    private fun handle(sock: Socket) {
        sock.use { s ->
            val input = s.inputStream
            val out = BufferedOutputStream(s.outputStream)
            val req = try {
                readRequest(input)
            } catch (e: Exception) {
                reply(out, 400, "Bad Request", "bad request")
                return
            }
            try {
                route(req, out)
            } catch (e: Exception) {
                Log.w(TAG, "Request failed: ${e.message}")
                try {
                    reply(out, 500, "Internal Error", "proxy error")
                } catch (_: Exception) {
                }
            } finally {
                req.bodyFile?.delete()
            }
        }
    }

    private fun route(req: HttpRequest, out: BufferedOutputStream) {
        val config = cfg

        // Reject anything that did not come from our WebView/ExoPlayer.
        // The port alone is not a secret: any on-device app can connect.
        if (!authorized(req)) {
            reply(out, 403, "Forbidden", "forbidden")
            return
        }

        // Native bridge API (was: shouldInterceptRequest)
        if (req.path == "/api" || req.path.startsWith("/api/") ||
            req.path == "/image" || req.path.startsWith("/image/")
        ) {
            val uri = android.net.Uri.parse(
                "http://127.0.0.1${req.path}" + (req.query?.let { "?$it" } ?: "")
            )
            try {
                writeBridgeResponse(bridge(req.method, uri), out)
            } catch (e: Exception) {
                Log.w(TAG, "Bridge failed: ${e.message}")
                reply(out, 500, "Internal Error", "{}", mime = "application/json")
            }
            out.flush()
            return
        }

        // Static entry pages and their resources, straight from the APK
        if (req.path.startsWith("/local/static/")) {
            return serveApkFile(req, out)
        }

        // Local shell and assets need no server configured beyond files
        if (req.path == "/local/index.html" && config != null && serveShell(config, req, out)) {
            return
        }
        if (req.path.startsWith("/local/assets/js/") && config != null) {
            return serveFile(req, out, File(config.assetDir, "js"), "text/javascript; charset=utf-8")
        }
        if (req.path.startsWith("/local/assets/css/") && config != null) {
            return serveFile(req, out, File(config.assetDir, "css"), "text/css; charset=utf-8")
        }
        if (config == null) {
            reply(out, 503, "Not Configured", "not configured")
            return
        }

        // App JS chunks: always local, never remote JS
        val jsPrefix1 = config.webRoot + "/index.php/apps/memories/js/"
        val jsPrefix2 = config.webRoot + "/apps/memories/js/"
        if (req.method == "GET" &&
            (req.path.startsWith(jsPrefix1) || req.path.startsWith(jsPrefix2))
        ) {
            val name = req.path.substringAfterLast("/")
            val file = File(File(config.assetDir, "js"), name)
            if (name.isEmpty() || name.contains("..") || !file.isFile) {
                Log.w(TAG, "xx MISSING-CHUNK ${req.path}")
                reply(out, 404, "Not Found", "not found")
                return
            }
            return serveFile(req, out, File(config.assetDir, "js"), "text/javascript; charset=utf-8")
        }

        // SPA fallback: app entry and client routes serve the shell
        val appPrefix = config.webRoot + "/index.php/apps/memories"
        val isEntry = req.path == "/" || req.path == config.webRoot ||
            req.path == config.webRoot + "/"
        val isAppRoute = req.path == appPrefix || req.path == "$appPrefix/" ||
            req.path.startsWith("$appPrefix/")
        if (req.method == "GET" && (isEntry || isAppRoute) &&
            (isEntry || req.headers["accept"]?.contains("text/html") == true) &&
            serveShell(config, req, out)
        ) {
            return
        }

        forward(req, config, out)
        out.flush()
    }

    private fun isDebug(req: HttpRequest): Boolean {
        return req.query?.split("&")?.any { it == "shelldebug" || it.startsWith("shelldebug=") } == true
    }

    private fun authorized(req: HttpRequest): Boolean {
        val cookie = req.headers["cookie"] ?: return false
        return cookie.split(";").any { it.trim() == "local-auth=$secret" }
    }

    /** Small text reply (writes headers and body, then flushes). */
    private fun reply(
        out: BufferedOutputStream,
        code: Int,
        phrase: String,
        body: String,
        mime: String = "text/plain",
    ) {
        writeStatus(out, code, phrase, mime, body.toByteArray())
        out.flush()
    }

    /** Serve the locally built app shell; false when no snapshot is on disk (caller falls through). */
    private fun serveShell(config: Config, req: HttpRequest, out: BufferedOutputStream): Boolean {
        val describe = mAssets.readDescribe(config.assetDir) ?: return false
        val nonce = java.util.UUID.randomUUID().toString()
        val html = ShellPage.build(describe, config.webRoot, config.baseUrl, isDebug(req), csrfToken, mHttp.credentials()?.first, nonce)
        writeStatus(
            out, 200, "OK", "text/html; charset=utf-8",
            html.toByteArray(), noStore = true,
            extraHeaders = mapOf("Content-Security-Policy" to ShellPage.csp(nonce)),
        )
        out.flush()
        return true
    }

    private fun writeBridgeResponse(res: android.webkit.WebResourceResponse, out: BufferedOutputStream) {
        val headers = mutableMapOf<String, String>()
        var mime = "application/octet-stream"
        try {
            if (res.mimeType != null) {
                mime = res.mimeType
                if (res.encoding != null) mime += "; charset=${res.encoding}"
            }
            res.responseHeaders?.let { headers.putAll(it) }
        } catch (_: Exception) {
        }
        headers.putIfAbsent("Content-Type", mime)
        headers["Connection"] = "close"

        val code = try {
            res.statusCode
        } catch (_: Exception) {
            200
        }
        val phrase = try {
            res.reasonPhrase ?: reason(code)
        } catch (_: Exception) {
            reason(code)
        }

        val body = try {
            res.data
        } catch (_: Exception) {
            null
        }
        if (body != null) {
            headers["Transfer-Encoding"] = "chunked"
            writeHeaders(out, code, phrase, headers)
            body.use { streamChunked(it, out) }
        } else {
            headers["Content-Length"] = "0"
            writeHeaders(out, code, phrase, headers)
        }
        out.flush()
    }

    /** Validate a static-file request; replies 405/404 itself, null means replied. */
    private fun staticName(req: HttpRequest, out: BufferedOutputStream): String? {
        if (req.method != "GET") {
            reply(out, 405, "Method Not Allowed", "method")
            return null
        }
        val name = req.path.substringAfterLast("/")
        if (name.isEmpty() || name.contains("..") || name.contains("/")) {
            reply(out, 404, "Not Found", "not found")
            return null
        }
        return name
    }

    private fun sendStream(out: BufferedOutputStream, mime: String, length: Long, open: () -> InputStream) {
        writeHeaders(
            out, 200, "OK",
            mapOf(
                "Content-Type" to mime,
                "Content-Length" to length.toString(),
                "Cache-Control" to "no-store",
                "Connection" to "close",
            ),
        )
        open().use { it.copyTo(out) }
        out.flush()
    }

    private fun serveApkFile(req: HttpRequest, out: BufferedOutputStream) {
        val name = staticName(req, out) ?: return
        val mime = when (name.substringAfterLast(".", "")) {
            "html" -> "text/html; charset=utf-8"
            "css" -> "text/css; charset=utf-8"
            "svg" -> "image/svg+xml"
            "png" -> "image/png"
            "js" -> "text/javascript; charset=utf-8"
            else -> "application/octet-stream"
        }
        val bytes = try {
            appCtx.assets.open(name).use { it.readBytes() }
        } catch (_: Exception) {
            reply(out, 404, "Not Found", "not found")
            return
        }
        writeStatus(out, 200, "OK", mime, bytes, noStore = true)
        out.flush()
    }

    private fun serveFile(req: HttpRequest, out: BufferedOutputStream, dir: File, mime: String) {
        val name = staticName(req, out) ?: return
        val file = File(dir, name)
        if (!file.isFile) {
            reply(out, 404, "Not Found", "not found")
            return
        }
        sendStream(out, mime, file.length()) { file.inputStream() }
    }

    /** Forward one request to the server and stream the response back. */
    private fun forward(req: HttpRequest, config: Config, out: BufferedOutputStream, retried: Boolean = false) {
        val url = config.serverOrigin + req.path +
            (req.query?.let { "?$it" } ?: "")

        // Self-heal the session inline so the first paint already carries
        // a valid CSRF token (single-flighted, cheap once established).
        if (!retried && csrfToken == null && recoverable(req) && mHttp.credentials() != null) {
            ensureSession()
        }

        val builder = Request.Builder().url(url)
        for ((name, value) in req.headers) {
            if (name == "host" || name == "content-length" || name == "transfer-encoding" ||
                name == "connection" || name == "cookie" || name == "accept-encoding"
            ) {
                continue
            }
            builder.header(name, value)
        }
        mHttp.authHeader()?.let { builder.header("Authorization", it) }

        val mediaType = req.headers["content-type"]?.toMediaTypeOrNull()
        val body = when {
            req.bodyFile != null -> req.bodyFile.asRequestBody(mediaType)
            req.body != null -> req.body.toRequestBody(mediaType)
            else -> null
        }
        builder.method(req.method, body)

        val replay = client().newCall(builder.build()).execute().use { res ->
            if (!retried && (res.code == 401 || res.code == 412) && recoverable(req) &&
                mHttp.credentials() != null
            ) {
                true
            } else {
                streamResponse(res, config, out)
                false
            }
        }
        if (replay) {
            synchronized(sessionLock) { csrfToken = null }
            // Heal the session, then replay once so the client sees the
            // real upstream error when healing fails, not a proxy one.
            ensureSession()
            forward(req, config, out, retried = true)
        }
    }

    private fun recoverable(req: HttpRequest): Boolean {
        // Never loop on the session bootstrap traffic itself
        return !req.path.endsWith("/login") && !req.path.endsWith("/csrftoken")
    }

    private fun streamResponse(res: okhttp3.Response, config: Config, out: BufferedOutputStream) {
        val respHeaders = mutableMapOf<String, String>()
        for (i in 0 until res.headers.size) {
            val name = res.headers.name(i).lowercase()
            if (name == "content-encoding" || name == "transfer-encoding" ||
                name == "connection" || name == "keep-alive" || name == "set-cookie"
            ) {
                continue
            }
            if (name == "location") {
                respHeaders["Location"] = rewriteLocation(res.headers.value(i), config)
                continue
            }
            // Only keep the last value of repeated headers
            respHeaders[res.headers.name(i)] = res.headers.value(i)
        }
        val ctype = res.body.contentType()?.toString() ?: "application/octet-stream"
        respHeaders.putIfAbsent("Content-Type", ctype)
        respHeaders["Connection"] = "close"

        val length = res.body.contentLength()
        if (length >= 0) {
            respHeaders["Content-Length"] = length.toString()
            writeHeaders(out, res.code, reason(res.code), respHeaders)
            res.body.byteStream().use { it.copyTo(out) }
        } else {
            respHeaders["Transfer-Encoding"] = "chunked"
            writeHeaders(out, res.code, reason(res.code), respHeaders)
            res.body.byteStream().use { streamChunked(it, out) }
        }
    }

    private fun rewriteLocation(location: String, config: Config): String {
        return if (location.startsWith(config.serverOrigin)) {
            origin() + location.substring(config.serverOrigin.length)
        } else location
    }

    /**
     * Make sure the proxy holds a live server session (drives CSRF tokens).
     * Single-flighted; safe to call from any background thread (never the UI thread).
     */
    fun ensureSession(): Boolean {
        synchronized(sessionLock) {
            if (csrfToken != null) return true
            if (System.currentTimeMillis() < bootstrapBlockedUntil) return false
            val creds = mHttp.credentials() ?: return false
            val config = cfg ?: return false
            val ok = try {
                bootstrapSession(creds, config)
            } catch (e: Exception) {
                Log.w(TAG, "Session bootstrap failed: ${e.message}")
                false
            }
            if (!ok) bootstrapBlockedUntil = System.currentTimeMillis() + 60 * 1000
            return ok
        }
    }

    private fun bootstrapSession(creds: Pair<String, String>, config: Config): Boolean {
        // No auto-follow here: login success is exactly the 302/303.
        val client = client().newBuilder()
            .followRedirects(false)
            .followSslRedirects(false)
            .build()
        val tokenUrl = config.serverOrigin + config.webRoot + "/index.php/csrftoken"

        // Form login with the stored app password, then a session-bound token.
        // (The jar and the token share the same fate, so there is no point
        // trying a token without a login first.)
        var loginPath: String? = null
        var pageToken: String? = null
        for (candidate in listOf("${config.webRoot}/index.php/login", "${config.webRoot}/login")) {
            val token = fetchLoginToken(client, config, config.serverOrigin + candidate)
            if (token != null) {
                loginPath = candidate
                pageToken = token
                break
            }
        }
        val path = loginPath ?: return false
        val token = pageToken ?: return false

        val form = FormBody.Builder()
            .add("user", creds.first)
            .add("password", creds.second)
            .add("requesttoken", token)
            .add("remember_login", "1")
            .add("timezone-offset", "")
            .build()
        client.newCall(
            Request.Builder().url(config.serverOrigin + path).post(form).build()
        ).execute().use { res ->
            if (res.code != 302 && res.code != 303) {
                Log.w(TAG, "Login POST unexpected status ${res.code}")
            }
            res.body.string() // consume
        }

        return fetchToken(client, tokenUrl)?.let {
            Log.i(TAG, "Proxy session established")
            csrfToken = it
            true
        } ?: false
    }

    /** GET a login page (following one redirect) and scrape its request token, if any. */
    private fun fetchLoginToken(client: OkHttpClient, config: Config, url: String): String? {
        fun tokenOf(html: String): String? =
            Regex("data-requesttoken=\"([^\"]+)\"").find(html)?.groupValues?.getOrNull(1)
        client.newCall(Request.Builder().url(url).get().build()).execute().use { res ->
            if (res.code == 200) return tokenOf(res.body.string())
            val location = res.headers["Location"]
            if ((res.code == 302 || res.code == 303) && location != null) {
                // Follow one redirect (e.g. front controller to pretty URL),
                // but POST back to the canonical path, not the redirect target.
                Log.w(TAG, "Login page $url -> ${res.code} to $location")
                val target = if (location.startsWith("http")) location
                else config.serverOrigin + location
                client.newCall(Request.Builder().url(target).get().build()).execute().use { redir ->
                    if (redir.code != 200) {
                        Log.w(TAG, "Login redirect $target -> ${redir.code}")
                        return null
                    }
                    return tokenOf(redir.body.string())
                }
            }
            Log.w(TAG, "Login page $url -> ${res.code}")
            return null
        }
    }

    private fun fetchToken(client: OkHttpClient, url: String): String? {
        return try {
            client.newCall(Request.Builder().url(url).get().build()).execute().use { res ->
                if (res.code != 200) return null
                JSONObject(res.body.string()).optString("token", null)?.ifEmpty { null }
            }
        } catch (e: Exception) {
            Log.w(TAG, "Token fetch failed: ${e.message}")
            null
        }
    }

    // ---- HTTP parsing ----

    @Throws(Exception::class)
    private fun readRequest(input: InputStream): HttpRequest {
        val head = readHead(input, 65536)
        val lines = String(head, Charsets.ISO_8859_1).split("\r\n")
        if (lines.isEmpty()) throw Exception("Empty request")

        val requestLine = lines[0].split(" ")
        if (requestLine.size < 3) throw Exception("Bad request line")
        val method = requestLine[0].uppercase()
        var target = requestLine[1]

        // Support absolute-form targets
        if (target.startsWith("http://") || target.startsWith("https://")) {
            val afterScheme = target.substringAfter("://").substringAfter("/", "")
            target = "/$afterScheme"
        }

        val path = target.substringBefore("?")
        val query = target.substringAfter("?", "").ifEmpty { null }

        val headers = mutableMapOf<String, String>()
        for (i in 1 until lines.size) {
            val line = lines[i]
            if (line.isEmpty()) continue
            val idx = line.indexOf(":")
            if (idx <= 0) continue
            val name = line.substring(0, idx).trim().lowercase()
            val value = line.substring(idx + 1).trim()
            headers[name] = if (headers.containsKey(name)) headers[name] + ", " + value else value
        }

        var body: ByteArray? = null
        var bodyFile: File? = null
        val contentLength = headers["content-length"]?.toLongOrNull()
        if (contentLength != null && contentLength > 0) {
            if (contentLength > MAX_BODY_TOTAL) throw Exception("Body too large")
            body = readExact(input, contentLength)
            if (body.size > MAX_BODY_MEMORY) {
                bodyFile = spoolToFile(body)
                body = null
            }
        } else if (headers["transfer-encoding"]?.contains("chunked") == true) {
            val bytes = readChunked(input)
            if (bytes.size > MAX_BODY_MEMORY) {
                bodyFile = spoolToFile(bytes)
            } else {
                body = bytes
            }
        }

        return HttpRequest(method, path, query, headers, body, bodyFile)
    }

    @Throws(Exception::class)
    private fun readHead(input: InputStream, max: Int): ByteArray {
        val buf = ByteArrayOutputStream()
        val tail = ByteArray(4)
        var tailLen = 0
        while (buf.size() < max) {
            val b = input.read()
            if (b < 0) break
            buf.write(b)
            tail[tailLen % 4] = b.toByte()
            tailLen++
            if (tailLen >= 4 &&
                tail[(tailLen - 4) % 4] == '\r'.code.toByte() &&
                tail[(tailLen - 3) % 4] == '\n'.code.toByte() &&
                tail[(tailLen - 2) % 4] == '\r'.code.toByte() &&
                tail[(tailLen - 1) % 4] == '\n'.code.toByte()
            ) {
                return buf.toByteArray()
            }
        }
        throw Exception("Header too large or stream ended")
    }

    @Throws(Exception::class)
    private fun readExact(input: InputStream, n: Long): ByteArray {
        val out = ByteArrayOutputStream(n.coerceAtMost(MAX_BODY_TOTAL.toLong()).toInt())
        val buf = ByteArray(32768)
        var remaining = n
        while (remaining > 0) {
            val r = input.read(buf, 0, remaining.coerceAtMost(buf.size.toLong()).toInt())
            if (r < 0) throw Exception("Stream ended early")
            out.write(buf, 0, r)
            remaining -= r
        }
        return out.toByteArray()
    }

    @Throws(Exception::class)
    private fun readChunked(input: InputStream): ByteArray {
        val out = ByteArrayOutputStream()
        while (true) {
            val line = readLine(input)
            val size = line.substringBefore(";").trim().toLongOrNull(16)
                ?: throw Exception("Bad chunk size")
            if (size == 0L) {
                // Consume trailing headers
                while (readLine(input).isNotEmpty()) {
                }
                break
            }
            if (out.size() + size > MAX_BODY_TOTAL) throw Exception("Body too large")
            out.write(readExact(input, size))
            readLine(input) // CRLF after chunk
        }
        return out.toByteArray()
    }

    @Throws(Exception::class)
    private fun readLine(input: InputStream): String {
        val sb = StringBuilder()
        while (true) {
            val b = input.read()
            if (b < 0) throw Exception("Stream ended in line")
            if (b == '\n'.code) break
            if (b != '\r'.code) sb.append(b.toChar())
        }
        return sb.toString()
    }

    private fun spoolToFile(bytes: ByteArray): File {
        val file = File.createTempFile("proxy", ".body", appCtx.cacheDir)
        file.writeBytes(bytes)
        return file
    }

    // ---- HTTP writing ----

    private fun writeStatus(
        out: BufferedOutputStream,
        code: Int,
        phrase: String,
        mime: String,
        body: ByteArray,
        noStore: Boolean = false,
        extraHeaders: Map<String, String> = emptyMap(),
    ) {
        val headers = mutableMapOf(
            "Content-Type" to mime,
            "Content-Length" to body.size.toString(),
            "Connection" to "close",
        )
        if (noStore) headers["Cache-Control"] = "no-store"
        headers.putAll(extraHeaders)
        writeHeaders(out, code, phrase, headers)
        out.write(body)
        out.flush()
    }

    private fun writeHeaders(out: BufferedOutputStream, code: Int, phrase: String, headers: Map<String, String>) {
        val sb = StringBuilder()
        sb.append("HTTP/1.1 ").append(code).append(' ').append(phrase).append("\r\n")
        for ((name, value) in headers) {
            sb.append(name).append(": ").append(value).append("\r\n")
        }
        sb.append("\r\n")
        out.write(sb.toString().toByteArray(Charsets.ISO_8859_1))
    }

    private fun streamChunked(input: InputStream, out: BufferedOutputStream) {
        val buf = ByteArray(32768)
        while (true) {
            val r = input.read(buf)
            if (r < 0) break
            out.write(Integer.toHexString(r).toByteArray(Charsets.US_ASCII))
            out.write("\r\n".toByteArray(Charsets.US_ASCII))
            out.write(buf, 0, r)
            out.write("\r\n".toByteArray(Charsets.US_ASCII))
        }
        out.write("0\r\n\r\n".toByteArray(Charsets.US_ASCII))
        out.flush()
    }

    private fun reason(code: Int): String {        return when (code) {
            200 -> "OK"
            201 -> "Created"
            204 -> "No Content"
            206 -> "Partial Content"
            207 -> "Multi-Status"
            301 -> "Moved Permanently"
            302 -> "Found"
            303 -> "See Other"
            304 -> "Not Modified"
            307 -> "Temporary Redirect"
            308 -> "Permanent Redirect"
            400 -> "Bad Request"
            401 -> "Unauthorized"
            403 -> "Forbidden"
            404 -> "Not Found"
            405 -> "Method Not Allowed"
            409 -> "Conflict"
            412 -> "Precondition Failed"
            415 -> "Unsupported Media Type"
            423 -> "Locked"
            500 -> "Internal Error"
            501 -> "Not Implemented"
            502 -> "Bad Gateway"
            503 -> "Unavailable"
            507 -> "Insufficient Storage"
            else -> "Status"
        }
    }
}

/** In-memory cookie jar: enough for one upstream session, no extra deps. */
private class MemoryCookieJar : CookieJar {
    private val lock = Any()
    private val store = mutableListOf<Cookie>()

    override fun saveFromResponse(url: HttpUrl, cookies: List<Cookie>) {
        synchronized(lock) {
            for (cookie in cookies) {
                store.removeAll {
                    it.name == cookie.name && it.domain == cookie.domain && it.path == cookie.path
                }
                if (cookie.persistent && cookie.expiresAt <= System.currentTimeMillis()) continue
                store.add(cookie)
            }
        }
    }

    override fun loadForRequest(url: HttpUrl): List<Cookie> {
        synchronized(lock) {
            val now = System.currentTimeMillis()
            store.removeAll { it.persistent && it.expiresAt <= now }
            return store.filter { it.matches(url) }.toList()
        }
    }
}
