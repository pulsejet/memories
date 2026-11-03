package gallery.memories.data.remote.assets

import android.util.Log
import gallery.memories.data.remote.http.NextcloudApi
import java.io.File
import java.security.MessageDigest
import java.util.concurrent.atomic.AtomicInteger

/** Downloads single assets with SHA-256 verification. Thread-safe for parallel downloads. */
class AssetDownloader(private val api: NextcloudApi) {
    private val progress = AtomicInteger(0)

    companion object {
        private val TAG = AssetDownloader::class.java.simpleName
    }

    /** Files completed in the current sync. Reset per sync via [reset]. */
    val done: Int get() = progress.get()

    fun reset() { progress.set(0) }

    /**
     * Downloads the first of [urls] that succeeds into [dir]/[name], verifying [expectedSha256].
     * Writes atomically via a temp file so interrupted downloads never
     * leave a partial file behind.
     */
    @Throws(Exception::class)
    fun downloadTo(dir: File, name: String, urls: List<String>, expectedSha256: String? = null) {
        var error: Exception? = null
        var source: String? = null
        val bytes = urls.firstNotNullOfOrNull { url ->
            try {
                api.downloadBytes(url).also { if (expectedSha256 != null) verifySha256(it, expectedSha256, name) }
                    .also { source = url }
            } catch (e: Exception) {
                Log.w(TAG, "Failed to download $name from $url: ${e.message}")
                error = e
                null
            }
        } ?: throw error ?: Exception("No URL for $name")
        val tmp = File.createTempFile(name, ".part", dir)
        try {
            tmp.writeBytes(bytes)
            if (!tmp.renameTo(File(dir, name))) throw Exception("Failed to store $name")
        } finally {
            tmp.delete()
        }
        progress.incrementAndGet()
        Log.i(TAG, "Downloaded $name from $source")
    }

    @Throws(Exception::class)
    private fun verifySha256(bytes: ByteArray, expected: String, name: String) {
        val actual = MessageDigest.getInstance("SHA-256").digest(bytes).joinToString("") { "%02x".format(it) }
        if (!actual.equals(expected, ignoreCase = true)) {
            throw Exception("SHA-256 mismatch for $name: expected $expected, got $actual")
        }
    }
}
