package gallery.memories.share

import android.content.Intent
import androidx.appcompat.app.AppCompatActivity
import androidx.collection.ArrayMap
import androidx.media3.common.util.UnstableApi
import gallery.memories.timeline.TimelineJson
import gallery.memories.timeline.TimelineRepository
import org.json.JSONArray
import java.util.concurrent.CountDownLatch

@UnstableApi
/** Shares URLs/files with other apps. Remote files are downloaded first; completion is matched by download ID. */
class ShareManager(
    private val activity: AppCompatActivity,
    private val timeline: TimelineRepository,
    private val downloads: DownloadManagerWrapper,
) {
    private val callbacks: MutableMap<Long, () -> Unit> = ArrayMap()
    private var shareBlobs: JSONArray? = null

    fun runDownloadCallback(intent: Intent) {
        if (activity.isDestroyed) return
        if (android.app.DownloadManager.ACTION_DOWNLOAD_COMPLETE == intent.action) {
            val id = intent.getLongExtra(android.app.DownloadManager.EXTRA_DOWNLOAD_ID, 0)
            synchronized(callbacks) {
                callbacks[id]?.let {
                    it()
                    callbacks.remove(id)
                }
            }
        }
    }

    fun queue(url: String, filename: String): Long = downloads.queue(url, filename)

    fun shareUrl(url: String): Boolean {
        val intent = Intent(Intent.ACTION_SEND).apply {
            type = "text/plain"
            putExtra(Intent.EXTRA_TEXT, url)
        }
        activity.startActivity(Intent.createChooser(intent, null))
        return true
    }

    fun setShareBlobs(objects: JSONArray) {
        shareBlobs = objects
    }

    /**
     * Shares staged blobs: on-device files directly, remote ones after
     * downloading. Blocks until every download completes.
     */
    @Throws(Exception::class)
    fun shareBlobs(): Boolean {
        val blobs = shareBlobs ?: throw Exception("No blobs to share")
        val files = ArrayList<DownloadManagerWrapper.DlFile>()
        val dlHref = ArrayList<String>()
        for (i in 0 until blobs.length()) {
            val obj = blobs.getJSONObject(i)
            val auid = obj.getString(TimelineJson.Photo.AUID)
            if (auid.isNotEmpty()) {
                val sysImgs = timeline.getSystemImagesByAUIDs(listOf(auid))
                if (sysImgs.isNotEmpty()) {
                    files.add(DownloadManagerWrapper.DlFile().apply {
                        uri = sysImgs[0].uri
                        name = sysImgs[0].baseName
                        mimeType = sysImgs[0].mimeType
                    })
                    continue
                }
            }
            val href = obj.getString(TimelineJson.Other.HREF)
            if (href.isNotEmpty()) dlHref.add(href)
        }
        val dlIds = dlHref.map { downloads.queue(it, "") }
        val latch = CountDownLatch(dlIds.size)
        synchronized(callbacks) {
            dlIds.forEach { id -> callbacks[id] = { latch.countDown() } }
        }
        latch.await()
        dlIds.forEach { id -> files.add(downloads.getDownloadedFile(id) ?: throw Exception("Failed to download file")) }
        if (files.size > 1) {
            val intent = Intent(Intent.ACTION_SEND_MULTIPLE)
            val uris = files.map { it.uri }.toCollection(ArrayList())
            val firstMime = files[0].mimeType?.split("/")?.get(0) ?: "*"
            intent.type = if (files.all { it.mimeType?.startsWith(firstMime) == true }) "$firstMime/*" else "*/*"
            intent.addFlags(Intent.FLAG_GRANT_READ_URI_PERMISSION)
            intent.putExtra(Intent.EXTRA_STREAM, uris)
            activity.startActivity(Intent.createChooser(intent, null))
        } else if (files.size == 1) {
            val file = files[0]
            val intent = Intent(Intent.ACTION_SEND).apply {
                type = file.mimeType
                addFlags(Intent.FLAG_GRANT_READ_URI_PERMISSION)
                putExtra(Intent.EXTRA_STREAM, file.uri)
            }
            activity.startActivity(Intent.createChooser(intent, file.name))
        }
        shareBlobs = null
        return true
    }
}
