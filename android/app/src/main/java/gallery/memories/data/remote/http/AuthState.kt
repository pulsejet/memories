package gallery.memories.data.remote.http

import android.util.Base64

/**
 * Current server, credentials, and TLS mode. Rebuilt from storage on
 * login/logout; HTTP clients must be rebuilt after any change here.
 */
class AuthState {
    private var authHeader: String? = null
    private var uid: String? = null
    private var baseUrl: String? = null
    private var trustAll = false

    /** Whether self-signed certificates are accepted (user opt-in per server). */
    val isTrustingAllCertificates: Boolean get() = trustAll

    fun isLoggedIn(): Boolean = authHeader != null

    /** Canonical server URL (the describeApi baseUrl), null when logged out. */
    fun baseUrl(): String? = baseUrl

    /** Basic auth header value, null when logged out. */
    fun authHeader(): String? = authHeader

    /** Server user ID, null when logged out. Falls back to the login name until known. */
    fun uid(): String? = uid

    fun setUid(uid: String) {
        this.uid = uid
    }

    /** Points at a server. Clears with a null url (see AccountManager.deleteCredentials). */
    fun build(url: String?, trustAll: Boolean) {
        baseUrl = url
        this.trustAll = trustAll
    }

    /** Sets credentials, or clears them with null. */
    fun setAuthHeader(credentials: Pair<String, String>?, uid: String? = null) {
        if (credentials != null) {
            val raw = "${credentials.first}:${credentials.second}"
            authHeader = "Basic ${Base64.encodeToString(raw.toByteArray(), Base64.NO_WRAP)}"
            this.uid = uid ?: credentials.first
            return
        }
        authHeader = null
        this.uid = null
    }
}
