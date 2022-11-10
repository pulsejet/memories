# Changelog

This file is manually updated. Please file an issue if something is missing.

## v4.7.0, v3.7.0

- **Note**: you must run `occ memories:index -f` to take advantage of new features.
- **Massively improved video performance**
  - Memories now comes with a dedicated transcoding server with HLS support.
  - Read the documentation [here](https://github.com/pulsejet/memories/wiki/Configuration#video-transcoding) carefully for more details.
- **Feature**: Show EXIF metadata in sidebar ([#68](https://github.com/pulsejet/memories/issues/68))
- **Feature**: Show duration on video tiles
- Fix stretched images in viewer ([#176](https://github.com/pulsejet/memories/issues/176))
- Editor: Restore metadata after image edit ([#174](https://github.com/pulsejet/memories/issues/174))
- Editor: Fix loss of resolution after edit

## v4.6.1, v3.6.1 (2022-11-07)

- **Feature**: Native sharing from the viewer (images only)
- **Feature**: Deep linking to photos on opening viewer
- **Feature**: Password protected folder shares ([#165](https://github.com/pulsejet/memories/issues/165))
- **Feature**: Folders view will now show only folders with photos ([#163](https://github.com/pulsejet/memories/issues/163))
- Improvements to viewer UX
- Restore image editor (see v4.6.0)

## v4.6.0, v3.6.0 (2022-11-06)

- **Brand new photo viewer** with improved touch interface and UX
- Improvements from v4.5.4 below
- Known regressions: Photo Editor and Slideshow are not implemented yet

## v4.5.4, v3.5.4 (skipped)

- New layout for Albums view (date ascending, grouped by month)
- Re-enable viewer editing and deletion

## v4.5.2, v3.5.2 (2022-10-30)

- Improved scroller performance
- Improved support for external storage and FreeBSD
- Improved selection of photos

## v4.5.0, v3.5.0 (2022-10-28)

- **Feature**: Album sharing to other Nextcloud users
- **Feature**: Folder sharing with public link [#74](https://github.com/pulsejet/memories/issues/74)
- Performance improvements and bug fixes

## v4.4.1, v3.4.1 (2022-10-27)

- **Feature**: Albums support for Nextcloud 25 (alpha)
- Performance improvements and bug fixes

## v4.3.8, v3.3.8 (2022-10-26)

- **Feature**: Full screen viewer on desktop
- **Feature**: Allow opening people and tags in new tab
- Bugfix: Fix regression in performance with large number of files
- Bugfix: Improve image quality on mobile

## v4.3.7, v3.3.7 (2022-10-24)

- **Feature**: Support for RAW (must run `occ memories:index` after upgrade) with camera raw previews app ([#107](https://github.com/pulsejet/memories/issues/107))
- **Feature**: Better settings experience.
- **Feature**: Better first start experience.
- Bug fixes for postgresql and mysql

## v4.3.0, v3.3.0 (2022-10-22)

- **Note:** you must run `occ memories:index -f` after updating to take advantage of new features.
- **Feature**: **Brand new tiled layout for photos**
- **Feature**: Photos from "On this day" are now shown at the top of the timeline
- **Feature**: Move selected photos from one person to another ([#78](https://github.com/pulsejet/memories/issues/78))
- **Feature**: Highlight faces in People view ([#79](https://github.com/pulsejet/memories/issues/79))
- **Feature**: Choose root folder for Folders view ([#85](https://github.com/pulsejet/memories/issues/85))
- **No longer need to install exiftool**. It will be bundled with the app.
- Improve overall performance with caching
- Basic offline support with cache
- Improve scroller performance
- Improve faces view performance

## v4.2.2, v3.2.2 (2022-10-12)

- Update to mobile layout with improved performance
- Show how old photos are in `On this day`

## v4.2.1, v3.2.1 (2022-10-11)

- Fix incorrect layout of `On this day`

## v4.2.0, v3.2.0 (2022-10-11)

- Allow renaming and merging recognize faces
- Bug fixes

## v4.1.0, v3.1.0 (2022-10-08)

- First release for Nextcloud 25

## v3.0.0 (2022-10-07)

- People tab with faces from recognize app
- Tags tab with objects from recognize app
- On this day tab
- Bug fixes and performance improvements

## v2.1.3 (2022-09-27)

- Bug fixes and optimized performance

## v2.1.2 (2022-09-25)

- Breadcrumb navigation in folder view
- Edit Exif date feature (use with care)
- Archive photos function
- Improved localization and performance

## v2.0.0 (2022-09-23)

- **Note:** you must re-run `occ memories:index` after updating.
- Support for external storage and shared folders for timeline.
- Localization support. Many languages already available.
- Select and favorite / unfavorite photos

## v1.1.6 (2022-09-15)

- **New feature:** Select photos from an entire day together
- **Fix:** Timeline with nested folders

## v1.1.5 (2022-09-15)

- Fix for postgres
- Fix for Exiftool crash

## v1.1.1 - v1.1.4 (2022-09-13)

- PHP 7.4 support
- Bug fixes

## v1.1.0 (2022-09-13)

- Support for external storage
- Favorites and Videos tabs
- Improved performance
- Better support for folder shares

## v1.0.1 - v1.1.0 (2022-09-08)

- Initial releases
