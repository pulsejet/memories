---
description: Setting up Memories and Nextcloud
---

# Installation

This page describes how to install the latest version of Memories.

## Nextcloud

Memories is an app for [Nextcloud](https://nextcloud.com/), so you need to install Nextcloud first. You can find the installation instructions [here](https://docs.nextcloud.com/server/latest/admin_manual/installation/). For the best experience, we recommend to use the latest stable version of Nextcloud and PHP.

For easy setup and maintenance, you can use the community Nextcloud Docker image, and add extra dependencies using a custom Dockerfile. A [Docker Compose example](https://github.com/pulsejet/memories/tree/master/.examples/Docker) can be found in the repository. Make sure to read the instructions in `docker-compose.yml` carefully.

Another option is to use [Nextcloud AIO](https://github.com/nextcloud/all-in-one#how-to-use-this), in which case most dependencies are already installed.

!!! success "Recommended Configuration"

    If you plan to use hardware transcoding, using **Docker Compose** or **Nextcloud AIO** is recommended.

## Requirements

Before installing Memories, make sure that the following requirements are met:

1. Nextcloud 26 or later.
1. PHP 8.0 or later.
1. MySQL, MariaDB, or PostgreSQL (>=v15) database.
1. [Imagick](https://www.php.net/manual/en/book.imagick.php) PHP extension.
1. [ffmpeg](https://ffmpeg.org/) and [ffprobe](https://ffmpeg.org/ffprobe.html) binaries.

## Installing Memories

Memories can be installed from the Nextcloud [app store](https://apps.nextcloud.com/apps/memories) page. Alternatively, you can install it manually by following these steps:

1. Download the latest release from the [releases page](https://github.com/pulsejet/memories/releases)
1. Extract the archive to the `apps` or `custom_apps` directory of your Nextcloud installation.
1. Enable the app in the Nextcloud app settings page.
1. Make sure you follow the [configuration](./config.md) instructions carefully.

## Installing from source

To build the app from source, you need to have [node.js](https://nodejs.org/) installed.

1. Clone the repository to the `apps` or `custom_apps` directory of Nextcloud.
1. Run `make dev-setup` to install the dependencies.
1. Run `make patch-external` to apply patches to external dependencies.
1. Run `make build-js-production` to build the JavaScript files.
1. Enable the app in the Nextcloud app settings page.

## Mobile Apps

An Android client for Memories is available in early access on [Google Play](https://play.google.com/store/apps/details?id=gallery.memories), [F-Droid](https://f-droid.org/packages/gallery.memories/) and [GitHub Releases](https://github.com/pulsejet/memories/releases?q=android).

For automatic uploads, you can use the official Nextcloud mobile apps. These are available for [Android](https://play.google.com/store/apps/details?id=com.nextcloud.client) ([F-Droid](https://f-droid.org/en/packages/com.nextcloud.client/)) and [iOS](https://apps.apple.com/us/app/nextcloud/id1125420102).
