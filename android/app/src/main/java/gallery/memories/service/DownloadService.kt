package gallery.memories.service

import android.app.DownloadManager
import android.content.Context
import android.content.Intent
import android.net.Uri
import android.os.Environment
import android.webkit.CookieManager
import androidx.appcompat.app.AppCompatActivity
import androidx.collection.ArrayMap
import androidx.media3.common.util.UnstableApi
import org.json.JSONArray
import java.util.concurrent.CountDownLatch

@UnstableApi class DownloadService(private val mActivity: AppCompatActivity, private val query: TimelineQuery) {
    private val mDownloads: MutableMap<Long, () -> Unit> = ArrayMap()
    private var mShareBlobs: JSONArray? = null

    /**
     * Callback when download is complete
     * @param intent The intent that triggered the callback
     */
    fun runDownloadCallback(intent: Intent) {
        if (mActivity.isDestroyed) return

        if (DownloadManager.ACTION_DOWNLOAD_COMPLETE == intent.action) {
            val id = intent.getLongExtra(DownloadManager.EXTRA_DOWNLOAD_ID, 0)
            synchronized(mDownloads) {
                mDownloads[id]?.let {
                    it()
                    mDownloads.remove(id)
                    return
                }
            }
        }
    }

    /**
     * Queue a download
     * @param url The URL to download
     * @param filename The filename to save the download as
     * @return The download ID
     */
    fun queue(url: String, filename: String): Long {
        val uri = Uri.parse(url)
        val manager = mActivity.getSystemService(Context.DOWNLOAD_SERVICE) as DownloadManager
        val request = DownloadManager.Request(uri)
        request.setNotificationVisibility(DownloadManager.Request.VISIBILITY_VISIBLE)

        // Copy all cookies from the webview to the download request
        val cookies = CookieManager.getInstance().getCookie(url)
        request.addRequestHeader("cookie", cookies)
        if (filename != "") {
            // Save the file to external storage
            request.setDestinationInExternalPublicDir(
                Environment.DIRECTORY_DOWNLOADS,
                "memories/$filename"
            )
        }

        // Start the download
        return manager.enqueue(request)
    }

    /**
     * Share a URL as a string
     * @param url The URL to share
     * @return True if the URL was shared
     */
    fun shareUrl(url: String): Boolean {
        val intent = Intent(Intent.ACTION_SEND)
        intent.type = "text/plain"
        intent.putExtra(Intent.EXTRA_TEXT, url)
        mActivity.startActivity(Intent.createChooser(intent, null))
        return true
    }

    /**
     * Share the blobs from URLs already set by setShareBlobs
     * @return True if the URL was shared
     */
    @Throws(Exception::class)
    fun shareBlobs(): Boolean {
        if (mShareBlobs == null) throw Exception("No blobs to share")

        // All URIs to share including remote and local files
        val uris = ArrayList<Uri>()
        val dlIds = ArrayList<Long>()

        // Process all objects to share
        for (i in 0 until mShareBlobs!!.length()) {
            val obj = mShareBlobs!!.getJSONObject(i)

            // If AUID is found, then look for local file
            val auid = obj.getString("auid")
            if (auid != "") {
                val sysImgs = query.getSystemImagesByAUIDs(listOf(auid))
                if (sysImgs.isNotEmpty()) {
                    uris.add(sysImgs[0].uri)
                    continue
                }
            }

            // Queue a download for remote files
            dlIds.add(queue(obj.getString("href"), ""))
        }

        // Wait for all downloads to complete
        val latch = CountDownLatch(dlIds.size)
        synchronized(mDownloads) {
            for (dlId in dlIds) {
                mDownloads.put(dlId, fun() { latch.countDown() })
            }
        }
        latch.await()

        // Get the URI of the downloaded file
        for (id in dlIds) {
            val sUri = getDownloadedFileURI(id) ?: throw Exception("Failed to download file")
            uris.add(Uri.parse(sUri))
        }

        // Create sharing intent
        val intent = Intent(Intent.ACTION_SEND_MULTIPLE)
        intent.type = "*/*"
        intent.putExtra(Intent.EXTRA_STREAM, uris)
        mActivity.startActivity(Intent.createChooser(intent, null))

        // Reset the blobs
        mShareBlobs = null

        return true
    }

    /**
     * Set the blobs to share
     * @param objects The blobs to share
     */
    fun setShareBlobs(objects: JSONArray) {
        mShareBlobs = objects
    }

    /**
     * Get the URI of a downloaded file from download ID
     * @param downloadId The download ID
     * @return The URI of the downloaded file
     */
    private fun getDownloadedFileURI(downloadId: Long): String? {
        val downloadManager =
            mActivity.getSystemService(Context.DOWNLOAD_SERVICE) as DownloadManager
        val query = DownloadManager.Query()
        query.setFilterById(downloadId)
        val cursor = downloadManager.query(query)
        if (cursor.moveToFirst()) {
            val columnIndex = cursor.getColumnIndex(DownloadManager.COLUMN_LOCAL_URI)
            return cursor.getString(columnIndex)
        }
        cursor.close()
        return null
    }
}