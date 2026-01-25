package gallery.memories

import android.util.Log
import android.view.SoundEffectConstants
import android.webkit.JavascriptInterface
import android.webkit.WebResourceRequest
import android.webkit.WebResourceResponse
import android.widget.Toast
import androidx.media3.common.util.UnstableApi
import gallery.memories.service.AccountService
import gallery.memories.service.DownloadService
import gallery.memories.service.HttpService
import gallery.memories.service.ImageService
import gallery.memories.service.PermissionsService
import gallery.memories.service.TimelineQuery
import org.json.JSONArray
import java.io.ByteArrayInputStream
import java.net.URLDecoder
import androidx.core.net.toUri

@UnstableApi
class NativeX(private val mCtx: MainActivity) {
    private var themeStored = false
    val query = TimelineQuery(mCtx)
    val image = ImageService(mCtx, query)
    val http = HttpService()
    val account = AccountService(mCtx, http)
    val permissions = PermissionsService(mCtx).register()

    init {
        dlService = DownloadService(mCtx, query)
    }

    companion object {
        var dlService: DownloadService? = null
        val TAG: String = NativeX::class.java.simpleName
    }

    fun destroy() {
        dlService = null
        query.destroy()
    }

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
    }

    @JavascriptInterface
    fun isNative(): Boolean {
        return true
    }

    @JavascriptInterface
    fun setThemeColor(color: String?, isDark: Boolean) {
        // Save for getting it back on next start
        if (!themeStored && http.isLoggedIn()) {
            themeStored = true
            mCtx.storeTheme(color, isDark)
        }

        // Apply the theme
        mCtx.runOnUiThread {
            mCtx.applyTheme(color, isDark)
        }
    }

    @JavascriptInterface
    fun playTouchSound() {
        mCtx.runOnUiThread {
            mCtx.binding.webview.playSoundEffect(SoundEffectConstants.CLICK)
        }
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
        mCtx.runOnUiThread {
            mCtx.loadDefaultUrl()
        }
    }

    @JavascriptInterface
    fun downloadFromUrl(url: String?, filename: String?) {
        if (url == null || filename == null) return
        dlService!!.queue(url, filename)
    }

    @JavascriptInterface
    fun setShareBlobs(objects: String?) {
        if (objects == null) return
        dlService!!.setShareBlobs(JSONArray(objects))
    }

    @JavascriptInterface
    fun playVideo(auid: String, fileid: Long, urlsArray: String) {
        mCtx.threadPool.submit {
            // Get URI of remote videos
            val urls = JSONArray(urlsArray)
            val list = Array(urls.length()) {
                urls.getString(it).toUri()
            }

            // Get URI of local video
            val videos = query.getSystemImagesByAUIDs(arrayListOf(auid))

            // Play with exoplayer
            mCtx.runOnUiThread {
                if (!videos.isEmpty()) {
                    mCtx.initializePlayer(arrayOf(videos[0].uri), fileid)
                } else {
                    mCtx.initializePlayer(list, fileid)
                }
            }
        }
    }

    @JavascriptInterface
    fun destroyVideo(fileid: Long) {
        mCtx.runOnUiThread {
            mCtx.destroyPlayer(fileid)
        }
    }

    @JavascriptInterface
    fun configSetLocalFolders(json: String?) {
        if (json == null) return
        query.localFolders = JSONArray(json)
    }

    @JavascriptInterface
    fun configGetLocalFolders(): String {
        return query.localFolders.toString()
    }

    @JavascriptInterface
    fun configHasMediaPermission(): Boolean {
        return permissions.hasAllowMedia() && permissions.hasMediaPermission()
    }

    @JavascriptInterface
    fun getSyncStatus(): Int {
        return query.syncStatus
    }

    @JavascriptInterface
    fun setHasRemote(auids: String, buids: String, value: Boolean) {
        Log.v(TAG, "setHasRemote: auids=$auids, buids=$buids, value=$value")
        mCtx.threadPool.submit {
            val auidArray = JSONArray(auids)
            val buidArray = JSONArray(buids)
            query.setHasRemote(
                List(auidArray.length()) { auidArray.getString(it) },
                List(buidArray.length()) { buidArray.getString(it) },
                value
            )
        }
    }

    fun handleRequest(request: WebResourceRequest): WebResourceResponse {
        val path = request.url.path ?: return makeErrorResponse()

        val response = try {
            when (request.method) {
                "GET" -> {
                    routerGet(request)
                }

                "OPTIONS" -> {
                    WebResourceResponse(
                        "text/plain",
                        "UTF-8",
                        ByteArrayInputStream("".toByteArray())
                    )
                }

                else -> {
                    throw Exception("Method Not Allowed")
                }
            }
        } catch (e: Exception) {
            Log.w(TAG, "handleRequest: " + e.message)
            makeErrorResponse()
        }

        // Allow CORS from all origins
        response.responseHeaders = mutableMapOf(
            "Access-Control-Allow-Origin" to "*",
            "Access-Control-Allow-Headers" to "*"
        )

        // Cache image responses for 7 days
        if (path.matches(API.IMAGE_PREVIEW) || path.matches(API.IMAGE_FULL)) {
            response.responseHeaders["Cache-Control"] = "max-age=604800"
        }

        return response
    }

    @Throws(Exception::class)
    private fun routerGet(request: WebResourceRequest): WebResourceResponse {
        val path = request.url.path ?: return makeErrorResponse()

        val parts = path.split("/").toTypedArray()
        return if (path.matches(API.LOGIN)) {
            makeResponse(
                account.login(
                    URLDecoder.decode(parts[3], "UTF-8"),
                    request.url.getBooleanQueryParameter("trustAll", false)
                )
            )
        } else if (path.matches(API.DAYS)) {
            makeResponse(query.getDays())
        } else if (path.matches(API.DAY)) {
            makeResponse(query.getDay(parts[3].toLong()))
        } else if (path.matches(API.IMAGE_INFO)) {
            makeResponse(query.getImageInfo(parts[4].toLong()))
        } else if (path.matches(API.IMAGE_DELETE)) {
            makeResponse(
                query.delete(
                    parseIds(parts[4]),
                    request.url.getBooleanQueryParameter("dry", false)
                )
            )
        } else if (path.matches(API.IMAGE_PREVIEW)) {
            val x = request.url.getQueryParameter("x")?.toInt()
            val y = request.url.getQueryParameter("y")?.toInt()
            makeResponse(image.getPreview(parts[3].toLong(), x, y), "image/jpeg")
        } else if (path.matches(API.IMAGE_FULL)) {
            val size = request.url.getQueryParameter("size")?.toInt()
            makeResponse(image.getFull(parts[3], size), "image/jpeg")
        } else if (path.matches(API.SHARE_URL)) {
            makeResponse(dlService!!.shareUrl(URLDecoder.decode(parts[4], "UTF-8")))
        } else if (path.matches(API.SHARE_BLOB)) {
            makeResponse(dlService!!.shareBlobs())
        } else if (path.matches(API.CONFIG_ALLOW_MEDIA)) {
            permissions.setAllowMedia(true)
            if (permissions.requestMediaPermissionSync()) {
                doMediaSync(true) // separate thread
            }
            makeResponse("done")
        } else {
            throw Exception("Path did not match any known API route: $path")
        }
    }

    private fun makeResponse(bytes: ByteArray?, mimeType: String?): WebResourceResponse {
        return if (bytes != null) {
            WebResourceResponse(mimeType, "UTF-8", ByteArrayInputStream(bytes))
        } else makeErrorResponse()
    }

    private fun makeResponse(json: Any): WebResourceResponse {
        return makeResponse(json.toString().toByteArray(), "application/json")
    }

    private fun makeErrorResponse(): WebResourceResponse {
        val response = WebResourceResponse(
            "application/json",
            "UTF-8",
            ByteArrayInputStream("{}".toByteArray())
        )
        response.setStatusCodeAndReasonPhrase(500, "Internal Server Error")
        return response
    }

    private fun parseIds(ids: String): List<String> {
        return ids.trim().split(",")
    }

    fun doMediaSync(forceFull: Boolean) {
        if (permissions.hasAllowMedia()) {
            // Full sync if this is the first time permission was granted
            val fullSync = forceFull || !permissions.hasMediaPermission()

            mCtx.threadPool.submit {
                // Block for media permission
                if (!permissions.requestMediaPermissionSync()) return@submit

                // Full sync requested
                if (fullSync) query.syncFullDb()

                // Run delta sync and register hooks
                query.initialize()
            }
        }
    }
}