package gallery.memories.server

import java.io.File

/** Upstream server plus the snapshot currently being served. Held nullable by the server until first login. */
data class ServerConfig(
    val serverOrigin: String,
    val webRoot: String,
    val assetDir: File,
    val baseUrl: String,
)
