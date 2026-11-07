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
2. Ship to master: `git fetch origin`, rebase on `origin/master`, push `master`. Linear history, no merges. Push before tagging.
3. Verify `master` matches `origin/master`. Dirty tree is OK only if the tag targets a clean HEAD.
4. Tag: check the tag is absent, `git tag vX.X.X`, `git push origin vX.X.X`, verify with `ls-remote`. Never `--tags` (publishes stale local tags).
5. Create the GitHub release:
   ```sh
   gh release create <tag> --title <tag> --generate-notes --notes <NOTES> --notes-start-tag <prev-stable> --verify-tag
   ```
   - `<NOTES>`: full changelog text for this release AND the changelog URL, prepended to auto notes.
   - `<prev-stable>`: last stable tag. Must override; auto picks the newest tag by date, e.g. `go-vod/*`.
   - Alpha/beta/rc: add `--prerelease`.
   - Wrong base: regenerate with `gh api .../generate-notes -f previous_tag_name=<prev>`, then `gh release edit --notes-file`.

## Hotfix release

For hotfix releases, just update the latest entry in the CHANGELOG.md to point to the new version rather than creating a new entry. Then follow the main release steps. In the gh release changelog, just say "Hotfix release for vX.X.X" and link to the previous release and changelog.

## go-vod release (two phases)

`VERSION`, `GOVOD_VER` and the tag must agree (enforced by `get-bin-ext.sh` and `testGoVod`).

1. Phase 1: bump `VERSION` in `go-vod/main.go` only, leaving `GOVOD_VER` untouched. Push master, tag `go-vod/X.Y.Z`. CI builds the binaries.
2. Phase 2, once binaries exist: bump `GOVOD_VER` in `lib/Service/BinExt.php` in a separate commit, then `make bin-ext` to refresh the dev env.

## Android release

Independent of app versions. `release.yaml` ignores `android/*` tags, so the APK is built and uploaded by hand.

1. Commit (`git commit -s`) `chore(android): bump to 1.N`, changing only `android/app/build.gradle`: `versionCode = N`, `versionName = "1.N"`.
2. Ship to master as in the main release. Check the `android` workflow is green.
3. Tag: check the tag is absent, `git tag android/1.N`, `git push origin android/1.N`, verify with `ls-remote`.
4. Ask the user to build the AAB from the tag in Android Studio, upload it to Google Play (which signs it), and download the signed APK.
5. Verify with `apksigner verify --print-certs`: the cert must match the previous release's APK (`gh release download android/1.<N-1>`), or updates fail.
6. Create the GitHub release, with the APK named `memories-1.N.apk`:
   ```sh
   gh release create android/1.N memories-1.N.apk --title android/1.N --notes '**Full Changelog**: https://github.com/pulsejet/memories/compare/<prev>...android/1.N' --latest=false --verify-tag
   ```
   - Notes are only the compare link, no generated notes. `<prev>` is the last `android/*` release tag.
