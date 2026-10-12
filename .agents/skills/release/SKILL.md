---
name: release
description: Cut a production or nightly release
---

# release

Confirm each step with the user before running it.

## Main release

1. Bump `appinfo/info.xml` (`<version>`) + `package.json` (`"version"`), move `CHANGELOG.md` `[Unreleased]` to `## [vX.X.X] - YYYY-MM-DD`, all in one commit (`git commit -s`).
2. Ship to master: `git fetch origin`, rebase `development` on `origin/master`, fast-forward `master`, push `master`. Linear history only, no merges.
3. On master, verify in sync with `origin/master` and `development`. Dirty tree OK only if tag targets clean HEAD.
4. Check tag absent first, then `git tag vX.X.X` and `git push origin vX.X.X`. Verify with `ls-remote` after.
5. Push branches before tagging/releasing. Push only the new tag, never `--tags` (publishes stale local tags).
6. Create GitHub release:
   `gh release create <tag> --title <tag> --generate-notes --notes <NOTES> --notes-start-tag <prev-stable> --verify-tag`
   Add `--prerelease` for alpha/beta/rc.
   - `<NOTES>` = full release changelog text from `CHANGELOG.md` AND the changelog URL (both), prepended to auto notes.
   - `--notes-start-tag` = last stable tag (auto picks newest tag by date, e.g. `go-vod/*` — must override).
   - Wrong base: regenerate via `gh api .../generate-notes -f previous_tag_name=<prev>` + `gh release edit --notes-file`.

## go-vod release (two phases)

1. Phase 1: bump `VERSION` in `go-vod/main.go` only. Push master + dev, tag `go-vod/X.Y.Z` (CI builds binaries). Keep `GOVOD_VER` untouched.
2. Phase 2 (after binaries exist): bump `GOVOD_VER` in `lib/Service/BinExt.php` in a separate commit, then `make bin-ext` to refresh the dev env.
3. `VERSION`, `GOVOD_VER`, and tag must all agree (enforced by `get-bin-ext.sh` and `testGoVod`).
