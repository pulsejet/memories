package gallery.memories.data.remote.http

import android.util.Base64

/** Current server, credentials, and TLS mode. Rebuilt from storage on login/logout. */
class AuthState {
    private var authHeader: String? = null
    private var creds: Pair<String, String>? = null
    private var baseUrl: String? = null
    private var trustAll = false

    val isTrustingAllCertificates: Boolean get() = trustAll

    fun isLoggedIn(): Boolean = authHeader != null

    fun baseUrl(): String? = baseUrl

    fun authHeader(): String? = authHeader

    fun credentials(): Pair<String, String>? = creds

    fun build(url: String?, trustAll: Boolean) {
        baseUrl = url
        this.trustAll = trustAll
    }

    fun setAuthHeader(credentials: Pair<String, String>?) {
        if (credentials != null) {
            val raw = "${credentials.first}:${credentials.second}"
            authHeader = "Basic ${Base64.encodeToString(raw.toByteArray(), Base64.NO_WRAP)}"
            creds = credentials
            return
        }
        authHeader = null
        creds = null
    }
}
