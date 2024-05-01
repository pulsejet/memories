package gallery.memories.mapper

import androidx.exifinterface.media.ExifInterface

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
        const val EPOCH = "epoch"
        const val AUID = "auid"
        const val BUID = "buid"
        const val DAYID = "dayid"
        const val ISVIDEO = "isvideo"
        const val VIDEO_DURATION = "video_duration"
        const val EXIF = "exif"
        const val PERMISSIONS = "permissions"
    }

    object Perm {
        const val DELETE = "D"
    }

    object EXIF {
        val MAP = mapOf(
            ExifInterface.TAG_APERTURE_VALUE to "Aperture",
            ExifInterface.TAG_FOCAL_LENGTH to "FocalLength",
            ExifInterface.TAG_F_NUMBER to "FNumber",
            ExifInterface.TAG_SHUTTER_SPEED_VALUE to "ShutterSpeed",
            ExifInterface.TAG_EXPOSURE_TIME to "ExposureTime",
            ExifInterface.TAG_ISO_SPEED to "ISO",
            ExifInterface.TAG_DATETIME_ORIGINAL to "DateTimeOriginal",
            ExifInterface.TAG_OFFSET_TIME_ORIGINAL to "OffsetTimeOriginal",
            ExifInterface.TAG_GPS_LATITUDE to "GPSLatitude",
            ExifInterface.TAG_GPS_LONGITUDE to "GPSLongitude",
            ExifInterface.TAG_GPS_ALTITUDE to "GPSAltitude",
            ExifInterface.TAG_MAKE to "Make",
            ExifInterface.TAG_MODEL to "Model",
            ExifInterface.TAG_ORIENTATION to "Orientation",
            ExifInterface.TAG_IMAGE_DESCRIPTION to "Description"
        )
    }

    object Bucket {
        const val ID = "id"
        const val NAME = "name"
        const val ENABLED = "enabled"
    }

    object Other {
        const val HREF = "href"
    }
}