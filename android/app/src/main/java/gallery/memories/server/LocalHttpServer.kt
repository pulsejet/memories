package gallery.memories.server

import android.content.Context
import android.net.Uri
import android.util.Log
import android.webkit.WebResourceResponse
import gallery.memories.data.remote.assets.AssetSyncCoordinator
import gallery.memories.data.remote.http.AuthState
import gallery.memories.data.remote.http.HttpClients
import gallery.memories.server.controllers.BridgeController
import gallery.memories.server.controllers.ProxyController
import gallery.memories.server.controllers.UpstreamNetworkException
import java.io.BufferedOutputStream
import java.io.File
import java.net.BindException
import java.net.InetSocketAddress
import java.net.ServerSocket
import java.net.Socket
import java.util.UUID
import java.util.concurrent.ExecutorService
import java.util.concurrent.Executors

/**
 * Localhost server that serves the offline shell/assets and proxies the rest
 * to Nextcloud with stored credentials, keeping everything same-origin.
 * The port alone is not a secret (any on-device app can connect), so every
 * request must carry the per-process cookie checked by [AuthGuard].
 */
class LocalHttpServer(
    private val appCtx: Context,
    private val auth: AuthState,
    private val clients: HttpClients,
    private val assets: AssetSyncCoordinator,
    private val bridge: (method: String, url: Uri) -> WebResourceResponse,
) {
    companion object {
        val TAG: String = LocalHttpServer::class.java.simpleName
        const val HOST = "127.0.0.1"
        // Fixed port so the WebView origin stays identical across restarts.
        const val PORT = 62079
    }

    @Volatile private var cfg: ServerConfig? = null
    @Volatile private var server: ServerSocket? = null
    @Volatile private var pool: ExecutorService? = null
    @Volatile var port = 0
        private set

    /** Per-process secret: the WebView proves same-process origin via this cookie. */
    val secret: String = UUID.randomUUID().toString()

    private val guard = AuthGuard(secret)
    private val bridgeController = BridgeController(bridge)
    private val proxy = ProxyController(auth, clients) { origin() }
    private val router = ServerRouter(appCtx, auth, assets, guard, bridgeController, proxy) { cfg }

    /** Points the server at an upstream origin and its offline snapshot. */
    fun configure(serverOrigin: String, webRoot: String, assetDir: File, baseUrl: String) {
        cfg = ServerConfig(serverOrigin, webRoot, assetDir, baseUrl)
    }

    /**
     * Starts the accept loop once; returns the bound port. Falls back to an
     * ephemeral port when a stale process still holds the fixed one.
     */
    @Throws(Exception::class)
    @Synchronized
    fun ensureStarted(): Int {
        if (server == null) {
            val s = ServerSocket()
            s.reuseAddress = true
            try {
                s.bind(InetSocketAddress(HOST, PORT))
            } catch (e: BindException) {
                // Stale process still holding the port; storage just misses this launch.
                Log.w(TAG, "Fixed port $PORT busy, falling back to ephemeral", e)
                s.bind(InetSocketAddress(HOST, 0))
            }
            server = s
            port = s.localPort
            val workers = Executors.newCachedThreadPool()
            pool = workers
            Thread {
                try {
                    while (!s.isClosed) {
                        val sock = s.accept()
                        workers.submit {
                            try {
                                handle(sock)
                            } catch (e: Exception) {
                                Log.w(TAG, "Connection failed: ${e.message}")
                                try { sock.close() } catch (_: Exception) {}
                            }
                        }
                    }
                } catch (_: Exception) {
                    // ServerSocket closed under us during stop(); not an error.
                } finally {
                    workers.shutdownNow()
                    if (server === s) {
                        server = null
                        pool = null
                        port = 0
                    }
                }
            }.start()
            Log.i(TAG, "Listening on $HOST:$port")
        }
        return port
    }

    /** Closes the socket and stops accepting; in-flight requests run to completion. */
    fun stop() {
        try { server?.close() } catch (_: Exception) {}
        pool?.shutdownNow()
        pool = null
        server = null
        port = 0
    }

    fun origin(): String = "http://$HOST:$port"

    private fun handle(sock: Socket) {
        sock.use { s ->
            val input = s.inputStream
            val out = BufferedOutputStream(s.outputStream)
            val req = try {
                HttpParser.readRequest(input, appCtx.cacheDir)
            } catch (e: Exception) {
                HttpWriter.reply(out, 400, "Bad Request", "bad request")
                return
            }
            try {
                router.route(req, out)
            } catch (e: UpstreamNetworkException) {
                // Upstream offline: close without a response so the WebView
                // sees a network failure (axios ERR_NETWORK), not an HTTP 5xx.
                Log.i(TAG, "Upstream unreachable, dropping connection: ${e.cause?.message}")
                return
            } catch (e: Exception) {
                Log.w(TAG, "Request failed: ${e.message}")
                try { HttpWriter.reply(out, 500, "Internal Error", "proxy error") } catch (_: Exception) {}
            } finally {
                req.bodyFile?.delete()
            }
        }
    }
}
