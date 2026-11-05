package gallery.memories.server

import android.util.Log
import io.ktor.http.HttpStatusCode
import io.ktor.server.application.ApplicationCall
import io.ktor.server.application.ApplicationCallPipeline
import io.ktor.server.application.call
import io.ktor.server.cio.CIO
import io.ktor.server.engine.EmbeddedServer
import io.ktor.server.engine.embeddedServer
import java.net.BindException
import java.util.UUID
import kotlinx.coroutines.runBlocking
import kotlinx.coroutines.withTimeout

/**
 * Process-wide loopback engine. It outlives activities so the port (hence the
 * WebView origin and its storage) and the auth secret stay stable; each live
 * activity attaches its own [ServerRouter], the last resumed one serving.
 *
 * Backed by Ktor CIO: it owns request parsing, framing (Content-Length,
 * chunked, 100-continue) and keep-alive. Any HTTP method token (SEARCH,
 * PROPFIND, REPORT, ...) routes through; see [ServerRouter].
 */
object LocalEngine {
    private val TAG = LocalEngine::class.java.simpleName
    const val HOST = "127.0.0.1"
    // Fixed port so the WebView origin stays identical across restarts.
    const val PORT = 62079

    /** Per-process secret; the port alone is not one, since any on-device app can connect. */
    val guard = AuthGuard(UUID.randomUUID().toString())

    private val routers = ArrayList<ServerRouter>()
    @Volatile private var server: EmbeddedServer<*, *>? = null
    @Volatile var port = 0
        private set

    fun origin(): String = "http://$HOST:$port"

    /**
     * Starts the engine once per process; returns the bound port. Falls back
     * to an ephemeral port when another process holds the fixed one.
     */
    @Throws(Exception::class)
    @Synchronized
    fun ensureStarted(): Int {
        if (server != null) return port
        try {
            server = startServer(PORT)
            port = PORT
        } catch (e: BindException) {
            Log.w(TAG, "Fixed port $PORT busy, falling back to ephemeral", e)
            val fallback = startServer(0)
            server = fallback
            port = runBlocking { withTimeout(10_000) { fallback.engine.resolvedConnectors().first().port } }
        }
        Log.i(TAG, "Listening on $HOST:$port")
        return port
    }

    /** Makes [r] the serving router; earlier ones take over again once it detaches. */
    @Synchronized
    fun attach(r: ServerRouter) {
        routers.remove(r)
        routers.add(r)
    }

    @Synchronized
    fun detach(r: ServerRouter) {
        routers.remove(r)
    }

    @Synchronized
    private fun current(): ServerRouter? = routers.lastOrNull()

    /**
     * Single pipeline interceptor, not routing: it imposes no method or path
     * constraints, so WebDAV methods unknown to Ktor's constants still arrive.
     * Not calling proceed() ends the pipeline.
     */
    private fun startServer(port: Int): EmbeddedServer<*, *> {
        val s = embeddedServer(CIO, host = HOST, port = port) {
            intercept(ApplicationCallPipeline.Call) { handle(call) }
        }
        try {
            s.start(wait = false)
        } catch (e: Exception) {
            runCatching { s.stop(0, 0) }
            throw e
        }
        return s
    }

    private suspend fun handle(call: ApplicationCall) {
        if (!guard.authorized(call)) {
            KtorRespond.plain(call, HttpStatusCode.Forbidden, "forbidden")
            return
        }
        val r = current()
        if (r == null) {
            KtorRespond.plain(call, HttpStatusCode.ServiceUnavailable, "no activity")
            return
        }
        r.handle(call)
    }
}
