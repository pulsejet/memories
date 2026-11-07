package gallery.memories.server

import android.content.Context
import android.util.Log
import gallery.memories.data.local.media.MediaStoreDataSource
import gallery.memories.data.remote.assets.AssetSyncCoordinator
import gallery.memories.data.remote.http.AuthState
import gallery.memories.server.controllers.BridgeController
import gallery.memories.server.controllers.ViewImageController
import gallery.memories.server.controllers.ProxyController
import gallery.memories.ui.image.NativeHoleImage
import gallery.memories.server.controllers.ShellController
import gallery.memories.server.controllers.StaticController
import gallery.memories.server.controllers.UpstreamNetworkException
import gallery.memories.server.controllers.VideoController
import io.ktor.http.HttpStatusCode
import io.ktor.server.application.ApplicationCall
import io.ktor.server.request.httpMethod
import io.ktor.server.request.uri
import java.io.File
import kotlinx.coroutines.CancellationException
import kotlinx.coroutines.Dispatchers
import kotlinx.coroutines.withContext

/**
 * Dispatches local-server requests to the bridge, static files, the
 * offline shell, or the upstream proxy. See [handle] for the order.
 * Requests reach it already authorized by [LocalEngine].
 *
 * Paths are matched against the raw request target (as before), since routing
 * here is prefix-based and must not depend on percent-decoding.
 */
class ServerRouter(
    private val appCtx: Context,
    private val auth: AuthState,
    private val assets: AssetSyncCoordinator,
    private val bridge: BridgeController,
    private val proxy: ProxyController,
    private val dataSource: MediaStoreDataSource,
    private val holeImage: NativeHoleImage,
    private val configProvider: () -> ServerConfig?,
) {
    companion object {
        private val TAG = ServerRouter::class.java.simpleName

        /** Sec-Fetch-Dest values that execute what they load. */
        private val SCRIPT_DESTS = setOf(
            "script", "worker", "sharedworker", "serviceworker", "audioworklet", "paintworklet",
        )
    }

    /**
     * Route order matters: bridge and video first (work unconfigured), then local
     * files (unknown /local/ paths 404 on-device, never proxied), then the
     * shell for app routes, and finally the upstream proxy.
     */
    suspend fun handle(call: ApplicationCall) {
        val config = configProvider()
        val method = call.request.httpMethod.value.uppercase()
        val rawPath = call.request.uri.substringBefore("?")
        try {
            if (VideoController.isVideo(rawPath)) {
                VideoController.serveVideo(appCtx, call, dataSource)
                return
            }
            if (ViewImageController.isViewImage(rawPath)) {
                ViewImageController.serveImage(call, holeImage)
                return
            }
            if (ViewImageController.isViewImageLocal(rawPath)) {
                ViewImageController.serveLocalImage(call, holeImage)
                return
            }
            if (BridgeController.isBridge(rawPath)) {
                bridge.handle(call)
                return
            }
            if (rawPath.startsWith("/local/static/")) {
                StaticController.serveApkFile(appCtx, call)
                return
            }
            // Everything under /local/ lives on-device; never proxy it upstream,
            // where it would only burn a full round trip for a meaningless 404.
            if (rawPath == "/local" || rawPath.startsWith("/local/")) {
                if (config != null) {
                    if (rawPath.startsWith("/local/assets/js/")) {
                        StaticController.serveFile(call, File(config.assetDir, "js"), "text/javascript; charset=utf-8")
                        return
                    }
                    if (rawPath.startsWith("/local/assets/css/")) {
                        StaticController.serveFile(call, File(config.assetDir, "css"), "text/css; charset=utf-8")
                        return
                    }
                }
                KtorRespond.plain(call, HttpStatusCode.NotFound, "not found")
                return
            }
            // Requested implicitly per page load; answer locally instead of proxying a 404.
            if (rawPath == "/favicon.ico") {
                KtorRespond.plain(call, HttpStatusCode.NotFound, "not found")
                return
            }
            if (config == null) {
                KtorRespond.plain(call, HttpStatusCode.ServiceUnavailable, "not configured")
                return
            }
            val jsPrefix1 = config.webRoot + "/index.php/apps/memories/js/"
            val jsPrefix2 = config.webRoot + "/apps/memories/js/"
            val jsPrefix3 = config.appsPath?.let { "$it/js/" }
            val isJs = rawPath.startsWith(jsPrefix1) || rawPath.startsWith(jsPrefix2) ||
                (jsPrefix3 != null && rawPath.startsWith(jsPrefix3))
            if (method == "GET" && isJs) {
                StaticController.serveFile(call, File(config.assetDir, "js"), "text/javascript; charset=utf-8")
                return
            }
            val appPrefix = config.webRoot + "/index.php/apps/memories"
            val isEntry = rawPath == "/" || rawPath == config.webRoot || rawPath == config.webRoot + "/"
            val isAppRoute = rawPath == appPrefix || rawPath == "$appPrefix/" || rawPath.startsWith("$appPrefix/")
            val isDocument = call.request.headers["Accept"]?.contains("text/html") == true ||
                call.request.headers["Sec-Fetch-Dest"] == "document"
            if (method == "GET" && (isEntry || isAppRoute) && isDocument && serveShell(config, call)) return
            // Only local code serves documents (shell/static above): never forward a
            // page navigation upstream, or remote content would render as a local page.
            // The entry is never an API: an XHR here (e.g. a followed redirect) must not get the shell.
            if (method == "GET" && (isDocument || isEntry)) {
                KtorRespond.plain(call, HttpStatusCode.NotFound, "not found")
                return
            }
            // Scripts run with full nativex access, so they must come from the signed
            // snapshot: 'self' in the CSP also covers everything proxied from the server.
            if (call.request.headers["Sec-Fetch-Dest"] in SCRIPT_DESTS) {
                KtorRespond.plain(call, HttpStatusCode.NotFound, "not found")
                return
            }
            proxy.forward(call, config)
        } catch (e: UpstreamNetworkException) {
            // Upstream offline: answer 504 Gateway Timeout. Ktor cannot drop
            // the connection without a response, so isNetworkError() treats a
            // native 504 as offline (axios ERR_NETWORK equivalent).
            Log.i(TAG, "Upstream unreachable: ${e.cause?.message}")
            KtorRespond.plain(call, HttpStatusCode.GatewayTimeout, "upstream unreachable")
        } catch (e: CancellationException) {
            throw e
        } catch (e: Exception) {
            Log.w(TAG, "Request failed: ${e.message}")
            if (!call.response.isSent) {
                try {
                    KtorRespond.plain(call, HttpStatusCode.InternalServerError, "proxy error")
                } catch (_: Exception) {}
            }
        }
    }

    /**
     * Serves the offline shell for the snapshot in [config].
     * False when no snapshot describe is available or rendering failed.
     */
    private suspend fun serveShell(config: ServerConfig, call: ApplicationCall): Boolean {
        val describe = withContext(Dispatchers.IO) { assets.readDescribe(config.assetDir) } ?: return false
        return ShellController.serveShell(config, call, describe, auth.uid())
    }
}
