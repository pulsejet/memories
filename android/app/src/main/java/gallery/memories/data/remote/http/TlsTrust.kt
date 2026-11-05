package gallery.memories.data.remote.http

import android.annotation.SuppressLint
import java.security.KeyStore
import java.security.SecureRandom
import java.security.cert.CertificateException
import java.security.cert.X509Certificate
import javax.net.ssl.KeyManager
import javax.net.ssl.SSLContext
import javax.net.ssl.TrustManagerFactory
import javax.net.ssl.X509TrustManager

/** Per-client TLS configuration; server verification is only bypassed on explicit opt-in. */
object TlsTrust {
    /** Keeps Android's configured trust anchors unless the user explicitly opts out. */
    fun context(trustAll: Boolean, keyManager: KeyManager? = null): Pair<SSLContext, X509TrustManager> {
        val tm = if (trustAll) insecureTrustManager() else {
            val factory = TrustManagerFactory.getInstance(TrustManagerFactory.getDefaultAlgorithm())
            factory.init(null as KeyStore?)
            factory.trustManagers.filterIsInstance<X509TrustManager>().single()
        }
        val sc = SSLContext.getInstance("TLS")
        sc.init(keyManager?.let { arrayOf(it) }, arrayOf(tm), SecureRandom())
        return sc to tm
    }

    @SuppressLint("CustomX509TrustManager") // Deliberate opt-in trust-all, see class docs
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
