# Changelog

This file is manually updated. Please file an issue if something is missing.

## v4.11.0, v3.11.0 (unreleased)

- **Feature**: Show map of photos ([#396](https://github.com/pulsejet/memories/pull/396))
- **Feature**: Show list of places using reverse geocoding (MySQL/Postgres only) ([#395](https://github.com/pulsejet/memories/issues/395))  
  To configure this feature, you need to run `occ memories:places-setup` followed by `occ memories:index -f`
- Other minor fixes and features ([milestone](https://github.com/pulsejet/memories/milestone/7?closed=1))

## v4.10.0, v3.10.0 (2023-01-17)

- **Feature**: Allow sharing albums using public links ([#274](https://github.com/pulsejet/memories/issues/274))
- **Feature**: Allow sharing albums with groups ([#329](https://github.com/pulsejet/memories/issues/329))
- **Feature**: Directly move photos from the timeline to any folder ([#321](https://github.com/pulsejet/memories/pull/321))
- **Feature**: Optionally view folders in the recursive timeline view ([#260](https://github.com/pulsejet/memories/pull/260))
- Fix folder share title and remove footer ([#323](https://github.com/pulsejet/memories/issues/323))
- Other minor fixes ([milestone](https://github.com/pulsejet/memories/milestone/6?closed=1))

## v4.9.0, v3.9.0 (2022-12-08)

- **Important**: v4.9.0 comes with an optimization that greatly reduces CPU usage for preview serving. However, for best experience, the preview generator app is now **required** to be configured properly. Please install it from the app store.
- **Feature**: Slideshow for photos and videos ([#217](https://github.com/pulsejet/memories/issues/217))
- **Feature**: Support for GPU transcoding ([#194](https://github.com/pulsejet/memories/issues/194))
- **Feature**: Allow downloading entire albums
- **Feature**: Allow editing more EXIF fields ([#169](https://github.com/pulsejet/memories/issues/169))
- **Feature**: Alpha integration with the face recognition app ([#146](https://github.com/pulsejet/memories/issues/146))
- Fix downloading from albums ([#259](https://github.com/pulsejet/memories/issues/259))
- Fix support for HEVC live photos ([#234](https://github.com/pulsejet/memories/issues/234))
- Fix native photo sharing ([#254](https://github.com/pulsejet/memories/issues/254), [#263](https://github.com/pulsejet/memories/issues/263))
- Use larger previews in viewer (please see [these docs](https://github.com/pulsejet/memories/wiki/Configuration#preview-storage-considerations)) ([#226](https://github.com/pulsejet/memories/issues/226))

## v4.8.0, v3.8.0 (2022-11-22)

- **Feature**: Support for Live Photos ([#124](https://github.com/pulsejet/memories/issues/124))
  - You need to run `occ memories:index --clear` to reindex live photos
  - Only JPEG (iOS with MOV, Google, Samsung) is supported. HEIC is not supported.
- **Feature**: Timeline path now scans recursively for mounted volumes / shares inside it
- **Feature**: Multiple timeline paths can be specified ([#178](https://github.com/pulsejet/memories/issues/178))
- Support for server-side encrypted storage ([#99](https://github.com/pulsejet/memories/issues/99))
- Mouse wheel now zooms on desktop
- Improved caching performance
  - Due to incorrect caching in previous versions, your browser cache may have become very large. You can clear it to save some space.

## v4.7.0, v3.7.0 (2022-11-14)

- **Note**: you must run `occ memories:index -f` to take advantage of new features.
- **Massively improved video performance**
  - Memories now comes with a dedicated transcoding server with HLS support.
  - Read the documentation [here](https://github.com/pulsejet/memories/wiki/Configuration#video-transcoding) carefully for more details.
- **Feature**: Show EXIF metadata in sidebar ([#68](https://github.com/pulsejet/memories/issues/68))
- **Feature**: Multi-selection with drag (mobile) and shift+click ([#28](https://github.com/pulsejet/memories/issues/28))
- **Feature**: Show duration on video tiles
- **Feature**: Allow editing all image formats (HEIC etc.)
- Fix stretched images in viewer ([#176](https://github.com/pulsejet/memories/issues/176))
- Restore metadata after image edit ([#174](https://github.com/pulsejet/memories/issues/174))
- Fix loss of resolution after image edit

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
