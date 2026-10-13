---
description: Steps to configure support for different image and video formats
---

# File Type Support

!!! danger "Use the admin interface"

    It is **strongly recommended** that you use the Memories admin interface to configure file type support. This will ensure that your configuration is valid and that you do not accidentally disable support for any file types.

Please note that if Imaginary is configured on your instance like on Nextcloud AIO, you do not need to follow this documentation any further; most file types should work out-of-the-box.

Memories supports the file types supported by Nextcloud. File type support is determined in part by the values listed in the `enabledPreviewProviders` configuration parameter in your configuration file. If your `config.php` does not contain an `enabledPreviewProviders` array, this means you are using Nextcloud's defaults. Copy the array over from `config.sample.php` before adding any of the values below, or else you will effectively disable all of the defaults.

If you add support for any one of the file types below, you must run `occ memories:index` to index these files.

## Vips (built-in)

*Requires Memories v10.1 or later.*

!!! warning "No configuration needed"

    Memories ships statically-linked `vips` binaries and registers preview providers for common image formats out-of-the-box. In most cases you do **not** need to configure anything below for image formats.

The following formats are covered with no extra dependencies:

```
JPEG (image/jpeg)
PNG (image/png)
GIF (image/gif)
WebP (image/webp)
AVIF (image/avif)
HEIC (image/heic)
HEIF (image/heif)
TIFF (image/tiff)
```

These providers take priority over the Nextcloud core providers: if vips successfully generates a preview, it is used; otherwise generation falls through to the next matching provider.

## Common Formats

```
PNG (image/png)
JPEG (image/jpeg)
GIF (image/gif)
BMP (image/bmp)
```

These are enabled by inclusion of the following values in `config.php`'s `enabledPreviewProviders` array:

```php
  'OC\Preview\Image',
```

## HEIC and TIFF

These are enabled by inclusion of the following values in `config.php`'s `enabledPreviewProviders` array:

```php
  'OC\Preview\HEIC',
  'OC\Preview\TIFF',
```

You must also install Imagemagick (included in the official Nextcloud docker image).

## Videos

These are enabled by inclusion of the following value in `config.php`'s `enabledPreviewProviders` array:

```php
  'OC\Preview\Movie',
```

You must also install `ffmpeg` and add the video config to `config.php`.

## RAW images

Install the [camera raw previews](https://github.com/ariselseng/camerarawpreviews) app from the Nextcloud app store.
