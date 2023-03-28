<?php

const EXIF_FIELDS_LIST = [
    // Date/Time
    'DateTimeOriginal' => true,
    'SubSecDateTimeOriginal' => true,
    'CreateDate' => true,
    'OffsetTimeOriginal' => true,
    'OffsetTime' => true,
    'ModifyDate' => true,

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

    // GPS info
    'GPSLatitude' => true,
    'GPSLongitude' => true,
    'GPSAltitude' => true,
    'GPSTimeStamp' => true,
    'GPSStatus' => true,

    // Size / rotation info
    'ImageSize' => true,
    'ExifImageWidth' => true,
    'ExifImageHeight' => true,
    'ImageWidth' => true,
    'ImageHeight' => true,
    'XResolution' => true,
    'YResolution' => true,
    'ResolutionUnit' => true,
    'Megapixels' => true,
    'Rotation' => true,
    'Orientation' => true,

    // Editable Metadata
    'Title' => true,
    'Description' => true,
    'Label' => true,
    'Artist' => true,
    'Copyright' => true,

    // Live Photo
    'ContentIdentifier' => true,
    'MediaGroupUUID' => true,
    'EmbeddedVideoType' => true,
    'MotionPhoto' => true,

    // Other image info
    'Rating' => true,
    'NumberOfImages' => true,
    'ExposureMode' => true,
    'SceneCaptureType' => true,
    'YCbCrPositioning' => true,
    'DriveMode' => true,
    'FlashType' => true,
    'ShootingMode' => true,
    'RedEyeReduction' => true,
    'CircleOfConfusion' => true,
    'DOF' => true,
    'FOV' => true,

    // Currently unused fields
    'SensitivityType' => true,
    'RecommendedExposureIndex' => true,
    'ExifVersion' => true,
    'ExposureProgram' => true,
    'ExifByteOrder' => true,
    'Quality' => true,
    'FocusMode' => true,
    'RecordMode' => true,

    // Video info
    'Duration' => true,
    'FrameRate' => true,
    'TrackDuration' => true,
    'VideoCodec' => true,

    // File Info
    'SourceFile' => true,
    'FileName' => true,
    'FileSize' => true,
    'FileType' => true,
    'MIMEType' => true,
];
