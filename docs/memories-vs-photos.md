---
description: Comparison of Memories and Nextcloud Photos
---

# Memories vs Photos

Nextcloud comes bundled with an official [Photos](https://github.com/nextcloud/photos) app. This page provides a brief feature comparison between Memories and Photos, and links some relevant issues in the Photos repository.

Note: this article is outdated and needs to be updated.

## Features in Memories missing in Photos

1. **Sorting by Date Taken**: The Photos app sorts images and videos by the file modification time. Memories uses the EXIF data to get the Date Taken, providing for the correct sort order regardless if the files are edited / touched later ([issue](https://github.com/nextcloud/photos/issues/87)).
1. **Scrubbable Timeline**: Both apps provide an infinite virtual scroll. However, Photos has no way to jump at any time in the past, and you need to scroll through all photos to get to any point. Memories provides a scroller to directly jump to any date in the timeline ([issue](https://github.com/nextcloud/photos/issues/426)).
1. **Limiting to a single root for Photos**: Memories can scan through photos in a single (or multiple) directory. Photos scans everything the user has ([issue](https://github.com/nextcloud/photos/issues/141)).
1. **Video Transcoding**: Memories supports all video file formats with live adaptive transcoding, along with quality selection. Photos only supports videos compatible with the user's browser at full resolution.
1. **Archive**: Allows separating photos to a different folder quickly. Photos has no equivalent function.
1. **External Folder Sharing**: Allows sharing a folder to non-Nextcloud users. Photos has no equivalent function ([issue](https://github.com/nextcloud/photos/issues/236)).
1. **EXIF Data Editing**: Memories allows basic editing of EXIF data including fields such as date taken, title, description etc. Photos has no equivalent function.
1. **Support for iOS / Google / Samsung Live Photos**: Memories supports live photos, Photos does not. ([issue](https://github.com/nextcloud/photos/issues/344), [issue](https://github.com/nextcloud/photos/issues/365))
1. **Advanced Multi-Selection**: Memories supports all multi-selection methods including selecting a day, selecting with Shift+Click and with Touch+Drag. Photos does not support these ([issue](https://github.com/nextcloud/photos/issues/1154), [issue](https://github.com/nextcloud/photos/issues/83))
1. **Viewer Gestures**: Memories provides a superior photo viewer experience, including gestures such as zoom in and out using touch.
1. **Preview pipelining**: For maximum performance, Memories highly optimizes loading thumbnails, whereas Photos loads them one by one.
1. **Server-side image editing**: The image editor in Memories works server-side allowing editing of large images and all formats (such as HEIC). The Photos image editor works client-side with HTML5 canvas, limiting it's capabilities and the quality of output.

## Features in Photos missing in Memories

1. Photos supports drawing on photos, Memories does not ([issue](https://github.com/pulsejet/memories/issues/785)).
1. Photos shows which photos have been `Shared with you`. Memories does not ([issue](https://github.com/pulsejet/memories/issues/787)).
