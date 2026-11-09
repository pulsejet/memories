package gallery.memories.server

import android.content.Context
import android.net.Uri
import android.webkit.WebResourceResponse
import gallery.memories.data.local.media.MediaStoreDataSource
import gallery.memories.data.remote.assets.AssetSyncCoordinator
import gallery.memories.data.remote.http.AuthState
import gallery.memories.data.remote.http.HttpClients
import gallery.memories.server.controllers.BridgeController
import gallery.memories.server.controllers.ProxyController
import gallery.memories.ui.image.NativeHoleImage
import java.io.File

/**
 * Activity-scoped side of the localhost server: the upstream config and a
 * router bound to this activity's services, attached to the process-wide
 * [LocalEngine] while the activity lives. Serves the offline shell/assets and
 * proxies the rest to Nextcloud with stored credentials, keeping everything
 * same-origin. Every request must carry the cookie checked by [AuthGuard].
 */
class LocalHttpServer(
    appCtx: Context,
    auth: AuthState,
    clients: HttpClients,
    assets: AssetSyncCoordinator,
    dataSource: MediaStoreDataSource,
    holeImage: NativeHoleImage,
    bridge: (method: String, url: Uri) -> WebResourceResponse,
) {
    @Volatile private var cfg: ServerConfig? = null

    private val proxy = ProxyController(
        auth = auth,
        clients = clients,
        originProvider = { origin() },
        onServerVersion = { onServerVersion?.invoke(it) },
    )
    private val router = ServerRouter(
        appCtx = appCtx.applicationContext,
        auth = auth,
        assets = assets,
        bridge = BridgeController(bridge),
        proxy = proxy,
        dataSource = dataSource,
        holeImage = holeImage,
        configProvider = { cfg },
    )

    val port: Int get() = LocalEngine.port

    /** Called with the server version header of each proxied response. */
    @Volatile var onServerVersion: ((String) -> Unit)? = null

    /** HttpOnly cookie value the WebView must present; see [AuthGuard]. */
    fun authCookie(): String = LocalEngine.guard.cookieHeader()

    /** Points the server at an upstream origin and its offline snapshot. */
    fun configure(serverOrigin: String, webRoot: String, assetDir: File, appsPath: String? = null) {
        cfg = ServerConfig(
            serverOrigin = serverOrigin,
            webRoot = webRoot,
            assetDir = assetDir,
            appsPath = appsPath,
        )
    }

    /** Starts the shared engine if needed and routes it to this activity. */
    @Throws(Exception::class)
    fun ensureStarted(): Int = LocalEngine.ensureStarted().also { attach() }

    /** Routes the shared engine to this activity, e.g. when it comes back to the foreground. */
    fun attach() = LocalEngine.attach(router)

    /** Unroutes this activity and aborts its upstream calls; the engine keeps running. */
    fun stop() {
        LocalEngine.detach(router)
        proxy.cancelAll()
    }

    fun origin(): String = LocalEngine.origin()

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
        val appPrefix = config.webRoot + "/index.php/apps/memories"
        if (p == appPrefix || p == "$appPrefix/" || p.startsWith("$appPrefix/")) return null
        val prettyPrefix = config.webRoot + "/apps/memories"
        if (p == prettyPrefix || p == "$prettyPrefix/" || p.startsWith("$prettyPrefix/")) return null
        config.appsPath?.let { if (p == it || p.startsWith("$it/")) return null }
        return config.serverOrigin + p + (query?.let { "?$it" } ?: "")
    }
}
