package gallery.memories.data.remote.http

import android.annotation.SuppressLint
import java.security.SecureRandom
import java.security.cert.CertificateException
import java.security.cert.X509Certificate
import javax.net.ssl.HttpsURLConnection
import javax.net.ssl.SSLContext
import javax.net.ssl.X509TrustManager

/** Opt-in certificate bypass for self-signed home servers. Never enabled by default. */
object TlsTrust {
    @Volatile private var defaultInsecure = false

    /** Trust-all TLS context for OkHttp clients. Scoped to the clients built with it. */
    fun insecureContext(): Pair<SSLContext, X509TrustManager> {
        val tm = insecureTrustManager()
        val sc = SSLContext.getInstance("TLS")
        sc.init(null, arrayOf(tm), SecureRandom())
        return sc to tm
    }

    /**
     * Makes trust-all the default for HttpsURLConnection (used by the video
     * player). One-way: there is no supported scenario for re-enabling
     * verification within a process lifetime.
     */
    fun setDefaultInsecureTLS() {
        if (defaultInsecure) return
        defaultInsecure = true
        val (sc, _) = insecureContext()
        HttpsURLConnection.setDefaultSSLSocketFactory(sc.socketFactory)
        HttpsURLConnection.setDefaultHostnameVerifier { _, _ -> true }
    }

    private fun insecureTrustManager(): X509TrustManager {
        return object : X509TrustManager {
            @SuppressLint("TrustAllX509TrustManager")
            @Throws(CertificateException::class)
            override fun checkClientTrusted(chain: Array<X509Certificate>, authType: String) = Unit

            @SuppressLint("TrustAllX509TrustManager")
            @Throws(CertificateException::class)
            override fun checkServerTrusted(chain: Array<X509Certificate>, authType: String) = Unit

            override fun getAcceptedIssuers(): Array<X509Certificate> = arrayOf()
        }
    }
}
