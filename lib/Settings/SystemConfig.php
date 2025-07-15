<?php

declare(strict_types=1);

namespace OCA\Memories\Settings;

use OCA\Memories\AppInfo\Application;

class SystemConfig
{
    // Do not change the next line, it's used by the docs
    // to generate the default config page
    public const DEFAULTS = [
        // Path to exiftool binary
        'memories.exiftool' => '',

        // Do not use packaged binaries of exiftool
        // This requires perl to be available
        'memories.exiftool_no_local' => false,

        // Temporary directory for non-php binaries. The directory must be writable
        // and the webserver user should be able to create executable binaries in it.
        // go-vod temp files are separately configured (memories.vod.tempdir)
        // Defaults to system temp directory if blank
        'memories.exiftool.tmp' => '',

        // How to index user directories
        // 0 = auto-index disabled
        // 1 = index everything
        // 2 = index only user timelines
        // 3 = index only configured path
        'memories.index.mode' => '1',

        // Path to index (only used if indexing mode is 3)
        'memories.index.path' => '/',

        // Blacklist file or folder paths by regex
        'memories.index.path.blacklist' => '\/@(Recycle|eaDir)\/',

        // Places database type identifier
        'memories.gis_type' => -1,

        // Default timeline path for all users
        // If set to '_empty_', the user is prompted to select a path
        'memories.timeline.default_path' => '_empty_',

        // Default viewer high resolution image loading condition
        // Valid values: 'always' | 'zoom' | 'never'
        'memories.viewer.high_res_cond_default' => 'zoom',

        // Disable transcoding
        'memories.vod.disable' => true,

        // VA-API configuration options
        'memories.vod.vaapi' => false,  // Transcode with VA-API
        'memories.vod.vaapi.low_power' => false, // Use low_power mode for VA-API

        // NVENC configuration options
        'memories.vod.nvenc' => false,  // Transcode with NVIDIA NVENC
        'memories.vod.nvenc.temporal_aq' => false,
        'memories.vod.nvenc.scale' => 'cuda', // cuda or npp

        // Extra streaming configuration
        'memories.vod.use_transpose' => false,
        'memories.vod.use_transpose.force_sw' => false,
        'memories.vod.use_gop_size' => false,

        // Paths to ffmpeg and ffprobe binaries
        'memories.vod.ffmpeg' => '',
        'memories.vod.ffprobe' => '',

        // Path to go-vod binary
        'memories.vod.path' => '',

        // Path to use for transcoded files (/tmp/go-vod/instanceid)
        // Make sure this has plenty of space
        'memories.vod.tempdir' => '',

        // Bind address to use when starting the transcoding server
        'memories.vod.bind' => '127.0.0.1:47788',

        // Address used to connect to the transcoding server
        // If not specified, the bind address above will be used
        'memories.vod.connect' => '127.0.0.1:47788',

        // Mark go-vod as external. If true, Memories will not attempt to
        // start go-vod if it is not running already.
        'memories.vod.external' => false,

        // Quality Factor used for transcoding
        // This correspondes to CRF for x264 and global_quality for VA-API
        'memories.vod.qf' => 24,

        // Set the default video quality for a first time user
        //    0 => Auto (default)
        //   -1 => Original (max quality with transcoding)
        //   -2 => Direct (disable transcoding)
        // 1080 => 1080p (and so on)
        'memories.video_default_quality' => '0',

        // Availability of database features, e.g. triggers
        'memories.db.triggers.fcu' => false,

        // Run in read-only config mode
        'memories.readonly' => false,

        // Memories only provides an admin interface for these
        'enabledPreviewProviders' => [],
        'preview_max_x' => 4096,
        'preview_max_y' => 4096,
        'preview_max_memory' => 128,
        'preview_max_filesize_image' => 50,
        'preview_ffmpeg_path' => '',
        'default_timezone' => '',

        // Placeholders only; these are not touched by the app
        'instanceid' => 'default',
    ];

    /**
     * Get a system config key with the correct default.
     *
     * @param string $key     System config key
     * @param mixed  $default Default value
     */
    public static function get(string $key, mixed $default = null): mixed
    {
        if (!\array_key_exists($key, self::DEFAULTS)) {
            throw new \InvalidArgumentException("Invalid system config key: {$key}");
        }

        // Use the default value if not provided
        $default = $default ?? self::DEFAULTS[$key];

        // Get the value from the config
        $value = \OC::$server->get(\OCP\IConfig::class)
            ->getSystemValue($key, $default)
        ;

        // Check if the value has the correct type
        if (($got = \gettype($value)) !== ($exp = \gettype($default))) {
            throw new \InvalidArgumentException("Invalid type for system config {$key}, expected {$exp}, got {$got}");
        }

        return $value;
    }

    /**
     * Set a system config key.
     *
     * @param string $key   System config key
     * @param mixed  $value Value to set
     *
     * @throws \InvalidArgumentException
     */
    public static function set(string $key, mixed $value): void
    {
        // Check if the key is valid
        if (!\array_key_exists($key, self::DEFAULTS)) {
            throw new \InvalidArgumentException("Invalid system config key: {$key}");
        }

        // Key belongs to memories namespace
        $isAppKey = str_starts_with($key, Application::APPNAME.'.');

        // Check if the value has the correct type
        if (null !== $value && ($got = \gettype($value)) !== ($exp = \gettype(self::DEFAULTS[$key]))) {
            throw new \InvalidArgumentException("Invalid type for system config {$key}, expected {$exp}, got {$got}");
        }

        // Do not allow null for non-app keys
        if (!$isAppKey && null === $value) {
            throw new \InvalidArgumentException("Invalid value for system config {$key}, null is not allowed");
        }

        $config = \OC::$server->get(\OCP\IConfig::class);
        if ($isAppKey && ($value === self::DEFAULTS[$key] || null === $value)) {
            $config->deleteSystemValue($key);
        } else {
            $config->setSystemValue($key, $value);
        }
    }

    /**
     * Check if geolocation (places) is enabled and available.
     * Returns the type of the GIS.
     */
    public static function gisType(): int
    {
        return self::get('memories.gis_type');
    }
}
