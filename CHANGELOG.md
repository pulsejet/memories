# Changelog

All notable changes to this project will be documented in this file.

## [v7.3.1] - 2024-05-03

- **Security**: Enable code signing and integrity checking
- **Fix**: Always exclude `.trashed-` files from index
- **Fix**: Auto-correct some bad preview settings in `config.php` ([#1168](https://github.com/pulsejet/memories/pull/1168))

## [v7.3.0] - 2024-04-30

- **Feature**: Allow excluding files and folders from indexing with regex pattern ([#1015](https://github.com/pulsejet/memories/pull/1015))
- **Feature**: Allow forcing software-based transpose for transcoding ([#794](https://github.com/pulsejet/memories/issues/794))
- **Note**: Using Imaginary is now discouraged for now due to multiple bugs. The admin panel will show a warning if Imaginary is enabled.
- Various bug fixes.

## [v7.2.0] - 2024-04-05

- **Important**: This release has multiple hotfixes. If you are running v7.1.0, please upgrade as soon as possible.
- **Feature**: Add option to de-duplicate identical files ([#1112](https://github.com/pulsejet/memories/issues/1112)).
- **Feature**: Show current date at top of timeline ([#1116](https://github.com/pulsejet/memories/issues/1116))

## [v7.1.0] - 2024-04-01

- **Notice**: This release significantly overhauls the core querying infrastructure for better performance. If you run into any regressions (including performance), please [file a bug report](https://github.com/pulsejet/memories/issues)
- **Breaking**: If you are running MySQL / MariaDB, you may need some extra configuration for setting up database triggers. Check the admin panel for any errors in the performance section and refer to the documentation [here](https://memories.gallery/troubleshooting/#trigger-compatibility-mode).
- **Feature**: Basic search functionality.
- **Feature**: Allow sorting albums by most recently updated.
- **Feature**: Allow sorting albums in ascending and descending direction.
- **Feature**: Manual upload to folder is now available in the Android app.
- **Documentation**: A Docker Compose example using the community Nextcloud image is now available in the repo.
- **Fix**: Prevent turning off screen when playing videos.
- Support for Nextcloud 29

## [v7.0.2] - 2024-03-19

- **Breaking**: You must run `occ memories:places-setup` again after this update
    - Reverse geocoding lookups with Postgres/MySQL (not MariaDB) are now 100x faster
- **Breaking**: Prevent automatically retrying files that failed to index ([#917](https://github.com/pulsejet/memories/issues/917))
    - The list of files that could not be indexed can be found in the admin panel
    - To retry indexing, you can run `occ memories:index --retry`
- **Feature**: Allow changing cover photos of albums, tags, places and people ([#1071](https://github.com/pulsejet/memories/issues/1071), [#125](https://github.com/pulsejet/memories/issues/125), [#557](https://github.com/pulsejet/memories/issues/557), [#764](https://github.com/pulsejet/memories/issues/764), [#1032](https://github.com/pulsejet/memories/issues/1032))
- **Feature**: Allow direct file upload from web interface ([#69](https://github.com/pulsejet/memories/issues/69))
- **Feature**: Hide files starting with `.` in the timeline
- **Feature**: Support for 3GP videos ([#1055](https://github.com/pulsejet/memories/issues/1055))
- **Feature**: Option to show metadata in slideshow ([#819](https://github.com/pulsejet/memories/issues/819))
- **Feature**: Improve UX of image editor especially on mobile
- **Fix**: The cover photo of clusters will now update automatically when files are moved ([#1071](https://github.com/pulsejet/memories/issues/1071), [#458](https://github.com/pulsejet/memories/issues/458), [#661](https://github.com/pulsejet/memories/issues/661), [#1025](https://github.com/pulsejet/memories/issues/1025), [#761](https://github.com/pulsejet/memories/issues/761), [#665](https://github.com/pulsejet/memories/issues/665))
- **Fix**: Query performance improvements for MySQL and MariaDB with very large folders
- **Fix**: Allow face creation on Android ([#934](https://github.com/pulsejet/memories/issues/934))
- Android app is now available on [F-Droid](https://f-droid.org/packages/gallery.memories/) ([#852](https://github.com/pulsejet/memories/issues/852))

## [v6.2.2] - 2024-01-10

- Hotfix for a bug in request pipelining.

## [v6.2.0] - 2024-01-09

- Nextcloud 28 compatibility
- Various bug fixes

## [v6.1.5] - 2023-11-25

- Hotfix in service worker caching strategy.

## [v6.1.1] - 2023-11-24

- This is an off-cycle hotfix release for some bugs in v6.1.0 ([see](https://github.com/pulsejet/memories/milestone/19?closed=1)).
- **Breaking**: The CUDA scaler is now the default for NVENC. You may need to reconfigure your transcoder. (see [#582](https://github.com/pulsejet/memories/issues/582))
- This release also cuts down a lot of weirdness and improves the usage of dependencies significantly.

## [v6.1.0] - 2023-11-15

- **Feature**: RAW files are now hidden (stacked) when another file with the same basename exists ([#537](https://github.com/pulsejet/memories/issues/537), [#152](https://github.com/pulsejet/memories/issues/152), [#419](https://github.com/pulsejet/memories/issues/419))
- **Feature**: Multiple files can be now selected and shared from the timeline ([#472](https://github.com/pulsejet/memories/issues/472), [#901](https://github.com/pulsejet/memories/issues/901))
- **Feature**: Bulk rotating of images. You can now rotate images losslessly by editing the rotation EXIF metadata. ([#856](https://github.com/pulsejet/memories/issues/856))
- **Feature**: Icon animation when playing live photos ([#898](https://github.com/pulsejet/memories/issues/898))
- **Feature**: Swipe to refresh on timeline ([#547](https://github.com/pulsejet/memories/issues/547))
- **Bugfix**: Allow switching video to direct on Safari ([#650](https://github.com/pulsejet/memories/issues/650))
- Many other [bug fixes](https://github.com/pulsejet/memories/milestone/18?closed=1)
- Android app is now open source ([see](https://github.com/pulsejet/memories/tree/master/android))

## [v6.0.1] - 2023-10-27

- Bug fixes in video streaming.

## [v6.0.0] - 2023-10-25

- This release focuses on improvements in code quality, maintainability and [documentation](https://memories.gallery/install/).
    - New CI/CD [jobs](https://github.com/pulsejet/memories/actions/workflows/static-analysis.yaml) for type checking with [Psalm](https://psalm.dev/) and [vue-tsc](https://www.npmjs.com/package/vue-tsc)
    - Vue templates are now checked and largely type-safe
    - The backend now has native type coverage with PHP 8 type hints. This unearthed multiple bugs that are now fixed.
    - [Developing](https://github.com/pulsejet/memories/#-development-setup) is now easier for new contributors
- **Breaking**: Nextcloud 26+ and PHP 8.0 are now required.
- **Breaking**: The directory containing the `exiftool` and `go-vod` binaries was renamed from `exiftool-bin` to `bin-ext`
- **Feature**: External transcoders are much easier to set up now. See [docs](https://memories.gallery/hw-transcoding) for details.
- **Feature**: Folders view in shares ([#880](https://github.com/pulsejet/memories/pull/880))
- **Feature**: Improved back button navigation on mobile ([#861](https://github.com/pulsejet/memories/issues/861)).
- **Feature**: The transcoding quality factor can now be configured from the admin panel.

## [v5.5.0] - 2023-10-06

- **Important**: This update runs some slow database migrations. It is recommended to upgrade using the CLI (`occ upgrade`) instead of the web interface.
- **Important**: This version corrects some errors in indexing and indexes some new EXIF fields. It is recommended to run `occ memories:index -f` after upgrading.
- **Breaking**: Files in hidden folders are now hidden in the timeline ([#825](https://github.com/pulsejet/memories/issues/825))
- **Feature**: An Android app is now available with early access (https://play.google.com/store/apps/details?id=gallery.memories). Memories v5.5+ is required.
- **Feature**: Support showing full file path in sidebar ([#173](https://github.com/pulsejet/memories/issues/173))
- **Feature**: View file in folder on clicking name in sidebar
- **Feature**: User can leave albums that are shared with them
- **Feature**: Admin can now configure default behavior of loading high resolution image in viewer ([#672](https://github.com/pulsejet/memories/pull/672))
- **Feature**: Shared videos will now be transcoded to be smaller in size
- **Feature**: Confirmation box on deletion ([#798](https://github.com/pulsejet/memories/issues/798))
- **Feature**: Prompt on editing metadata if date will be lost
- **Feature**: Allow changing binary temp directory ([#821](https://github.com/pulsejet/memories/issues/821))
- **Feature**: Support for Samsung HEIC Motion Photos on newer devices
- **Fix**: Support for transcoding MKV files.

## [v5.4.1] - 2023-08-20

- Corrects a versioning error. This version is the same as v5.3.0

## [v5.3.0] - 2023-08-20

- **Feature**: Allow adding photos to multiple albums together ([#752](https://github.com/pulsejet/memories/pull/752))
- **Feature**: Improved layout for albums list view
- **Feature**: Search bar for album picker when adding to album.
- **Feature**: Show albums of photo in metadata ([#752](https://github.com/pulsejet/memories/pull/752))
- **Feature**: Show faces in photo in sidebar metadata
- **Feature**: Allow creation of new tags when editing metadata ([#487](https://github.com/pulsejet/memories/issues/487))
- **Feature**: Allow disabling autoplay of live photo ([#591](https://github.com/pulsejet/memories/issues/591))
- **Feature**: Improvements in admin interface
- **Feature**: A `.nomemories` file will now hide a folder from Memories without affecting other apps ([#777](https://github.com/pulsejet/memories/issues/777))
- **Feature**: More crop options for image editor ([#546](https://github.com/pulsejet/memories/issues/546))
- **Bugfix**: You can now configure the transpose strategy of the transcoder (required for QSV)

## [v5.2.1] - 2023-07-03

- **Feature**: Allow moving unclustered faces to a cluster with Recognize (v4.2.0+)

## [v5.2.0] - 2023-06-30

**Note:** You will need to run `occ memories:places-setup --recalculate` to re-index places (or reindex everything)

- New project home page: https://memories.gallery
- New Discord community: https://discord.gg/7Dr9f9vNjJ
- Nextcloud 27 compatibility
- **Feature**: Hierarchical places view
- **Feature**: Layout improvements especially for mobile.
- **Feature**: Allow downloading entire publicly shared albums.
- **Feature**: Basic preview generation configuration in admin interface.
- **Bugfix**: Prevent keeping original file on metadata edit.
- **Bugfix**: Use correct locale for time in metadata view.
- **Bugfix**: Allow editing metadata on large video files.

## [v5.1.0] - 2023-04-29

- **Feature**: Allow creating new cluster in recognize while moving faces.
- **Feature**: Allow specifying precise coordinates while editing GPS metadata.
- **Feature**: Whitelist x-msvideo mime type.
- **Fix**: Improved handling of duplicate Live Photos.
- **Fix**: Prevent zombie processes when running in Docker.
- **Breaking**: Recognize v3.8 (minimum) is now required.

## [v5.0.0] - 2023-04-16

Note: this is a major release and may introduce breaking changes to your workflow.

- **Feature**: You can now configure Memories from the admin panel.  
  To access the admin panel, go to the admin settings and click on the "Memories" tab.
- **Breaking**: The `memories:video-setup` command has been removed.  
  Transcoding with or without hardware acceleration must now be configured from the admin panel.  
  For running an external go-vod instance, specifying a configuration file is no longer required.
- **Breaking**: The transcoder and exiftool binaries will be copied to the temp directory before execution.  
  Make sure your temp directory is writable by the web server.
- **Breaking**: The `--cleanup` flag to `memories:index` has been removed and is no longer necessary.  
  Folders having a `.nomedia` file will automatically be excluded from the timeline.
- **Feature**: Indexing will now build and check indices automatically in the backgroud.  
  Make sure Nextcloud cron is configured correctly. You can disable automatic indexing in the admin panel.
  Note that files are still indexed immediately on upload.
- **Feature**: You can now choose which folders to index by default.  
  This can be configured from the admin panel. The available options are:
  - All media files (excluding folders with `.nomedia` files, default and recommended)
  - All files in every user's configured timeline folder (not recommended).
  - All files in a given folder for each user (relative path).
- **Feature**: You can now run indexing in parallel on multiple threads.  
  `for i in {1..4}; do (occ memories:index &); done`
- **Feature**: Image editing is now done server-side, and is much faster and more reliable.
  - PHP Imagick extension is now required for image editing.
  - This fixes multiple issues editing images especially in Firefox.
- **Feature**: Significant performance improvements for the timeline view.

## [v4.13.1] - 2023-04-03

- **Feature**: "Direct" video playback will now fall back to HLS (transcoding) if playback fails (e.g. due to lack of browser support).

## [v4.13.0] - 2023-04-03

- **Feature**: Use GPS location data for timezone calculation.  
  Many cameras do not store the timezone in EXIF data. This feature allows Memories to use the GPS location data to calculate the timezone. To take advantage of this, you will need to run `occ memories:places-setup` followed by `occ memories:index --clear` (or `occ memories:index -f`) to reindex your photos.
- **Feature**: You can now specify the user and/or folder to index when running `occ memories:index` ([#184](https://github.com/pulsejet/memories/issues/184)).
- **Feature**: The map view now has a much more flexible layout, especially on mobile.
- **Feature**: Support for Google MVIMG photos ([#468](https://github.com/pulsejet/memories/issues/468))

## [v4.12.5] - 2023-03-23

- These releases significantly overhaul the application logic for better maintainability. If you run into any regressions, please [file a bug report](https://github.com/pulsejet/memories/issues).

## [v4.12.2] - 2023-03-17

- **Feature**: Allow migrating Google Takeout metadata to EXIF ([#430](https://github.com/pulsejet/memories/issues/430))

## [v4.12.1] - 2023-03-15

- **Feature**: Load full image on zoom ([#266](https://github.com/pulsejet/memories/issues/266))

## [v4.12.0] - 2023-03-10

**This release drops support for Nextcloud 24.**

Make sure you run at least Nextcloud 25.0.4  
PHP 7.4 support is now deprecated. Please upgrade to at least PHP 8.0.  
You may need to clear browser cache to use location search.

- **Feature**: Allow editing of GPS coordinates ([#418](https://github.com/pulsejet/memories/issues/418))
- **Feature**: Allow bulk editing of EXIF attributes other than date/time
- **Feature**: Allow (optionally bulk) editing of collaborative tags
- **Feature**: Allow sharing single photo / video ([#307](https://github.com/pulsejet/memories/issues/307))
- **Feature**: Allow sharing photos in high/low resolution.
- **Feature**: Allow sharing videos ([#261](https://github.com/pulsejet/memories/issues/261))
- **Feature**: Show list of tags in sidebar
- **Feature**: Better configurability and feature detection for go-vod ([#450](https://github.com/pulsejet/memories/issues/450))
- **Feature**: Configurable folder/album sorting order ([#371](https://github.com/pulsejet/memories/issues/371))
- **Feature**: Configurable album list sorting order ([#377](https://github.com/pulsejet/memories/issues/377))
- **Feature**: Allow archiving photos through folder view ([#350](https://github.com/pulsejet/memories/issues/350))
- **Feature**: Add search bar to face cluster merge dialog ([#177](https://github.com/pulsejet/memories/issues/177))
- **Bugfix**: Sidebar now shows metadata on albums and public shares ([#320](https://github.com/pulsejet/memories/issues/320)).
- Other fixes and features ([milestone](https://github.com/pulsejet/memories/milestone/9?closed=1))

## [v4.11.0] - 2023-02-10

- **Feature**: Show map of photos ([#396](https://github.com/pulsejet/memories/pull/396))  
  To index existing images, you must run `occ memories:index -f`
- **Feature**: Show list of places using reverse geocoding (MySQL/Postgres only) ([#395](https://github.com/pulsejet/memories/issues/395))  
  To configure this feature, you need to run `occ memories:places-setup` followed by `occ memories:index -f`
- Other minor fixes and features ([milestone](https://github.com/pulsejet/memories/milestone/7?closed=1))

## [v4.10.0] - 2023-01-17

- **Feature**: Allow sharing albums using public links ([#274](https://github.com/pulsejet/memories/issues/274))
- **Feature**: Allow sharing albums with groups ([#329](https://github.com/pulsejet/memories/issues/329))
- **Feature**: Directly move photos from the timeline to any folder ([#321](https://github.com/pulsejet/memories/pull/321))
- **Feature**: Optionally view folders in the recursive timeline view ([#260](https://github.com/pulsejet/memories/pull/260))
- Fix folder share title and remove footer ([#323](https://github.com/pulsejet/memories/issues/323))
- Other minor fixes ([milestone](https://github.com/pulsejet/memories/milestone/6?closed=1))

## [v4.9.0] - 2022-12-08

- **Important**: v4.9.0 comes with an optimization that greatly reduces CPU usage for preview serving. However, for best experience, the preview generator app is now **required** to be configured properly. Please install it from the app store.
- **Feature**: Slideshow for photos and videos ([#217](https://github.com/pulsejet/memories/issues/217))
- **Feature**: Support for GPU transcoding ([#194](https://github.com/pulsejet/memories/issues/194))
- **Feature**: Allow downloading entire albums
- **Feature**: Allow editing more EXIF fields ([#169](https://github.com/pulsejet/memories/issues/169))
- **Feature**: Alpha integration with the face recognition app ([#146](https://github.com/pulsejet/memories/issues/146))
- Fix downloading from albums ([#259](https://github.com/pulsejet/memories/issues/259))
- Fix support for HEVC Live Photos ([#234](https://github.com/pulsejet/memories/issues/234))
- Fix native photo sharing ([#254](https://github.com/pulsejet/memories/issues/254), [#263](https://github.com/pulsejet/memories/issues/263))
- Use larger previews in viewer (please see [these docs](https://memories.gallery/config/#preview-storage)) ([#226](https://github.com/pulsejet/memories/issues/226))

## [v4.8.0] - 2022-11-22

- **Feature**: Support for Live Photos ([#124](https://github.com/pulsejet/memories/issues/124))
  - You need to run `occ memories:index --clear` to reindex Live Photos
  - Only JPEG (iOS with MOV, Google, Samsung) is supported. HEIC is not supported.
- **Feature**: Timeline path now scans recursively for mounted volumes / shares inside it
- **Feature**: Multiple timeline paths can be specified ([#178](https://github.com/pulsejet/memories/issues/178))
- Support for server-side encrypted storage ([#99](https://github.com/pulsejet/memories/issues/99))
- Mouse wheel now zooms on desktop
- Improved caching performance
  - Due to incorrect caching in previous versions, your browser cache may have become very large. You can clear it to save some space.

## [v4.7.0] - 2022-11-14

- **Note**: you must run `occ memories:index -f` to take advantage of new features.
- **Massively improved video performance**
  - Memories now comes with a dedicated transcoding server with HLS support.
  - Read the documentation [here](https://memories.gallery/config/#transcoding) carefully for more details.
- **Feature**: Show EXIF metadata in sidebar ([#68](https://github.com/pulsejet/memories/issues/68))
- **Feature**: Multi-selection with drag (mobile) and shift+click ([#28](https://github.com/pulsejet/memories/issues/28))
- **Feature**: Show duration on video tiles
- **Feature**: Allow editing all image formats (HEIC etc.)
- Fix stretched images in viewer ([#176](https://github.com/pulsejet/memories/issues/176))
- Restore metadata after image edit ([#174](https://github.com/pulsejet/memories/issues/174))
- Fix loss of resolution after image edit

## [v4.6.1] - 2022-11-07

- **Feature**: Native sharing from the viewer (images only)
- **Feature**: Deep linking to photos on opening viewer
- **Feature**: Password protected folder shares ([#165](https://github.com/pulsejet/memories/issues/165))
- **Feature**: Folders view will now show only folders with photos ([#163](https://github.com/pulsejet/memories/issues/163))
- Improvements to viewer UX
- Restore image editor (see v4.6.0)

## [v4.6.0] - 2022-11-06

- **Brand new photo viewer** with improved touch interface and UX
- Improvements from v4.5.4 below
- Known regressions: Photo Editor and Slideshow are not implemented yet
- New layout for Albums view (date ascending, grouped by month)
- Re-enable viewer editing and deletion

## [v4.5.2] - 2022-10-30

- Improved scroller performance
- Improved support for external storage and FreeBSD
- Improved selection of photos

## [v4.5.0] - 2022-10-28

- **Feature**: Album sharing to other Nextcloud users
- **Feature**: Folder sharing with public link [#74](https://github.com/pulsejet/memories/issues/74)
- Performance improvements and bug fixes

## [v4.4.1] - 2022-10-27

- **Feature**: Albums support for Nextcloud 25 (alpha)
- Performance improvements and bug fixes

## [v4.3.8] - 2022-10-26

- **Feature**: Full screen viewer on desktop
- **Feature**: Allow opening people and tags in new tab
- Bugfix: Fix regression in performance with large number of files
- Bugfix: Improve image quality on mobile

## [v4.3.7] - 2022-10-24

- **Feature**: Support for RAW (must run `occ memories:index` after upgrade) with camera raw previews app ([#107](https://github.com/pulsejet/memories/issues/107))
- **Feature**: Better settings experience.
- **Feature**: Better first start experience.
- Bug fixes for postgresql and mysql

## [v4.3.0] - 2022-10-22

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

## [v4.2.2] - 2022-10-12

- Update to mobile layout with improved performance
- Show how old photos are in `On this day`

## [v4.2.1] - 2022-10-11

- Fix incorrect layout of `On this day`

## [v4.2.0] - 2022-10-11

- Allow renaming and merging recognize faces
- Bug fixes

## [v4.1.0] - 2022-10-08

- First release for Nextcloud 25

## [v3.0.0] - 2022-10-07

- People tab with faces from recognize app
- Tags tab with objects from recognize app
- On this day tab
- Bug fixes and performance improvements

## [v2.1.3] - 2022-09-27

- Bug fixes and optimized performance

## [v2.1.2] - 2022-09-25

- Breadcrumb navigation in folder view
- Edit Exif date feature (use with care)
- Archive photos function
- Improved localization and performance

## [v2.0.0] - 2022-09-23

- **Note:** you must re-run `occ memories:index` after updating.
- Support for external storage and shared folders for timeline.
- Localization support. Many languages already available.
- Select and favorite / unfavorite photos

## [v1.1.6] - 2022-09-15

- **New feature:** Select photos from an entire day together
- **Fix:** Timeline with nested folders

## [v1.1.5] - 2022-09-15

- Fix for postgres
- Fix for Exiftool crash

## [v1.1.4] - 2022-09-13

- PHP 7.4 support
- Bug fixes

## [v1.1.0] - 2022-09-13

- Support for external storage
- Favorites and Videos tabs
- Improved performance
- Better support for folder shares

## [v1.0.1] - 2022-09-08

- Initial release
