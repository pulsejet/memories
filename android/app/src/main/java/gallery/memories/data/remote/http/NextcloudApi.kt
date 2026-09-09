package gallery.memories.data.remote.http

import android.webkit.CookieManager
import android.webkit.WebView
import androidx.core.net.toUri
import okhttp3.MediaType.Companion.toMediaTypeOrNull
import okhttp3.Request
import okhttp3.RequestBody.Companion.toRequestBody
import okhttp3.Response
import org.json.JSONArray
import org.json.JSONObject

class NextcloudApi(
    private val auth: AuthState,
    private val clients: HttpClients,
) {
    @Throws(Exception::class)
    fun bodyJson(response: Response): JSONObject? = getBody(response)?.let { JSONObject(it) }

    @Throws(Exception::class)
    fun bodyJsonArray(response: Response): JSONArray? = getBody(response)?.let { JSONArray(it) }

    @Throws(Exception::class)
    fun getBody(response: Response): String? {
        val body = response.body.string()
        response.body.close()
        return body
    }

    @Throws(Exception::class)
    fun getApiDescription(): Response = clients.client().newCall(buildGet("api/describe")).execute()

    /** Describe call that also carries the JS/CSS asset manifests for offline sync. */
    @Throws(Exception::class)
    fun getApiDescriptionManifest(): Response = clients.client().newCall(buildGet("api/describe?manifest=1")).execute()

    @Throws(Exception::class)
    fun downloadBytes(url: String): ByteArray {
        val request = Request.Builder().url(url).header("User-Agent", "Memories").get().build()
        clients.client().newCall(request).execute().use { res ->
            if (res.code != 200) throw Exception("HTTP ${res.code} for $url")
            return res.body.bytes()
        }
    }

    @Throws(Exception::class)
    fun postLoginFlow(loginFlowUrl: String): Response {
        return clients.client().newCall(
            Request.Builder().url(loginFlowUrl).header("User-Agent", "Memories")
                .post("".toRequestBody("application/json".toMediaTypeOrNull())).build(),
        ).execute()
    }

    @Throws(Exception::class)
    fun getPollLogin(pollUrl: String, pollToken: String): Response {
        return clients.client().newCall(
            Request.Builder().url(pollUrl)
                .post("token=$pollToken".toRequestBody("application/x-www-form-urlencoded".toMediaTypeOrNull())).build(),
        ).execute()
    }

    fun loadWebView(webView: WebView, subpath: String? = null): String? {
        val header = auth.authHeader()
        var url = auth.baseUrl()
        if (header != null && url != null) {
            if (subpath != null) url += subpath
            val host = url.toUri().host
            webView.clearHistory()
            CookieManager.getInstance().setCookie(url, "nx_auth=$header; Path=/; Domain=$host; HttpOnly")
            webView.loadUrl(url, mapOf("Authorization" to header))
            return host
        }
        return null
    }

    private fun buildGet(path: String, withAuth: Boolean = true): Request {
        val builder = Request.Builder().url(auth.baseUrl() + path).header("User-Agent", "Memories").get()
        if (withAuth) builder.header("Authorization", auth.authHeader() ?: "")
        return builder.build()
    }
}
