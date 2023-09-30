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
import java.util.concurrent.CountDownLatch

class DownloadService(private val mActivity: AppCompatActivity, private val query: TimelineQuery) {
    private val mDownloads: MutableMap<Long, () -> Unit> = ArrayMap()

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

    fun shareUrl(url: String): Boolean {
        val intent = Intent(Intent.ACTION_SEND)
        intent.type = "text/plain"
        intent.putExtra(Intent.EXTRA_TEXT, url)
        mActivity.startActivity(Intent.createChooser(intent, null))
        return true
    }

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

    @Throws(Exception::class)
    fun shareLocal(auid: Long): Boolean {
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