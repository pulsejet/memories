package gallery.memories.data.local.secure

/**
 * Stored login. token is a Nextcloud app password, encrypted at rest. Immutable: edits save a copy.
 * username is the login name for Basic auth; uid is the server's user ID, which can differ
 * (email or LDAP logins). A null uid means it isn't known yet.
 */
data class Credential(
    val url: String,
    val trustAll: Boolean,
    val username: String,
    val token: String,
    val uid: String? = null,
)
