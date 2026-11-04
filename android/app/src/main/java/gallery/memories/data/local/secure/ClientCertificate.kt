package gallery.memories.data.local.secure

/** KeyChain alias and the exact TLS endpoint approved by the user. Never stores key material. */
data class ClientCertificate(val alias: String, val host: String, val port: Int)
