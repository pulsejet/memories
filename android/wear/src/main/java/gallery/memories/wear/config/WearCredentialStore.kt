package gallery.memories.wear.config

import android.content.Context

/**
 * Simple SharedPreferences-based credential storage for the Wear OS module.
 * Stores the Nextcloud Memories server URL, username, and app password.
 */
class WearCredentialStore(context: Context) {

    private val prefs = context.getSharedPreferences(PREFS_NAME, Context.MODE_PRIVATE)

    data class Credential(
        val url: String,
        val username: String,
        val password: String,
        val trustAll: Boolean,
    )

    fun save(cred: Credential) {
        prefs.edit()
            .putString(KEY_URL, cred.url)
            .putString(KEY_USERNAME, cred.username)
            .putString(KEY_PASSWORD, cred.password)
            .putBoolean(KEY_TRUST_ALL, cred.trustAll)
            .apply()
    }

    fun get(): Credential? {
        val url = prefs.getString(KEY_URL, null) ?: return null
        val username = prefs.getString(KEY_USERNAME, null) ?: return null
        val password = prefs.getString(KEY_PASSWORD, null) ?: return null
        val trustAll = prefs.getBoolean(KEY_TRUST_ALL, false)
        return Credential(url, username, password, trustAll)
    }

    fun clear() {
        prefs.edit().clear().apply()
    }

    companion object {
        private const val PREFS_NAME = "wear_credentials"
        private const val KEY_URL = "url"
        private const val KEY_USERNAME = "username"
        private const val KEY_PASSWORD = "password"
        private const val KEY_TRUST_ALL = "trustAll"
    }
}
