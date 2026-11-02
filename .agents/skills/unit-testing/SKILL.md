---
name: unit-testing
description: Run PHP unit tests with phpunit
---

# unit-testing

- All: `vendor/bin/phpunit`.
- One: `vendor/bin/phpunit --filter <TestClassOrMethod>`.
- After adding/editing tests, always run `make php-format`.
- Tests and standalone scripts/REPL must bootstrap via `tests/bootstrap.php`.
  - `vendor/autoload.php` is not enough: `OC\*`, `OCP\*` and app namespaces need Nextcloud's `lib/base.php` and the app container.
- Before asserting on `tests/assets/`, inspect raw metadata, stream offsets or binary properties with a `tests/bootstrap.php` one-liner.
- Metadata/transcoding tests use external binaries via `OCA\Memories\Service\BinExt`.
