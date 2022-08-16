# Polaroid

**📸 Yet another photo management app for Nextcloud**

## How is this different?

* **📸 Photo and Video Timeline**: Sorts photos by date taken.
* **🤔 Quick Recap**: Jump to anywhere in the timeline instantly.

## 🚀 Installation

1. ☁ Clone this into your `apps` folder of your Nextcloud.
1. Run `php ./occ polaroid:index` to generate metadata indices for existing photos.
1. Consider installing the [preview generator](https://github.com/rullzer/previewgenerator) for pre-generating thumbnails.

## 🏗 Development setup

1. ☁ Clone this into your `apps` folder of your Nextcloud.
1. 👩‍💻 In a terminal, run the command `make dev-setup` to install the dependencies.
1. 🏗 Then to build the Javascript whenever you make changes, run `make build-js`. To create a pull request use `make build-js-production`. Watch changes with: `make watch-js`.
1. ✅ Enable the app through the app management of your Nextcloud.
1. 🎉 Partytime!

## Why a separate app?
The approach of this app is fundamentally different from the official Nextcloud Photos app, which is very lightweight and works entirely using webdav. This app instead maintains special metadata in a separate table on the backend, and thus can be considered to have different objectives.

## Special Thanks 🙏🏻
Nextcloud team. At least one half of the code is based on the work of the [Nextcloud Photos](https://github.com/nextcloud/photos).