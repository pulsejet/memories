# Agent Guidelines & Project Context

## Architecture Overview
- **Backend**: Nextcloud PHP app in `lib/` (namespace `OCA\Memories\`).
- **Frontend**: Vue 2 app in `src/`, built with Webpack to `js/`.
- **External Binaries** (`bin-ext/`):
  - `exiftool`: Extracted static binary or Perl script used for fast EXIF metadata parsing/writing.
  - `go-vod`: Go on-demand video transcoder daemon source in `go-vod/` (binaries in `bin-ext/`).

## Development
- See `Makefile` for common workflows (building, linting, testing, and managing external binaries).

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
  - Required headers: Use `ocsHeaders` from `navigation.ts` for all tests.
- **Test Categories & Data**:
  - Derive expected API responses dynamically via `e2e/dataset-measurements.ts` (`goldXXX()`) rather than hardcoding. Strip non-deterministic fields using before assertions.
- **Fast Iteration & Execution**:
  - Run full suite with orchestration: `make e2e`.
  - Fast iteration mode: `E2E_USER="test-user" make e2e` reuses an existing test user.
  - Run single spec: `npx playwright test e2e/<spec-file>`
  - Don't run any e2e test unless the user has explictly asked you to.
