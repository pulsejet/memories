---
name: unit-testing
description: Run PHP unit tests with phpunit
---

# unit-testing

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
