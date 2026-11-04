package gallery.memories.data.remote.http

import android.content.Context
import android.security.KeyChain
import okhttp3.ConnectionPool
import okhttp3.Dispatcher
import okhttp3.OkHttpClient
import java.io.IOException
import java.util.concurrent.Executors
import java.util.concurrent.TimeUnit

/** OkHttp clients following the current [AuthState]. Rebuild after any auth/TLS change. */
class HttpClients(context: Context, private val auth: AuthState, private val cookies: SessionCookieJar) {
    private val appContext = context.applicationContext
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
        val selection = auth.clientCertificate
        if (auth.isTrustingAllCertificates || selection != null) {
            val keyManager = selection?.let { certificate ->
                ClientCertificateKeyManager(
                    certificate,
                    readPrivateKey = { readKeyChain { KeyChain.getPrivateKey(appContext, certificate.alias) } },
                    readCertificateChain = { readKeyChain { KeyChain.getCertificateChain(appContext, certificate.alias) } },
                )
            }
            val (sc, tm) = TlsTrust.context(auth.isTrustingAllCertificates, keyManager)
            builder.sslSocketFactory(sc.socketFactory, tm)
            if (auth.isTrustingAllCertificates) builder.hostnameVerifier { _, _ -> true }
            else if (selection != null) {
                // A wrapper keeps normal hostname verification and disables cross-host HTTP/2 coalescing.
                val verifier = builder.build().hostnameVerifier
                builder.hostnameVerifier { host, session -> verifier.verify(host, session) }
            }
        }
        return builder.build()
    }

    private fun <T> readKeyChain(read: () -> T?): T = try {
        checkNotNull(read()) { "KeyChain permission was revoked or the certificate was removed" }
    } catch (e: InterruptedException) {
        Thread.currentThread().interrupt()
        throw IOException("Client certificate access interrupted", e)
    } catch (e: Exception) {
        throw IOException("Cannot access client certificate. Sign in again to select a certificate.", e)
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
