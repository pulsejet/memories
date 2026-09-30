package gallery.memories.bridge

import android.webkit.WebResourceResponse
import java.io.ByteArrayInputStream

/** Builds bridge responses. Status must be set explicitly: the 3-arg constructor leaves it 0, which the socket server would write raw onto the wire. */
object BridgeResponses {
    /** Serializes any JSON value (JSONObject, JSONArray, String, Boolean). */
    fun json(json: Any): WebResourceResponse = bytes(json.toString().toByteArray(), "application/json")

    /** Null bytes become a 500; every other response is 200. */
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

    /** Splits a comma-separated id list, dropping blanks from stray commas. */
    fun parseIds(ids: String): List<String> = ids.split(",").map { it.trim() }.filter { it.isNotEmpty() }
}
