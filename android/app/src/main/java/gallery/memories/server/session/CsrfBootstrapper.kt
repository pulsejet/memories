package gallery.memories.server.session

import android.util.Log
import gallery.memories.server.ServerConfig
import okhttp3.FormBody
import okhttp3.OkHttpClient
import okhttp3.Request
import org.json.JSONObject

class CsrfBootstrapper {
    companion object {
        private val TAG = CsrfBootstrapper::class.java.simpleName
    }

    /**
     * Form-login with the stored app password, then a session-bound CSRF token.
     * Tries both index.php and pretty login URLs; login success is exactly the 302/303.
     */
    fun bootstrapSession(client: OkHttpClient, creds: Pair<String, String>, config: ServerConfig): String? {
        val noRedirect = client.newBuilder().followRedirects(false).followSslRedirects(false).build()
        val tokenUrl = config.serverOrigin + config.webRoot + "/index.php/csrftoken"
        var loginPath: String? = null
        var pageToken: String? = null
        for (candidate in listOf("${config.webRoot}/index.php/login", "${config.webRoot}/login")) {
            val token = fetchLoginToken(noRedirect, config, config.serverOrigin + candidate)
            if (token != null) {
                loginPath = candidate
                pageToken = token
                break
            }
        }
        val path = loginPath ?: return null
        val token = pageToken ?: return null
        val form = FormBody.Builder()
            .add("user", creds.first).add("password", creds.second)
            .add("requesttoken", token).add("remember_login", "1").add("timezone-offset", "")
            .build()
        noRedirect.newCall(Request.Builder().url(config.serverOrigin + path).post(form).build()).execute().use { res ->
            if (res.code != 302 && res.code != 303) Log.w(TAG, "Login POST unexpected status ${res.code}")
            res.body.string()
        }
        return fetchToken(noRedirect, tokenUrl)?.also { Log.i(TAG, "Proxy session established") }
    }

    private fun fetchLoginToken(client: OkHttpClient, config: ServerConfig, url: String): String? {
        fun tokenOf(html: String): String? = Regex("data-requesttoken=\"([^\"]+)\"").find(html)?.groupValues?.getOrNull(1)
        client.newCall(Request.Builder().url(url).get().build()).execute().use { res ->
            if (res.code == 200) return tokenOf(res.body.string())
            val location = res.headers["Location"]
            if ((res.code == 302 || res.code == 303) && location != null) {
                val target = if (location.startsWith("http")) location else config.serverOrigin + location
                client.newCall(Request.Builder().url(target).get().build()).execute().use { redir ->
                    if (redir.code != 200) {
                        Log.w(TAG, "Login redirect $target -> ${redir.code}")
                        return null
                    }
                    return tokenOf(redir.body.string())
                }
            }
            Log.w(TAG, "Login page $url -> ${res.code}")
            return null
        }
    }

    private fun fetchToken(client: OkHttpClient, url: String): String? {
        return try {
            client.newCall(Request.Builder().url(url).get().build()).execute().use { res ->
                if (res.code != 200) return null
                JSONObject(res.body.string()).optString("token", "").ifEmpty { null }
            }
        } catch (e: Exception) {
            Log.w(TAG, "Token fetch failed: ${e.message}")
            null
        }
    }
}
