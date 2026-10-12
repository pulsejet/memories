---
name: github
description: Interact with GitHub issues and labels
---

# github

- Only for *GitHub*, not general Git stuff.
- Use `gh` CLI, prompt user if not installed.
- View/list: `gh issue view <num>`, `gh issue list --label "<label>"`, `gh label list`.
- Edit labels: `gh issue edit <num> --add-label "<label>" --remove-label "<label>"`.
  - `bug`, `feature`, `regression`, `performance`, `android`,
  - `duplicate`, `wontfix`, `needs details`, `documentation`
- Triage issues with `needs triage` label.
  - Don't relabel unless confirmed bug or regression.
- NEVER comment on issues/PRs without explicit user consent.
