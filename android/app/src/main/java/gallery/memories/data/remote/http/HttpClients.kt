package gallery.memories.data.remote.http

import okhttp3.ConnectionPool
import okhttp3.CookieJar
import okhttp3.Dispatcher
import okhttp3.OkHttpClient
import java.util.concurrent.Executors
import java.util.concurrent.TimeUnit

/** OkHttp clients following the current [AuthState]. Rebuild after any auth/TLS change. */
class HttpClients(private val auth: AuthState, private val cookies: SessionCookieJar) {
    @Volatile private var client = OkHttpClient.Builder().cookieJar(cookies).build()

    /** Swaps the shared client; in-flight calls keep the previous instance. */
    fun rebuild() {
        client = if (auth.isTrustingAllCertificates) {
            val (sc, tm) = TlsTrust.insecureContext()
            OkHttpClient.Builder()
                .sslSocketFactory(sc.socketFactory, tm)
                .hostnameVerifier { _, _ -> true }
                .cookieJar(cookies)
                .build()
        } else {
            OkHttpClient.Builder().cookieJar(cookies).build()
        }
    }

    fun clearCookies() = cookies.clear()

    /** Shared client for short API calls. */
    fun client(): OkHttpClient = client

    /** Proxy client with streaming-friendly timeouts and isolated pools. */
    fun newProxyClient(cookieJar: CookieJar? = null): OkHttpClient {
        val builder = client.newBuilder()
            .dispatcher(Dispatcher(Executors.newCachedThreadPool()))
            .connectionPool(ConnectionPool())
            .connectTimeout(15, TimeUnit.SECONDS)
            .readTimeout(60, TimeUnit.SECONDS)
            .writeTimeout(60, TimeUnit.SECONDS)
        if (cookieJar != null) builder.cookieJar(cookieJar)
        return builder.build()
    }
}
