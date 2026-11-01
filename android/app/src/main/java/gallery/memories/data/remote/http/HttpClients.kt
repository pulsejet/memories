package gallery.memories.data.remote.http

import okhttp3.ConnectionPool
import okhttp3.Dispatcher
import okhttp3.OkHttpClient
import java.util.concurrent.Executors
import java.util.concurrent.TimeUnit

/** OkHttp clients following the current [AuthState]. Rebuild after any auth/TLS change. */
class HttpClients(private val auth: AuthState, private val cookies: SessionCookieJar) {
    @Volatile private var client = newClient()
    @Volatile private var proxyClient = newProxyClient()

    /** Swaps the clients; in-flight calls keep the previous instances. */
    fun rebuild() {
        client = newClient()
        proxyClient = newProxyClient()
    }

    fun clearCookies() = cookies.clear()

    /** Shared client for short API calls. */
    fun client(): OkHttpClient = client

    /** Proxy client with streaming-friendly timeouts and isolated pools.
     *  Redirects are not followed so Location stays rewritable.
     *  Replaced by [rebuild]: fetch per call, never hold on to it. */
    fun proxyClient(): OkHttpClient = proxyClient

    private fun newClient(): OkHttpClient {
        val builder = OkHttpClient.Builder().cookieJar(cookies)
        if (auth.isTrustingAllCertificates) {
            val (sc, tm) = TlsTrust.insecureContext()
            builder.sslSocketFactory(sc.socketFactory, tm).hostnameVerifier { _, _ -> true }
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
