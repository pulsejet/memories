---
name: release-readiness
description: Check prod release readiness from commits and open issues
metadata:
  opencode/autoinvoke: false
---

# release-readiness

Read-only check. DO NOT bump versions, tag, comment on issues, rebuild JS, or run e2e/tests.

## Baseline

- `git fetch origin --tags`, `git branch --show-current`, `git status --porcelain`.
- Last stable prod tag: newest `vX.Y.Z` excluding `alpha/beta/rc/nightly` and all `go-vod/*` tags.
- Use the branch `origin/development` and also check `origin/master`.

## Fan out with subagents in parallel

Run 3-4 subagents in one block. Each returns verdict + evidence.

1. Scope: `git log --oneline <tag>..origin/development`, full messages for significant ones. `git diff --name-only --stat <tag>..origin/development`. Categorize features/fixes/perf/chore. Flag breaking changes and high blast radius changes.
2. go-vod: read `VERSION` in `go-vod/main.go`, `GOVOD_VER` in `lib/Service/BinExt.php`, latest `go-vod/*` tag. `git log --oneline <go-vod-tag>..origin/development -- go-vod/` + diff. Verdict Y/N. Agreement enforced by `scripts/get-bin-ext.sh` and `BinExt::testGoVod`. No functional change = no release.
3. GitHub (read-onlyt): `gh issue list --state open` + by label `regression`, `bug`, `performance`, `needs triage`. `gh pr list --state open`. Flag fresh crash/data-loss/corruption/regression; ignore stale feature requests.
4. Code risk: dirty tree, reverts/hotfixes/WIP in log, `TODO` additions, coverage of risky items.

## Do not flag

- `appinfo/info.xml`, `package.json`, `CHANGELOG.md` not yet bumped/moved. Readiness is before release; bumping is the release job. Only note suggested version shape (patch/minor/major) and whether `[Unreleased]` describes risks adequately.

## Verdict

- `Ready` / `Ready with verification` / `Not ready`.
- Blockers (must fix) vs watchlist (verify if cheap).
- go-vod needed Y/N with reason.
- Targeted QA testing to run manually.
