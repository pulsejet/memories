![Screenshot](appinfo/screenshot.jpg)

## Photo Viewer and Manager

Memories is a photo management app for Nextcloud with advanced features including:

* **📸 Timeline**: Sort photos and videos by date taken, parsed from Exif data.
* **⏪ Rewind**: Jump to any time in the past instantly and relive your memories.
* **🤖 AI Tagging**: Group photos by people and objects using AI, powered by the [recognize](https://github.com/nextcloud/recognize) app.
* **🖼️ Folders**: Browse your own and shared folders with a similar, efficient timeline.
* **🎦 Slideshow**: View photos from your timeline and folders easily.
* **📱 Mobile Support**: Works on devices of any shape and size through the web app.
* **✏️ Edit Metadata**: Edit Exif dates on photos quickly and easily.
* **📦 Archive**: Store photos you don't want to see in your timeline in a separate folder.
* **⚡️ Fast**: Memories is extremely fast. Period. More details below.

## How to support development
* **🌟 Star this repository**: This is the easiest way to support the project and costs nothing.
* **🪲 Report bugs**: If you find a bug, please report it on the issue tracker.
* **📝 Contribute**: If you want to contribute, please read file / comment on an issue and ask for guidance.

## 🚀 Installation

1. Install the app from the Nextcloud app store.
1. Run `php ./occ memories:index` to generate metadata indices for existing photos.
1. Open the 📷 Memories app in Nextcloud and set the directory containing your photos. Photos from this directory will be displayed in the timeline, including any photos in nested subdirectories.
1. Installing the [preview generator](https://github.com/rullzer/previewgenerator) for pre-generating thumbnails is strongly recommended.

## 🏗 Development setup

1. ☁ Clone this into your `apps` folder of your Nextcloud.
1. 👩‍💻 In a terminal, run the command `make dev-setup` to install the dependencies.
1. 🏗 Then to build the Typescript whenever you make changes, run `make build-js`. Watch changes with: `make watch-js`.
1. ✅ Enable the app through the app management of your Nextcloud.
1. 🎉 Partytime!

## 🤔 Why a separate app?
The approach of this app is fundamentally different from the official Nextcloud Photos app, which is very lightweight and works entirely using webdav. This app instead maintains special metadata in a separate table on the backend, and thus can be considered to have different objectives.

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
    - This function is experimental and may not work as expected. Please report any issues.
- The archive feature moves photos to a separate folder called `.archive` at the root of your timeline. You can use this, for example, to move these photos to a cold storage.

## Special Thanks
Nextcloud team. At least one half of the code is based on the work of the [Nextcloud Photos](https://github.com/nextcloud/photos).