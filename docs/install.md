---
description: Setting up Memories and Nextcloud
---

# Installation

This page describes how to install the latest version of Memories.

## Nextcloud

Memories is an app for [Nextcloud](https://nextcloud.com/), so you need to install Nextcloud first. You can find the installation instructions [here](https://docs.nextcloud.com/server/latest/admin_manual/installation/).

For the best experience, we recommend to use the latest stable version of Nextcloud and PHP.
For easy setup and maintenance, you can use the official Nextcloud Docker image, and add extra dependencies
using a custom Dockerfile.

## Requirements

Before installing Memories, make sure that the following requirements are met:

1. Nextcloud 25 or later.
1. PHP 8.0 or later.
1. MySQL, MariaDB, or PostgreSQL database.
1. [Imagick](https://www.php.net/manual/en/book.imagick.php) PHP extension.
1. [ffmpeg](https://ffmpeg.org/) and [ffprobe](https://ffmpeg.org/ffprobe.html) binaries.

## Installing Memories

Memories can be installed from the Nextcloud app store page. Alternatively, you can install it manually by following these steps:

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
