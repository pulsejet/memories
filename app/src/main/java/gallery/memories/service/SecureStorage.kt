import android.content.Context
import android.security.keystore.KeyGenParameterSpec
import android.security.keystore.KeyProperties
import android.security.keystore.KeyProperties.KEY_ALGORITHM_AES
import android.security.keystore.KeyProperties.PURPOSE_DECRYPT
import android.security.keystore.KeyProperties.PURPOSE_ENCRYPT
import android.util.Base64
import java.security.KeyStore
import javax.crypto.Cipher
import javax.crypto.KeyGenerator
import javax.crypto.SecretKey
import javax.crypto.spec.IvParameterSpec

class SecureStorage(private val context: Context) {

    private val keyStore = KeyStore.getInstance("AndroidKeyStore")
    private val keyAlias = "MemoriesKey"

    init {
        keyStore.load(null)
        if (!keyStore.containsAlias(keyAlias)) {
            generateNewKey()
        }
    }

    fun saveCredentials(url: String, username: String, token: String) {
        val cipher = getCipher()
        cipher.init(Cipher.ENCRYPT_MODE, getSecretKey())
        val encryptedToken = cipher.doFinal(token.toByteArray())

        context.getSharedPreferences("credentials", Context.MODE_PRIVATE).edit()
            .putString("url", url)
            .putString("username", username)
            .putString("encryptedToken", Base64.encodeToString(encryptedToken, Base64.DEFAULT))
            .putString("iv", Base64.encodeToString(cipher.iv, Base64.DEFAULT))
            .apply()
    }

    fun getCredentials(): Triple<String, String, String>? {
        val sharedPreferences = context.getSharedPreferences("credentials", Context.MODE_PRIVATE)
        val url = sharedPreferences.getString("url", null)
        val username = sharedPreferences.getString("username", null)
        val encryptedToken = sharedPreferences.getString("encryptedToken", null)
        val ivStr = sharedPreferences.getString("iv", null)

        if (url != null && username != null && encryptedToken != null && ivStr != null) {
            val iv = Base64.decode(ivStr, Base64.DEFAULT)

            val cipher = getCipher()
            cipher.init(Cipher.DECRYPT_MODE, getSecretKey(), IvParameterSpec(iv))

            val token = String(cipher.doFinal(Base64.decode(encryptedToken, Base64.DEFAULT)))
            return Triple(url, username, token)
        }

        return null
    }

    fun deleteCredentials() {
        context.getSharedPreferences("credentials", Context.MODE_PRIVATE).edit()
            .remove("url")
            .remove("encryptedUsername")
            .remove("encryptedToken")
            .remove("iv")
            .apply()
    }

    private fun generateNewKey() {
        val keyGenerator = KeyGenerator.getInstance(KEY_ALGORITHM_AES, "AndroidKeyStore")
        val keyGenSpec = KeyGenParameterSpec.Builder(
            keyAlias,
            PURPOSE_ENCRYPT or PURPOSE_DECRYPT
        )
            .setBlockModes(KeyProperties.BLOCK_MODE_CBC)
            .setEncryptionPaddings(KeyProperties.ENCRYPTION_PADDING_PKCS7)
            .setUserAuthenticationRequired(false) // Change this if needed
            .build()

        keyGenerator.init(keyGenSpec)
        keyGenerator.generateKey()
    }

    private fun getCipher(): Cipher {
        val transformation =
            "$KEY_ALGORITHM_AES/${KeyProperties.BLOCK_MODE_CBC}/${KeyProperties.ENCRYPTION_PADDING_PKCS7}"
        return Cipher.getInstance(transformation)
    }

    private fun getSecretKey(): SecretKey {
        return keyStore.getKey(keyAlias, null) as SecretKey
    }
}