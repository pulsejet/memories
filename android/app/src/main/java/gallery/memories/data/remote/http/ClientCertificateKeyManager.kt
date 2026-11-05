package gallery.memories.data.remote.http

import android.app.Activity
import android.content.Context
import android.security.KeyChain
import android.util.Log
import androidx.core.content.edit
import okhttp3.HttpUrl
import okhttp3.HttpUrl.Companion.toHttpUrlOrNull
import java.net.Socket
import java.security.Principal
import java.security.PrivateKey
import java.security.cert.X509Certificate
import java.util.concurrent.CountDownLatch
import java.util.concurrent.TimeUnit
import javax.net.ssl.SNIHostName
import javax.net.ssl.SSLEngine
import javax.net.ssl.SSLSocket
import javax.net.ssl.X509ExtendedKeyManager

/**
 * Only presents a user-selected identity to its approved host and port, including on redirects.
 * Also records whether that server asked for a certificate at all, so login can prompt on demand.
 */
class ClientCertificateKeyManager private constructor(
    private val context: Context,
    private val server: HttpUrl,
    private val alias: String?,
) : X509ExtendedKeyManager() {
    /** The server's CertificateRequest constraints, and whether we answered with a certificate. */
    class Request(val keyTypes: Array<String>, val issuers: Array<Principal>, val presented: Boolean)

    companion object {
        private val TAG = ClientCertificateKeyManager::class.java.simpleName

        /** Bumped when the system picker opens, so the login page can hold off its timeout. */
        @Volatile var prompts = 0
            private set

        private fun preferences(context: Context) =
            context.applicationContext.getSharedPreferences("client-certificates", Context.MODE_PRIVATE)

        private fun key(server: HttpUrl) = "${server.host}:${server.port}"

        private fun https(url: String?) = url?.toHttpUrlOrNull()?.takeIf { it.isHttps }

        private fun selected(context: Context, url: String?): String? =
            https(url)?.let { preferences(context).getString(key(it), null) }

        /** A key manager for [url]; with [detect], also returned without a selection to observe requests. */
        fun forServer(context: Context, url: String?, detect: Boolean = false): ClientCertificateKeyManager? {
            val server = https(url) ?: return null
            val alias = selected(context, url)
            if (alias == null && !detect) return null
            return ClientCertificateKeyManager(context.applicationContext, server, alias)
        }

        /** Shows the system picker filtered by [request] and blocks until it closes. Returns whether one was chosen. */
        fun prompt(activity: Activity, url: String, request: Request): Boolean {
            val server = https(url) ?: return false
            val prefs = preferences(activity)
            val done = CountDownLatch(1)
            var chosen: String? = null
            prompts++
            activity.runOnUiThread {
                if (activity.isFinishing || activity.isDestroyed) return@runOnUiThread done.countDown()
                KeyChain.choosePrivateKeyAlias(
                    activity, { alias -> chosen = alias; done.countDown() },
                    request.keyTypes.takeIf { it.isNotEmpty() }, request.issuers.takeIf { it.isNotEmpty() },
                    server.host, server.port, prefs.getString(key(server), null),
                )
            }
            done.await(10, TimeUnit.MINUTES)
            chosen?.let { prefs.edit { putString(key(server), it) } }
            return chosen != null
        }

        /** Carries the selection for [from] over to the canonical [to]. Returns whether [to] is a different server. */
        fun follow(context: Context, from: String, to: String): Boolean {
            val src = https(from) ?: return false
            val dst = https(to)?.takeIf { key(it) != key(src) } ?: return false
            val alias = selected(context, from) ?: return false
            preferences(context).edit { putString(key(dst), alias) }
            return true
        }

        fun clear(context: Context) = preferences(context).edit { clear() }
    }

    /** Set when [server] asked for a certificate during a handshake. */
    @Volatile var request: Request? = null
        private set

    private val chain by lazy { keyChain { KeyChain.getCertificateChain(context, alias!!) }?.takeIf { it.isNotEmpty() } }
    private val key by lazy { keyChain { KeyChain.getPrivateKey(context, alias!!) } }

    /** Missing or revoked identities fall back to sending no certificate. */
    private fun <T> keyChain(fn: () -> T?): T? = try {
        fn()
    } catch (e: Exception) {
        Log.w(TAG, "KeyChain access failed for $alias: ${e.message}")
        null
    }

    override fun chooseClientAlias(keyTypes: Array<String>?, issuers: Array<Principal>?, socket: Socket?): String? {
        val ssl = socket as? SSLSocket ?: return null
        val session = ssl.handshakeSession
        val host = session?.peerHost
            ?: ssl.sslParameters?.serverNames?.firstNotNullOfOrNull { (it as? SNIHostName)?.asciiName }
        val port = session?.peerPort?.takeIf { it > 0 } ?: ssl.port
        return choose(keyTypes, issuers, host, port)
    }

    override fun chooseEngineClientAlias(keyTypes: Array<String>?, issuers: Array<Principal>?, engine: SSLEngine?): String? =
        choose(keyTypes, issuers, engine?.peerHost, engine?.peerPort)

    private fun choose(keyTypes: Array<String>?, issuers: Array<Principal>?, host: String?, port: Int?): String? {
        if (!server.host.equals(host, ignoreCase = true) || server.port != port) return null
        // The user picked this identity for this server; let the server judge key type and issuer.
        val chosen = alias?.takeIf { chain != null && key != null }
        request = Request(keyTypes ?: emptyArray(), issuers ?: emptyArray(), chosen != null)
        return chosen
    }

    override fun getPrivateKey(alias: String?): PrivateKey? = if (alias != null && alias == this.alias) key else null

    override fun getCertificateChain(alias: String?): Array<X509Certificate>? = if (alias != null && alias == this.alias) chain else null

    // There is no peer identity in these callbacks, so fail closed.
    override fun getClientAliases(keyType: String?, issuers: Array<Principal>?): Array<String>? = null
    override fun getServerAliases(keyType: String?, issuers: Array<Principal>?): Array<String>? = null
    override fun chooseServerAlias(keyType: String?, issuers: Array<Principal>?, socket: Socket?): String? = null
    override fun chooseEngineServerAlias(keyType: String?, issuers: Array<Principal>?, engine: SSLEngine?): String? = null
}
