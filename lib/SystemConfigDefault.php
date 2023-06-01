<?php

return [
    // Path to exiftool binary
    'memories.exiftool' => '',

    // Do not use packaged binaries of exiftool
    // This requires perl to be available
    'memories.exiftool_no_local' => false,

    // How to index user directories
    // 0 = auto-index disabled
    // 1 = index everything
    // 2 = index only user timelines
    // 3 = index only configured path
    'memories.index.mode' => '1',

    // Path to index (only used if indexing mode is 3)
    'memories.index.path' => '/',

    // Places database type identifier
    'memories.gis_type' => -1,

    // Disable transcoding
    'memories.vod.disable' => true,

    // VA-API configuration options
    'memories.vod.vaapi' => false,  // Transcode with VA-API
    'memories.vod.vaapi.low_power' => false, // Use low_power mode for VA-API

    // NVENC configuration options
    'memories.vod.nvenc' => false,  // Transcode with NVIDIA NVENC
    'memories.vod.nvenc.temporal_aq' => false,
    'memories.vod.nvenc.scale' => 'npp', // npp or cuda

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

    // Set the default video quality for a first time user
    //    0 => Auto (default)
    //   -1 => Original (max quality with transcoding)
    //   -2 => Direct (disable transcoding)
    // 1080 => 1080p (and so on)
    'memories.video_default_quality' => '0',

    // Memories only provides an admin interface for these
    // https://docs.nextcloud.com/server/latest/admin_manual/configuration_server/config_sample_php_parameters.html#previews
    'enabledPreviewProviders' => [],
    'preview_max_x' => 4096,
    'preview_max_y' => 4096,
    'preview_max_memory' => 128,
    'preview_max_filesize_image' => 50,

    'memories.global_full_res_on_zoom' => true,
    'memories.global_full_res_always' => false,
];
