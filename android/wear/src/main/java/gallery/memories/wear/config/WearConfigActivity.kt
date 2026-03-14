package gallery.memories.wear.config

import android.app.Activity
import android.os.Bundle
import android.view.View
import android.widget.Button
import android.widget.CheckBox
import android.widget.EditText
import android.widget.TextView
import android.widget.Toast
import gallery.memories.wear.R
import gallery.memories.wear.tile.PhotoRefreshWorker

/**
 * Simple configuration activity for the Wear OS tile.
 * Lets the user enter their Nextcloud Memories server URL and credentials.
 */
class WearConfigActivity : Activity() {

    override fun onCreate(savedInstanceState: Bundle?) {
        super.onCreate(savedInstanceState)
        setContentView(R.layout.activity_wear_config)

        val inputUrl = findViewById<EditText>(R.id.input_url)
        val inputUsername = findViewById<EditText>(R.id.input_username)
        val inputPassword = findViewById<EditText>(R.id.input_password)
        val checkTrustAll = findViewById<CheckBox>(R.id.check_trust_all)
        val btnSave = findViewById<Button>(R.id.btn_save)
        val statusText = findViewById<TextView>(R.id.status_text)

        // Pre-fill existing credentials
        val store = WearCredentialStore(this)
        store.get()?.let { cred ->
            inputUrl.setText(cred.url)
            inputUsername.setText(cred.username)
            inputPassword.setText(cred.password)
            checkTrustAll.isChecked = cred.trustAll
        }

        btnSave.setOnClickListener {
            val url = inputUrl.text.toString().trim()
            val username = inputUsername.text.toString().trim()
            val password = inputPassword.text.toString().trim()

            if (url.isEmpty() || username.isEmpty() || password.isEmpty()) {
                Toast.makeText(this, R.string.config_error, Toast.LENGTH_SHORT).show()
                return@setOnClickListener
            }

            // Ensure URL includes /apps/memories/ path and ends with /
            var normalizedUrl = if (url.endsWith("/")) url else "$url/"
            if (!normalizedUrl.contains("/apps/memories")) {
                normalizedUrl = normalizedUrl.trimEnd('/') + "/apps/memories/"
            }
            if (!normalizedUrl.endsWith("/")) normalizedUrl += "/"

            store.save(
                WearCredentialStore.Credential(
                    url = normalizedUrl,
                    username = username,
                    password = password,
                    trustAll = checkTrustAll.isChecked,
                )
            )

            // Schedule periodic photo refresh
            PhotoRefreshWorker.schedule(this)

            // Also trigger an immediate fetch
            PhotoRefreshWorker.refreshNow(this)

            statusText.text = getString(R.string.config_saved)
            statusText.visibility = View.VISIBLE

            // Close after a short delay
            statusText.postDelayed({ finish() }, 1500)
        }
    }
}
