package gallery.memories.service

import android.content.Intent
import android.net.Uri
import android.util.Base64
import android.util.Log
import android.widget.Toast
import androidx.media3.common.util.UnstableApi
import gallery.memories.MainActivity
import gallery.memories.R
import io.github.g00fy2.versioncompare.Version
import okhttp3.MediaType.Companion.toMediaTypeOrNull
import okhttp3.OkHttpClient
import okhttp3.Request
import okhttp3.RequestBody.Companion.toRequestBody
import okhttp3.Response
import org.json.JSONObject
import java.net.SocketTimeoutException

@UnstableApi class AccountService(private val mCtx: MainActivity) {
    companion object {
        val TAG = AccountService::class.java.simpleName
    }

    var authHeader: String? = null
    var memoriesUrl: String? = null

    private fun toast(message: String) {
        mCtx.runOnUiThread {
            Toast.makeText(mCtx, message, Toast.LENGTH_LONG).show()
        }
    }

    fun login(baseUrl: String, loginFlowUrl: String) {
        // Make POST request to login flow URL
        val client = OkHttpClient()
        val request = Request.Builder()
            .url(loginFlowUrl)
            .header("User-Agent", "Memories")
            .post("".toRequestBody("application/json".toMediaTypeOrNull()))
            .build()

        val response: Response
        try {
            response = client.newCall(request).execute()
        } catch (e: SocketTimeoutException) {
            toast("Failed to connect to login flow URL")
            return
        }

        // Read response body
        val body = response.body?.string()
        response.body?.close()
        if (body == null) {
            toast("Failed to get login flow response")
            return
        }

        // Parse response body as JSON
        val json = JSONObject(body)
        val pollToken: String
        val pollUrl: String
        val loginUrl: String
        try {
            val pollObj = json.getJSONObject("poll")
            pollToken = pollObj.getString("token")
            pollUrl = pollObj.getString("endpoint")
            loginUrl = json.getString("login")

            toast("Opening login page...")

            // Open login page in browser
            mCtx.startActivity(Intent(Intent.ACTION_VIEW, Uri.parse(loginUrl)))
        } catch (e: Exception) {
            Log.e(TAG, "login: ", e)
            toast("Failed to parse login flow response")
            return
        }

        // Start polling in background
        Thread {
            pollLogin(pollUrl, pollToken, baseUrl)
        }.start()
    }

    private fun pollLogin(pollUrl: String, pollToken: String, baseUrl: String) {
        mCtx.binding.webview.post {
            mCtx.binding.webview.loadUrl("file:///android_asset/sync.html")
        }

        val client = OkHttpClient()
        val rbody = "token=$pollToken".toRequestBody("application/x-www-form-urlencoded".toMediaTypeOrNull())
        var pollCount = 0

        while (true) {
            pollCount += 3
            if (pollCount >= 10 * 60) return

            // Sleep for 3s
            Thread.sleep(3000)

            // Poll login flow URL
            val request = Request.Builder()
                .url(pollUrl)
                .post(rbody)
                .build()

            val response: Response
            try {
                response = client.newCall(request).execute()
            } catch (e: SocketTimeoutException) {
                continue
            }

            Log.v(TAG, "pollLogin: Got status code ${response.code}")

            // Check status code
            if (response.code != 200) {
                response.body?.close()
                continue
            }

            // Read response body
            val body = response.body!!.string()
            response.body?.close()
            val json = JSONObject(body)
            val loginName = json.getString("loginName")
            val appPassword = json.getString("appPassword")

            mCtx.runOnUiThread {
                // Save login info (also updates header)
                storeCredentials(baseUrl, loginName, appPassword)

                // Go to next screen
                mCtx.binding.webview.evaluateJavascript("window.loggedIn()", {})
            }

            return;
        }
    }

    fun checkCredentialsAndVersion() {
        memoriesUrl.let { base ->
            val request = Request.Builder()
                .url(base + "api/describe")
                .get()
                .header("Authorization", authHeader ?: "")
                .build()

            val response: Response
            try {
                response = OkHttpClient().newCall(request).execute()
            } catch (e: SocketTimeoutException) {
                return
            }

            val body = response.body?.string()
            response.body?.close()

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

            val json = JSONObject(body)
            val version = json.getString("version")
            val uid = json.get("uid")

            // Check UID exists
            if (uid.equals(null) && authHeader != null) {
                return loggedOut()
            }

            // Check minimum version
            if (Version(version) < Version(mCtx.getString(R.string.min_server_version))) {
                return toast(mCtx.getString(R.string.err_no_ver))
            }
        }
    }

    fun loggedOut() {
        toast(mCtx.getString(R.string.err_logged_out))
        deleteCredentials()
        mCtx.runOnUiThread {
            mCtx.loadDefaultUrl()
        }
    }

    fun storeCredentials(url: String, user: String, password: String) {
        mCtx.getSharedPreferences("credentials", 0).edit()
            .putString("memoriesUrl", url)
            .putString("user", user)
            .putString("password", password)
            .apply()
        memoriesUrl = url
        setAuthHeader(Pair(user, password))
    }

    fun getCredentials(): Pair<String, String>? {
        val prefs = mCtx.getSharedPreferences("credentials", 0)
        memoriesUrl = prefs.getString("memoriesUrl", null)
        val user = prefs.getString("user", null)
        val password = prefs.getString("password", null)
        if (user == null || password == null) return null
        return Pair(user, password)
    }

    fun deleteCredentials() {
        authHeader = null
        memoriesUrl = null
        mCtx.getSharedPreferences("credentials", 0).edit()
            .remove("memoriesUrl")
            .remove("user")
            .remove("password")
            .apply()
    }

    fun refreshAuthHeader() {
        setAuthHeader(getCredentials())
    }

    private fun setAuthHeader(credentials: Pair<String, String>?) {
        if (credentials != null) {
            val auth = "${credentials.first}:${credentials.second}"
            authHeader = "Basic ${Base64.encodeToString(auth.toByteArray(), Base64.NO_WRAP)}"
            return
        }
        authHeader = null
    }
}