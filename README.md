![Screenshot](appinfo/screenshot.jpg)

# Memories: Photo Management for Nextcloud

[![Discord](https://dcbadge.vercel.app/api/server/7Dr9f9vNjJ)](https://discord.gg/7Dr9f9vNjJ)
[![Website](https://img.shields.io/website?down_color=red&down_message=offline&label=website&style=for-the-badge&up_color=brightgreen&up_message=online&url=https%3A%2F%2Fmemories.gallery)](https://memories.gallery)
[![Demo](https://img.shields.io/website?down_color=red&down_message=offline&label=demo&style=for-the-badge&up_color=brightgreen&up_message=online&url=https%3A%2F%2Fdemo.memories.gallery)](https://demo.memories.gallery/apps/memories/)
[![Nextcloud Store](https://img.shields.io/badge/nextcloud_store-blue?style=for-the-badge)](https://apps.nextcloud.com/apps/memories)

![GitHub](https://img.shields.io/github/license/pulsejet/memories)
[![e2e](https://github.com/pulsejet/memories/actions/workflows/e2e.yaml/badge.svg)](https://github.com/pulsejet/memories/actions/workflows/e2e.yaml)
[![GitHub issues](https://img.shields.io/github/issues/pulsejet/memories)](https://github.com/pulsejet/memories/issues)
[![GitHub Sponsor](https://img.shields.io/github/sponsors/pulsejet?logo=GitHub)](https://github.com/sponsors/pulsejet)

Memories is a _batteries-included_ photo management solution for Nextcloud with advanced features

## 🎁 Features

- **📸 Timeline**: Sort photos and videos by date taken, parsed from Exif data.
- **⏪ Rewind**: Jump to any time in the past instantly and relive your memories.
- **🤖 AI Tagging**: Group photos by people and objects, powered by [recognize](https://github.com/nextcloud/recognize) and [facerecognition](https://github.com/matiasdelellis/facerecognition).
- **🖼️ Albums**: Create albums to group photos and videos together. Then share these albums with others.
- **🫱🏻‍🫲🏻 External Sharing**: Share photos and videos with people outside of your Nextcloud instance.
- **📱 Mobile Support**: Work from any device, of any shape and size through the web app.
- **✏️ Edit Metadata**: Edit dates and other metadata on photos quickly and in bulk.
- **📦 Archive**: Store photos you don't want to see in your timeline in a separate folder.
- **📹 Video Transcoding**: Transcode videos and use HLS for maximal performance.
- **🗺️ Map**: View your photos on a map, tagged with accurate reverse geocoding.
- **📦 Migration**: Migrate easily from Nextcloud Photos and Google Takeout.
- **⚡️ Performance**: Do all this very fast.

## 🚀 Installation

1. Install the app from the Nextcloud [app store](https://apps.nextcloud.com/apps/memories).
1. Perform the recommended [configuration steps](https://memories.gallery/config/).
1. Run `php occ memories:index` to generate metadata indices for existing photos.
1. Open the 📷 Memories app in Nextcloud and set the directory containing your photos.

## 🏗 Development Setup

1. ☁ Clone this into your `custom_apps` folder of your Nextcloud.
1. 📥 Install [Composer](https://getcomposer.org/) and [Node.js 18](https://nodejs.org)
1. 👩‍💻 In a terminal, run the command `make dev-setup` to install the dependencies.
1. 🏗 To build/watch the UI, run `make watch-js`. Lint-fix PHP with `make php-lint`.
1. ✅ Enable the app through the app management of your Nextcloud.
1. ⚒️ (Strongly recommended) use VS Code and install Vetur and Prettier.

## 🤝 Support the project

1. **🌟 Star this repository**: This is the easiest way to support Memories and costs nothing.
1. **🪲 Report bugs**: Report any bugs you find on the issue tracker.
1. **📝 Contribute**: Read and file or comment on an issue and ask for guidance.
1. **🪙 Sponsorship**: You can support the project financially at [GitHub Sponsors](https://github.com/sponsors/pulsejet).

A shout out to the current and past financial backers of Memories! See the sponsors page for a full list.

[<img src="https://github.com/mpodshivalin.png" width="42" />](https://github.com/mpodshivalin)
[<img src="https://github.com/k1l1.png" width="42" />](https://github.com/k1l1)
[<img src="https://github.com/ChickenTarm.png" width="42" />](https://github.com/ChickenTarm)
[<img src="https://github.com/ChildLearningClub.png" width="42" />](https://github.com/ChildLearningClub)
[<img src="https://github.com/mpanhans.png" width="42" />](https://github.com/mpanhans)

## 📝 Changelog

For the full changelog, see [CHANGELOG.md](CHANGELOG.md).

## 🙏 Special Thanks

Nextcloud team. A lot of this work is based on [Photos](https://github.com/nextcloud/photos).
