package gallery.memories.server

import android.content.Context
import android.net.Uri
import android.util.Log
import android.webkit.WebResourceResponse
import gallery.memories.data.local.media.MediaStoreDataSource
import gallery.memories.data.remote.assets.AssetSyncCoordinator
import gallery.memories.data.remote.http.AuthState
import gallery.memories.data.remote.http.HttpClients
import gallery.memories.server.controllers.BridgeController
import gallery.memories.server.controllers.ProxyController
import io.ktor.server.application.ApplicationCallPipeline
import io.ktor.server.application.call
import io.ktor.server.cio.CIO
import io.ktor.server.engine.ApplicationEngine
import io.ktor.server.engine.embeddedServer
import java.io.File
import java.net.BindException
import java.util.UUID
import kotlinx.coroutines.runBlocking
import kotlinx.coroutines.withTimeout

/**
 * Localhost server that serves the offline shell/assets and proxies the rest
 * to Nextcloud with stored credentials, keeping everything same-origin.
 * The port alone is not a secret (any on-device app can connect), so every
 * request must carry the per-process cookie checked by [AuthGuard].
 *
 * Backed by Ktor CIO: it owns request parsing, framing (Content-Length,
 * chunked, 100-continue) and keep-alive. Any HTTP method token (SEARCH,
 * PROPFIND, REPORT, ...) routes through; see [ServerRouter].
 */
class LocalHttpServer(
    private val appCtx: Context,
    private val auth: AuthState,
    private val clients: HttpClients,
    private val assets: AssetSyncCoordinator,
    private val dataSource: MediaStoreDataSource,
    private val bridge: (method: String, url: Uri) -> WebResourceResponse,
) {
    companion object {
        val TAG: String = LocalHttpServer::class.java.simpleName
        const val HOST = "127.0.0.1"
        // Fixed port so the WebView origin stays identical across restarts.
        const val PORT = 62079
    }

    @Volatile private var cfg: ServerConfig? = null
    @Volatile private var engine: ApplicationEngine? = null
    @Volatile var port = 0
        private set

    /** Per-process secret: the WebView proves same-process origin via this cookie. */
    val secret: String = UUID.randomUUID().toString()

    private val guard = AuthGuard(secret)
    private val bridgeController = BridgeController(bridge)
    private val proxy = ProxyController(auth, clients) { origin() }
    private val router = ServerRouter(appCtx, auth, assets, guard, bridgeController, proxy, dataSource) { cfg }

    /** HttpOnly cookie value the WebView must present; see [AuthGuard]. */
    fun authCookie(): String = guard.cookieHeader()

    /** Points the server at an upstream origin and its offline snapshot. */
    fun configure(serverOrigin: String, webRoot: String, assetDir: File) {
        cfg = ServerConfig(serverOrigin, webRoot, assetDir)
    }

    /**
     * Starts the engine once; returns the bound port. Falls back to an
     * ephemeral port when a stale process still holds the fixed one.
     */
    @Throws(Exception::class)
    @Synchronized
    fun ensureStarted(): Int {
        if (engine == null) {
            try {
                engine = startEngine(PORT)
                port = PORT
            } catch (e: BindException) {
                // Stale process still holding the port; storage just misses this launch.
                Log.w(TAG, "Fixed port $PORT busy, falling back to ephemeral", e)
                val fallback = startEngine(0)
                engine = fallback
                port = runBlocking { withTimeout(10_000) { fallback.resolvedConnectors().first().port } }
            }
            Log.i(TAG, "Listening on $HOST:$port")
        }
        return port
    }

    /** Stops the engine; in-flight requests get a brief grace period. */
    fun stop() {
        try {
            engine?.stop(0, 500)
        } catch (_: Exception) {}
        engine = null
        port = 0
    }

    fun origin(): String = "http://$HOST:$port"

    /**
     * Browser URL for a top-level navigation to a loopback [path]: local documents
     * (shell/static/bridge) stay inside (null), anything else is a proxied upstream
     * path unwrapped to the origin URL. Null when unconfigured: without an upstream
     * nothing is proxied, so there is nothing to unwrap to.
     */
    fun browserUrlFor(path: String?, query: String?): String? {
        val config = cfg ?: return null
        val p = path ?: "/"
        if (p == "/local" || p.startsWith("/local/")) return null
        if (p == "/" || p == config.webRoot || p == config.webRoot + "/") return null
        if (p == "/api" || p.startsWith("/api/") || p == "/image" || p.startsWith("/image/")) return null
        if (p == "/video" || p.startsWith("/video/")) return null
        if (p == "/favicon.ico") return null
        return config.serverOrigin + p + (query?.let { "?$it" } ?: "")
    }

    /**
     * Single pipeline interceptor, not routing: it imposes no method or path
     * constraints, so WebDAV methods unknown to Ktor's constants still arrive.
     * Not calling proceed() ends the pipeline; every request is answered (or
     * the connection dropped) inside [ServerRouter.handle].
     */
    private fun startEngine(port: Int): ApplicationEngine =
        embeddedServer(CIO, host = HOST, port = port) {
            intercept(ApplicationCallPipeline.Call) {
                router.handle(call)
            }
        }.start(wait = false).engine
}
