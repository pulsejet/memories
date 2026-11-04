package gallery.memories.data.remote.http

import android.app.Activity
import android.content.Context
import android.security.KeyChain
import androidx.core.content.edit
import okhttp3.HttpUrl
import okhttp3.HttpUrl.Companion.toHttpUrlOrNull
import java.net.Socket
import java.security.Principal
import java.security.PrivateKey
import java.security.cert.X509Certificate
import javax.net.ssl.SSLEngine
import javax.net.ssl.SSLSocket
import javax.net.ssl.X509ExtendedKeyManager

/** Only presents a user-selected identity to its approved host and port, including on redirects. */
class ClientCertificateKeyManager private constructor(
    private val context: Context,
    private val server: HttpUrl,
    private val alias: String,
) : X509ExtendedKeyManager() {
    companion object {
        private fun preferences(context: Context) =
            context.getSharedPreferences("client-certificates", Context.MODE_PRIVATE)

        private fun key(server: HttpUrl) = "${server.host}:${server.port}"

        fun forServer(context: Context, url: String?): ClientCertificateKeyManager? {
            val server = url?.toHttpUrlOrNull()?.takeIf { it.isHttps } ?: return null
            val alias = preferences(context).getString(key(server), null) ?: return null
            return ClientCertificateKeyManager(context.applicationContext, server, alias)
        }

        fun select(activity: Activity, url: String) {
            val server = url.toHttpUrlOrNull()?.takeIf { it.isHttps } ?: return
            val prefs = preferences(activity.applicationContext)
            activity.runOnUiThread {
                if (activity.isFinishing || activity.isDestroyed) return@runOnUiThread
                KeyChain.choosePrivateKeyAlias(
                    activity, { alias -> prefs.edit { putString(key(server), alias) } },
                    null, null, server.host, server.port, prefs.getString(key(server), null),
                )
            }
        }
    }

    override fun chooseClientAlias(keyTypes: Array<String>?, issuers: Array<Principal>?, socket: Socket?): String? {
        val session = (socket as? SSLSocket)?.handshakeSession ?: return null
        return choose(keyTypes, issuers, session.peerHost, session.peerPort)
    }

    override fun chooseEngineClientAlias(keyTypes: Array<String>?, issuers: Array<Principal>?, engine: SSLEngine?): String? =
        choose(keyTypes, issuers, engine?.peerHost, engine?.peerPort)

    private fun choose(keyTypes: Array<String>?, issuers: Array<Principal>?, host: String?, port: Int?): String? {
        if (!server.host.equals(host, ignoreCase = true) || server.port != port) return null
        val chain = getCertificateChain(alias)?.takeIf { it.isNotEmpty() } ?: return null
        if (keyTypes.isNullOrEmpty() || keyTypes.none { it == chain[0].publicKey.algorithm }) return null
        if (!issuers.isNullOrEmpty() && chain.none { it.issuerX500Principal in issuers }) return null
        return alias.takeIf { getPrivateKey(it) != null }
    }

    override fun getPrivateKey(alias: String?): PrivateKey? =
        if (alias == this.alias) KeyChain.getPrivateKey(context, alias) else null

    override fun getCertificateChain(alias: String?): Array<X509Certificate>? =
        if (alias == this.alias) KeyChain.getCertificateChain(context, alias) else null

    // There is no peer identity in these callbacks, so fail closed.
    override fun getClientAliases(keyType: String?, issuers: Array<Principal>?): Array<String>? = null
    override fun getServerAliases(keyType: String?, issuers: Array<Principal>?): Array<String>? = null
    override fun chooseServerAlias(keyType: String?, issuers: Array<Principal>?, socket: Socket?): String? = null
    override fun chooseEngineServerAlias(keyType: String?, issuers: Array<Principal>?, engine: SSLEngine?): String? = null
}
