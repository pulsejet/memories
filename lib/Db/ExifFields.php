<?php

declare(strict_types=1);

namespace OCA\Memories\Db;

class ExifFields
{
    /**
     * This is the list of fields that will be STORED in the databse as JSON.
     * This is mostly only used for the metadata view.
     */
    public const EXIF_FIELDS_LIST = [
        // Date/Time
        'DateTimeOriginal' => true,
        'CreateDate' => true,
        'OffsetTimeOriginal' => true,
        'OffsetTime' => true,

        // Generated date fields
        'DateTimeEpoch' => true,
        'LocationTZID' => true,

        // Camera Info
        'Make' => true,
        'Model' => true,
        'LensModel' => true,
        'CameraType' => true,
        'AutoRotate' => true,
        'SerialNumber' => true,

        // Photo Info
        'FNumber' => true,
        'ApertureValue' => true,
        'FocalLength' => true,
        'ISO' => true,
        'ShutterSpeedValue' => true,
        'ShutterSpeed' => true,
        'ExposureTime' => true,
        'WhiteBalance' => true,
        'Sharpness' => true,
        'ColorTemperature' => true,
        'HDR' => true,
        'HDREffect' => true,
        'ColorSpace' => true,
        'Aperture' => true,
        'ImageUniqueID' => true,

        // GPS info
        'GPSLatitude' => true,
        'GPSLongitude' => true,
        'GPSAltitude' => true,
        'GPSTimeStamp' => true,
        'GPSStatus' => true,

        // Size / rotation info
        'Megapixels' => true,
        'Rotation' => true,
        'Orientation' => true,

        // Editable Metadata
        'Title' => true,
        'Description' => true,
        'Label' => true,
        'Artist' => true,
        'Copyright' => true,

        // Other image info
        'Rating' => true,
        'NumberOfImages' => true,
        'FlashType' => true,
        'RedEyeReduction' => true,
        'CircleOfConfusion' => true,
        'DOF' => true,
        'FOV' => true,

        // Currently unused fields
        'ExifVersion' => true,

        // Video info
        'Duration' => true,
        'FrameRate' => true,
        'TrackDuration' => true,
        'VideoCodec' => true,
    ];
}
