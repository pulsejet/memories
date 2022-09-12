# Memories

**📷 Yet another photo management app for Nextcloud**

![Screenshot](appinfo/screencap.webp)

## How is this different?

* **📸 Photo and Video Timeline**: Sorts photos by date taken, parsed from Exif data.
* **🤔 Quick Recap**: Jump to anywhere in the timeline instantly.
* **🖼️ Folders**: Browse your and shared folders with a similar, efficient timeline.
* **🎦 Slideshow**: View photos from your timeline and folders easily.
* **📱 Mobile Support**: Relive your memories on devices of any shape and size through the web app.
* **🗑️ Recycle**: Select and delete multiple photos and videos at once.
* **⚡️ Fast**: Memories is extremely fast. Period.

## 🚀 Installation

1. Install the app from the Nextcloud app store
1. ⚒️ Install `exiftool` (`sudo apt install exiftool`).
1. Run `php ./occ memories:index` to generate metadata indices for existing photos.
1. Open the 📷 Memories app in Nextcloud and set the directory containing your photos. Photos from this directory will be displayed in the timeline, including any photos in nested subdirectories.
1. Installing the [preview generator](https://github.com/rullzer/previewgenerator) for pre-generating thumbnails is strongly recommended.

## 🏗 Development setup

1. ☁ Clone this into your `apps` folder of your Nextcloud.
1. 👩‍💻 In a terminal, run the command `make dev-setup` to install the dependencies.
1. 🏗 Then to build the Javascript whenever you make changes, run `make build-js`. To create a pull request use `make build-js-production`. Watch changes with: `make watch-js`.
1. ✅ Enable the app through the app management of your Nextcloud.
1. 🎉 Partytime!

## Why a separate app?
The approach of this app is fundamentally different from the official Nextcloud Photos app, which is very lightweight and works entirely using webdav. This app instead maintains special metadata in a separate table on the backend, and thus can be considered to have different objectives.

## Notes
1. The app has been tested with 100GB worth of ~25k photos.
1. You may need to configure the Nextcloud preview generator and Imagemagick / ffmpeg to support all types of images and videos (e.g. HEIC). If using the official docker image, add `OC\Preview\HEIC` to `enabledPreviewProviders` in your `config.php`.
1. If local time is not found in the photo (especially for videos), the server timezone is used.

## Special Thanks 🙏🏻
Nextcloud team. At least one half of the code is based on the work of the [Nextcloud Photos](https://github.com/nextcloud/photos).