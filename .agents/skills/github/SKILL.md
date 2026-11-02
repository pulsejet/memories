---
name: github
description: Interact with GitHub issues and labels
---

# github

- For GitHub only, not general Git.
- Use `gh`; prompt the user if not installed.
- NEVER comment on issues/PRs without explicit user consent.

## Commands
- `gh issue view <num>`
- `gh issue list --label "<label>"`
- `gh label list`
- `gh issue edit <num> --add-label "<label>" --remove-label "<label>"`

## Labels
- `bug`, `feature`, `regression`, `performance`, `android`, `duplicate`, `wontfix`, `needs details`, `documentation`.
- Triage issues labeled `needs triage`; don't relabel unless confirmed bug or regression.
