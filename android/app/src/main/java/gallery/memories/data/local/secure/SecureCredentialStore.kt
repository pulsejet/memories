package gallery.memories.data.local.secure

import android.content.Context
import android.security.keystore.KeyGenParameterSpec
import android.security.keystore.KeyProperties
import android.util.Base64
import android.util.Log
import java.security.KeyStore
import javax.crypto.Cipher
import javax.crypto.KeyGenerator
import javax.crypto.SecretKey
import javax.crypto.spec.IvParameterSpec

/** Keystore-backed credential storage. Only the token is encrypted; the rest is plaintext prefs. Also seals other secrets. */
class SecureCredentialStore(private val context: Context) {
    companion object {
        private val TAG = SecureCredentialStore::class.java.simpleName
        private const val KEY_ALIAS = "MemoriesKey"
        private const val PREFS_NAME = "credentials"
    }

    private val keyStore = KeyStore.getInstance("AndroidKeyStore")

    init {
        keyStore.load(null)
        if (!keyStore.containsAlias(KEY_ALIAS)) generateNewKey()
    }

    /** Encrypts and persists credentials. Overwrites any previous login. */
    fun saveCredentials(cred: Credential) {
        val cipher = getCipher()
        cipher.init(Cipher.ENCRYPT_MODE, getSecretKey())
        val encryptedToken = cipher.doFinal(cred.token.toByteArray())
        context.applicationContext.getSharedPreferences(PREFS_NAME, Context.MODE_PRIVATE).edit()
            .putString("url", cred.url)
            .putBoolean("trustAll", cred.trustAll)
            .putString("username", cred.username)
            .putString("uid", cred.uid)
            .putString("clientCertificateAlias", cred.clientCertificate?.alias)
            .putString("clientCertificateHost", cred.clientCertificate?.host)
            .putInt("clientCertificatePort", cred.clientCertificate?.port ?: -1)
            .putString("encryptedToken", Base64.encodeToString(encryptedToken, Base64.DEFAULT))
            .putString("iv", Base64.encodeToString(cipher.iv, Base64.DEFAULT))
            .apply()
    }

    /**
     * Decrypted credentials, or null when logged out. Runs crypto on the caller thread.
     * An undecryptable token also reads as logged out; it stays stored so a transient
     * Keystore error can't erase a login, and the next login overwrites it.
     */
    fun getCredentials(): Credential? {
        val prefs = context.applicationContext.getSharedPreferences(PREFS_NAME, Context.MODE_PRIVATE)
        val url = prefs.getString("url", null)
        val trustAll = prefs.getBoolean("trustAll", false)
        val username = prefs.getString("username", null)
        val encryptedToken = prefs.getString("encryptedToken", null)
        val ivStr = prefs.getString("iv", null)
        if (url != null && username != null && encryptedToken != null && ivStr != null) {
            return try {
                val cipher = getCipher()
                cipher.init(Cipher.DECRYPT_MODE, getSecretKey(), IvParameterSpec(Base64.decode(ivStr, Base64.DEFAULT)))
                val token = String(cipher.doFinal(Base64.decode(encryptedToken, Base64.DEFAULT)))
                val alias = prefs.getString("clientCertificateAlias", null)
                val host = prefs.getString("clientCertificateHost", null)
                val port = prefs.getInt("clientCertificatePort", -1)
                val certificate = if (alias != null && host != null && port in 1..65535) {
                    ClientCertificate(alias, host, port)
                } else null
                Credential(url, trustAll, username, token, prefs.getString("uid", null), certificate)
            } catch (e: Exception) {
                Log.w(TAG, "Failed to decrypt stored credentials", e)
                null
            }
        }
        return null
    }

    fun saveUid(uid: String) {
        context.applicationContext.getSharedPreferences(PREFS_NAME, Context.MODE_PRIVATE).edit()
            .putString("uid", uid).apply()
    }

    fun deleteCredentials() {
        context.applicationContext.getSharedPreferences(PREFS_NAME, Context.MODE_PRIVATE).edit()
            .remove("url").remove("trustAll").remove("username").remove("uid")
            .remove("clientCertificateAlias").remove("clientCertificateHost").remove("clientCertificatePort")
            .remove("encryptedToken").remove("iv").apply()
    }

    /** Encrypts [plain] into an opaque string for [decrypt]. */
    fun encrypt(plain: String): String {
        val cipher = getCipher()
        cipher.init(Cipher.ENCRYPT_MODE, getSecretKey())
        val data = cipher.doFinal(plain.toByteArray())
        return Base64.encodeToString(cipher.iv, Base64.NO_WRAP) + ":" + Base64.encodeToString(data, Base64.NO_WRAP)
    }

    /** Reverses [encrypt]. Null when [sealed] is unreadable, e.g. the Keystore key changed. */
    fun decrypt(sealed: String): String? = try {
        val (iv, data) = sealed.split(":", limit = 2)
        val cipher = getCipher()
        cipher.init(Cipher.DECRYPT_MODE, getSecretKey(), IvParameterSpec(Base64.decode(iv, Base64.NO_WRAP)))
        String(cipher.doFinal(Base64.decode(data, Base64.NO_WRAP)))
    } catch (e: Exception) {
        Log.w(TAG, "Failed to decrypt stored secret", e)
        null
    }

    private fun generateNewKey() {
        val keyGenerator = KeyGenerator.getInstance(KeyProperties.KEY_ALGORITHM_AES, "AndroidKeyStore")
        val spec = KeyGenParameterSpec.Builder(KEY_ALIAS, KeyProperties.PURPOSE_ENCRYPT or KeyProperties.PURPOSE_DECRYPT)
            .setBlockModes(KeyProperties.BLOCK_MODE_CBC)
            .setEncryptionPaddings(KeyProperties.ENCRYPTION_PADDING_PKCS7)
            .setUserAuthenticationRequired(false)
            .build()
        keyGenerator.init(spec)
        keyGenerator.generateKey()
    }

    private fun getCipher(): Cipher {
        val transformation = "${KeyProperties.KEY_ALGORITHM_AES}/${KeyProperties.BLOCK_MODE_CBC}/${KeyProperties.ENCRYPTION_PADDING_PKCS7}"
        return Cipher.getInstance(transformation)
    }

    private fun getSecretKey(): SecretKey = keyStore.getKey(KEY_ALIAS, null) as SecretKey
}
