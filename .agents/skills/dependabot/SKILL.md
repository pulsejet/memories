---
name: dependabot
description: Rebase-merge open Dependabot PRs on GitHub, fixing failures on the PR branch first
---

# dependabot

- Load the `github` skill first. Never comment on PRs.
- Use `gh pr merge <num> --rebase` so PRs show as merged; never cherry-pick or merge commits.

## Steps
1. `git fetch origin`; ensure `master` == `origin/master` and clean.
2. List PRs + failing checks:
   `gh pr list --author app/dependabot --state open --json number,title,headRefName,statusCheckRollup --jq '.[] | "\(.number)\t\(.headRefName)\t\([.statusCheckRollup[] | select(.conclusion=="FAILURE") | .name] | join(","))"'`
3. Green PRs: `gh pr merge <num> --rebase`.
4. Failing PRs: check out `<headRefName>` and fix there:
   - Install deps: `composer install` (composer PRs), `npm ci` (npm PRs).
   - Find failure: `gh run view <run-id> --log-failed | grep -vE 'git config|includeif|credentials' | grep -iE 'error|warn|\.php:|src/'`.
   - Reproduce + fix locally:
     - PHP: `make psalm`, `make php-format`.
     - JS: `make js-format`, `make js-lint`. Never rebuild JS.
     - Never run psalm with `--shepherd` locally (uploads data).
     - Check CI flags in `.github/workflows/` if a tool rejects them.
   - Add a signed-off fix commit (`git commit -s`), push to the PR branch (no force-push), wait for CI, then `gh pr merge <num> --rebase`.
5. `git checkout master && git pull --ff-only`, then `npm ci` / `composer install` if lockfiles changed.
6. Verify PRs merged: `gh pr list --author app/dependabot --state all --limit <n>`.
7. Report: PRs merged, fixes made, what wasn't run (unit tests, Android build).
