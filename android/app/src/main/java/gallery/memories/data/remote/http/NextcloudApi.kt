package gallery.memories.data.remote.http

import okhttp3.MediaType.Companion.toMediaTypeOrNull
import okhttp3.Request
import okhttp3.RequestBody.Companion.toRequestBody
import okhttp3.Response
import org.json.JSONObject

/**
 * Thin OkHttp wrappers for the endpoints the app needs: describeApi (with
 * and without the asset manifest), login flow, and raw asset downloads.
 *
 * Responses are returned open: callers own them and must close (or use
 * [bodyJson]/[getBody], which consume and close).
 */
class NextcloudApi(
    private val auth: AuthState,
    private val clients: HttpClients,
) {
    /** Consumes and closes [response], parsing its body as JSON. Null on empty bodies. */
    @Throws(Exception::class)
    fun bodyJson(response: Response): JSONObject? = getBody(response)?.let { JSONObject(it) }

    /** Consumes and closes [response], returning its body string. */
    @Throws(Exception::class)
    fun getBody(response: Response): String? {
        // string() consumes and closes the body; no extra close needed.
        return response.body.string()
    }

    /** describeApi without the manifest: credential and version checks. */
    @Throws(Exception::class)
    fun getApiDescription(): Response = clients.client().newCall(buildGet("api/describe")).execute()

    /** Describe call that also carries the JS/CSS asset manifests for offline sync. */
    @Throws(Exception::class)
    fun getApiDescriptionManifest(): Response = clients.client().newCall(buildGet("api/describe?manifest=1")).execute()

    /** Downloads a whole asset file; throws on non-200. */
    @Throws(Exception::class)
    fun downloadBytes(url: String): ByteArray {
        val request = Request.Builder().url(url).header("User-Agent", "Memories").get().build()
        clients.client().newCall(request).execute().use { res ->
            if (res.code != 200) throw Exception("HTTP ${res.code} for $url")
            return res.body.bytes()
        }
    }

    /** Starts the login flow; the response carries the poll endpoint and browser URL. */
    @Throws(Exception::class)
    fun postLoginFlow(loginFlowUrl: String): Response {
        return clients.client().newCall(
            Request.Builder().url(loginFlowUrl).header("User-Agent", "Memories")
                .post("".toRequestBody("application/json".toMediaTypeOrNull())).build(),
        ).execute()
    }

    /** One login-flow poll attempt; 200 carries loginName/appPassword once approved. */
    @Throws(Exception::class)
    fun getPollLogin(pollUrl: String, pollToken: String): Response {
        return clients.client().newCall(
            Request.Builder().url(pollUrl)
                .post("token=$pollToken".toRequestBody("application/x-www-form-urlencoded".toMediaTypeOrNull())).build(),
        ).execute()
    }

    private fun buildGet(path: String, withAuth: Boolean = true): Request {
        val base = requireNotNull(auth.baseUrl()) { "Not logged in: no server URL for $path" }
        val builder = Request.Builder().url(base + path).header("User-Agent", "Memories").get()
        if (withAuth) builder.header("Authorization", auth.authHeader() ?: "")
        return builder.build()
    }
}
