package gallery.memories.share

import android.app.DownloadManager
import android.content.Context
import android.net.Uri
import android.os.Environment
import android.webkit.CookieManager
import androidx.appcompat.app.AppCompatActivity

/** System downloader with the WebView session cookies forwarded so authed URLs work. */
class DownloadManagerWrapper(private val activity: AppCompatActivity) {
    class DlFile {
        var uri: Uri? = null
        var name: String? = null
        var mimeType: String? = null
    }

    fun queue(url: String, filename: String): Long {
        val uri = Uri.parse(url)
        val manager = activity.getSystemService(Context.DOWNLOAD_SERVICE) as DownloadManager
        val request = DownloadManager.Request(uri)
        request.setNotificationVisibility(DownloadManager.Request.VISIBILITY_VISIBLE)
        CookieManager.getInstance().getCookie(url)?.let { cookies ->
            request.addRequestHeader("cookie", cookies)
        }
        if (filename != "") {
            request.setDestinationInExternalPublicDir(Environment.DIRECTORY_DOWNLOADS, "memories/$filename")
        }
        return manager.enqueue(request)
    }

    fun getDownloadedFile(downloadId: Long): DlFile? {
        val manager = activity.getSystemService(Context.DOWNLOAD_SERVICE) as DownloadManager
        val q = DownloadManager.Query().setFilterById(downloadId)
        manager.query(q).use { cursor ->
            if (cursor.moveToFirst()) {
                val uriIdx = cursor.getColumnIndex(DownloadManager.COLUMN_LOCAL_URI)
                val nameIdx = cursor.getColumnIndex(DownloadManager.COLUMN_TITLE)
                val mimeIdx = cursor.getColumnIndex(DownloadManager.COLUMN_MEDIA_TYPE)
                return DlFile().apply {
                    uri = Uri.parse(cursor.getString(uriIdx))
                    name = cursor.getString(nameIdx)
                    mimeType = cursor.getString(mimeIdx)
                }
            }
        }
        return null
    }
}
