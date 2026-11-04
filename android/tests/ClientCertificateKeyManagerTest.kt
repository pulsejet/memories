import gallery.memories.data.local.secure.ClientCertificate
import gallery.memories.data.remote.http.ClientCertificateKeyManager
import java.io.File
import java.net.InetAddress
import java.net.Socket
import java.security.KeyStore
import java.security.Principal
import java.security.PrivateKey
import java.security.cert.X509Certificate
import java.util.concurrent.FutureTask
import java.util.concurrent.TimeUnit
import javax.net.ssl.KeyManagerFactory
import javax.net.ssl.SSLContext
import javax.net.ssl.SSLServerSocket
import javax.net.ssl.SSLSocket
import javax.net.ssl.TrustManagerFactory
import javax.security.auth.x500.X500Principal

/** Framework-free JVM checks: real TLS handshakes without an emulator or additional project dependencies. */
fun main(args: Array<String>) {
    val store = KeyStore.getInstance("PKCS12").apply {
        File(args.single()).inputStream().use { load(it, "test-password".toCharArray()) }
    }
    val trust = TrustManagerFactory.getInstance(TrustManagerFactory.getDefaultAlgorithm()).apply { init(store) }
    val serverKeys = KeyManagerFactory.getInstance(KeyManagerFactory.getDefaultAlgorithm()).apply {
        init(store, "test-password".toCharArray())
    }
    val serverContext = SSLContext.getInstance("TLS").apply { init(serverKeys.keyManagers, trust.trustManagers, null) }
    var assertions = 0
    fun expect(value: Boolean, message: String) {
        check(value) { message }
        assertions++
    }

    for (alias in listOf("rsa", "ec")) {
        val key = store.getKey(alias, "test-password".toCharArray()) as PrivateKey
        val chain = store.getCertificateChain(alias).map { it as X509Certificate }.toTypedArray()
        var keyReads = 0
        var chainReads = 0
        val manager = ClientCertificateKeyManager(ClientCertificate(alias, "localhost", 443),
            readPrivateKey = { keyReads++; key }, readCertificateChain = { chainReads++; chain },
        )
        fun choose(host: String = "localhost", port: Int = 443, types: Array<String>? = arrayOf(key.algorithm),
                   issuers: Array<Principal>? = null): String? =
            manager.chooseEngineClientAlias(types, issuers, serverContext.createSSLEngine(host, port))

        expect(choose() == alias, "$alias selection")
        expect(choose(host = "LOCALHOST") == alias, "case-insensitive hostname")
        expect(choose(issuers = arrayOf(chain[0].issuerX500Principal)) == alias, "accepted issuer")
        expect(choose(issuers = emptyArray()) == alias, "empty issuer list")
        val reads = keyReads + chainReads
        expect(choose(host = "127.0.0.1") == null, "same IP must not grant another hostname")
        expect(choose(host = "other.example") == null, "cross-host redirect")
        expect(choose(port = 8443) == null, "cross-port redirect")
        expect(manager.chooseEngineClientAlias(arrayOf(key.algorithm), null, null) == null, "missing peer")
        expect(manager.chooseClientAlias(arrayOf(key.algorithm), null, Socket()) == null, "non-TLS socket")
        expect(keyReads + chainReads == reads, "unapproved peers must not access KeyChain")
        expect(choose(types = arrayOf("DSA")) == null, "wrong key type")
        expect(choose(types = null) == null, "missing key types")
        expect(choose(issuers = arrayOf(X500Principal("CN=Other CA"))) == null, "wrong issuer")
        expect(manager.getPrivateKey("other") == null, "unknown private key alias")
        expect(manager.getCertificateChain("other") == null, "unknown certificate alias")
        expect(manager.getClientAliases(key.algorithm, null) == null, "unscoped enumeration")
        expect(manager.chooseEngineServerAlias(key.algorithm, null, null) == null, "server alias")

        for (protocol in listOf("TLSv1.2", "TLSv1.3")) {
            fun handshake(approved: Boolean, hasKey: Boolean = true, hasChain: Boolean = true): Boolean {
                val server = serverContext.serverSocketFactory.createServerSocket(0, 1, InetAddress.getLoopbackAddress()) as SSLServerSocket
                server.needClientAuth = true
                server.enabledProtocols = arrayOf(protocol)
                server.soTimeout = 5000
                val port = server.localPort
                val task = FutureTask {
                    try {
                        (server.accept() as SSLSocket).use {
                            it.soTimeout = 5000
                            it.startHandshake()
                            check(it.session.peerCertificates[0] == chain[0])
                            it.outputStream.write(42)
                        }
                        true
                    } catch (_: Exception) {
                        false
                    } finally {
                        server.close()
                    }
                }
                Thread(task).apply { isDaemon = true; start() }
                val scoped = ClientCertificateKeyManager(
                    ClientCertificate(alias, "localhost", if (approved) port else port + 1),
                    readPrivateKey = { key.takeIf { hasKey } },
                    readCertificateChain = { chain.takeIf { hasChain } },
                )
                val clientContext = SSLContext.getInstance("TLS").apply { init(arrayOf(scoped), trust.trustManagers, null) }
                val clientOk = try {
                    val socket = Socket(InetAddress.getLoopbackAddress(), port)
                    (clientContext.socketFactory.createSocket(socket, "localhost", port, true) as SSLSocket).use {
                        it.soTimeout = 5000
                        it.enabledProtocols = arrayOf(protocol)
                        it.sslParameters = it.sslParameters.apply { endpointIdentificationAlgorithm = "HTTPS" }
                        it.startHandshake()
                        it.inputStream.read() == 42
                    }
                } catch (_: Exception) {
                    false
                }
                return task.get(10, TimeUnit.SECONDS) && clientOk
            }
            expect(handshake(true), "$alias $protocol mutual TLS")
            expect(!handshake(false), "$alias $protocol rejects a different port")
            expect(!handshake(true, hasKey = false), "$alias $protocol rejects a revoked key")
            expect(!handshake(true, hasChain = false), "$alias $protocol rejects a missing chain")
        }
    }
    println("Passed $assertions client-certificate checks (RSA, EC, TLS 1.2 and TLS 1.3)")
}
