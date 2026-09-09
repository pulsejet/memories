package gallery.memories.data.local.secure

import android.content.Context
import android.security.keystore.KeyGenParameterSpec
import android.security.keystore.KeyProperties
import android.util.Base64
import java.security.KeyStore
import javax.crypto.Cipher
import javax.crypto.KeyGenerator
import javax.crypto.SecretKey
import javax.crypto.spec.IvParameterSpec

/** Keystore-backed credential storage. Only the token is encrypted; the rest is plaintext prefs. */
class SecureCredentialStore(private val context: Context) {
    private val keyStore = KeyStore.getInstance("AndroidKeyStore")
    private val keyAlias = "MemoriesKey"

    init {
        keyStore.load(null)
        if (!keyStore.containsAlias(keyAlias)) generateNewKey()
    }

    fun saveCredentials(cred: Credential) {
        val cipher = getCipher()
        cipher.init(Cipher.ENCRYPT_MODE, getSecretKey())
        val encryptedToken = cipher.doFinal(cred.token.toByteArray())
        context.applicationContext.getSharedPreferences("credentials", Context.MODE_PRIVATE).edit()
            .putString("url", cred.url)
            .putBoolean("trustAll", cred.trustAll)
            .putString("username", cred.username)
            .putString("encryptedToken", Base64.encodeToString(encryptedToken, Base64.DEFAULT))
            .putString("iv", Base64.encodeToString(cipher.iv, Base64.DEFAULT))
            .apply()
    }

    fun getCredentials(): Credential? {
        val prefs = context.applicationContext.getSharedPreferences("credentials", Context.MODE_PRIVATE)
        val url = prefs.getString("url", null)
        val trustAll = prefs.getBoolean("trustAll", false)
        val username = prefs.getString("username", null)
        val encryptedToken = prefs.getString("encryptedToken", null)
        val ivStr = prefs.getString("iv", null)
        if (url != null && username != null && encryptedToken != null && ivStr != null) {
            val cipher = getCipher()
            cipher.init(Cipher.DECRYPT_MODE, getSecretKey(), IvParameterSpec(Base64.decode(ivStr, Base64.DEFAULT)))
            val token = String(cipher.doFinal(Base64.decode(encryptedToken, Base64.DEFAULT)))
            return Credential(url, trustAll, username, token)
        }
        return null
    }

    fun deleteCredentials() {
        context.applicationContext.getSharedPreferences("credentials", Context.MODE_PRIVATE).edit()
            .remove("url").remove("trustAll").remove("username")
            .remove("encryptedToken").remove("iv").apply()
    }

    private fun generateNewKey() {
        val keyGenerator = KeyGenerator.getInstance(KeyProperties.KEY_ALGORITHM_AES, "AndroidKeyStore")
        val spec = KeyGenParameterSpec.Builder(keyAlias, KeyProperties.PURPOSE_ENCRYPT or KeyProperties.PURPOSE_DECRYPT)
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

    private fun getSecretKey(): SecretKey = keyStore.getKey(keyAlias, null) as SecretKey
}
