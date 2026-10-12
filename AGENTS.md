# Project Context

This file should be human-readable - less tokens.
Keep it concise, short bullets, no wrapping.

## Development
- `Makefile` has all common workflows (build, lint, test, external binaries).
- Never rebuild JS; typecheck via `make js-lint`.
- Assume the watcher is running.
  - If the build looks stale, check `ps` and ask the user to run it.
- Never read built JS in `js/`; exclude it from grep etc.
- Never install new deps without explicit user consent.
- Never patch non-first-party code without user consent.

## Code Style
Very important.
- Keep code concise and clean.
- Minimal or zero comments when things are obvious.
- Use `git commit -s`.
  - Message looks like: `<type>(<scope>): <imperative description>`.
  - Types like fix, feat, chore, test, etc.
  - Include few lines of description.
  - Include refs to issues / PRs like Closes #... or Fixes #...

## Git
- Always work on `development`; keep it checked out locally.
- Linear history only: rebase, never merge commits.
- Ship to master: `git fetch origin`, rebase `development` on `origin/master`, fast-forward `master`, push `master`.
- Never force-push unless explicitly asked.

## Releases
- For any release, load the `release` skill first and follow it exactly.
- Confirm each step with user.

## Testing
- For unit tests, load the `unit-testing` skill first and follow it exactly.
- For e2e tests, load the `e2e-testing` skill first and follow it exactly.
- Never run e2e unless the user explicitly asks.

## GitHub
- For any GitHub interaction, load the `github` skill first and follow it exactly.
- NEVER comment on issues/PRs without explicit user consent.

## Key Subsystems & Architecture

### Overview
- Backend: Nextcloud PHP app in `lib/` (`OCA\Memories\`).
- Routes in `appinfo/routes.php`.
- Checkout lives at `<nextcloud>/apps/memories` (or `custom_apps`).
- `../..` is usually a Nextcloud checkout.

### Database Schema & Storage
- `oc_memories`: main filesystem-indexed catalog.
  - Columns: `fileid`, `dayid`, `datetaken`, coordinates, EXIF.
- `oc_memories_livephoto`: motion photos.
- `oc_memories_mapclusters`: geohash clusters for map view.
- `oc_memories_places` & `oc_memories_planet`: reverse geocoding.
- `oc_memories_failures`: failed files.

### Metadata Extraction & Indexing
- `exiftool` via `OCA\Memories\Exif` + `BinExt`.
- Extracts metadata and writes EXIF back.
- Triggers:
  - Filesystem hooks (`PostWriteListener`, `PostDeleteListener`).
  - Cron (`OCA\Memories\Cron\IndexJob`).
  - Manual: `occ memories:index`.

### Timeline Query Engine
- High-performance SQL in `lib/Db/TimelineQuery*.php`.
- Uses CTEs (`TimelineQueryCTE.php`).
- Uses subquery materialization (`SQL::materialize`).
- Scales to millions of photos.
- Aggregates by `dayid` (epoch day index).
- Powers virtual scrolling and jump-to-date.

### Cluster Backends (`lib/ClustersBackend/`)
- Groupings under `/api/clusters/{backend}`.
- Albums (Nextcloud Photos).
- Tags (systemtags).
- Places (offline reverse geocoding).
- AI tagging (Recognize, Face Recognition).

### Video Transcoding (`go-vod/`)
- `go-vod` HTTP daemon generates HLS via `ffmpeg`.
- VA-API and NVENC hardware acceleration supported.

### Frontend (`src/`)
- Vue 3 + Webpack, built to `js/`.
- History mode under `/apps/memories`.
- Routes in `src/router.ts`.
