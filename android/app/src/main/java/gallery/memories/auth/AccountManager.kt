package gallery.memories.auth

import android.content.Intent
import android.net.Uri
import android.util.Log
import android.widget.Toast
import androidx.media3.common.util.UnstableApi
import gallery.memories.MainActivity
import gallery.memories.R
import gallery.memories.data.local.secure.Credential
import gallery.memories.data.local.secure.SecureCredentialStore
import gallery.memories.data.remote.assets.AssetSyncCoordinator
import gallery.memories.data.remote.assets.ManifestVerifier
import gallery.memories.data.remote.http.AuthState
import gallery.memories.data.remote.http.HttpClients
import gallery.memories.data.remote.http.NextcloudApi
import io.github.g00fy2.versioncompare.Version

@UnstableApi
/** Login, credential storage, and server version checks. */
class AccountManager(
    private val activity: MainActivity,
    private val auth: AuthState,
    private val clients: HttpClients,
    private val api: NextcloudApi,
    private val assets: AssetSyncCoordinator,
    private val store: SecureCredentialStore,
) {
    companion object {
        private val TAG = AccountManager::class.java.simpleName
    }

    /** Invalidates in-flight login polls on retry/logout so only the latest can transition UI. */
    @Volatile private var loginGeneration = 0

    /** Validates the server, starts a non-blocking asset sync, then hands off to the browser login flow. */
    fun login(url: String, trustAll: Boolean) {
        try {
            loginGeneration++
            auth.build(url, trustAll)
            clients.rebuild()
            val res = api.getApiDescriptionManifest()
            if (res.code != 200) {
                res.close()
                throw Exception("$url/api/describe (status ${res.code})")
            }
            val body = api.bodyJson(res) ?: throw Exception("Failed to parse API description")
            if (!ManifestVerifier.verifyDescribe(body)) {
                throw Exception("Asset manifest signature verification failed")
            }
            try {
                assets.sync(body)
            } catch (e: Exception) {
                Log.w(TAG, "Asset sync did not start: ${e.message}")
            }
            loginFlow(body.getString("baseUrl"), body.getString("loginFlowUrl"))
        } catch (e: Exception) {
            toast("Error: ${e.message}")
            throw Exception("Failed to connect to server: ${e.message}")
        }
    }

    fun loginFlow(baseUrl: String, loginFlowUrl: String) {
        val res = api.postLoginFlow(loginFlowUrl)
        if (res.code != 200) {
            res.close()
            throw Exception("Login flow returned a ${res.code} status code. Check your reverse proxy configuration and overwriteprotocol is correct.")
        }
        val body = api.bodyJson(res) ?: throw Exception("Failed to parse login flow response")
        val pollObj = body.getJSONObject("poll")
        val pollToken = pollObj.getString("token")
        val pollUrl = pollObj.getString("endpoint")
        val loginUrl = body.getString("login")
        activity.startActivity(Intent(Intent.ACTION_VIEW, Uri.parse(loginUrl)))
        val gen = loginGeneration
        Thread { pollLogin(pollUrl, pollToken, baseUrl, gen) }.start()
    }

    /** Polls every 3s for up to 30min. Success boots into setup; timeout resets to welcome. Stale generations do nothing. */
    private fun pollLogin(pollUrl: String, pollToken: String, baseUrl: String, gen: Int) {
        activity.binding.webview.post {
            activity.setTransparentBars(true, true)
            activity.binding.webview.loadUrl(activity.localStaticUrl("waiting.html") + "?login=1")
        }
        var pollCount = 0
        while (pollCount < 10 * 60 && gen == loginGeneration) {
            pollCount += 3
            Thread.sleep(3000)
            try {
                val response = api.getPollLogin(pollUrl, pollToken)
                val body = api.bodyJson(response) ?: throw Exception("Failed to parse login flow response")
                Log.v(TAG, "pollLogin: Got status code ${response.code}")
                if (response.code != 200) throw Exception("Failed to poll login flow")
                val loginName = body.getString("loginName")
                val appPassword = body.getString("appPassword")
                activity.runOnUiThread {
                    if (gen != loginGeneration) return@runOnUiThread
                    storeCredentials(baseUrl, loginName, appPassword)
                    activity.startLocalApp(toNxSetup = true)
                }
                return
            } catch (e: Exception) {
                continue
            }
        }
        if (gen != loginGeneration) return
        activity.runOnUiThread {
            if (gen != loginGeneration) return@runOnUiThread
            toast(activity.getString(R.string.err_login_timeout))
            activity.loadDefaultUrl()
        }
    }

    fun checkCredentialsAndVersion() {
        if (!auth.isLoggedIn()) return
        try {
            val response = api.getApiDescription()
            val body = api.bodyJson(response)
            if (response.code == 401) return loggedOut()
            if (response.code == 404) return toast(activity.getString(R.string.err_no_ver))
            if (body == null || response.code != 200) {
                toast(activity.getString(R.string.err_no_describe))
                return
            }
            val version = body.getString("version")
            if (body.isNull("uid")) return loggedOut()
            if (Version(version) < Version(activity.getString(R.string.min_server_version))) {
                return toast(activity.getString(R.string.err_no_ver))
            }
        } catch (e: Exception) {
            Log.w(TAG, "checkCredentialsAndVersion: ", e)
        }
    }

    fun loggedOut() {
        loginGeneration++
        toast(activity.getString(R.string.err_logged_out))
        deleteCredentials()
        activity.runOnUiThread { activity.loadDefaultUrl() }
    }

    fun storeCredentials(url: String, user: String, password: String) {
        store.saveCredentials(Credential(url = url, trustAll = auth.isTrustingAllCertificates, username = user, token = password))
        refreshCredentials()
    }

    fun deleteCredentials() {
        store.deleteCredentials()
        auth.setAuthHeader(null)
        auth.build(null, false)
        clients.rebuild()
    }

    fun refreshCredentials() {
        val cred = store.getCredentials() ?: return
        auth.build(cred.url, cred.trustAll)
        clients.rebuild()
        auth.setAuthHeader(cred.username to cred.token)
    }

    private fun toast(message: String) {
        activity.runOnUiThread { Toast.makeText(activity, message, Toast.LENGTH_LONG).show() }
    }
}
