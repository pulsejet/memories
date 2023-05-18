package gallery.memories

import android.net.Uri
import android.util.Log
import android.view.SoundEffectConstants
import android.webkit.JavascriptInterface
import android.webkit.WebResourceRequest
import android.webkit.WebResourceResponse
import android.widget.Toast
import androidx.media3.common.util.UnstableApi
import gallery.memories.mapper.SystemImage
import gallery.memories.service.AccountService
import gallery.memories.service.DownloadService
import gallery.memories.service.ImageService
import gallery.memories.service.TimelineQuery
import java.io.ByteArrayInputStream
import java.net.URLDecoder

@UnstableApi class NativeX(private val mActivity: MainActivity) {
    val TAG = "NativeX"

    private var themeStored = false
    val mImageService = ImageService(mActivity)
    val mQuery = TimelineQuery(mActivity)
    val mAccountService = AccountService(mActivity)

    object API {
        val DAYS = Regex("^/api/days$")
        val DAY = Regex("^/api/days/\\d+$")
        val IMAGE_INFO = Regex("^/api/image/info/\\d+$")
        val IMAGE_DELETE = Regex("^/api/image/delete/\\d+(,\\d+)*$")

        val IMAGE_PREVIEW = Regex("^/image/preview/\\d+$")
        val IMAGE_FULL = Regex("^/image/full/\\d+$")

        val SHARE_URL = Regex("/api/share/url/.+$")
        val SHARE_BLOB = Regex("/api/share/blob/.+$")
        val SHARE_LOCAL = Regex("/api/share/local/\\d+$")
    }

    init {
        mDlService = DownloadService(mActivity)

        // Synchronize the database if possible
        if (mActivity.hasMediaPermission()) {
            mQuery.syncDeltaDb()
        }
    }

    companion object {
        var mDlService: DownloadService? = null
    }

    fun destroy() {
        mDlService = null
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
            Log.e(TAG, "handleRequest: ", e)
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

    @get:JavascriptInterface
    val isNative: Boolean
        get() = true

    @JavascriptInterface
    fun toast(message: String) {
        mActivity.runOnUiThread {
            Toast.makeText(mActivity, message, Toast.LENGTH_LONG).show()
        }
    }

    @JavascriptInterface
    fun login(baseUrl: String?, loginFlowUrl: String?) {
        if (baseUrl == null || loginFlowUrl == null) return;
        mAccountService.login(baseUrl, loginFlowUrl)
    }

    @JavascriptInterface
    fun logout() {
        mAccountService.loggedOut()
    }

    @JavascriptInterface
    fun setThemeColor(color: String?, isDark: Boolean) {
        // Save for getting it back on next start
        if (!themeStored) {
            themeStored = true
            mActivity.storeTheme(color, isDark);
        }

        // Apply the theme
        mActivity.runOnUiThread {
            mActivity.applyTheme(color, isDark)
        }
    }

    @JavascriptInterface
    fun downloadFromUrl(url: String?, filename: String?) {
        if (url == null || filename == null) return;
        mDlService!!.queue(url, filename)
    }

    @JavascriptInterface
    fun playTouchSound() {
        mActivity.runOnUiThread {
            mActivity.binding.webview.playSoundEffect(SoundEffectConstants.CLICK)
        }
    }

    @JavascriptInterface
    fun playVideoLocal(fileId: String?) {
        if (fileId == null) return;

        Thread {
            // Get URI of local video
            val videos = SystemImage.getByIds(mActivity, arrayListOf(fileId.toLong()))
            if (videos.isEmpty()) return@Thread
            val video = videos[0]

            // Play with exoplayer
            mActivity.runOnUiThread {
                mActivity.initializePlayer(video.uri, fileId)
            }
        }.start()
    }

    @JavascriptInterface
    fun playVideoHls(fileId: String?, url: String?) {
        if (fileId == null || url == null) return
        mActivity.runOnUiThread {
            mActivity.initializePlayer(Uri.parse(url), fileId)
        }
    }

    @JavascriptInterface
    fun destroyVideo(fileId: String?) {
        if (fileId == null) return;
        mActivity.runOnUiThread {
            mActivity.destroyPlayer(fileId)
        }
    }

    @Throws(Exception::class)
    private fun routerGet(path: String): WebResourceResponse {
        val parts = path.split("/").toTypedArray()
        if (path.matches(API.IMAGE_PREVIEW)) {
            return makeResponse(mImageService.getPreview(parts[3].toLong()), "image/jpeg")
        } else if (path.matches(API.IMAGE_FULL)) {
            return makeResponse(mImageService.getFull(parts[3].toLong()), "image/jpeg")
        } else if (path.matches(API.IMAGE_INFO)) {
            return makeResponse(mQuery.getImageInfo(parts[4].toLong()))
        } else if (path.matches(API.IMAGE_DELETE)) {
            return makeResponse(mQuery.delete(parseIds(parts[4])))
        } else if (path.matches(API.DAYS)) {
            return makeResponse(mQuery.getDays())
        } else if (path.matches(API.DAY)) {
            return makeResponse(mQuery.getByDayId(parts[3].toLong()))
        } else if (path.matches(API.SHARE_URL)) {
            return makeResponse(mDlService!!.shareUrl(URLDecoder.decode(parts[4], "UTF-8")))
        } else if (path.matches(API.SHARE_BLOB)) {
            return makeResponse(mDlService!!.shareBlobFromUrl(URLDecoder.decode(parts[4], "UTF-8")))
        } else if (path.matches(API.SHARE_LOCAL)) {
            return makeResponse(mDlService!!.shareLocal(parts[4].toLong()))
        } else {
            throw Exception("Not Found")
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
        return ids.split(",").map { it.toLong() }
    }
}