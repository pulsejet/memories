package gallery.memories.data.local.secure

/** Stored login. token is a Nextcloud app password, encrypted at rest. Immutable: edits save a copy. */
data class Credential(
    val url: String,
    val trustAll: Boolean,
    val username: String,
    val token: String,
)
