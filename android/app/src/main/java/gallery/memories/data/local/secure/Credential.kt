package gallery.memories.data.local.secure

/** Stored login. token is a Nextcloud app password, encrypted at rest. */
data class Credential(
    var url: String,
    var trustAll: Boolean,
    var username: String,
    var token: String,
)
