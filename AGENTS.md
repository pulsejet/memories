# Agent Guidelines & Project Context

## Development
- See `Makefile` for common workflows (building, linting, testing, and managing external binaries).
- Never rebuild JS (for typecheck `make js-lint`)
  - Assume watcher is always running.
  - If things are unexpected, check ps and ask user to run it.
- Never read built JS files (in `js/`). Exclude them for grep etc.
- To run e2e tests, create a test user using functions in `scripts/e2e.sh`. Create a file named .agent.json that contains the user name so you can re-read it. Re-read this file before running the test so you can reuse the context. Don't unnecessarily teardown and create new users frequently.

## Code Style
These instructions are very important.
- Keep code concise and clean.
- Minimal or zero comments when things are obvious

## Unit Testing Guidelines
- **Bootstrapping**:
  - Standard `vendor/autoload.php` alone is insufficient because Nextcloud core classes (`OC\*`, `OCP\*`) and app namespaces are registered by Nextcloud's environment.
  - For unit tests or standalone scripts/REPL, always bootstrap via `tests/bootstrap.php` (loads Nextcloud's `lib/base.php` and registers the app container).
- **Test Fixtures (`tests/assets/`)**:
  - For sample assets, inspect raw metadata, stream offsets, or binary properties using a `tests/bootstrap.php` one-liner before writing assertions.
  - Tests interacting with metadata or transcoding rely on external binaries managed by `OCA\Memories\Service\BinExt`.
- **Workflow**:
  - Run target test: `vendor/bin/phpunit --filter <TestClassOrMethod>`
  - Run full test suite: `vendor/bin/phpunit`
  - Auto-format code: Always run `make php-lint` (runs `php-cs-fixer`) after adding or editing tests.

## E2E Testing Guidelines
- **Architecture & Setup (`e2e/`)**:
  - Playwright test suite driven by `scripts/e2e.sh` and configured in `playwright.config.ts`.
  - Authentication runs once via `auth.setup.ts`; UI tests must call `await bootstrap(page)` in `beforeEach`.
  - Required headers: Use `e2eHeaders()` from `navigation.ts` for all tests.
- **Test Categories & Data**:
  - Derive expected API responses dynamically via `e2e/dataset-measurements.ts` (`goldXXX()`) rather than hardcoding. Strip non-deterministic fields using before assertions.
- **Fast Iteration & Execution**:
  - Run full suite with orchestration: `make e2e`.
  - Fast iteration mode: `E2E_USER="test-user" make e2e` reuses an existing test user.
  - Run single spec: `npx playwright test e2e/<spec-file>`
  - Don't run any e2e test unless the user has explictly asked you to.

## Key Subsystems & Architecture

### Architecture Overview
- **Backend**: Nextcloud PHP app in `lib/` (namespace `OCA\Memories\`).
- **Routing**: Backend routes defined in `appinfo/routes.php`.
- **Repository**: You are in Memories checkout, which is in `<nextcloud>/apps/memories` (or `custom_apps`)
  - In general `../..` will likely be a Nextcloud checkout.

### Database Schema & Storage
- `oc_memories`: Main catalog indexed from the filesystem (`fileid`, `dayid`, `datetaken`, coordinates, EXIF).
- `oc_memories_livephoto` (motion photos)
- `oc_memories_mapclusters` (geohash clusters for map view)
- `oc_memories_places` & `oc_memories_planet` (reverse geocoding)
- `oc_memories_failures` (tracking failed files).

### Metadata Extraction & Indexing Pipeline
- **Engine**: Uses `exiftool` via `OCA\Memories\Exif` and `BinExt` to extract metadata and write EXIF back to files.
- **Triggers**: Real-time filesystem hooks (`PostWriteListener`, `PostDeleteListener`), background cron (`OCA\Memories\Cron\IndexJob`), or manual OCC (`occ memories:index`).

### Timeline Query Engine
- High-performance SQL in `lib/Db/TimelineQuery*.php` leveraging Common Table Expressions (`TimelineQueryCTE.php`) and subquery materialization (`SQL::materialize`) to scale to millions of photos.
- Aggregates media by `dayid` (epoch day index) for virtual scrolling and jump-to-date navigation.

### Cluster Backends (`lib/ClustersBackend/`)
- Implements groupings under `/api/clusters/{backend}`
- Albums (integrates with Nextcloud Photos)
- Tags (native Nextcloud systemtags)
- Places (offline reverse geocoding)
- AI tagging (Recognize and Face Recognition).

### Video Transcoding (`go-vod/`)
- Communicates with the `go-vod` HTTP daemon, which generates HLS segments via `ffmpeg`.
- Supports hardware acceleration (VA-API, NVENC).

### Frontend Architecture (`src/`)
- Vue 2 app, built with Webpack to `js/`.
- History mode under `/apps/memories`; routes in `src/router.ts`.
