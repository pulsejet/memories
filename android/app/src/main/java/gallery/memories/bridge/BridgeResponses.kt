package gallery.memories.bridge

import android.net.Uri
import android.webkit.WebResourceResponse
import androidx.core.net.toUri
import java.io.ByteArrayInputStream

/** Builds bridge responses. Status must be set explicitly: the 3-arg constructor leaves it 0, which the socket server would write raw onto the wire. */
object BridgeResponses {
    fun ok(): ByteArray = "{\"message\":\"ok\"}".toByteArray()

    fun json(json: Any): WebResourceResponse = bytes(json.toString().toByteArray(), "application/json")

    fun bytes(bytes: ByteArray?, mimeType: String?): WebResourceResponse {
        if (bytes == null) return error()
        return WebResourceResponse(mimeType, "UTF-8", ByteArrayInputStream(bytes)).apply {
            setStatusCodeAndReasonPhrase(200, "OK")
        }
    }

    fun error(): WebResourceResponse {
        return WebResourceResponse("application/json", "UTF-8", ByteArrayInputStream("{}".toByteArray())).apply {
            setStatusCodeAndReasonPhrase(500, "Internal Server Error")
        }
    }

    fun withCors(res: WebResourceResponse, path: String, cacheImages: Boolean = false): WebResourceResponse {
        res.responseHeaders = mutableMapOf(
            "Access-Control-Allow-Origin" to "*",
            "Access-Control-Allow-Headers" to "*",
        )
        if (cacheImages) res.responseHeaders["Cache-Control"] = "max-age=604800"
        return res
    }

    fun emptyOk(): WebResourceResponse =
        WebResourceResponse("text/plain", "UTF-8", ByteArrayInputStream("".toByteArray())).apply {
            setStatusCodeAndReasonPhrase(200, "OK")
        }

    fun isImagePath(path: String, preview: Regex, full: Regex): Boolean =
        path.matches(preview) || path.matches(full)

    fun parseIds(ids: String): List<String> = ids.trim().split(",")

    fun decodeUris(urlsArray: String): List<Uri> {
        val arr = org.json.JSONArray(urlsArray)
        return List(arr.length()) { arr.getString(it).toUri() }
    }
}
