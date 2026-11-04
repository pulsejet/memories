package gallery.memories.auth

import android.app.Activity
import android.security.KeyChain
import gallery.memories.data.local.secure.ClientCertificate
import okhttp3.HttpUrl
import java.util.concurrent.CompletableFuture
import java.util.concurrent.TimeUnit
import java.util.concurrent.TimeoutException
import java.util.concurrent.atomic.AtomicReference

/** Shows the system picker on the UI thread; only the login worker waits for the result. */
class ClientCertificateSelector(private val activity: Activity) {
    private val pending = AtomicReference<CompletableFuture<String?>?>()

    fun select(server: HttpUrl): ClientCertificate {
        require(server.isHttps) { "Client certificates require an HTTPS server address" }
        val result = CompletableFuture<String?>()
        check(pending.compareAndSet(null, result)) { "Certificate selection is already in progress" }
        try {
            activity.runOnUiThread {
                if (result.isDone) return@runOnUiThread
                if (activity.isFinishing || activity.isDestroyed) {
                    result.cancel(false)
                    return@runOnUiThread
                }
                try {
                    KeyChain.choosePrivateKeyAlias(
                        activity, { result.complete(it) }, null, null, server.host, server.port, null,
                    )
                } catch (e: Exception) {
                    result.completeExceptionally(e)
                }
            }
            val alias = result.get(2, TimeUnit.MINUTES) ?: throw Exception("No client certificate selected")
            val context = activity.applicationContext
            check(KeyChain.getPrivateKey(context, alias) != null && !KeyChain.getCertificateChain(context, alias).isNullOrEmpty()) {
                "Cannot access the selected client certificate. Install it in Android Settings and try again."
            }
            return ClientCertificate(alias, server.host, server.port)
        } catch (e: TimeoutException) {
            throw Exception("Client certificate selection timed out. Please try again.", e)
        } catch (e: InterruptedException) {
            Thread.currentThread().interrupt()
            throw Exception("Client certificate selection interrupted", e)
        } finally {
            result.cancel(false)
            pending.compareAndSet(result, null)
        }
    }

    fun cancel() {
        pending.getAndSet(null)?.cancel(false)
    }
}
