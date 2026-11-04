package gallery.memories.data.remote.http

import gallery.memories.data.local.secure.ClientCertificate
import java.net.Socket
import java.security.Principal
import java.security.PrivateKey
import java.security.cert.X509Certificate
import javax.net.ssl.SSLEngine
import javax.net.ssl.SSLSocket
import javax.net.ssl.X509ExtendedKeyManager

/** Only presents a user-selected identity to its approved host and port, including on redirects. */
class ClientCertificateKeyManager(
    private val selection: ClientCertificate,
    private val readPrivateKey: () -> PrivateKey?,
    private val readCertificateChain: () -> Array<X509Certificate>?,
) : X509ExtendedKeyManager() {
    override fun chooseClientAlias(keyTypes: Array<String>?, issuers: Array<Principal>?, socket: Socket?): String? {
        val session = (socket as? SSLSocket)?.handshakeSession ?: return null
        return choose(keyTypes, issuers, session.peerHost, session.peerPort)
    }

    override fun chooseEngineClientAlias(keyTypes: Array<String>?, issuers: Array<Principal>?, engine: SSLEngine?): String? =
        choose(keyTypes, issuers, engine?.peerHost, engine?.peerPort)

    private fun choose(keyTypes: Array<String>?, issuers: Array<Principal>?, host: String?, port: Int?): String? {
        if (!selection.host.equals(host, ignoreCase = true) || selection.port != port) return null
        val chain = getCertificateChain(selection.alias)?.takeIf { it.isNotEmpty() } ?: return null
        if (keyTypes.isNullOrEmpty() || keyTypes.none { it == chain[0].publicKey.algorithm }) return null
        if (!issuers.isNullOrEmpty() && chain.none { it.issuerX500Principal in issuers }) return null
        return selection.alias.takeIf { getPrivateKey(it) != null }
    }

    override fun getPrivateKey(alias: String?): PrivateKey? =
        if (alias == selection.alias) readPrivateKey() else null

    override fun getCertificateChain(alias: String?): Array<X509Certificate>? =
        if (alias == selection.alias) readCertificateChain() else null

    // There is no peer identity in these callbacks, so fail closed.
    override fun getClientAliases(keyType: String?, issuers: Array<Principal>?): Array<String>? = null
    override fun getServerAliases(keyType: String?, issuers: Array<Principal>?): Array<String>? = null
    override fun chooseServerAlias(keyType: String?, issuers: Array<Principal>?, socket: Socket?): String? = null
    override fun chooseEngineServerAlias(keyType: String?, issuers: Array<Principal>?, engine: SSLEngine?): String? = null
}
