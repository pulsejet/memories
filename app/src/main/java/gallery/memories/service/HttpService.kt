package gallery.memories.service

import android.net.Uri
import android.util.Base64
import android.webkit.WebView
import okhttp3.MediaType.Companion.toMediaTypeOrNull
import okhttp3.OkHttpClient
import okhttp3.Request
import okhttp3.RequestBody.Companion.toRequestBody
import okhttp3.Response
import org.json.JSONArray
import org.json.JSONObject

class HttpService {
    companion object {
        val TAG = HttpService::class.java.simpleName
    }

    private var authHeader: String? = null
    private var memoriesUrl: String? = null

    /**
     * Check if the HTTP service is logged in
     */
    fun isLoggedIn(): Boolean {
        return authHeader != null
    }

    /**
     * Set the Memories URL
     * @param url The URL to use
     */
    fun setBaseUrl(url: String?) {
        memoriesUrl = url
    }

    /**
     * Set the authorization header
     * @param credentials The credentials to use
     */
    fun setAuthHeader(credentials: Pair<String, String>?) {
        if (credentials != null) {
            val auth = "${credentials.first}:${credentials.second}"
            authHeader = "Basic ${Base64.encodeToString(auth.toByteArray(), Base64.NO_WRAP)}"
            return
        }
        authHeader = null
    }

    /**
     * Load a webview at the default page
     * @param webView The webview to load
     * @return Host URL if authenticated, null otherwise
     */
    fun loadWebView(webView: WebView): String? {
        // Load app interface if authenticated
        if (authHeader != null && memoriesUrl != null) {
            // Get host name
            val host = Uri.parse(memoriesUrl).host

            // Set authorization header
            webView.loadUrl(memoriesUrl!!, mapOf("Authorization" to authHeader))

            return host
        }

        return null
    }

    /** Get body as JSON Object */
    @Throws(Exception::class)
    fun bodyJson(response: Response): JSONObject? {
        return getBody(response)?.let { JSONObject(it) }
    }

    /** Get body as JSON array */
    @Throws(Exception::class)
    fun bodyJsonArray(response: Response): JSONArray? {
        return getBody(response)?.let { JSONArray(it) }
    }

    /** Get a string from the response body */
    @Throws(Exception::class)
    fun getBody(response: Response): String? {
        val body = response.body?.string()
        response.body?.close()
        return body
    }

    /** Get the API description request */
    @Throws(Exception::class)
    fun getApiDescription(): Response {
        return runRequest(buildGet("api/describe"))
    }

    /** Make login flow request */
    @Throws(Exception::class)
    fun postLoginFlow(loginFlowUrl: String): Response {
        return runRequest(Request.Builder()
            .url(loginFlowUrl)
            .header("User-Agent", "Memories")
            .post("".toRequestBody("application/json".toMediaTypeOrNull()))
            .build())
    }

    /** Run a request and get a JSON object */
    @Throws(Exception::class)
    private fun runRequest(request: Request): Response {
        return OkHttpClient().newCall(request).execute()
    }

    /** Build a GET request */
    private fun buildGet(path: String, auth: Boolean = true): Request {
        val builder = Request.Builder()
            .url(memoriesUrl + path)
            .header("User-Agent", "Memories")
            .get()

        if (auth)
            builder.header("Authorization", authHeader ?: "")

        return builder.build()
    }
}