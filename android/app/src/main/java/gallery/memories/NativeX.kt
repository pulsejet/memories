package gallery.memories

import android.content.pm.ActivityInfo
import android.net.Uri
import android.os.VibrationEffect
import android.os.Vibrator
import android.util.Log
import android.view.SoundEffectConstants
import android.webkit.JavascriptInterface
import android.webkit.WebResourceResponse
import android.widget.Toast
import androidx.media3.common.util.UnstableApi
import gallery.memories.app.di.AppContainer
import gallery.memories.bridge.BridgeResponses
import gallery.memories.share.DownloadNotifier
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

    /** Application container to bridge API calls. */
    val container = AppContainer(mCtx, bridge = {
        method, url -> handleBridge(method, url)
    }, onAllowMedia = {
        doMediaSync(true)
    })

    /** Expose various services from the container. */
    val timeline get() = container.timeline
    val image get() = container.image
    val auth get() = container.auth
    val assets get() = container.assets
    val local get() = container.local
    val account get() = container.account
    val permissions get() = container.permissions
    val router get() = container.router
    val share: ShareManager get() = container.share

    /** Async listener for NativeX fire-and-forget interactions */
    private val asyncListener = NativeXAsync(mCtx, this)

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
        detachAsyncMessages()
        shareManager = null
        account.onDestroy()
        container.holeVideo.release()
        local.stop()
        timeline.destroy()
    }

    /** Fire-and-forget `nativex_async` WebMessage bridge. See [NativeXAsync]. */
    fun attachAsyncMessages() = asyncListener.attach()
    fun detachAsyncMessages() = asyncListener.detach()

    /** Bridge path patterns. Must stay in sync with the @regex docs in src/native/api.ts. */
    object API {
        val LOGIN = Regex("^/api/login/.+$")
        val DAYS = Regex("^/api/days$")
        val DAY = Regex("^/api/days/\\d+$")
        val IMAGE_INFO = Regex("^/api/image/info/\\d+$")
        val IMAGE_DELETE = Regex("^/api/image/delete/[0-9a-f]+(,[0-9a-f]+)*$")
        val IMAGE_PREVIEW = Regex("^/image/preview/\\d+$")
        val IMAGE_FULL = Regex("^/image/full/[0-9a-f]+$")
        val VIDEO_FULL = Regex("^/video/full/\\d+$")
        val SHARE_URL = Regex("^/api/share/url/.+$")
        val SHARE_BLOB = Regex("^/api/share/blobs$")
        val VIEW_IMAGE = Regex("^/api/view/image$")
        val VIEW_IMAGE_LOCAL = Regex("^/api/view/image/local$")
        val VIEW_VIDEO = Regex("^/api/view/video$")
        val UPLOAD_LOCAL = Regex("^/api/upload/local$")
        val CONFIG_ALLOW_MEDIA = Regex("^/api/config/allow_media/\\d+$")
        val CONFIG_ALLOW_MANAGE_MEDIA = Regex("^/api/config/allow_manage_media$")
        val ASSETS_PROGRESS = Regex("^/api/assets/progress$")
    }

    @JavascriptInterface
    fun isNative(): Boolean = true

    @JavascriptInterface
    fun getPlatform(): String = "android"

    /** No-op for feature detection; real registration happens via [NativeXAsync]. */
    @JavascriptInterface
    fun setupEventBus() = Unit

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

    /** Toggle HDR rendering for the activity window; off restores the default. */
    @JavascriptInterface
    fun setHdrColorMode(enabled: Boolean) {
        mCtx.runOnUiThread {
            mCtx.window.colorMode =
                if (enabled) ActivityInfo.COLOR_MODE_HDR
                else ActivityInfo.COLOR_MODE_DEFAULT
        }
    }

    /** Show/hide the native image below the WebView. */
    @JavascriptInterface
    fun showNativeImage(show: Boolean) {
        mCtx.runOnUiThread { container.holeImage.show(show) }
    }

    /** Move/resize the native image to the hole box (CSS px, may extend past the viewport). */
    @JavascriptInterface
    fun setNativeImageRect(x: Float, y: Float, w: Float, h: Float) {
        mCtx.runOnUiThread { container.holeImage.setRect(x, y, w, h) }
    }

    /** Show/hide the native video below the WebView. */
    @JavascriptInterface
    fun showNativeVideo(show: Boolean) {
        mCtx.runOnUiThread { container.holeVideo.show(show) }
    }

    /** Move/resize the native video to the hole box (CSS px, may extend past the viewport). */
    @JavascriptInterface
    fun setNativeVideoRect(x: Float, y: Float, w: Float, h: Float) {
        mCtx.runOnUiThread { container.holeVideo.setRect(x, y, w, h) }
    }

    /** Forwards one hole video control command; demuxed in [NativeHoleVideo]. */
    @JavascriptInterface
    fun nativeVideoCommand(json: String?) {
        if (json == null) return
        mCtx.runOnUiThread { container.holeVideo.handleCommand(json) }
    }

    @JavascriptInterface
    fun vibrate(duration: Long) {
        if (duration <= 0) return
        mCtx.getSystemService(Vibrator::class.java)?.vibrate(
            VibrationEffect.createOneShot(duration, VibrationEffect.DEFAULT_AMPLITUDE)
        )
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

    /** Downloads a file in-process with the app's own client. Notifies on completion. Nulls are ignored. */
    @JavascriptInterface
    fun downloadFromUrl(url: String?, filename: String?, title: String?) {
        if (url == null) return
        mCtx.threadPool.submit {
            try {
                permissions.requestNotificationsPermissionSync()
            } catch (_: Exception) {
            }
            val notifier = DownloadNotifier(mCtx)
            val label = title?.takeIf { it.isNotEmpty() }
            val failed = mCtx.getString(R.string.notif_download_failed)
            var progressId: Int? = null
            try {
                val files = shareManager?.downloadFile(url, filename ?: "") { name ->
                    progressId = progressId ?: notifier.start(label ?: name)
                } ?: throw Exception(failed)
                if (files.isEmpty()) throw Exception(failed)
                if (files.size == 1) notifier.success(files[0].name, files[0].uri, files[0].mimeType, label, progressId)
                else notifier.successMany(files.size, label, progressId)
            } catch (e: Exception) {
                Log.w(TAG, "downloadFromUrl failed: $url", e)
                notifier.failure(e.message ?: failed, label, progressId)
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

    /** True when silent local delete is allowed (no OS prompt). */
    @JavascriptInterface
    fun canManageMedia(): Boolean = permissions.canManageMedia()

    /** Playable video codecs, probed by the hole video player. */
    @JavascriptInterface
    fun getPlayableVideoCodecs(): String = container.holeVideo.getPlayableVideoCodecs()

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
