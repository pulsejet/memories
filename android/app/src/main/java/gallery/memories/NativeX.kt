package gallery.memories

import android.net.Uri
import android.util.Log
import android.view.SoundEffectConstants
import android.webkit.JavascriptInterface
import android.webkit.WebResourceResponse
import android.widget.Toast
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
        /**
         * Latest live [ShareManager], for the manifest-registered download receiver
         * which cannot receive it via intent. Nulled in [destroy].
         */
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
        val UPLOAD_LOCAL = Regex("^/api/upload/local$")
        val CONFIG_ALLOW_MEDIA = Regex("^/api/config/allow_media/\\d+$")
        val ASSETS_PROGRESS = Regex("^/api/assets/progress$")
    }

    @JavascriptInterface
    fun isNative(): Boolean = true

    /** Lets entry pages draw under transparent system bars. */
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

    /** Short or long toast on the UI thread. Safe to call from any thread. */
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

    /** Reloads whatever [MainActivity.loadDefaultUrl] resolves to (app or welcome). */
    @JavascriptInterface
    fun reload() {
        mCtx.runOnUiThread { mCtx.loadDefaultUrl() }
    }

    /** Downloads a file in-process with the app's own client. Toasts success/failure. Nulls are ignored. */
    @JavascriptInterface
    fun downloadFromUrl(url: String?, filename: String?) {
        if (url == null || filename == null) return
        mCtx.threadPool.submit {
            try {
                shareManager?.downloadFile(url, filename)
                mCtx.runOnUiThread { android.widget.Toast.makeText(mCtx, "Download complete: $filename", android.widget.Toast.LENGTH_SHORT).show() }
            } catch (e: Exception) {
                Log.w(TAG, "downloadFromUrl failed: $url", e)
                mCtx.runOnUiThread { android.widget.Toast.makeText(mCtx, "Download failed: ${e.message}", android.widget.Toast.LENGTH_LONG).show() }
            }
        }
    }

    /** Stages share blobs (as a JSON array string) for a later /api/share/blobs call. */
    @JavascriptInterface
    fun setShareBlobs(objects: String?) {
        if (objects == null) return
        shareManager?.setShareBlobs(JSONArray(objects))
    }

    /** Backwards-compatible alias of [playVideo2] without looping. */
    @JavascriptInterface
    fun playVideo(auid: String, fileid: Long, urlsArray: String) {
        this.playVideo2(auid, fileid, urlsArray, false)
    }

    /** Plays the on-device copy when indexed, else the provided remote URLs. */
    @JavascriptInterface
    fun playVideo2(auid: String, fileid: Long, urlsArray: String, loop: Boolean = false) {
        mCtx.threadPool.submit {
            val urls = JSONArray(urlsArray)
            val list = Array(urls.length()) { Uri.parse(urls.getString(it)) }
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

    /** Persists the folder selection from the setup UI. */
    @JavascriptInterface
    fun configSetLocalFolders(json: String?) {
        if (json == null) return
        timeline.localFolders = JSONArray(json)
    }

    /** Current folder selection as JSON. */
    @JavascriptInterface
    fun configGetLocalFolders(): String = timeline.localFolders.toString()

    /** True once the user opted in and the OS permission is granted. */
    @JavascriptInterface
    fun configHasMediaPermission(): Boolean =
        permissions.hasAllowMedia() && permissions.hasMediaPermission()

    /** Indexed-file count, or -1 when no sync is running. */
    @JavascriptInterface
    fun getSyncStatus(): Int = timeline.syncStatus

    /** Hides indexed files already present on the server. Runs off the UI thread. */
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
        // Merge CORS headers so any headers set by the route survive.
        val headers = (response.responseHeaders ?: emptyMap()).toMutableMap()
        headers["Access-Control-Allow-Origin"] = "*"
        headers["Access-Control-Allow-Headers"] = "*"
        response.responseHeaders = headers
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
