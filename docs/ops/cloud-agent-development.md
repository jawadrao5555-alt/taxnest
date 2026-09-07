# TaxNest Cloud Agent development

Repeatable local/Cloud development setup for **jawadrao5555-alt/taxnest**.
This is **not** production. Production deploy remains Environment-gated — see `docs/ops/github-production-deploy.md`.

## Bootstrap (fresh Cloud Agent or local VM)

```bash
git fetch origin main
git checkout -B cursor/<task>-0f83 origin/main

bash scripts/cloud-dev-install.sh      # PHP MySQL ext, MariaDB pkgs, composer, npm ci
bash scripts/cloud-dev-start.sh        # start MariaDB + ensure taxnest_dev DB/user
bash scripts/cloud-dev-bootstrap.sh    # .env from example, APP_KEY, migrate
```

Optional catalog seed only (no customer/demo credential seeders):

```bash
bash scripts/cloud-dev-bootstrap.sh --seed-plans
```

Optional fictional NestPOS local QA shop (for Chrome UI smoke — never production):

```bash
bash scripts/cloud-dev-bootstrap.sh --seed-local-qa
# or: bash scripts/cloud-local-qa-seed.sh
```

Browser / UI self-testing (fail-closed, loopback only): `docs/ops/cloud-agent-local-browser-qa.md`

Generate / refresh key manually if needed:

```bash
cp .env.example .env   # only when .env is missing
php artisan key:generate
```

Do **not** commit `.env`.

## Daily development commands

| Task | Command |
|---|---|
| Laravel HTTP | `php artisan serve --host=127.0.0.1 --port=8000` |
| Migrate local DB | `php artisan migrate` |
| PHPUnit (sqlite `:memory:`) | `php artisan test` or `composer test` |
| Frontend install | `npm ci` |
| Frontend build | `npm run build` |
| Platform check | `composer check-platform-reqs` |
| Setup static check | `bash scripts/tests/cloud-dev-check.sh` |
| Local QA seed (fictional shop) | `bash scripts/cloud-local-qa-seed.sh` |
| NestPOS Chrome UI smoke | `BASE_URL=http://127.0.0.1:8000 node scripts/cloud-local-ui-smoke.mjs` |
| Browser QA static check | `bash scripts/tests/cloud-local-browser-qa-check.sh` |

## Local database

| Item | Value |
|---|---|
| Engine | MariaDB (matches production family; local-only) |
| Host | `127.0.0.1:3306` |
| Database | `taxnest_dev` |
| User / password | `taxnest_dev` / `taxnest_local_dev_only` (placeholder in `.env.example`) |

These credentials are **local development placeholders only**. They must never be used in production and are safe to document because they only unlock a disposable local database.

Replit’s older MySQL Staging path (`.local/mysql_run`, port `9000`, `scripts/dev-mysql-*.sh`) remains for Replit and is **not** removed. Cloud Agents should prefer the MariaDB path above.

## Product focus & safety

- Default product focus: **NestPOS PRA** (`replit.md`, `CLOUD_AGENT_HANDOFF.md`).
- Architecture / invariants (non-secret): `docs/ops/cloud-agent-architecture.md`.
- Cloud Agents may use local DB, local `.env`, PHPUnit, Vite, and browser smoke against `127.0.0.1` only (`docs/ops/cloud-agent-local-browser-qa.md`).
- Prefer `scripts/cloud-local-ui-smoke.mjs` for NestPOS UI evidence. Do **not** use `scripts/live-screen-smoke.sh` from Cloud Agents (it targets live `taxnest.pk`).
- Cloud Agents must **never**:
  - deploy production or SSH to the VPS without explicit owner instruction
  - request/commit production secrets, FBR/PRA tokens, SSH keys, mail passwords, customer credentials
  - connect to production databases
  - commit `.env` or `.local/` secrets
  - point browser QA at non-loopback hosts

## Normal Git workflow

```
latest origin/main
  → cursor/* branch
  → implement
  → tests
  → PR to main
  → PR checks
  → automatic squash merge (cursor/*)
  → main
  → manual GitHub Environment "production" approval
  → GitHub Actions production deploy
```

Rollback remains `deployment/ROLLBACK.md`.
