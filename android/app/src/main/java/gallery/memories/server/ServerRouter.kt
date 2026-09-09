package gallery.memories.server

import android.content.Context
import android.util.Log
import gallery.memories.data.remote.assets.AssetSyncCoordinator
import gallery.memories.data.remote.http.AuthState
import gallery.memories.server.controllers.BridgeController
import gallery.memories.server.controllers.ProxyController
import gallery.memories.server.controllers.ShellController
import gallery.memories.server.controllers.StaticController
import java.io.BufferedOutputStream
import java.io.File

class ServerRouter(
    private val appCtx: Context,
    private val auth: AuthState,
    private val assets: AssetSyncCoordinator,
    private val guard: AuthGuard,
    private val bridge: BridgeController,
    private val proxy: ProxyController,
    private val configProvider: () -> ServerConfig?,
) {
    companion object {
        private val TAG = ServerRouter::class.java.simpleName
    }

    /**
     * Route order matters: bridge first (works unconfigured), then local
     * files, then app JS chunks (always local, never remote JS), then the
     * shell for app routes, and finally the upstream proxy.
     */
    fun route(req: HttpRequest, out: BufferedOutputStream) {
        val config = configProvider()
        if (!guard.authorized(req)) {
            HttpWriter.reply(out, 403, "Forbidden", "forbidden")
            return
        }
        if (bridge.isBridge(req)) {
            bridge.handle(req, out)
            return
        }
        if (req.path.startsWith("/local/static/")) {
            StaticController.serveApkFile(appCtx, req, out)
            return
        }
        if (req.path == "/local/index.html" && config != null && serveShell(config, req, out)) return
        if (req.path.startsWith("/local/assets/js/") && config != null) {
            StaticController.serveFile(req, out, File(config.assetDir, "js"), "text/javascript; charset=utf-8")
            return
        }
        if (req.path.startsWith("/local/assets/css/") && config != null) {
            StaticController.serveFile(req, out, File(config.assetDir, "css"), "text/css; charset=utf-8")
            return
        }
        if (config == null) {
            HttpWriter.reply(out, 503, "Not Configured", "not configured")
            return
        }
        val jsPrefix1 = config.webRoot + "/index.php/apps/memories/js/"
        val jsPrefix2 = config.webRoot + "/apps/memories/js/"
        if (req.method == "GET" && (req.path.startsWith(jsPrefix1) || req.path.startsWith(jsPrefix2))) {
            val name = req.path.substringAfterLast("/")
            val file = File(File(config.assetDir, "js"), name)
            if (name.isEmpty() || name.contains("..") || !file.isFile) {
                Log.w(TAG, "xx MISSING-CHUNK ${req.path}")
                HttpWriter.reply(out, 404, "Not Found", "not found")
                return
            }
            StaticController.serveFile(req, out, File(config.assetDir, "js"), "text/javascript; charset=utf-8")
            return
        }
        val appPrefix = config.webRoot + "/index.php/apps/memories"
        val isEntry = req.path == "/" || req.path == config.webRoot || req.path == config.webRoot + "/"
        val isAppRoute = req.path == appPrefix || req.path == "$appPrefix/" || req.path.startsWith("$appPrefix/")
        if (req.method == "GET" && (isEntry || isAppRoute) &&
            (isEntry || req.headers["accept"]?.contains("text/html") == true) &&
            serveShell(config, req, out)
        ) return
        proxy.forward(req, config, out)
        out.flush()
    }

    private fun serveShell(config: ServerConfig, req: HttpRequest, out: BufferedOutputStream): Boolean {
        val describe = assets.readDescribe(config.assetDir) ?: return false
        return ShellController.serveShell(config, req, out, describe, auth.credentials()?.first)
    }
}
