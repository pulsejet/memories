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

## Unit Testing
- `vendor/autoload.php` alone is insufficient.
  - `OC\*`, `OCP\*` and app namespaces come from Nextcloud's environment.
- Unit tests and standalone scripts/REPL must bootstrap via `tests/bootstrap.php`.
  - Loads Nextcloud `lib/base.php`, registers the app container.
- Before asserting on sample assets in `tests/assets/`, inspect them first.
  - Check raw metadata, stream offsets, or binary properties.
  - Use a `tests/bootstrap.php` one-liner.
- Metadata/transcoding tests rely on external binaries via `OCA\Memories\Service\BinExt`.
- Run one test: `vendor/bin/phpunit --filter <TestClassOrMethod>`.
- Run all tests: bare `vendor/bin/phpunit`.
- After adding/editing tests always run `make php-lint` (`php-cs-fixer`).

## E2E Testing
- Playwright suite in `e2e/`, run by `scripts/e2e.sh`.
- Configured in `playwright.config.ts`.
- Auth runs once via `auth.setup.ts`.
- Derive expected API responses dynamically, never hardcode.
  - Use `goldXXX()` from `e2e/dataset-measurements.ts`.
  - Strip non-deterministic fields before asserting.
- Full suite: `make e2e`.
- Reuse a user: `E2E_USER="test-user" make e2e`.
- Single spec: `npx playwright test e2e/<spec-file>`.
- Never run e2e unless the user explicitly asks.
- Create test users via functions in `scripts/e2e.sh`.
- Record the info in `.agent.yaml` and re-read it at the beginning.
- This reuses the context; don't teardown/recreate users unnecessarily.
- Prefer subsets over the full suite.

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
- Vue 2 + Webpack, built to `js/`.
- History mode under `/apps/memories`.
- Routes in `src/router.ts`.
