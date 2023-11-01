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
import java.security.SecureRandom
import java.security.cert.CertificateException
import java.security.cert.X509Certificate
import javax.net.ssl.SSLContext
import javax.net.ssl.TrustManager
import javax.net.ssl.X509TrustManager


class HttpService {
    companion object {
        val TAG = HttpService::class.java.simpleName
    }

    private var client = OkHttpClient()
    private var authHeader: String? = null
    private var mBaseUrl: String? = null
    private var mTrustAll = false

    /**
     * Check if all certificates are trusted
     */
    val isTrustingAllCertificates: Boolean
        get() = mTrustAll

    /**
     * Check if the HTTP service is logged in
     */
    fun isLoggedIn(): Boolean {
        return authHeader != null
    }

    /**
     * Build the HTTP client
     * @param url The URL to use
     * @param trustAll Whether to trust all certificates
     */
    fun build(url: String?, trustAll: Boolean) {
        mBaseUrl = url
        mTrustAll = trustAll
        client = if (trustAll) {
            val trustAllCerts = arrayOf<TrustManager>(
                object : X509TrustManager {
                    @Throws(CertificateException::class)
                    override fun checkClientTrusted(
                        chain: Array<X509Certificate>,
                        authType: String
                    ) {
                    }

                    @Throws(CertificateException::class)
                    override fun checkServerTrusted(
                        chain: Array<X509Certificate>,
                        authType: String
                    ) {
                    }

                    override fun getAcceptedIssuers(): Array<X509Certificate> {
                        return arrayOf()
                    }
                }
            )

            val sslContext = SSLContext.getInstance("SSL")
            sslContext.init(null, trustAllCerts, SecureRandom())

            OkHttpClient.Builder()
                .sslSocketFactory(sslContext.socketFactory, trustAllCerts[0] as X509TrustManager)
                .hostnameVerifier({ hostname, session -> true })
                .build()
        } else {
            OkHttpClient()
        }
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
     * @param subpath The subpath to load
     * @return Host URL if authenticated, null otherwise
     */
    fun loadWebView(webView: WebView, subpath: String? = null): String? {
        // Load app interface if authenticated
        if (authHeader != null && mBaseUrl != null) {
            var url = mBaseUrl
            if (subpath != null) url += subpath

            // Get host name
            val host = Uri.parse(url).host

            // Clear webview history
            webView.clearHistory()

            // Set authorization header
            webView.loadUrl(url!!, mapOf("Authorization" to authHeader))

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
        return runRequest(
            Request.Builder()
                .url(loginFlowUrl)
                .header("User-Agent", "Memories")
                .post("".toRequestBody("application/json".toMediaTypeOrNull()))
                .build()
        )
    }

    /** Make login polling request */
    @Throws(Exception::class)
    fun getPollLogin(pollUrl: String, pollToken: String): Response {
        return runRequest(
            Request.Builder()
                .url(pollUrl)
                .post("token=$pollToken".toRequestBody("application/x-www-form-urlencoded".toMediaTypeOrNull()))
                .build()
        )
    }

    /** Run a request and get a JSON object */
    @Throws(Exception::class)
    private fun runRequest(request: Request): Response {
        return client.newCall(request).execute()
    }

    /** Build a GET request */
    private fun buildGet(path: String, auth: Boolean = true): Request {
        val builder = Request.Builder()
            .url(mBaseUrl + path)
            .header("User-Agent", "Memories")
            .get()

        if (auth)
            builder.header("Authorization", authHeader ?: "")

        return builder.build()
    }
}