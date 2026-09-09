package gallery.memories.ui.startup

import android.util.Log
import androidx.media3.common.util.UnstableApi
import gallery.memories.MainActivity
import gallery.memories.data.remote.assets.AssetCache
import gallery.memories.data.remote.assets.AssetSyncCoordinator
import gallery.memories.data.remote.http.AuthState
import gallery.memories.server.LocalHttpServer

@UnstableApi
/** Boots the WebView: welcome when logged out, snapshot immediately when cached, waiting page while downloading. */
class AppStartupCoordinator(
    private val activity: MainActivity,
    private val auth: AuthState,
    private val assets: AssetSyncCoordinator,
    private val local: LocalHttpServer,
    private val toast: (String, Boolean) -> Unit,
) {
    companion object {
        private val TAG = AppStartupCoordinator::class.java.simpleName
    }

    fun localStaticUrl(name: String): String = local.origin() + "/local/static/$name"

    fun loadDefaultUrl(): Boolean {
        if (auth.baseUrl() != null) {
            startLocalApp()
            return true
        }
        local.resetSession()
        activity.setTransparentBars(true, true)
        activity.binding.webview.loadUrl(localStaticUrl("welcome.html"))
        return false
    }

    /** Cached snapshot loads at once with a background update; otherwise the waiting page blocks. */
    fun startLocalApp(toNxSetup: Boolean = false) {
        val base = auth.baseUrl() ?: return
        val subpath = if (toNxSetup) "nxsetup" else ""
        if (assets.hasSnapshot(base) && loadLocalApp(subpath)) {
            Thread { backgroundUpdateCheck(toNxSetup) }.start()
        } else {
            showWaitingAndEnsure(toNxSetup)
        }
    }

    /** Foreground update over the running app; keeps the setup subpath on first launch. */
    private fun backgroundUpdateCheck(toNxSetup: Boolean = false) {
        try {
            val fresh = assets.fetchDescribe() ?: return
            if (assets.isCurrent(fresh)) return
            Log.i(TAG, "Assets changed, updating in foreground")
            activity.runOnUiThread {
                activity.setTransparentBars(true, true)
                activity.binding.webview.loadUrl(localStaticUrl("waiting.html"))
            }
            if (!assets.syncAndAwait(fresh, 15 * 60 * 1000)) {
                activity.runOnUiThread { toast("Update failed", true) }
                return
            }
            val subpath = if (toNxSetup) "nxsetup" else ""
            activity.runOnUiThread {
                if (!loadLocalApp(subpath)) {
                    toast("Update failed", true)
                }
            }
        } catch (e: Exception) {
            Log.w(TAG, "Update check failed", e)
            activity.runOnUiThread { toast("Update check failed", true) }
        }
    }

    private fun showWaitingAndEnsure(toNxSetup: Boolean = false) {
        activity.host = "127.0.0.1"
        activity.setTransparentBars(true, true)
        activity.binding.webview.loadUrl(localStaticUrl("waiting.html"))
        Thread {
            try {
                val ok = assets.ensureFreshAndWait()
                Log.i(TAG, "Offline ensure done: $ok")
                activity.runOnUiThread {
                    if (ok) {
                        if (!loadLocalApp(if (toNxSetup) "nxsetup" else "")) {
                            Log.w(TAG, "Offline ensure ok but load failed")
                            toast("Could not load offline files", true)
                        }
                    } else {
                        toast("Could not load offline files", true)
                    }
                }
            } catch (e: Exception) {
                Log.w(TAG, "Offline ensure failed", e)
                activity.runOnUiThread { toast("Could not load offline files", true) }
            }
        }.start()
    }

    /** Serves the snapshot's shell locally. Session/CSRF heal inline on first use. */
    fun loadLocalApp(subpath: String = ""): Boolean {
        val base = auth.baseUrl() ?: return false
        val (_, dir) = assets.current ?: assets.latestSnapshot(base) ?: return false
        val describe = assets.readDescribe(dir) ?: return false
        val webRoot = AssetCache.webrootOf(base)
        local.configure(AssetCache.originOf(base), webRoot, dir, base)
        activity.host = "127.0.0.1"
        activity.binding.webview.clearHistory()
        activity.clearHistoryOnLoad = true
        val url = if (subpath.isEmpty()) local.origin() + "$webRoot/"
        else local.origin() + "$webRoot/index.php/apps/memories/$subpath"
        activity.binding.webview.loadUrl(url)
        return true
    }

    fun onLocalCookieReady() {
        val isApp = loadDefaultUrl()
        if (isApp) Thread { activity.nativex.account.checkCredentialsAndVersion() }.start()
    }
}
