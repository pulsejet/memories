package gallery.memories.auth

import android.content.ComponentName
import android.content.Intent
import android.net.Uri
import android.util.Log
import androidx.browser.customtabs.CustomTabsCallback
import androidx.browser.customtabs.CustomTabsClient
import androidx.browser.customtabs.CustomTabsIntent
import androidx.browser.customtabs.CustomTabsServiceConnection
import androidx.browser.customtabs.CustomTabsSession
import gallery.memories.MainActivity

/**
 * Login URL opener: a partial Custom Tab when a supporting browser exists, else
 * the default browser. There is no close API for tabs, but the tab joins our
 * task, so [close] pops it by bringing MainActivity to front.
 *
 * Launched at 90% height so our Activity stays in onPause() with full network
 * access while the login poll runs.
 */
class LoginCustomTab(private val activity: MainActivity) {
    companion object {
        private val TAG = LoginCustomTab::class.java.simpleName
    }

    @Volatile private var connection: CustomTabsServiceConnection? = null

    /** True after a launch until [close]. Read/written on the UI thread. */
    @Volatile private var isOpen = false

    /**
     * Opens [url]: partial tab with a session when a supporting browser exists,
     * else plain ACTION_VIEW. [onFailure] runs on the UI thread when nothing
     * could be launched, so callers can stop waiting instead of polling blindly.
     */
    fun open(url: String, onFailure: (Exception) -> Unit = {}) {
        val uri = Uri.parse(url)
        activity.runOnUiThread {
            unbind()
            val pkg = CustomTabsClient.getPackageName(activity, null)
            if (pkg == null) {
                fallbackBrowser(uri, onFailure)
                return@runOnUiThread
            }
            val conn =
                object : CustomTabsServiceConnection() {
                    override fun onCustomTabsServiceConnected(
                        name: ComponentName,
                        client: CustomTabsClient,
                    ) {
                        client.warmup(0L)
                        launchTab(client.newSession(CustomTabsCallback()), pkg, uri, onFailure)
                    }

                    override fun onServiceDisconnected(name: ComponentName) {}
                }
            connection = conn
            if (!CustomTabsClient.bindCustomTabsService(activity, pkg, conn)) {
                connection = null
                launchTab(null, pkg, uri, onFailure)
            }
        }
    }

    private fun launchTab(
        session: CustomTabsSession?,
        pkg: String,
        uri: Uri,
        onFailure: (Exception) -> Unit,
    ) {
        try {
            val heightPx = (activity.resources.displayMetrics.heightPixels * 0.90).toInt()
            val ctIntent =
                CustomTabsIntent.Builder(session)
                    .setInitialActivityHeightPx(heightPx, CustomTabsIntent.ACTIVITY_HEIGHT_ADJUSTABLE)
                    .build()
            ctIntent.intent.setPackage(pkg)
            ctIntent.launchUrl(activity, uri)
            isOpen = true
        } catch (e: Exception) {
            Log.w(TAG, "Custom Tab launch failed", e)
            onFailure(e)
        }
    }

    private fun fallbackBrowser(uri: Uri, onFailure: (Exception) -> Unit) {
        try {
            activity.startActivity(Intent(Intent.ACTION_VIEW, uri))
            isOpen = true
        } catch (e: Exception) {
            Log.w(TAG, "Browser launch failed", e)
            onFailure(e)
        }
    }

    /** Returns to the app, dismissing the tab when it sits on our task. Safe from any thread. */
    fun close() {
        activity.runOnUiThread {
            if (!isOpen) return@runOnUiThread
            isOpen = false
            try {
                activity.startActivity(
                    Intent(activity, MainActivity::class.java)
                        .addFlags(Intent.FLAG_ACTIVITY_CLEAR_TOP or Intent.FLAG_ACTIVITY_SINGLE_TOP),
                )
            } catch (e: Exception) {
                Log.w(TAG, "Close tab failed", e)
            }
            unbind()
        }
    }

    fun unbind() {
        connection?.let {
            try {
                activity.unbindService(it)
            } catch (_: Exception) {
            }
            connection = null
        }
    }
}
