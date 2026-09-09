package gallery.memories.server

import java.io.File

/** Parsed request. Bodies over 1MB spill from memory into bodyFile (deleted after routing). */
data class HttpRequest(
    val method: String,
    val path: String,
    val query: String?,
    val headers: Map<String, String>,
    val body: ByteArray?,
    val bodyFile: File?,
)
