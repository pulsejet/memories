---
name: release-readiness
description: Check prod release readiness from commits and open issues
disable-model-invocation: true
metadata:
  opencode/autoinvoke: false
---

# release-readiness

Read-only. DO NOT bump versions, tag, comment on issues, rebuild JS, or run e2e/tests.

## Baseline
- Run `git fetch origin --tags`, `git branch --show-current`, `git status --porcelain`.
- `<tag>`: newest `vX.Y.Z`, excluding `alpha/beta/rc/nightly` and `go-vod/*`.
- Compare against `origin/development`; also check `origin/master`.

## Checks
Run 3-4 subagents in parallel, in one block. Each returns a verdict + evidence.

1. Scope
   - `git log --oneline <tag>..origin/development`, with full messages for significant commits.
   - `git diff --name-only --stat <tag>..origin/development`.
   - Categorize as features/fixes/perf/chore. Flag breaking and high blast radius changes.
2. go-vod
   - Read `VERSION` in `go-vod/main.go`, `GOVOD_VER` in `lib/Service/BinExt.php`, and the latest `go-vod/*` tag.
   - `git log --oneline <go-vod-tag>..origin/development -- go-vod/` plus diff.
   - Verdict Y/N: no functional change means no release.
   - Agreement is enforced by `scripts/get-bin-ext.sh` and `BinExt::testGoVod`.
3. GitHub (read-only)
   - `gh issue list --state open`, plus by label: `regression`, `bug`, `performance`, `needs triage`.
   - `gh pr list --state open`.
   - Flag fresh crash/data-loss/corruption/regression; ignore stale feature requests.
4. Code risk
   - Dirty tree, reverts/hotfixes/WIP in the log, `TODO` additions, coverage of risky items.

## Do not flag
- Unbumped `appinfo/info.xml`, `package.json`, `CHANGELOG.md`; that's the release job.
- Only note the suggested version shape (patch/minor/major) and whether `[Unreleased]` covers risks adequately.

## Verdict
- `Ready` / `Ready with verification` / `Not ready`.
- Blockers (must fix) vs watchlist (verify if cheap).
- go-vod needed Y/N, with reason.
- Targeted manual QA to run.
