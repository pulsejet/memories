![Screenshot](appinfo/screenshot.jpg)

## Photo Viewer and Manager

Memories is a photo management app for Nextcloud with advanced features including:

- **📸 Timeline**: Sort photos and videos by date taken, parsed from Exif data.
- **⏪ Rewind**: Jump to any time in the past instantly and relive your memories.
- **🤖 AI Tagging**: Group photos by people and objects using AI, powered by the [recognize](https://github.com/nextcloud/recognize) app.
- **🖼️ Albums**: Create albums to group photos and videos together. Then share these albums with others.
- **📁 Folders**: Browse your own and shared folders with a similar, efficient timeline.
- **🎦 Slideshow**: View photos from your timeline and folders easily.
- **📱 Mobile Support**: Works on devices of any shape and size through the web app.
- **✏️ Edit Metadata**: Edit Exif dates on photos quickly and easily.
- **📦 Archive**: Store photos you don't want to see in your timeline in a separate folder.
- **📷 RAW Support**: View RAW photos from your camera with the [Camera RAW Previews](https://apps.nextcloud.com/apps/camerarawpreviews) app.
- **⚡️ Fast**: Memories is extremely fast. Period. More details below.

To get an idea of what memories looks and feels like, check out the [public demo](https://memories-demo.radialapps.com/apps/memories/). Note that the demo is read-only and may be slow since it runs in a low-end free tier VM provided by [Oracle Cloud](https://www.oracle.com/cloud/free/). Photo credits go to [Unsplash](https://unsplash.com/) (for individual credits, refer to each folder).

## How to support development

- **🌟 Star this repository**: This is the easiest way to support the project and costs nothing.
- **🪲 Report bugs**: If you find a bug, please report it on the issue tracker.
- **📝 Contribute**: If you want to contribute, please read file / comment on an issue and ask for guidance.

## 🚀 Installation

1. Install the app from the Nextcloud app store.
1. Run `php ./occ memories:index` to generate metadata indices for existing photos.
1. Open the 📷 Memories app in Nextcloud and set the directory containing your photos. Photos from this directory will be displayed in the timeline, including any photos in nested subdirectories.
1. Installing the [preview generator](https://github.com/rullzer/previewgenerator) for pre-generating thumbnails is strongly recommended.

## 🏗 Development setup

1. ☁ Clone this into your `apps` folder of your Nextcloud.
1. 👩‍💻 In a terminal, run the command `make dev-setup` to install the dependencies.
1. 🏗 To build the Typescript, run `make build-js`. Watch changes with: `make watch-js`.
1. ✅ Enable the app through the app management of your Nextcloud.
1. ⚒️ (Strongly recommended) use VS Code and install Vetur and Prettier.

## ⚡ Performance

- Once properly configured, Memories is **extremely fast**, possibly one of the fastest web photo viewers.
- On a server with relatively cheap hardware (`Intel Pentium G6400 / 8GB RAM / SSD`), loading the timeline takes only `~400ms` without cache on a laptop (`Intel Core i5-1035G1 / Windows 11 / Chrome`) for a library of `~17000 photos` totaling `100GB`. The test was performed on Nextcloud 24 with `nginx`, `php-fpm` and `mariadb` running in Docker.
- For best performance, install the [preview generator](https://github.com/rullzer/previewgenerator) and make sure HTTP/2 is enabled for your Nextcloud instance.

## 📝 Notes

- You may need to configure the Nextcloud preview generator and Imagemagick / ffmpeg to support all types of images and videos (e.g. HEIC). If using the official docker image, add `OC\Preview\HEIC` to `enabledPreviewProviders` in your `config.php`.
- If local time is not found in the photo (especially for videos), the server timezone is used.
- All photos in the timeline _must_ be on a single storage. For example, you cannot have a mounted directory inside your photos directory.
- The app can work with external storage for photos. Just set the mountpoint as the timeline directory.
  - If you add any photos from outside Nextcloud, you must run the scan and index commands.
  - Indexing may be slow, since all files must be downloaded from the storage. The app currently assumes that the Exif data is present with the first 20MB of each file.
- The archive feature moves photos to a separate folder called `.archive` at the root of your timeline. You can use this, for example, to move these photos to a cold storage.

## Special Thanks

Nextcloud team. A lot of this work is based on [Photos](https://github.com/nextcloud/photos).
