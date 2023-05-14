package gallery.memories

import android.R
import android.graphics.Color
import android.net.Uri
import android.os.Build
import android.util.Log
import android.view.View
import android.view.WindowInsetsController
import android.webkit.JavascriptInterface
import android.webkit.WebResourceRequest
import android.webkit.WebResourceResponse
import androidx.media3.common.util.UnstableApi
import gallery.memories.mapper.SystemImage
import gallery.memories.service.DownloadService
import gallery.memories.service.ImageService
import gallery.memories.service.TimelineQuery
import java.io.ByteArrayInputStream
import java.net.URLDecoder

@UnstableApi class NativeX(private val mActivity: MainActivity) {
    val TAG = "NativeX"

    private val mImageService: ImageService = ImageService(mActivity)
    private val mQuery: TimelineQuery = TimelineQuery(mActivity)

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
        response.responseHeaders = mapOf(
            "Access-Control-Allow-Origin" to "*",
            "Access-Control-Allow-Headers" to "*"
        )
        return response
    }

    @get:JavascriptInterface
    val isNative: Boolean
        get() = true

    @JavascriptInterface
    fun setThemeColor(color: String?, isDark: Boolean) {
        mActivity.runOnUiThread {
            val window = mActivity.window
            mActivity.setTheme(if (isDark) R.style.Theme_Black else R.style.Theme_Light)
            window.navigationBarColor = Color.parseColor(color)
            window.statusBarColor = Color.parseColor(color)
            if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.R) {
                val appearance = WindowInsetsController.APPEARANCE_LIGHT_STATUS_BARS or WindowInsetsController.APPEARANCE_LIGHT_NAVIGATION_BARS
                window.insetsController?.setSystemBarsAppearance(if (isDark) 0 else appearance, appearance)
            } else {
                window.decorView.systemUiVisibility = if (isDark) 0 else View.SYSTEM_UI_FLAG_LIGHT_STATUS_BAR or View.SYSTEM_UI_FLAG_LIGHT_NAVIGATION_BAR
            }
        }
    }

    @JavascriptInterface
    fun downloadFromUrl(url: String?, filename: String?) {
        if (url == null || filename == null) return;
        mDlService!!.queue(url, filename)
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