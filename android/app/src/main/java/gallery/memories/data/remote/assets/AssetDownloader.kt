package gallery.memories.data.remote.assets

import gallery.memories.data.remote.http.NextcloudApi
import java.io.File
import java.security.MessageDigest
import java.util.concurrent.atomic.AtomicInteger

/** Downloads single assets with SHA-256 verification. Thread-safe for parallel downloads. */
class AssetDownloader(private val api: NextcloudApi) {
    private val progress = AtomicInteger(0)

    /** Files completed in the current sync. Reset per sync via [reset]. */
    val done: Int get() = progress.get()

    fun reset() { progress.set(0) }

    /**
     * Downloads [url] into [dir]/[name], verifying [expectedSha256] first.
     * Writes atomically via a temp file so interrupted downloads never
     * leave a partial file behind.
     */
    @Throws(Exception::class)
    fun downloadTo(dir: File, name: String, url: String, expectedSha256: String? = null) {
        val bytes = api.downloadBytes(url)
        if (expectedSha256 != null) verifySha256(bytes, expectedSha256, name)
        val tmp = File.createTempFile(name, ".part", dir)
        try {
            tmp.writeBytes(bytes)
            if (!tmp.renameTo(File(dir, name))) throw Exception("Failed to store $name")
        } finally {
            tmp.delete()
        }
        progress.incrementAndGet()
    }

    @Throws(Exception::class)
    private fun verifySha256(bytes: ByteArray, expected: String, name: String) {
        val actual = MessageDigest.getInstance("SHA-256").digest(bytes).joinToString("") { "%02x".format(it) }
        if (!actual.equals(expected, ignoreCase = true)) {
            throw Exception("SHA-256 mismatch for $name: expected $expected, got $actual")
        }
    }
}
