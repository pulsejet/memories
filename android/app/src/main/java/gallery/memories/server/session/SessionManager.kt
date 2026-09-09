package gallery.memories.server.session

import android.util.Log
import gallery.memories.data.remote.http.AuthState
import gallery.memories.data.remote.http.HttpClients
import gallery.memories.server.ServerConfig
import okhttp3.OkHttpClient

class SessionManager(
    private val auth: AuthState,
    private val clients: HttpClients,
    private val configProvider: () -> ServerConfig?,
) {
    companion object {
        private val TAG = SessionManager::class.java.simpleName
    }

    private val jar = MemoryCookieJar()
    private var proxyClient: OkHttpClient? = null
    @Volatile var csrfToken: String? = null
        private set
    /** Failed bootstraps suppress retries for a minute to avoid log spam. */
    @Volatile private var blockedUntil = 0L
    private val lock = Any()
    private val bootstrapper = CsrfBootstrapper()

    fun client(): OkHttpClient {
        synchronized(lock) {
            if (proxyClient == null) proxyClient = clients.newProxyClient(jar)
            return proxyClient!!
        }
    }

    fun resetSession() {
        // Safe: the proxy client owns an isolated dispatcher/pool (see HttpClients),
        // so this never touches the main client's executor.
        synchronized(lock) {
            csrfToken = null
            try {
                proxyClient?.dispatcher?.executorService?.shutdown()
                proxyClient?.connectionPool?.evictAll()
            } catch (_: Exception) {}
            proxyClient = null
        }
    }

    fun clearToken() {
        synchronized(lock) { csrfToken = null }
    }

    /** Single-flighted upstream login; safe from any background thread, never the UI thread. */
    fun ensureSession(): Boolean {
        synchronized(lock) {
            if (csrfToken != null) return true
            if (System.currentTimeMillis() < blockedUntil) return false
            val creds = auth.credentials() ?: return false
            val config = configProvider() ?: return false
            val ok = try {
                bootstrapper.bootstrapSession(client(), creds, config)?.let { csrfToken = it; true } ?: false
            } catch (e: Exception) {
                Log.w(TAG, "Session bootstrap failed: ${e.message}")
                false
            }
            if (!ok) blockedUntil = System.currentTimeMillis() + 60 * 1000
            return ok
        }
    }
}
