![Screenshot](appinfo/screenshot.jpg)

# Memories: Photo Management for Nextcloud

![GitHub](https://img.shields.io/github/license/pulsejet/memories)
[![e2e](https://github.com/pulsejet/memories/actions/workflows/e2e.yaml/badge.svg)](https://github.com/pulsejet/memories/actions/workflows/e2e.yaml)
[![Demo](https://img.shields.io/badge/demo-online-blue)](https://memories-demo.radialapps.com/apps/memories/)
[![Gitter](https://img.shields.io/gitter/room/pulsejet/memories)](https://gitter.im/pulsejet/memories)
[![GitHub issues](https://img.shields.io/github/issues/pulsejet/memories)](https://github.com/pulsejet/memories/issues)
[![GitHub Sponsor](https://img.shields.io/github/sponsors/pulsejet?logo=GitHub)](https://github.com/sponsors/pulsejet)

Memories is a _batteries-included_ photo management solution for Nextcloud with advanced features including:

- **📸 Timeline**: Sort photos and videos by date taken, parsed from Exif data.
- **⏪ Rewind**: Jump to any time in the past instantly and relive your memories.
- **🤖 AI Tagging**: Group photos by people and objects, powered by [recognize](https://github.com/nextcloud/recognize) and [facerecognition](https://github.com/matiasdelellis/facerecognition).
- **🖼️ Albums**: Create albums to group photos and videos together. Then share these albums with others.
- **🫱🏻‍🫲🏻 External Sharing**: Share photos and videos with people outside of your Nextcloud instance.
- **📱 Mobile Support**: Works on devices of any shape and size through the web app.
- **✏️ Edit Metadata**: Edit dates and other metadata on photos quickly and easily.
- **📦 Archive**: Store photos you don't want to see in your timeline in a separate folder.
- **📹 Video Transcoding**: Memories transcodes videos and uses HLS for maximal performance.
- **🗺️ Map**: View your photos on a map, tagged with accurate reverse geocoding.
- **⚡️ Performance**: Memories is very fast.

## 🚀 Installation

1. Install the app from the Nextcloud app store.
1. Perform the recommended [configuration steps](https://github.com/pulsejet/memories/wiki/Configuration).
1. Run `php occ memories:index` to generate metadata indices for existing photos.
1. Open the 📷 Memories app in Nextcloud and set the directory containing your photos.

## 🏗 Development Setup

1. ☁ Clone this into your `custom_apps` folder of your Nextcloud.
1. 👩‍💻 In a terminal, run the command `make dev-setup` to install the dependencies.
1. 🏗 To build/watch the UI, run `make watch-js`. Lint-fix PHP with `make php-lint`.
1. ✅ Enable the app through the app management of your Nextcloud.
1. ⚒️ (Strongly recommended) use VS Code and install Vetur and Prettier.

## Support the project

1. **🌟 Star this repository**: This is the easiest way to support Memories and costs nothing.
1. **🪲 Report bugs**: Report any bugs you find on the issue tracker.
1. **📝 Contribute**: Read and file or comment on an issue and ask for guidance.
1. **🪙 Sponsorship**: You can support the project financially at [GitHub Sponsors](https://github.com/sponsors/pulsejet).

A shout out to the current and past financial backers of Memories! See the sponsors page for a full list.

[<img src="https://github.com/mpodshivalin.png" width="42" />](https://github.com/mpodshivalin)
[<img src="https://github.com/k1l1.png" width="42" />](https://github.com/k1l1)

## Changelog

For the full changelog, see [CHANGELOG.md](CHANGELOG.md).

## Special Thanks

Nextcloud team. A lot of this work is based on [Photos](https://github.com/nextcloud/photos).
