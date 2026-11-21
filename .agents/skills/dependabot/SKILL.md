---
name: dependabot
description: Merge open Dependabot PRs manually onto master, fixing lint in the same commit
---

# dependabot

- Load the `github` skill first. Never comment on PRs.
- Linear history: cherry-pick onto `master`, never merge commits.

## Steps
1. `git fetch origin`; ensure `master` == `origin/master` and clean.
2. List PRs + failing checks:
   `gh pr list --author app/dependabot --state open --json number,title,headRefName,statusCheckRollup --jq '.[] | "\(.number)\t\(.headRefName)\t\([.statusCheckRollup[] | select(.conclusion=="FAILURE") | .name] | join(","))"'`
3. Do green PRs first, then failing ones. Per PR:
   - `git cherry-pick <sha>` (from `git log origin/master..origin/<headRefName>`).
   - Install deps: `composer install` (composer PRs), `npm ci` (npm PRs).
   - Find failure: `gh run view <run-id> --log-failed | grep -vE 'git config|includeif|credentials' | grep -iE 'error|warn|\.php:|src/'`.
   - Reproduce + fix locally:
     - PHP: `make psalm`, `make php-format`.
     - JS: `make js-format`, `make js-lint`. Never rebuild JS.
     - Never run psalm with `--shepherd` locally (uploads data).
     - Check CI flags in `.github/workflows/` if a tool rejects them.
   - Amend fix into that commit (keep Dependabot author/body), add a short fix note and `Closes #<num>` after the subject, sign off:
     `printf '%s\n\n<fix note>\n\nCloses #N\n%s\n' "$subject" "$rest" | git commit --amend -s -F -`
   - Green PRs also get `Closes #N` + `-s`.
4. `git log origin/master..HEAD`: one commit per PR, linear.
5. `git fetch origin && git rebase origin/master && git push origin master`. Never force-push.
6. Verify PRs closed: `gh pr list --author app/dependabot --state all --limit <n>`.
7. Report: PRs merged, fixes made, what wasn't run (unit tests, Android build).
