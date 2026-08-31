# Agent Guidelines & Project Context

## Architecture Overview
- **Backend**: Nextcloud PHP app in `lib/` (namespace `OCA\Memories\`).
- **Frontend**: Vue 2 app in `src/`, built with Webpack to `js/`.
- **External Binaries** (`bin-ext/`):
  - `exiftool`: Extracted static binary or Perl script used for fast EXIF metadata parsing/writing.
  - `go-vod`: Go on-demand video transcoder daemon source in `go-vod/` (binaries in `bin-ext/`).

## Development
- See `Makefile` for common workflows (building, linting, testing, and managing external binaries).
