# Cloud Agent — local browser / UI QA (fail-closed)

Reusable NestPOS Chrome smoke for **local disposable environments only**.
Used as the UI verification step inside the **DEFAULT** issue-resolution
policy: `docs/ops/cloud-agent-issue-resolution.md`.

This is the Cloud Agent analogue of Replit-Agent self-testing: reproduce a UI
issue locally, exercise the real flow in Chrome, inspect console/page errors,
fix, and re-test — without production credentials or hosts.

## Safety (fail-closed)

| Rule | Enforcement |
|---|---|
| Loopback BASE_URL only | `scripts/lib/local-browser.mjs` → `assertLocalOnlyBaseUrl` |
| No `taxnest.pk` / production IP | Same helper refuses those hosts |
| No live QA accounts | Blocks `qa.fullaudit@taxnest.com.pk` etc. |
| Disposable DB only | `DevStagingGuard` allows exact names `taxnest_dev` \| `taxnest_staging` on `127.0.0.1`/`localhost` |
| Creds never committed | Written to gitignored `.local/qa-creds.env` |
| Live smoke stays live | `scripts/live-screen-smoke.sh` remains production-oriented and is **not** the Cloud path |

Never point these scripts at production. Never paste real customer passwords into `.local/` or `.env`.

## One-time / bootstrap

```bash
bash scripts/cloud-dev-install.sh
bash scripts/cloud-dev-start.sh
bash scripts/cloud-dev-bootstrap.sh
bash scripts/cloud-local-qa-seed.sh          # fictional videodemo@nestpos.pk shop
```

Optional: `bash scripts/cloud-dev-bootstrap.sh --seed-local-qa` runs the same seed after migrate.

## Run NestPOS local UI smoke

Terminal A:

```bash
php artisan serve --host=127.0.0.1 --port=8000
```

Terminal B:

```bash
BASE_URL=http://127.0.0.1:8000 node scripts/cloud-local-ui-smoke.mjs
```

Headed Chrome (when a display is available):

```bash
CLOUD_LOCAL_QA_HEADED=1 BASE_URL=http://127.0.0.1:8000 node scripts/cloud-local-ui-smoke.mjs
```

Screenshots land in `.local/browser-evidence/` (gitignored).

## What the smoke covers today

1. `/pos/login` renders  
2. Login as fictional `videodemo@nestpos.pk` (password from `.local/qa-creds.env`)  
3. `/pos/dashboard` loads while authenticated  
4. `/pos/v2/invoice/create` shows NestPOS sale root (`data-tn-sale-root`)  
5. Reports pageerrors / serious console errors  

Extend `scripts/cloud-local-ui-smoke.mjs` (or add sibling `scripts/cloud-local-*-smoke.mjs` files that import `scripts/lib/local-browser.mjs`) when a bug needs a longer flow — do **not** invent green fake tests.

## Static check (no Chrome required)

```bash
bash scripts/tests/cloud-local-browser-qa-check.sh
```

## Related commands for a full local verification loop

| Step | Command |
|---|---|
| Targeted PHPUnit | `php artisan test --filter=Whatever` |
| Guard unit tests | `php artisan test --filter=DevStagingGuardTest` |
| Full suite (when feasible) | `php artisan test` |
| Frontend build (if assets changed) | `npm ci && npm run build` |
| Browser smoke | `node scripts/cloud-local-ui-smoke.mjs` |

## Files

| Path | Role |
|---|---|
| `scripts/lib/local-browser.mjs` | Shared Chrome launch, URL guard, creds, diagnostics |
| `scripts/cloud-local-ui-smoke.mjs` | NestPOS login → dashboard → sale smoke |
| `scripts/cloud-local-qa-seed.sh` | Seed local demo shop + write `.local/qa-creds.env` |
| `scripts/tests/cloud-local-browser-qa-check.sh` | Static safety/presence checks |
| `app/Support/DevStagingGuard.php` | Exact local DB identity for destructive seeders |

## Out of scope

- Production deploy / SSH / live DB  
- Replacing PHPUnit  
- Calling `scripts/live-screen-smoke.sh` from Cloud Agents (uses live QA on `taxnest.pk`)  

## Related

- Default issue workflow: `docs/ops/cloud-agent-issue-resolution.md`
- Bootstrap: `docs/ops/cloud-agent-development.md`
- Handoff: `CLOUD_AGENT_HANDOFF.md`
