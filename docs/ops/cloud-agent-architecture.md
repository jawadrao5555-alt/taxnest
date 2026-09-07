# TaxNest Cloud Agent — architecture & invariants (non-secret)

Safe, tracked reference for Cloud Agents. Authoritative short map remains `replit.md`.
Deep Replit `.agents/memory/` topics are **not** in GitHub (gitignored). Use this file + `replit.md` + code.

**Do not** put secrets, tokens, SSH keys, customer credentials, or live data here.

## Current focus

Work **NestPOS PRA** only unless the owner explicitly expands scope. Do not start FBR POS / DI feature work unprompted.

## Stack

- Laravel 12 / PHP 8.4, Breeze auth, Blade + Alpine.js + Tailwind (Vite), Chart.js
- Production DB: MariaDB on Nayatel VPS; Cloud/local: MariaDB `taxnest_dev` or PHPUnit sqlite `:memory:`
- Dual SQL dialect helper: `App\Helpers\DbCompat` (MySQL / pgsql / sqlite test)

## Panels & auth guards

| Guard | Product |
|---|---|
| `web` | Digital Invoice (DI) |
| `pos` | NestPOS PRA |
| `fbrpos` | FBR POS |
| `health` | Nest ERPS / healthcare |
| `admin` | SaaS admin |
| `franchise` | Franchise |
| `agent` | Desktop Sync Agent API |

Guards are isolated — no cross-login except admin auto-detect rules documented in product map. Multi-tenant isolation via `company_id` + `CompanyIsolation` middleware (`company` alias). Branch context via `SetBranchContext`.

## NestPOS PRA — critical invariants

- **Sale screen:** `resources/views/pos/universal.blade.php` is the only live NestPOS sale screen.
- **Reporting-OFF finals:** regulator mode stays PRA/FBR with **NULL** `pra_status` — never rewrite to `'local'`. Provisionals use L-series; fiscal serials only when actually reported.
- **Tax rates:** read via `PosTaxRule::getRateForMethod()` / `effectiveRules()`; receipts/payloads use stored `tax_rate` snapshots.
- **Tax math:** all inclusive/exclusive math via `PosTaxMath`. Modes: `exclusive` / `inclusive` / `inclusive_card_save` (`Company::posTaxPricingMode()`).
- **Rounding:** NestPOS bill total = whole rupee on write paths; line items stay 2dp.
- **Numbering:** `PosLocalSeries` (L###), `PosFinalSeries` (P### / legacy fiscal forms), `OrderTokenService` (daily tokens), `PosBillNumberStyle` (`serial`/`token`/`daily` print styles). Generators use `DbCompat` and bypass `hide_archived` where required.
- **Offline PRA:** transport failures queue offline and auto-retry; Desktop Agent path for `fiscal_device` mode.
- **UI:** no blue on POS dashboard cards (teal brand); printed receipts ENGLISH only.

## Queue & scheduler

- Default queue env often `sync` locally; production uses database queue + `taxnest-queue` systemd unit.
- Schedule lives in `routes/console.php` (e.g. `SyncPosOfflineInvoicesJob` every 2 minutes, `pos:auto-dayclose` hourly).
- Deploys must restart `taxnest-queue` (see deploy scripts / rollback doc).

## PWA

- Shared `public/sw.js` — bump `CACHE_VERSION` on relevant frontend deploys.
- Sale-screen cache skip rules are intentional; do not “simplify” SW caching casually.

## Testing expectations

- **DEFAULT issue workflow:** `docs/ops/cloud-agent-issue-resolution.md` (reproduce → root-cause fix → re-test original failure → evidence → PR).
- **Issue → live:** `docs/ops/cloud-agent-issue-to-live.md` (auto-merge → Environment-gated deploy → `ci-live-verify.sh` → self-heal; max 3 iterations).
- Primary gate: `php artisan test` (sqlite `:memory:` via `phpunit.xml`).
- After NestPOS changes: targeted Feature tests under `tests/Feature/Pos*`.
- Local Chrome UI smoke (Cloud Agent): `docs/ops/cloud-agent-local-browser-qa.md` — loopback only; fictional `videodemo@nestpos.pk` shop; `DevStagingGuard` allows `taxnest_dev` \| `taxnest_staging`.
- Frontend: `npm ci` && `npm run build` when assets/Vite inputs change.
- Do not weaken tests to hide environment gaps. Do not claim FIXED / LIVE VERIFIED without re-testing the reported issue (live claims require Actions live-verify).

## Deploy / Git (summary)

- Feature work only on `cursor/*` branches; PR to `main`; do not commit on `main`.
- Production: GitHub Actions + Environment `production` approval + `PRODUCTION_SSH_PRIVATE_KEY` (dedicated deploy key). See `docs/ops/github-production-deploy.md`.
- Elaan / What’s New remains part of production deploy gates.
- Rollback: `deployment/ROLLBACK.md`.

## Related docs

- `replit.md` — product map & owner rules
- `CLOUD_AGENT_HANDOFF.md` — agent workflow & safety
- `AGENTS.md` — short agent entrypoint
- `docs/ops/cloud-agent-issue-resolution.md` — **DEFAULT** local issue-resolution policy
- `docs/ops/cloud-agent-issue-to-live.md` — issue → merge → deploy → live-verify → self-heal
- `docs/ops/cloud-agent-development.md` — how to bootstrap this environment
- `docs/ops/cloud-agent-local-browser-qa.md` — fail-closed Chrome UI smoke
