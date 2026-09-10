package gallery.memories.share

import android.content.ContentValues
import android.content.Context
import android.net.Uri
import android.os.Environment
import android.provider.MediaStore
import android.util.Log
import android.webkit.CookieManager
import androidx.core.content.FileProvider
import gallery.memories.data.remote.http.AuthState
import gallery.memories.data.remote.http.HttpClients
import okhttp3.Request
import java.io.File
import java.util.concurrent.TimeUnit

/**
 * In-process file downloader using the app's own OkHttp client.
 *
 * The system DownloadManager runs as `com.android.providers.downloads`, which
 * on Android 17 (targetSdk 37) has no `ACCESS_LOCAL_NETWORK` grant and can
 * never reach a LAN / split-horizon-DNS host: the SYN is dropped on-device
 * and the download spins forever. This downloader runs in-process, so it
 * inherits the app's own network grant (implicit via INTERNET on target <=36,
 * explicit via ACCESS_LOCAL_NETWORK on 37+) and the user's TLS opt-in.
 *
 * URLs are fetched as-given: local `http://127.0.0.1/...` URLs go through the
 * localhost proxy (CookieManager supplies the `local-auth` cookie, the proxy
 * adds basic auth upstream), while absolute upstream URLs use the stored
 * basic auth directly. Both carry `OCS-APIREQUEST` to exempt CSRF checks.
 * Bodies always stream to disk, never fully into memory.
 */
