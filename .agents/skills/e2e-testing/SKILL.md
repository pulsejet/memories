---
name: e2e-testing
description: Run Playwright e2e tests
---

# e2e-testing

- Never run e2e unless the user explicitly asks.
- Playwright suite in `e2e/`, run by `scripts/e2e.sh`.
- Configured in `playwright.config.ts`.
- Auth runs once via `auth.setup.ts`.
- Derive expected API responses dynamically, never hardcode.
  - Use `goldXXX()` from `e2e/dataset-measurements.ts`.
  - Strip non-deterministic fields before asserting.
- Full suite: `make e2e`.
- Reuse a user: `E2E_USER="test-user" make e2e`.
- Single spec: `npx playwright test e2e/<spec-file>`.
- Create test users via functions in `scripts/e2e.sh`.
- Record the info in `.agent.yaml` and re-read it at the beginning.
- This reuses the context; don't teardown/recreate users unnecessarily.
- Prefer subsets over the full suite.
- Specs run over HTTP only; assume a remote runner.
- File changes index reactively; never wait/poll for indexing.
- Never run `occ` in spec.
- Put helpers bottom of spec, after all tests.

## Browser checks (ad hoc)
- Login: use `.agent.yaml`; the default `admin` password does not work.
- Chrome DevTools MCP can't start as root.
  - Use Playwright from `node_modules` with `--no-sandbox` instead.
  - Keep throwaway scripts in the scratchpad, not the repo.
- Instance is at `http://localhost` (port 80).
- `overwriteprotocol => https` in `config.php` redirects login to port 443, where nothing listens.
  - Symptom: `chrome-error://chromewebdata/` or `ERR_CONNECTION_REFUSED` after login.
  - Never edit `config.php`.
  - Check with `curl -sI http://localhost/ | grep -i location`.
  - Workaround: run a temporary Node HTTPS forwarder on `127.0.0.1:443` to `127.0.0.1:80` with a self-signed cert.
  - Set `ignoreHTTPSErrors: true` in Playwright; stop the forwarder and delete the key afterwards.
- Mobile: touch input needs `hasTouch: true`; synthesize pinch with CDP `Input.dispatchTouchEvent`.
