package gallery.memories.share

import android.content.ClipData
import android.content.Intent
import androidx.appcompat.app.AppCompatActivity
import androidx.media3.common.util.UnstableApi
import gallery.memories.timeline.TimelineJson
import gallery.memories.timeline.TimelineRepository
import org.json.JSONArray
import org.json.JSONObject

@UnstableApi
/**
 * Shares URLs/files with other apps. Remote files are downloaded in-process
 * first (see [InAppDownloader]: the system DownloadManager cannot reach LAN
 * hosts on Android 17). All methods must be called on the bridge thread:
 * [shareBlobs] blocks until every download completes or throws.
 */
class ShareManager(
    private val activity: AppCompatActivity,
    private val timeline: TimelineRepository,
    private val downloads: InAppDownloader,
) {
    private var shareBlobs: JSONArray? = null

    /** Downloads one URL in-process; throws on failure. */
    @Throws(Exception::class)
    fun downloadFile(url: String, filename: String): InAppDownloader.DlFile =
        downloads.download(url, filename)

    /** Shares a plain URL via the system chooser. */
    fun shareUrl(url: String): Boolean {
        val intent = Intent(Intent.ACTION_SEND).apply {
            type = "text/plain"
            putExtra(Intent.EXTRA_TEXT, url)
        }
        activity.startActivity(Intent.createChooser(intent, null))
        return true
    }

    /** Stages blobs for the next [shareBlobs] call. */
    fun setShareBlobs(objects: JSONArray) {
        shareBlobs = objects
    }

    /**
     * Shares staged blobs: on-device files directly, remote ones after
     * in-process downloading. Blocks until every download completes; throws
     * on the first failure instead of hanging forever.
     */
    @Throws(Exception::class)
    fun shareBlobs(): Boolean {
        val blobs = shareBlobs ?: throw Exception("No blobs to share")
        try {
            val files = List(blobs.length()) { blobs.getJSONObject(it) }.mapNotNull(::resolve)
            if (files.isEmpty()) throw Exception("Nothing to share")
            send(files)
            return true
        } finally {
            shareBlobs = null
        }
    }

    /** On-device copy when indexed, else in-process download. Null when unresolvable. */
    @Throws(Exception::class)
    private fun resolve(obj: JSONObject): InAppDownloader.DlFile? {
        val auid = obj.optString(TimelineJson.Photo.AUID)
        if (auid.isNotEmpty()) {
            timeline.getSystemImagesByAUIDs(listOf(auid)).firstOrNull()?.let {
                return InAppDownloader.DlFile(it.uri, it.baseName, it.mimeType)
            }
        }
        val href = obj.optString(TimelineJson.Other.HREF)
        if (href.isNotEmpty()) return downloads.download(href, "")
        return null
    }

    /**
     * Fires the system chooser for resolved [files] (non-empty).
     *
     * - Single file: ACTION_SEND with its exact mime type (wildcard when unknown)
     *   and its name as the chooser title.
     * - Uniform set: ACTION_SEND_MULTIPLE with the shared top-level type
     *   (e.g. image wildcard) for tight target filtering.
     * - Mixed set: ACTION_SEND_MULTIPLE with the wildcard type (an intent holds
     *   a single [Intent.type]) plus the distinct mime types in
     *   [Intent.EXTRA_MIME_TYPES] for precise filtering.
     * - Read access to the FileProvider/MediaStore URIs is granted via
     *   FLAG_GRANT_READ_URI_PERMISSION plus ClipData carrying every URI,
     *   since the receiving app reads them asynchronously after we return.
     */
    private fun send(files: List<InAppDownloader.DlFile>) {
        val intent = if (files.size > 1) {
            Intent(Intent.ACTION_SEND_MULTIPLE).apply {
                val top = files[0].mimeType.substringBefore("/").ifEmpty { "*" }
                type = if (files.all { it.mimeType.substringBefore("/").ifEmpty { "*" } == top }) "$top/*" else "*/*"
                putExtra(Intent.EXTRA_STREAM, ArrayList(files.map { it.uri }))
                files.map { it.mimeType }.filter { it.isNotEmpty() }.distinct()
                    .takeIf { it.size > 1 }?.let { putExtra(Intent.EXTRA_MIME_TYPES, it.toTypedArray()) }
            }
        } else {
            Intent(Intent.ACTION_SEND).apply {
                type = files[0].mimeType.ifEmpty { "*/*" }
                putExtra(Intent.EXTRA_STREAM, files[0].uri)
            }
        }
        intent.addFlags(Intent.FLAG_GRANT_READ_URI_PERMISSION)
        val clip = ClipData.newUri(activity.contentResolver, files[0].name, files[0].uri)
        for (i in 1 until files.size) clip.addItem(ClipData.Item(files[i].uri))
        intent.clipData = clip
        activity.startActivity(Intent.createChooser(intent, if (files.size == 1) files[0].name else null))
    }
}
