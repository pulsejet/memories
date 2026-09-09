package gallery.memories

import android.net.Uri
import android.util.Log
import android.view.SoundEffectConstants
import android.webkit.JavascriptInterface
import android.webkit.WebResourceResponse
import android.widget.Toast
import androidx.core.net.toUri
import androidx.media3.common.util.UnstableApi
import gallery.memories.app.di.AppContainer
import gallery.memories.bridge.BridgeResponses
import gallery.memories.share.ShareManager
import org.json.JSONArray
import java.io.ByteArrayInputStream

@UnstableApi
/**
 * Injected into the WebView as `nativex`: sync calls below plus the localhost
 * bridge (fetch /api/… and /image/… against 127.0.0.1).
 */
class NativeX(private val mCtx: MainActivity) {
    private var themeStored = false

    val container = AppContainer(mCtx, bridge = { method, url -> handleBridge(method, url) }, onAllowMedia = { doMediaSync(true) })

    val timeline get() = container.timeline
    val query get() = container.timeline
    val image get() = container.image
    val auth get() = container.auth
    val assets get() = container.assets
    val local get() = container.local
    val account get() = container.account
    val permissions get() = container.permissions
    val router get() = container.router
    val share: ShareManager get() = container.share

    init {
        shareManager = container.share
    }

    companion object {
        var shareManager: ShareManager? = null
        val TAG: String = NativeX::class.java.simpleName
    }

    fun destroy() {
        shareManager = null
        local.stop()
        timeline.destroy()
    }

    /** Bridge path patterns. Must stay in sync with the @regex docs in src/native/api.ts. */
    object API {
        val LOGIN = Regex("^/api/login/.+$")
        val DAYS = Regex("^/api/days$")
        val DAY = Regex("^/api/days/\\d+$")
        val IMAGE_INFO = Regex("^/api/image/info/\\d+$")
        val IMAGE_DELETE = Regex("^/api/image/delete/[0-9a-f]+(,[0-9a-f]+)*$")
        val IMAGE_PREVIEW = Regex("^/image/preview/\\d+$")
        val IMAGE_FULL = Regex("^/image/full/[0-9a-f]+$")
        val SHARE_URL = Regex("^/api/share/url/.+$")
        val SHARE_BLOB = Regex("^/api/share/blobs$")
        val CONFIG_ALLOW_MEDIA = Regex("^/api/config/allow_media/\\d+$")
        val ASSETS_PROGRESS = Regex("^/api/assets/progress$")
    }

    @JavascriptInterface
    fun isNative(): Boolean = true

    @JavascriptInterface
    fun setTransparentBars(transparent: Boolean, isDark: Boolean) {
        mCtx.runOnUiThread { mCtx.setTransparentBars(transparent, isDark) }
    }

    /** Persists the theme once per login, then just applies it. */
    @JavascriptInterface
    fun setThemeColor(color: String?, isDark: Boolean) {
        if (!themeStored && auth.isLoggedIn()) {
            themeStored = true
            mCtx.storeTheme(color, isDark)
        }
        mCtx.runOnUiThread { mCtx.applyTheme(color, isDark) }
    }

    @JavascriptInterface
    fun playTouchSound() {
        mCtx.runOnUiThread { mCtx.binding.webview.playSoundEffect(SoundEffectConstants.CLICK) }
    }

    @JavascriptInterface
    fun toast(message: String, long: Boolean = false) {
        mCtx.runOnUiThread {
            val duration = if (long) Toast.LENGTH_LONG else Toast.LENGTH_SHORT
            Toast.makeText(mCtx, message, duration).show()
        }
    }

    @JavascriptInterface
    fun logout() {
        account.loggedOut()
    }

    @JavascriptInterface
    fun reload() {
        mCtx.runOnUiThread { mCtx.loadDefaultUrl() }
    }