class InAppDownloader(
    private val ctx: Context,
    private val auth: AuthState,
    private val clients: HttpClients,
) {
    companion object {
        private val TAG = InAppDownloader::class.java.simpleName
        private const val SHARE_MAX_AGE_MS = 3 * 60 * 60 * 1000L
    }

    /** One downloaded file. */
    data class DlFile(val uri: Uri, val name: String, val mimeType: String)

    /**
     * Downloads [url] synchronously; must be called off the UI thread.
     * Saves visibly to Downloads/memories/ when [toPublic] (inferring the
     * name from Content-Disposition when [filename] is empty), otherwise to
     * the private share cache for immediate ACTION_SEND. Throws on failure.
     */
    @Throws(Exception::class)
    fun download(url: String, filename: String, toPublic: Boolean = filename.isNotEmpty()): DlFile {
        val request = buildRequest(url)
        // Per-call client: shares the current pool/dispatcher, picks up the
        // latest TLS/auth config, times out instead of hanging forever.
        val client = clients.client().newBuilder()
            .connectTimeout(15, TimeUnit.SECONDS)
            .readTimeout(60, TimeUnit.SECONDS)
            .writeTimeout(60, TimeUnit.SECONDS)
            .build()
        client.newCall(request).execute().use { res ->
            if (res.code !in 200..299) throw Exception("Download failed: HTTP ${res.code}")
            val body = res.body
            val mime = body.contentType()?.let { "${it.type}/${it.subtype}" }
                ?: res.header("Content-Type")?.substringBefore(";")?.trim()
                ?: "application/octet-stream"
            val name = sanitize(if (filename.isNotEmpty()) filename else inferName(res.header("Content-Disposition"), url))
            if (toPublic) {
                return DlFile(storePublic(name, mime) { out -> body.byteStream().use { it.copyTo(out) } }, name, mime)
            }
            return DlFile(storeCached(name) { out -> body.byteStream().use { it.copyTo(out) } }, name, mime)
        }
    }

    private fun buildRequest(url: String): Request {
        val builder = Request.Builder().url(url)
            .header("User-Agent", "Memories")
            .header("OCS-APIREQUEST", "true")
        auth.authHeader()?.let { builder.header("Authorization", it) }
        try {
            CookieManager.getInstance().getCookie(url)?.let { cookies ->
                if (cookies.isNotEmpty()) builder.header("Cookie", cookies)
            }
        } catch (e: Exception) {
            Log.w(TAG, "Cookie lookup failed, continuing without cookies", e)
        }
        return builder.get().build()
    }

    /** Visible save to Downloads/memories/ via MediaStore. Returns the new URI. */
    @Throws(Exception::class)
    private fun storePublic(name: String, mime: String, copy: (java.io.OutputStream) -> Unit): Uri {
        val resolver = ctx.contentResolver
        val values = ContentValues().apply {
            put(MediaStore.Downloads.DISPLAY_NAME, name)
            put(MediaStore.Downloads.MIME_TYPE, mime)
            put(MediaStore.Downloads.RELATIVE_PATH, Environment.DIRECTORY_DOWNLOADS + "/memories")
            put(MediaStore.Downloads.IS_PENDING, 1)
        }
        val uri = resolver.insert(MediaStore.Downloads.EXTERNAL_CONTENT_URI, values)
            ?: throw Exception("Failed to create download entry")
        try {
            resolver.openOutputStream(uri)?.use(copy) ?: throw Exception("Failed to write download")
        } catch (e: Exception) {
            try { resolver.delete(uri, null, null) } catch (_: Exception) {}
            throw e
        }
        values.clear()
        values.put(MediaStore.Downloads.IS_PENDING, 0)
        resolver.update(uri, values, null, null)
        return uri
    }

    /** Transient save to cache/share/ shared via FileProvider. Returns a content URI. */
    @Throws(Exception::class)
    private fun storeCached(name: String, copy: (java.io.OutputStream) -> Unit): Uri {
        val dir = File(ctx.cacheDir, "share").apply { mkdirs() }
        pruneShareCache(dir)
        val file = uniqueFile(dir, name)
        try {
            file.outputStream().use(copy)
        } catch (e: Exception) {
            try { file.delete() } catch (_: Exception) {}
            throw e
        }
        return FileProvider.getUriForFile(ctx, "${ctx.packageName}.fileprovider", file)
    }

    private fun pruneShareCache(dir: File) {
        try {
            val cutoff = System.currentTimeMillis() - SHARE_MAX_AGE_MS
            dir.listFiles()?.forEach { if (it.lastModified() < cutoff) it.delete() }
        } catch (e: Exception) {
            Log.w(TAG, "Share cache prune failed", e)
        }
    }

    private fun uniqueFile(dir: File, name: String): File {        var file = File(dir, name)
        if (!file.exists()) return file
        val dot = name.lastIndexOf('.')
        val stem = if (dot > 0) name.substring(0, dot) else name
        val ext = if (dot > 0) name.substring(dot) else ""
        var i = 1
        while (file.exists() && i < 1000) file = File(dir, "$stem ($i)$ext").also { i++ }
        if (file.exists()) throw Exception("Failed to create share file")
        return file
    }

    private fun inferName(disposition: String?, url: String): String {
        if (!disposition.isNullOrEmpty()) {
            // RFC 5987 first, then quoted, then bare.
            Regex("""filename\*\s*=\s*UTF-8''([^;\s]+)""", RegexOption.IGNORE_CASE)
                .find(disposition)?.groupValues?.get(1)?.let { return decodeFileName(it) }
            Regex("""filename\s*=\s*"([^"]+)"""").find(disposition)?.groupValues?.get(1)
                ?.let { if (it.isNotEmpty()) return it }
            Regex("""filename\s*=\s*([^;\s]+)""", RegexOption.IGNORE_CASE)
                .find(disposition)?.groupValues?.get(1)?.let { if (it.isNotEmpty()) return it.trim('"') }
        }
        val last = url.substringBefore('?').substringAfterLast('/').substringAfterLast('\\')
        if (last.isNotEmpty()) return decodeFileName(last)
        return "download.bin"
    }

    private fun decodeFileName(raw: String): String = try {
        java.net.URLDecoder.decode(raw, "UTF-8")
    } catch (_: Exception) {
        raw
    }

    private fun sanitize(name: String): String {
        var clean = name.replace(Regex("[/\\\\\u0000]"), "_").trim().trim('.')
        if (clean.isEmpty()) clean = "download.bin"
        if (clean.length > 200) {
            val dot = clean.lastIndexOf('.')
            clean = if (dot > 0) clean.take(200 - (clean.length - dot)) + clean.substring(dot)
            else clean.take(200)
        }
        return clean
    }
}
