---
name: release
description: Cut a production or nightly release
---

# release

Confirm each step with the user before running it.

## Main release

1. Commit (`git commit -s`) in one commit:
   - Bump `<version>` in `appinfo/info.xml` and `"version"` in `package.json`.
   - Move `[Unreleased]` in `CHANGELOG.md` to `## [vX.X.X] - YYYY-MM-DD`.
2. Ship to master: `git fetch origin`, rebase `development` on `origin/master`, fast-forward `master`, push `master`. Linear history, no merges. Branches must be pushed before tagging.
3. Verify `master` matches `origin/master` and `development`. Dirty tree is OK only if the tag targets a clean HEAD.
4. Tag: check the tag is absent, `git tag vX.X.X`, `git push origin vX.X.X`, verify with `ls-remote`. Never `--tags` (publishes stale local tags).
5. Create the GitHub release:
   ```sh
   gh release create <tag> --title <tag> --generate-notes --notes <NOTES> --notes-start-tag <prev-stable> --verify-tag
   ```
   - `<NOTES>`: full changelog text for this release AND the changelog URL, prepended to auto notes.
   - `<prev-stable>`: last stable tag. Must override; auto picks the newest tag by date, e.g. `go-vod/*`.
   - Alpha/beta/rc: add `--prerelease`.
   - Wrong base: regenerate with `gh api .../generate-notes -f previous_tag_name=<prev>`, then `gh release edit --notes-file`.

## go-vod release (two phases)

`VERSION`, `GOVOD_VER` and the tag must agree (enforced by `get-bin-ext.sh` and `testGoVod`).

1. Phase 1: bump `VERSION` in `go-vod/main.go` only, leaving `GOVOD_VER` untouched. Push master + development, tag `go-vod/X.Y.Z`. CI builds the binaries.
2. Phase 2, once binaries exist: bump `GOVOD_VER` in `lib/Service/BinExt.php` in a separate commit, then `make bin-ext` to refresh the dev env.
