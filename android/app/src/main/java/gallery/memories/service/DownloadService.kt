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
import gallery.memories.mapper.Fields
import org.json.JSONArray
import java.util.concurrent.CountDownLatch

@UnstableApi
class DownloadService(private val mActivity: AppCompatActivity, private val query: TimelineQuery) {
    private val mDownloads: MutableMap<Long, () -> Unit> = ArrayMap()
    private var mShareBlobs: JSONArray? = null

    class DlFile {
        var uri: Uri? = null
        var name: String? = null
        var mimeType: String? = null
    }

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
        val files = ArrayList<DlFile>()
        val dlHref = ArrayList<String>()
        val dlIds = ArrayList<Long>()

        // Process all objects to share
        for (i in 0 until mShareBlobs!!.length()) {
            val obj = mShareBlobs!!.getJSONObject(i)

            // If AUID is found, then look for local file
            val auid = obj.getString(Fields.Photo.AUID)
            if (auid.isNotEmpty()) {
                val sysImgs = query.getSystemImagesByAUIDs(listOf(auid))
                if (sysImgs.isNotEmpty()) {
                    files.add(DlFile().apply {
                        uri = sysImgs[0].uri
                        name = sysImgs[0].baseName
                        mimeType = sysImgs[0].mimeType
                    })
                    continue
                }
            }

            // Mark that we need to download this file
            // Don't start the download yet since we haven't latched
            val href = obj.getString(Fields.Other.HREF)
            if (href.isNotEmpty()) {
                dlHref.add(href)
            }
        }

        // Queue all downloads
        dlHref.forEach { dlIds.add(queue(it, "")) }

        // Wait for all downloads to complete
        val latch = CountDownLatch(dlIds.size)
        synchronized(mDownloads) {
            dlIds.forEach { dlId ->
                mDownloads[dlId] = fun() { latch.countDown() }
            }
        }
        latch.await()

        // Get the URI of the downloaded file
        dlIds.forEach { id ->
            files.add(getDownloadedFile(id) ?: throw Exception("Failed to download file"))
        }

        // Create sharing intent
        if (files.size > 1) {
            val intent = Intent(Intent.ACTION_SEND_MULTIPLE)

            // get uris as list
            val uris = files.map { it.uri }.toCollection(ArrayList())

            // check if all mimetypes have the same first part
            // in that case use that part, otherwise use */*
            val firstMime = files[0].mimeType?.split("/")?.get(0) ?: "*"
            intent.type =
                if (files.all { it.mimeType?.startsWith(firstMime) == true }) "$firstMime/*"
                else "*/*"

            // populate intent
            intent.addFlags(Intent.FLAG_GRANT_READ_URI_PERMISSION)
            intent.putExtra(Intent.EXTRA_STREAM, uris)
            mActivity.startActivity(Intent.createChooser(intent, null))
        } else if (files.size == 1) {
            val file = files[0]

            // create intent
            val intent = Intent(Intent.ACTION_SEND)
            intent.type = file.mimeType
            intent.addFlags(Intent.FLAG_GRANT_READ_URI_PERMISSION)
            intent.putExtra(Intent.EXTRA_STREAM, file.uri)
            mActivity.startActivity(Intent.createChooser(intent, file.name))
        }

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
     */
    private fun getDownloadedFile(downloadId: Long): DlFile? {
        val downloadManager =
            mActivity.getSystemService(Context.DOWNLOAD_SERVICE) as DownloadManager
        val query = DownloadManager.Query()
        query.setFilterById(downloadId)
        val cursor_ = downloadManager.query(query)
        cursor_.use { cursor ->
            if (cursor.moveToFirst()) {
                val uriIdx = cursor.getColumnIndex(DownloadManager.COLUMN_LOCAL_URI)
                val nameIdx = cursor.getColumnIndex(DownloadManager.COLUMN_TITLE)
                val mimeTypeIdx = cursor.getColumnIndex(DownloadManager.COLUMN_MEDIA_TYPE)

                return DlFile().apply {
                    uri = Uri.parse(cursor.getString(uriIdx))
                    name = cursor.getString(nameIdx)
                    mimeType = cursor.getString(mimeTypeIdx)
                }
            }
        }

        return null
    }
}