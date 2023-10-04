package gallery.memories.service

import android.app.DownloadManager
import android.content.Context
import android.content.Intent
import android.net.Uri
import android.os.Environment
import android.webkit.CookieManager
import android.widget.Toast
import androidx.appcompat.app.AppCompatActivity
import androidx.collection.ArrayMap
import androidx.media3.common.util.UnstableApi
import java.util.concurrent.CountDownLatch

@UnstableApi class DownloadService(private val mActivity: AppCompatActivity, private val query: TimelineQuery) {
    private val mDownloads: MutableMap<Long, () -> Unit> = ArrayMap()

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

            Toast.makeText(mActivity, "Download Complete", Toast.LENGTH_SHORT).show()
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
     * Share a URL as a blob
     * @param url The URL to share
     * @return True if the URL was shared
     */
    @Throws(Exception::class)
    fun shareBlobFromUrl(url: String): Boolean {
        val id = queue(url, "")
        val latch = CountDownLatch(1)
        synchronized(mDownloads) {
            mDownloads.put(id, fun() { latch.countDown() })
        }
        latch.await()

        // Get the URI of the downloaded file
        val sUri = getDownloadedFileURI(id) ?: throw Exception("Failed to download file")
        val uri = Uri.parse(sUri)

        // Create sharing intent
        val intent = Intent(Intent.ACTION_SEND)
        intent.type = mActivity.contentResolver.getType(uri)
        intent.putExtra(Intent.EXTRA_STREAM, uri)
        mActivity.startActivity(Intent.createChooser(intent, null))
        return true
    }

    /**
     * Share a local image
     * @param auid The AUID of the image to share
     * @return True if the image was shared
     */
    @Throws(Exception::class)
    fun shareLocal(auid: String): Boolean {
        val sysImgs = query.getSystemImagesByAUIDs(listOf(auid))
        if (sysImgs.isEmpty()) throw Exception("Image not found locally")
        val uri = sysImgs[0].uri

        val intent = Intent(Intent.ACTION_SEND)
        intent.addFlags(Intent.FLAG_GRANT_READ_URI_PERMISSION)
        intent.type = mActivity.contentResolver.getType(uri)
        intent.putExtra(Intent.EXTRA_STREAM, uri)
        mActivity.startActivity(Intent.createChooser(intent, null))
        return true
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