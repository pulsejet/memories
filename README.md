![Screenshot](appinfo/screenshot.jpg)

# Memories: Photo Management for Nextcloud

![GitHub](https://img.shields.io/github/license/pulsejet/memories)
[![e2e](https://github.com/pulsejet/memories/actions/workflows/e2e.yaml/badge.svg)](https://github.com/pulsejet/memories/actions/workflows/e2e.yaml)
[![Demo](https://img.shields.io/badge/demo-online-blue)](https://memories-demo.radialapps.com/apps/memories/)
[![Gitter](https://img.shields.io/gitter/room/pulsejet/memories)](https://gitter.im/pulsejet/memories)
[![GitHub issues](https://img.shields.io/github/issues/pulsejet/memories)](https://github.com/pulsejet/memories/issues)

Memories is a photo management app for Nextcloud with advanced features including:

- **📸 Timeline**: Sort photos and videos by date taken, parsed from Exif data.
- **⏪ Rewind**: Jump to any time in the past instantly and relive your memories.
- **🤖 AI Tagging**: Group photos by people and objects using AI, powered by [recognize](https://github.com/nextcloud/recognize).
- **🖼️ Albums**: Create albums to group photos and videos together. Then share these albums with others.
- **🫱🏻‍🫲🏻 External Sharing**: Share photos and videos with people outside of your Nextcloud instance.
- **📱 Mobile Support**: Works on devices of any shape and size through the web app.
- **✏️ Edit Metadata**: Edit dates on photos quickly and easily.
- **📦 Archive**: Store photos you don't want to see in your timeline in a separate folder.
- **⚡️ Performance**: Memories is extremely fast.

## 🌐 Online Demo

- To get an idea of what memories looks and feels like, check out the [public demo](https://memories-demo.radialapps.com/apps/memories/).
- The demo is read-only and may be slow (free tier VM from [Oracle Cloud](https://www.oracle.com/cloud/free/)).
- Photo credits go to [Unsplash](https://unsplash.com/) (for individual credits, refer to each folder).

## 🚀 Installation

1. Install the app from the Nextcloud app store.
1. Perform the recommended [configuration steps](https://github.com/pulsejet/memories/wiki/Configuration).
1. Run `php ./occ memories:index` to generate metadata indices for existing photos.
1. Open the 📷 Memories app in Nextcloud and set the directory containing your photos.

## 🏗 Development Setup

1. ☁ Clone this into your `apps` folder of your Nextcloud.
1. 👩‍💻 In a terminal, run the command `make dev-setup` to install the dependencies.
1. 🏗 To build the Typescript, run `make build-js`. Watch changes with: `make watch-js`.
1. ✅ Enable the app through the app management of your Nextcloud.
1. ⚒️ (Strongly recommended) use VS Code and install Vetur and Prettier.

## How to support development

1. **🌟 Star this repository**: This is the easiest way to support the project and costs nothing.
1. **🪲 Report bugs**: If you find a bug, please report it on the issue tracker.
1. **📝 Contribute**: If you want to contribute, please read file / comment on an issue and ask for guidance.

## Special Thanks

Nextcloud team. A lot of this work is based on [Photos](https://github.com/nextcloud/photos).
