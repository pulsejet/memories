package gallery.memories.auth

import android.util.Log
import android.webkit.WebStorage
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
import gallery.memories.ui.theme.ThemeManager
import io.github.g00fy2.versioncompare.Version

@UnstableApi
/**
 * Login, credential storage, and server version checks.
 *
 * [login] performs network I/O synchronously; callers must invoke it off
 * the UI thread (today that is the localhost bridge worker).
 */
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

    private val tabs = LoginCustomTab(activity)

    /** Validates the server, starts a non-blocking asset sync, then hands off to the browser login flow. */
    fun login(url: String, trustAll: Boolean) {
        val gen = ++loginGeneration
        try {
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
            if (gen == loginGeneration) resetPendingLogin()
            toast("Error: ${e.message}")
            throw Exception("Failed to connect to server: ${e.message}")
        }
    }

    /** Starts the Custom Tab login flow for an already-validated server. */
    private fun loginFlow(baseUrl: String, loginFlowUrl: String) {
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
        val gen = loginGeneration
        tabs.close()
        tabs.open(loginUrl) {
            if (gen != loginGeneration) return@open
            loginGeneration++
            resetPendingLogin()
            toast("Error: failed to open browser")
            activity.loadDefaultUrl()
        }
        Thread { pollLogin(pollUrl, pollToken, baseUrl, gen) }.start()
    }

    /** Drops the server set by an unfinished [login], returning to the stored account if any. */
    private fun resetPendingLogin() {
        auth.build(null, false)
        clients.rebuild()
        refreshCredentials()
    }

    /** Polls every 2s for up to 5min. Success boots into setup; timeout resets to welcome. Stale generations do nothing. */
    private fun pollLogin(pollUrl: String, pollToken: String, baseUrl: String, gen: Int) {
        activity.runOnUiIfAlive {
            if (gen != loginGeneration) return@runOnUiIfAlive
            activity.applyTheme(ThemeManager.ENTRY_THEME_COLOR, true)
            activity.binding.webview.loadUrl(activity.localStaticUrl("waiting.html") + "?login=1")
        }
        // Counts elapsed seconds, not polls: incremented by the 2s sleep below.
        var polledSeconds = 0
        while (polledSeconds < 5 * 60 && gen == loginGeneration && activity.isAlive) {
            polledSeconds += 2
            Thread.sleep(2000)
            try {
                val response = api.getPollLogin(pollUrl, pollToken)
                val code = response.code
                if (code == 404) {
                    response.close()
                    Log.v(TAG, "pollLogin: waiting ($polledSeconds s): pending")
                    continue
                }
                if (code != 200) {
                    response.close()
                    throw Exception("poll HTTP $code")
                }
                val body = api.bodyJson(response) ?: throw Exception("Failed to parse login flow response")
                val loginName = body.getString("loginName")
                val appPassword = body.getString("appPassword")
                val uid = fetchUid(baseUrl, loginName, appPassword)
                activity.runOnUiIfAlive {
                    if (gen != loginGeneration) return@runOnUiIfAlive
                    tabs.close()
                    storeCredentials(baseUrl, loginName, appPassword, uid)
                    activity.startLocalApp(toNxSetup = true)
                }
                return
            } catch (e: Exception) {
                // Most polls fail until the user approves in the browser; keep waiting.
                Log.v(TAG, "pollLogin: waiting ($polledSeconds s): ${e.message}")
                continue
            }
        }
        if (gen != loginGeneration) return
        activity.runOnUiIfAlive {
            if (gen != loginGeneration) return@runOnUiIfAlive
            tabs.close()
            resetPendingLogin()
            toast(activity.getString(R.string.err_login_timeout))
            activity.loadDefaultUrl()
        }
    }

    /** The login flow's poll token is single-use, so a failure here must not fail the login. */
    private fun fetchUid(baseUrl: String, loginName: String, appPassword: String): String? = try {
        api.getUid(baseUrl, loginName, appPassword)
    } catch (e: Exception) {
        Log.w(TAG, "Failed to fetch uid: ${e.message}")
        null
    }

    /**
     * Revalidates stored credentials against describeApi: 401 clears them,
     * an old server warns, anything else is silently kept. Also corrects the stored uid.
     */
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
            updateUid(body.getString("uid"))
            if (Version(version) < Version(activity.getString(R.string.min_server_version))) {
                return toast(activity.getString(R.string.err_no_ver))
            }
        } catch (e: Exception) {
            Log.w(TAG, "checkCredentialsAndVersion: ", e)
        }
    }

    private fun updateUid(uid: String) {
        if (!auth.isLoggedIn() || uid == auth.uid()) return
        auth.setUid(uid)
        store.saveUid(uid)
    }

    /**
     * Clears credentials and the account's WebView data, then returns to the welcome page.
     * WebView cookies stay: the only one is the local server's auth cookie.
     * Safe to call from any thread.
     */
    fun loggedOut() {
        loginGeneration++
        tabs.close()
        toast(activity.getString(R.string.err_logged_out))
        deleteCredentials()
        activity.runOnUiThread {
            WebStorage.getInstance().deleteAllData()
            if (!activity.isAlive) return@runOnUiThread
            activity.binding.webview.clearCache(true)
            activity.loadDefaultUrl()
        }
    }

    /** Persists new credentials and applies them to the HTTP clients. */
    fun storeCredentials(url: String, user: String, password: String, uid: String?) {
        store.saveCredentials(Credential(url = url, trustAll = auth.isTrustingAllCertificates, username = user, token = password, uid = uid))
        refreshCredentials()
    }

    /** Drops stored credentials and resets HTTP clients to the logged-out state. */
    fun deleteCredentials() {
        store.deleteCredentials()
        clients.clearCookies()
        auth.setAuthHeader(null)
        auth.build(null, false)
        clients.rebuild()
    }

    /** Reloads stored credentials into the HTTP clients. No-op when logged out. */
    fun refreshCredentials() {
        val cred = store.getCredentials() ?: return
        auth.build(cred.url, cred.trustAll)
        clients.rebuild()
        auth.setAuthHeader(cred.username to cred.token, cred.uid)
    }

    private fun toast(message: String) {
        activity.runOnUiThread { Toast.makeText(activity, message, Toast.LENGTH_LONG).show() }
    }

    fun onDestroy() = tabs.unbind()
}
