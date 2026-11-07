---
name: e2e-testing
description: Run Playwright e2e tests
---

# e2e-testing

Never run e2e unless the user explicitly asks.

## Running
- Suite in `e2e/`, run by `scripts/e2e.sh`, configured in `playwright.config.ts`.
- Full: `make e2e`. Prefer subsets.
- Reuse a user: `E2E_USER="test-user" make e2e`.
- Single spec: `npx playwright test e2e/<spec-file>`.
- Auth runs once via `auth.setup.ts`.

## Test users
- Create via functions in `scripts/e2e.sh`.
- Record them in `.agent.yaml`; re-read it at the start.
- Reuse them; don't teardown/recreate unnecessarily.

## Writing specs
- Specs run over HTTP only; assume a remote runner. Never run `occ`.
- Import `test` from `./navigation`, not `@playwright/test`; its `page` is bootstrapped automatically.
- Derive expected API responses with `goldXXX()` from `e2e/dataset-measurements.ts`, never hardcode.
- Strip non-deterministic fields before asserting.
- File changes index reactively; never wait/poll for indexing.
- Put helpers at the bottom, after all tests.

## Ad hoc browser checks
- Instance: `http://localhost` (port 80). Login from `.agent.yaml`; default `admin` password fails.
- Chrome DevTools MCP can't start as root: use Playwright from `node_modules` with `--no-sandbox`.
- Keep throwaway scripts in the scratchpad.
- Mobile: set `hasTouch: true`; synthesize pinch with CDP `Input.dispatchTouchEvent`.

### Login redirects to 443
- Cause: `overwriteprotocol => https` in `config.php`; nothing listens on 443. Never edit `config.php`.
- Symptom: `chrome-error://chromewebdata/` or `ERR_CONNECTION_REFUSED` after login.
- Also breaks e2e runs: `auth.setup.ts` fails with `ECONNREFUSED ::1:443`.
- Check: `curl -sI http://localhost/ | grep -i location`.
- Fix: forward 443 → 80 with a throwaway self-signed cert, in the background:
  ```bash
  pem="$(openssl req -x509 -newkey rsa:2048 -nodes -days 1 -subj /CN=localhost -keyout - -out - 2>/dev/null)"
  exec socat OPENSSL-LISTEN:443,fork,reuseaddr,pf=ip6,ipv6only=0,verify=0,cert=<(echo "$pem"),key=<(echo "$pem") TCP:127.0.0.1:80
  ```
- `playwright.config.ts` already sets `ignoreHTTPSErrors`; ad hoc scripts must set it themselves.
- Run e2e with `E2E_BASE_URL=https://localhost` so it matches the server's canonical URL.
- Afterwards, kill `socat`.