    @JavascriptInterface
    fun downloadFromUrl(url: String?, filename: String?) {
        if (url == null || filename == null) return
        shareManager?.queue(url, filename)
    }

    @JavascriptInterface
    fun setShareBlobs(objects: String?) {
        if (objects == null) return
        shareManager?.setShareBlobs(JSONArray(objects))
    }

    @JavascriptInterface
    fun playVideo(auid: String, fileid: Long, urlsArray: String) {
        this.playVideo2(auid, fileid, urlsArray, false)
    }

    /** Plays the on-device copy when indexed, else the provided remote URLs. */
    @JavascriptInterface
    fun playVideo2(auid: String, fileid: Long, urlsArray: String, loop: Boolean = false) {
        mCtx.threadPool.submit {
            val urls = JSONArray(urlsArray)
            val list = Array(urls.length()) { urls.getString(it).toUri() }
            val videos = timeline.getSystemImagesByAUIDs(arrayListOf(auid))
            mCtx.runOnUiThread {
                if (videos.isNotEmpty()) {
                    mCtx.initializePlayer(arrayOf(videos[0].uri), fileid, loop)
                } else {
                    mCtx.initializePlayer(list, fileid, loop)
                }
            }
        }
    }

    @JavascriptInterface
    fun destroyVideo(fileid: Long) {
        mCtx.runOnUiThread { mCtx.destroyPlayer(fileid) }
    }

    @JavascriptInterface
    fun configSetLocalFolders(json: String?) {
        if (json == null) return
        timeline.localFolders = JSONArray(json)
    }

    @JavascriptInterface
    fun configGetLocalFolders(): String = timeline.localFolders.toString()

    @JavascriptInterface
    fun configHasMediaPermission(): Boolean =
        permissions.hasAllowMedia() && permissions.hasMediaPermission()

    @JavascriptInterface
    fun getSyncStatus(): Int = timeline.syncStatus

    @JavascriptInterface
    fun setHasRemote(auids: String, buids: String, value: Boolean) {
        Log.v(TAG, "setHasRemote: auids=$auids, buids=$buids, value=$value")
        mCtx.threadPool.submit {
            val auidArray = JSONArray(auids)
            val buidArray = JSONArray(buids)
            timeline.setHasRemote(
                List(auidArray.length()) { auidArray.getString(it) },
                List(buidArray.length()) { buidArray.getString(it) },
                value,
            )
        }
    }

    /** Serves one bridge request. GET-only; failures become 500, images get week-long caching. */
    fun handleBridge(method: String, url: Uri): WebResourceResponse {
        val path = url.path ?: return BridgeResponses.error()
        val response = try {
            when (method) {
                "GET" -> router.routerGet(url)
                "OPTIONS" -> WebResourceResponse("text/plain", "UTF-8", ByteArrayInputStream("".toByteArray())).apply {
                    setStatusCodeAndReasonPhrase(200, "OK")
                }
                else -> throw Exception("Method Not Allowed")
            }
        } catch (e: Exception) {
            Log.w(TAG, "handleBridge: $method $path failed", e)
            BridgeResponses.error()
        }
        response.responseHeaders = mutableMapOf(
            "Access-Control-Allow-Origin" to "*",
            "Access-Control-Allow-Headers" to "*",
        )
        if (path.matches(API.IMAGE_PREVIEW) || path.matches(API.IMAGE_FULL)) {
            response.responseHeaders["Cache-Control"] = "max-age=604800"
        }
        return response
    }

    /** Delta sync normally; a first-time media grant forces a full sync instead. */
    fun doMediaSync(forceFull: Boolean) {
        if (permissions.hasAllowMedia()) {
            val fullSync = forceFull || !permissions.hasMediaPermission()
            mCtx.threadPool.submit {
                if (!permissions.requestMediaPermissionSync()) return@submit
                if (fullSync) timeline.syncFullDb()
                timeline.initialize()
            }
        }
    }
}
