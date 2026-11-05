package gallery.memories.data.remote.http

import android.content.Context
import okhttp3.ConnectionPool
import okhttp3.Dispatcher
import okhttp3.OkHttpClient
import java.util.concurrent.Executors
import java.util.concurrent.TimeUnit
import javax.net.ssl.HttpsURLConnection

/** OkHttp clients following the current [AuthState]. Rebuild after any auth/TLS change. */
class HttpClients(context: Context, private val auth: AuthState, private val cookies: SessionCookieJar) {
    private val appContext = context.applicationContext
    @Volatile private var keyManager: ClientCertificateKeyManager? = null
    @Volatile private var client = newClient(false)
    @Volatile private var proxyClient = newProxyClient()

    /** Swaps the clients; in-flight calls keep the previous instances.
     *  With [detectClientCert], records whether the server asks for a client certificate. */
    fun rebuild(detectClientCert: Boolean = false) {
        client = newClient(detectClientCert)
        proxyClient = newProxyClient()
    }

    /** The certificate request seen since the last [rebuild], if any. */
    fun clientCertRequest() = keyManager?.request

    fun clearCookies() = cookies.clear()

    /** Shared client for short API calls. */
    fun client(): OkHttpClient = client

    /** Proxy client with streaming-friendly timeouts and isolated pools.
     *  Redirects are not followed so Location stays rewritable.
     *  Replaced by [rebuild]: fetch per call, never hold on to it. */
    fun proxyClient(): OkHttpClient = proxyClient

    private fun newClient(detectClientCert: Boolean): OkHttpClient {
        val builder = OkHttpClient.Builder().cookieJar(cookies)
        val keyManager = ClientCertificateKeyManager.forServer(appContext, auth.baseUrl(), detectClientCert)
        this.keyManager = keyManager
        if (auth.isTrustingAllCertificates || keyManager != null) {
            val (sc, tm) = TlsTrust.context(auth.isTrustingAllCertificates, keyManager)
            builder.sslSocketFactory(sc.socketFactory, tm)
            // A custom verifier also disables cross-host HTTP/2 coalescing, so the identity never leaks across hosts.
            if (auth.isTrustingAllCertificates) {
                builder.hostnameVerifier { _, _ -> true }
            } else {
                builder.hostnameVerifier(HttpsURLConnection.getDefaultHostnameVerifier())
            }
        }
        return builder.build()
    }

    private fun newProxyClient(): OkHttpClient = client.newBuilder()
        .dispatcher(Dispatcher(Executors.newCachedThreadPool()))
        .connectionPool(ConnectionPool())
        .connectTimeout(15, TimeUnit.SECONDS)
        .readTimeout(60, TimeUnit.SECONDS)
        .writeTimeout(60, TimeUnit.SECONDS)
        .followRedirects(false)
        .followSslRedirects(false)
        .build()
}
