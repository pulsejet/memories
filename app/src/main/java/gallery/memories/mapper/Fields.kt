package gallery.memories.mapper

class Fields {
    object Day {
        const val DAYID = Photo.DAYID
        const val COUNT = "count"
    }

    object Photo {
        const val FILEID = "fileid"
        const val BASENAME = "basename"
        const val MIMETYPE = "mimetype"
        const val HEIGHT = "h"
        const val WIDTH = "w"
        const val SIZE = "size"
        const val ETAG = "etag"
        const val DATETAKEN = "datetaken"
        const val DAYID = "dayid"
        const val ISVIDEO = "isvideo"
        const val VIDEO_DURATION = "video_duration"
        const val EXIF = "exif"
        const val PERMISSIONS = "permissions"
    }

    object Perm {
        const val DELETE = "D"
    }
}