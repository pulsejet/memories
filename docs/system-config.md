---
description: Documentation for config.php options that Memories uses
---

# config.php options

This is a list of all options in `config.php` that memories uses

## General

```php
<?php
// Path to exiftool binary
'memories.exiftool' => '/var/www/html/apps/memories/exiftool-bin/exiftool-amd64-glibc',

// Do not use packaged binaries of exiftool
// This requires perl to be available
'memories.exiftool_no_local' => false,

// Makes the memories instance readonly
'memories.readonly' => false,

// Type of reverse geocoding planet database
// -1 => Unconfigured
//  0 => Disable
//  1 => MySQL / MariaDB
//  2 => PostgreSQL
'memories.gis_type' => -1,
```

## Transcoding

```php
<?php
// Disable transcoding
'memories.vod.disable' => false,

// Hardware support for transcoding
'memories.vod.vaapi' => false,  // Transcode with VA-API
'memories.vod.vaapi.low_power' => false, // Use low_power mode for VA-API

'memories.vod.nvenc' => false,  // Transcode with NVIDIA NVENC
'memories.vod.nvenc.temporal_aq' => false,
'memories.vod.nvenc.scale' => 'npp', // npp or cuda

// Paths to ffmpeg and ffprobe binaries
'memories.vod.ffmpeg' => '/usr/bin/ffmpeg',
'memories.vod.ffprobe' => '/usr/bin/ffprobe',

// Path to go-vod binary
'memories.vod.path' => '/var/www/html/apps/memories/exiftool-bin/go-vod-amd64',

// Path to use for transcoded files (/tmp/go-vod/instanceid)
// Make sure this has plenty of space
'memories.vod.tempdir' => '/tmp/my-dir',

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
```
