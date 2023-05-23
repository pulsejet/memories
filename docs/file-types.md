---
description: Steps to configure support for different image and video formats
---

# File type support

Memories supports the file types supported by the Nextcloud previews app. If you add support for any one of the file types below, you must run `occ memories:index` to index these files.

## Common Formats

```
PNG (image/png)
JPEG (image/jpeg)
GIF (image/gif)
BMP (image/bmp)
```

These are enabled by having the following in your `config.php`,

```php
'enabledPreviewProviders' =>
array (
  'OC\\Preview\\Image',
),
```

## HEIC and TIFF

You must enable `HEIC` and `TIFF` in Nextcloud `config.php`, and install Imagemagick (included in the official Nextcloud docker image)

In `config.php`, add,

```php
'enabledPreviewProviders' =>
array (
  'OC\\Preview\\HEIC',
  'OC\\Preview\\TIFF',
),
```

## Videos

You need to install `ffmpeg` and add the video config to `config.php`

```php
'enabledPreviewProviders' =>
array (
  'OC\\Preview\\Movie',
),
```

## RAW images

Install the [camera raw previews](https://github.com/ariselseng/camerarawpreviews) app from the Nextcloud app store.
