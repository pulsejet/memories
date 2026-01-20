package gallery.memories.service

import SecureStorage
import android.content.Intent
import android.net.Uri
import android.util.Log
import android.widget.Toast
import androidx.media3.common.util.UnstableApi
import gallery.memories.MainActivity
import gallery.memories.R
import io.github.g00fy2.versioncompare.Version

@UnstableApi
class AccountService(private val mCtx: MainActivity, private val mHttp: HttpService) {
    companion object {
        val TAG = AccountService::class.java.simpleName
    }

    private val store = SecureStorage(mCtx)

    /**
     * Make the first request to log in
     * @param url The URL of the Nextcloud server
     * @param trustAll Whether to trust all certificates
     */
    fun login(url: String, trustAll: Boolean) {
        try {
            mHttp.build(mCtx, url, trustAll)

            val res = mHttp.getApiDescription()
            if (res.code != 200) {
                throw Exception("${url}api/describe (status ${res.code})")
            }

            val body = mHttp.bodyJson(res) ?: throw Exception("Failed to parse API description")

            val baseUrl = body.getString("baseUrl")
            val loginFlowUrl = body.getString("loginFlowUrl")
            loginFlow(baseUrl, loginFlowUrl)
        } catch (e: Exception) {
            toast("Error: ${e.message}")
            throw Exception("Failed to connect to server: ${e.message}")
        }
    }

    /**
     * Login to a server
     * @param baseUrl The base URL of the server
     * @param loginFlowUrl The login flow URL
     * @throws Exception If the login flow failed
     */
    fun loginFlow(baseUrl: String, loginFlowUrl: String) {
        val res = mHttp.postLoginFlow(loginFlowUrl)

        // Check if 200 was received
        if (res.code != 200) {
            throw Exception("Login flow returned a ${res.code} status code. Check your reverse proxy configuration and overwriteprotocol is correct.")
        }

        // Get body as JSON
        val body = mHttp.bodyJson(res) ?: throw Exception("Failed to parse login flow response")

        // Parse response body as JSON
        val pollObj = body.getJSONObject("poll")
        val pollToken = pollObj.getString("token")
        val pollUrl = pollObj.getString("endpoint")
        val loginUrl = body.getString("login")

        // Open login page in browser
        mCtx.startActivity(Intent(Intent.ACTION_VIEW, Uri.parse(loginUrl)))

        // Start polling in background
        Thread { pollLogin(pollUrl, pollToken, baseUrl) }.start()
    }

    /**
     * Poll the login flow URL until we get a login token
     * @param pollUrl The login flow URL
     * @param pollToken The login token
     * @param baseUrl The base URL of the server
     */
    private fun pollLogin(pollUrl: String, pollToken: String, baseUrl: String) {
        mCtx.binding.webview.post {
            mCtx.binding.webview.loadUrl("file:///android_asset/waiting.html")
        }

        var pollCount = 0
        while (pollCount < 10 * 60) {
            pollCount += 3

            // Sleep for 3s
            Thread.sleep(3000)

            try {
                val response = mHttp.getPollLogin(pollUrl, pollToken)
                val body = mHttp.bodyJson(response) ?: throw Exception("Failed to parse login flow response")
                Log.v(TAG, "pollLogin: Got status code ${response.code}")

                // Check status code
                if (response.code != 200) {
                    throw Exception("Failed to poll login flow")
                }

                val loginName = body.getString("loginName")
                val appPassword = body.getString("appPassword")

                toast("Logged in, waiting for next page ...")

                mCtx.runOnUiThread {
                    // Save login info (also updates header)
                    storeCredentials(baseUrl, loginName, appPassword)

                    // Go to next screen
                    mHttp.loadWebView(mCtx.binding.webview, "nxsetup")
                }

                return
            } catch (e: Exception) {
                continue
            }
        }
    }

    /**
     * Check if the credentials are valid and the server version is supported
     * Makes a toast to the user if something is wrong
     */
    fun checkCredentialsAndVersion() {
        if (!mHttp.isLoggedIn()) return

        try {
            val response = mHttp.getApiDescription()
            val body = mHttp.bodyJson(response)

            // Check status code
            if (response.code == 401) {
                return loggedOut()
            }

            // Could not connect to memories
            if (response.code == 404) {
                return toast(mCtx.getString(R.string.err_no_ver))
            }

            // Check body
            if (body == null || response.code != 200) {
                toast(mCtx.getString(R.string.err_no_describe))
                return
            }

            // Get body values
            val uid = body.get("uid")
            val version = body.getString("version")

            // Check UID exists
            if (uid.equals(null)) {
                return loggedOut()
            }

            // Check minimum version
            if (Version(version) < Version(mCtx.getString(R.string.min_server_version))) {
                return toast(mCtx.getString(R.string.err_no_ver))
            }
        } catch (e: Exception) {
            Log.w(TAG, "checkCredentialsAndVersion: ", e)
            return
        }
    }

    /**
     * Handle a logout. Delete the stored credentials and go back to the login screen.
     */
    fun loggedOut() {
        toast(mCtx.getString(R.string.err_logged_out))
        deleteCredentials()
        mCtx.runOnUiThread {
            mCtx.loadDefaultUrl()
        }
    }

    /**
     * Store the credentials
     * @param url The URL to store
     * @param user The username to store
     * @param password The password to store
     */
    fun storeCredentials(url: String, user: String, password: String) {
        store.saveCredentials(Credential(
            url = url,
            trustAll = mHttp.isTrustingAllCertificates,
            username = user,
            token = password,
        ))
        refreshCredentials()
    }

    /**
     * Delete the stored credentials
     */
    fun deleteCredentials() {
        store.deleteCredentials()
        mHttp.setAuthHeader(null)
        mHttp.build(mCtx, null, false)
    }

    /**
     * Refresh the authorization header
     */
    fun refreshCredentials() {
        val cred = store.getCredentials() ?: return
        mHttp.build(mCtx, cred.url, cred.trustAll)
        mHttp.setAuthHeader(Pair(cred.username, cred.token))
    }

    /**
     * Show a toast on the UI thread
     * @param message The message to show
     */
    private fun toast(message: String) {
        mCtx.runOnUiThread {
            Toast.makeText(mCtx, message, Toast.LENGTH_LONG).show()
        }
    }
}