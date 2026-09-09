package gallery.memories.server

import java.io.File

/** Upstream server plus the snapshot currently being served. Null until first login. */
data class ServerConfig(
    val serverOrigin: String,
    val webRoot: String,
    val assetDir: File,
    val baseUrl: String,
)
