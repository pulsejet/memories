package gallery.memories

import android.net.Uri
import android.util.Log
import android.view.SoundEffectConstants
import android.webkit.JavascriptInterface
import android.webkit.WebResourceRequest
import android.webkit.WebResourceResponse
import android.widget.Toast
import gallery.memories.mapper.SystemImage
import gallery.memories.service.AccountService
import gallery.memories.service.DownloadService
import gallery.memories.service.ImageService
import gallery.memories.service.TimelineQuery
import org.json.JSONArray
import java.io.ByteArrayInputStream
import java.net.URLDecoder

class NativeX(private val mCtx: MainActivity) {
    val TAG = NativeX::class.java.simpleName

    private var themeStored = false
    val image = ImageService(mCtx)
    val query = TimelineQuery(mCtx)
    val account = AccountService(mCtx)

    object API {
        val DAYS = Regex("^/api/days$")
        val DAY = Regex("^/api/days/\\d+$")

        val IMAGE_INFO = Regex("^/api/image/info/\\d+$")
        val IMAGE_DELETE = Regex("^/api/image/delete/\\d+(,\\d+)*$")

        val IMAGE_PREVIEW = Regex("^/image/preview/\\d+$")
        val IMAGE_FULL = Regex("^/image/full/\\d+$")

        val SHARE_URL = Regex("^/api/share/url/.+$")
        val SHARE_BLOB = Regex("^/api/share/blob/.+$")
        val SHARE_LOCAL = Regex("^/api/share/local/\\d+$")

        val CONFIG_LOCAL_FOLDES = Regex("^/api/config/local-folders$")
    }

    init {
        dlService = DownloadService(mCtx)
    }

    companion object {
        var dlService: DownloadService? = null
    }

    fun destroy() {
        dlService = null
        query.destroy()
    }

    fun handleRequest(request: WebResourceRequest): WebResourceResponse {
        val path = request.url.path ?: return makeErrorResponse()

        val response = try {
            when (request.method) {
                "GET" -> {
                    routerGet(path)
                }
                "OPTIONS" -> {
                    WebResourceResponse("text/plain", "UTF-8", ByteArrayInputStream("".toByteArray()))
                }
                else -> {
                    throw Exception("Method Not Allowed")
                }
            }
        } catch (e: Exception) {
            Log.w(TAG, "handleRequest: ", e)
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

    @JavascriptInterface
    fun isNative(): Boolean {
        return true
    }

    @JavascriptInterface
    fun setThemeColor(color: String?, isDark: Boolean) {
        // Save for getting it back on next start
        if (!themeStored && account.authHeader != null) {
            themeStored = true
            mCtx.storeTheme(color, isDark);
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
    fun downloadFromUrl(url: String?, filename: String?) {
        if (url == null || filename == null) return;
        dlService!!.queue(url, filename)
    }

    @JavascriptInterface
    fun playVideoLocal(fileId: String?) {
        if (fileId == null) return;

        Thread {
            // Get URI of local video
            val videos = SystemImage.getByIds(mCtx, arrayListOf(fileId.toLong()))
            if (videos.isEmpty()) return@Thread
            val video = videos[0]

            // Play with exoplayer
            mCtx.runOnUiThread {
                mCtx.initializePlayer(arrayOf(video.uri), fileId)
            }
        }.start()
    }

    @JavascriptInterface
    fun playVideoRemote(fileId: String?, urlsArray: String?) {
        if (fileId == null || urlsArray == null) return
        val urls = JSONArray(urlsArray)
        val list = Array(urls.length()) {
            Uri.parse(urls.getString(it))
        }

        mCtx.runOnUiThread {
            mCtx.initializePlayer(list, fileId)
        }
    }

    @JavascriptInterface
    fun destroyVideo(fileId: String?) {
        if (fileId == null) return;
        mCtx.runOnUiThread {
            mCtx.destroyPlayer(fileId)
        }
    }

    @JavascriptInterface
    fun configSetLocalFolders(json: String?) {
        if (json == null) return;
        query.localFolders = JSONArray(json)
    }

    @JavascriptInterface
    fun login(baseUrl: String?, loginFlowUrl: String?) {
        if (baseUrl == null || loginFlowUrl == null) return;
        account.login(baseUrl, loginFlowUrl)
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

    @Throws(Exception::class)
    private fun routerGet(path: String): WebResourceResponse {
        val parts = path.split("/").toTypedArray()
        return if (path.matches(API.DAYS)) {
            makeResponse(query.getDays())
        } else if (path.matches(API.DAY)) {
            makeResponse(query.getByDayId(parts[3].toLong()))
        } else if (path.matches(API.IMAGE_INFO)) {
            makeResponse(query.getImageInfo(parts[4].toLong()))
        } else if (path.matches(API.IMAGE_DELETE)) {
            makeResponse(query.delete(parseIds(parts[4])))
        } else if (path.matches(API.IMAGE_PREVIEW)) {
            makeResponse(image.getPreview(parts[3].toLong()), "image/jpeg")
        } else if (path.matches(API.IMAGE_FULL)) {
            makeResponse(image.getFull(parts[3].toLong()), "image/jpeg")
        } else if (path.matches(API.SHARE_URL)) {
            makeResponse(dlService!!.shareUrl(URLDecoder.decode(parts[4], "UTF-8")))
        } else if (path.matches(API.SHARE_BLOB)) {
            makeResponse(dlService!!.shareBlobFromUrl(URLDecoder.decode(parts[4], "UTF-8")))
        } else if (path.matches(API.SHARE_LOCAL)) {
            makeResponse(dlService!!.shareLocal(parts[4].toLong()))
        } else if (path.matches(API.CONFIG_LOCAL_FOLDES)) {
            makeResponse(query.localFolders)
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
        val response = WebResourceResponse("application/json", "UTF-8", ByteArrayInputStream("{}".toByteArray()))
        response.setStatusCodeAndReasonPhrase(500, "Internal Server Error")
        return response
    }

    private fun parseIds(ids: String): List<Long> {
        return ids.trim().split(",").map { it.toLong() }
    }
}