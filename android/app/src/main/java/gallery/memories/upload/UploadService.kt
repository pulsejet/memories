package gallery.memories.upload

import android.content.Context
import android.net.Uri
import gallery.memories.data.remote.assets.AssetCache
import gallery.memories.data.remote.http.AuthState
import gallery.memories.data.remote.http.HttpClients
import gallery.memories.timeline.TimelineRepository
import okhttp3.MediaType.Companion.toMediaTypeOrNull
import okhttp3.Request
import okhttp3.RequestBody
import okio.BufferedSink
import org.json.JSONObject
import java.io.IOException
import java.util.concurrent.TimeUnit

/**
 * Uploads on-device files to Nextcloud over WebDAV without routing bytes
 * through the WebView. Bodies stream straight from the MediaStore, so even
 * large videos never sit fully in memory.
 */
class UploadService(
    private val ctx: Context,
    private val timeline: TimelineRepository,
    private val auth: AuthState,
    clients: HttpClients,
) {
    // No write timeout: big uploads on slow links must not be cut off.
    private val client = clients.newProxyClient().newBuilder()
        .writeTimeout(0, TimeUnit.SECONDS)
        .build()

    /**
     * PUTs the device file behind [auid] to [destPath] (e.g. /Photos/IMG.jpg).
     * @returns {"fileid"} of the uploaded file.
     */
    @Throws(Exception::class)
    fun upload(auid: String, destPath: String): JSONObject {
        val sysImgs = timeline.getSystemImagesByAUIDs(listOf(auid))
        if (sysImgs.isEmpty()) throw Exception("Image not found")
        val img = sysImgs[0]
        val base = auth.baseUrl() ?: throw Exception("Not logged in")
        val uid = auth.credentials()?.first ?: throw Exception("Not logged in")
        val url = davUrl(base, uid, destPath)
        val mime = img.mimeType.ifEmpty { "application/octet-stream" }
        val body = object : RequestBody() {
            override fun contentType() = mime.toMediaTypeOrNull()
            override fun contentLength() = img.size
            override fun writeTo(sink: BufferedSink) {
                ctx.applicationContext.contentResolver.openInputStream(img.uri)?.use { input ->
                    sink.outputStream().use { out -> input.copyTo(out) }
                } ?: throw IOException("Image not found")
            }
        }
        val request = Request.Builder().url(url)
            .header("User-Agent", "Memories")
            .header("Authorization", auth.authHeader() ?: "")
            .header("OCS-APIREQUEST", "true")
            .put(body)
            .build()
        client.newCall(request).execute().use { res ->
            if (res.code !in 200..299) throw Exception("Upload failed: HTTP ${res.code}")
            // oc-fileid is the DAV ID (zero-padded ID + instance ID);
            // the numeric ID is its leading digits, like parseInt elsewhere.
            val fileid = res.header("oc-fileid")?.takeWhile { it.isDigit() }?.toLongOrNull() ?: 0
            return JSONObject(mapOf("fileid" to fileid))
        }
    }

    /** <origin><webroot>/remote.php/dav/files/<uid>/<segments...> */
    private fun davUrl(base: String, uid: String, destPath: String): String {
        val segs = destPath.split("/").filter { it.isNotEmpty() }
        if (segs.isEmpty()) throw Exception("Invalid destination")
        val path = segs.joinToString("/") { Uri.encode(it) }
        return AssetCache.originOf(base) + AssetCache.webrootOf(base) +
            "/remote.php/dav/files/${Uri.encode(uid)}/$path"
    }
}
