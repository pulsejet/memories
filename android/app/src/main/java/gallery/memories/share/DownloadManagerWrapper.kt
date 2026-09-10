package gallery.memories.share

import android.app.DownloadManager
import android.content.Context
import android.net.Uri
import android.os.Environment
import android.webkit.CookieManager
import androidx.appcompat.app.AppCompatActivity

/** System downloader with the WebView session cookies forwarded so authed URLs work. */
class DownloadManagerWrapper(private val activity: AppCompatActivity) {
    /** One downloaded file. Fields are null when the system database lacks the row/column. */
    data class DlFile(
        var uri: Uri? = null,
        var name: String? = null,
        var mimeType: String? = null,
    )

    /**
     * Enqueues a download. An empty [filename] leaves the destination to the
     * system (used for transient share blobs); otherwise files land in
     * Downloads/memories/.
     */
    fun queue(url: String, filename: String): Long {
        val uri = Uri.parse(url)
        val manager = activity.getSystemService(Context.DOWNLOAD_SERVICE) as DownloadManager
        val request = DownloadManager.Request(uri)
        request.setNotificationVisibility(DownloadManager.Request.VISIBILITY_VISIBLE)
        CookieManager.getInstance().getCookie(url)?.let { cookies ->
            request.addRequestHeader("cookie", cookies)
        }
        if (filename.isNotEmpty()) {
            request.setDestinationInExternalPublicDir(Environment.DIRECTORY_DOWNLOADS, "memories/$filename")
        }
        return manager.enqueue(request)
    }

    /** Row for a finished download, or null when unknown. */
    fun getDownloadedFile(downloadId: Long): DlFile? {
        val manager = activity.getSystemService(Context.DOWNLOAD_SERVICE) as DownloadManager
        val q = DownloadManager.Query().setFilterById(downloadId)
        manager.query(q).use { cursor ->
            if (cursor.moveToFirst()) {
                val uriIdx = cursor.getColumnIndexOrThrow(DownloadManager.COLUMN_LOCAL_URI)
                val nameIdx = cursor.getColumnIndexOrThrow(DownloadManager.COLUMN_TITLE)
                val mimeIdx = cursor.getColumnIndexOrThrow(DownloadManager.COLUMN_MEDIA_TYPE)
                // COLUMN_LOCAL_URI is deprecated on newer APIs but still populated for our queries.
                return DlFile(
                    uri = cursor.getString(uriIdx)?.let(Uri::parse),
                    name = cursor.getString(nameIdx),
                    mimeType = cursor.getString(mimeIdx),
                )
            }
        }
        return null
    }
}
