package gallery.memories.data.local.media

import android.content.ContentUris
import android.net.Uri
import android.provider.MediaStore

/**
 * One MediaStore row plus derived IDs; uri/epoch are computed, not stored.
 * Times are MediaStore units: [dateTaken] and [mtime] are milliseconds,
 * [videoDuration] is milliseconds, [epoch] is seconds.
 */
class SystemImage {
    var fileId = 0L
    var baseName = ""
    var mimeType = ""
    var dateTaken = 0L
    var height = 0L
    var width = 0L
    var size = 0L
    var mtime = 0L
    var dataPath = ""
    var bucketId = 0L
    var bucketName = ""
    var isVideo = false
    var videoDuration = 0L
    var collection: Uri = MediaStore.Images.Media.EXTERNAL_CONTENT_URI

    val uri: Uri
        get() = ContentUris.withAppendedId(collection, fileId)

    val epoch: Long
        get() = dateTaken / 1000

    companion object {
        val IMAGE_URI: Uri = MediaStore.Images.Media.EXTERNAL_CONTENT_URI
        val VIDEO_URI: Uri = MediaStore.Video.Media.EXTERNAL_CONTENT_URI
    }
}
