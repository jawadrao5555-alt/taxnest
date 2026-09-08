# Cursor TaxNest Master Architecture and Review Guideline

## Status, scope, and methodology

This is a read-only forensic reference for senior reviewers and implementation agents. It describes the repository as inspected, not an idealized replacement architecture. The review covered application code, routes, middleware, models, services, migrations, tests, operational scripts, release tooling, desktop/mobile clients, and existing architecture/operations documentation. Important claims from the parallel forensic reports were cross-checked against current source.

Evidence labels used below:

- **CODE TRUTH** — behavior directly evidenced by current tracked code.
- **DOCUMENTED INTENT** — an explicit repository rule or runbook statement that may still require runtime proof.
- **CONTRADICTION** — documentation and code, or two code/data contracts, disagree.
- **UNKNOWN** — static repository inspection cannot establish the answer.

All citations are workspace-relative and use current file line numbers. No production host, database, secret store, external API, GitHub setting, deployment, workflow, or live customer account was accessed or changed for this report. Sensitive values and personal data are intentionally omitted.

---

## 1. Complete application architecture

**CODE TRUTH.** TaxNest is a Laravel 12 monolith with PHP `^8.2` as the Composer constraint, server-rendered Blade, Vite assets, and separate companion processes/apps (`composer.json:8-21`, `composer.json:45-60`, `package.json:1-25`). The business platform shares a company/user/database foundation but exposes isolated product panels:

1. Digital Invoice (DI) on the `web` guard.
2. PRA POS / NestPOS on the `pos` guard.
3. FBR POS on the `fbrpos` guard.
4. Nest ERPS verticals, currently Healthcare under `/health`, on the `health` guard.
5. SaaS Admin and Franchise control planes on separate account providers/guards.
6. Desktop PRA Agent, realtime gateway, PWA service worker, and Android shells.

The primary HTTP route surface is `routes/web.php`; scheduled and CLI orchestration is in `routes/console.php`. A deliberate stateless machine-endpoint group removes session/cookie/CSRF/impersonation middleware for device/app/webhook traffic (`routes/web.php:1-31`). Models and services are not one unified fiscal domain: PRA POS uses `PosTransaction`, FBR POS uses `FbrPosTransaction`, and DI uses `Invoice`; their submission states and idempotency mechanisms differ (`app/Models/PosTransaction.php:10-57`, `app/Models/Invoice.php:9-50`).

**CODE TRUTH.** Nest ERPS is an umbrella discriminator, not a new billing product per vertical. Existing `health` product rows are migrated to `erps` plus a healthcare vertical while `/health`, the guard, and healthcare tables remain stable (`database/migrations/2026_10_20_100000_nest_erps_umbrella_product_line.php:59-81`). New verticals must flow through `App\Support\NestErps` and `App\Support\ProductCatalog`, not ad hoc product-type branches (`replit.md:73-76`).

**DOCUMENTED INTENT.** Root `replit.md` is the short architecture map; subsystem memory in `.agents/memory/` is mandatory pre-edit reading (`replit.md:3-6`). Root `README.md` remains a generic Laravel skeleton and is not an architectural authority (`README.md:1-59`).

## 2. Authentication guards and authorization

**CODE TRUTH.** Session guards are `web`, `pos`, `fbrpos`, `health`, `admin`, `franchise`, and `agent`. The first four use the shared `users` provider; Admin and Franchise use distinct models/providers (`config/auth.php:38-70`, `config/auth.php:90-103`). Middleware aliases bind `company`, role, POS, FBR POS, Health, Admin, and Franchise enforcement; impersonation, impersonated-write logging, consultant switching, and locale middleware are appended after session middleware (`bootstrap/app.php:65-103`).

- `CompanyIsolation` validates the shared-web user/company, active state, product boundary, and binds `currentCompanyId`; it is context plus admission control, not proof that every query is scoped (`app/Http/Middleware/CompanyIsolation.php:11-89`).
- `PosAuth`, `FbrPosAuth`, and `HealthAuth` are panel-specific. Healthcare rejects a missing company, wrong product, or missing health role, then applies the centralized path-to-capability map (`app/Http/Middleware/HealthAuth.php:45-69`, `app/Http/Middleware/HealthAuth.php:89-98`).
- Admin authorization begins with a separate `admin` guard. `AdminAuth` establishes authentication only; sensitive controller actions must additionally assert super-admin authority (`app/Http/Middleware/AdminAuth.php:8-17`, `app/Http/Controllers/SaasAdmin/AdminCompanyController.php:537-541`).
- Franchise authentication checks separate login and active status, but the complete franchise-to-company authorization matrix was not established (`app/Http/Middleware/FranchiseAuth.php:8-23`).

**Invariant:** never replace a panel guard with the default `auth()` call without proving that the default guard is intended. Generic services such as security logging that use `auth()->id()` may misattribute POS/FBR/Health actors (`app/Services/SecurityLogService.php:7-18`).

## 3. Company/tenant isolation

**CODE TRUTH.** Tenant identity is primarily `company_id`. `CompanyIsolation` binds the current company in the application container and rejects users without an eligible active company (`app/Http/Middleware/CompanyIsolation.php:11-89`). Many tenant models use `CompanyScope`; Admin routes intentionally operate without tenant scope, making cross-company access both necessary and security-sensitive (`app/Models/Scopes/CompanyScope.php:1-18`).

Isolation is layered, not automatic:

1. Correct guard and product admission.
2. `currentCompanyId` binding.
3. Model global scope where present.
4. Explicit `where('company_id', ...)` in controllers/services.
5. Ownership validation when accepting route IDs.

**Invariant:** an unscoped lookup such as `Model::find($id)` in a tenant controller is unsafe unless a model global scope is proven. Admin, consultant, import, queue, webhook, and machine endpoints deserve special scrutiny because some intentionally bypass normal middleware.

**UNKNOWN.** This review establishes the isolation mechanism but is not proof that every one of the hundreds of endpoints applies it correctly. Cursor must audit the route, controller, nested relation, queued job, export, and download path involved in each change.

## 4. Branch isolation

**CODE TRUTH.** `BranchContextService` is the shared branch authority. Owner/company admin/super admin may access all company branches; managers use their branch pivot; cashiers/employees are pinned to a default branch (`app/Services/BranchContextService.php:8-18`). The `all` sentinel intentionally resolves to no branch predicate for authorized company-wide views (`app/Services/BranchContextService.php:23-29`). Branchless archive/local-viewer audit accounts may intentionally remain company-wide (`app/Services/BranchContextService.php:31-43`).

Middleware resolves and shares `currentBranchId`, but filtering is opt-in: controllers/services must invoke `BranchContextService::applyToQuery()` or equivalent. There is no universal branch global scope. Missing branch schema deliberately degrades to single-company behavior for lean tests/schema drift (`app/Services/BranchContextService.php:51-71`). Healthcare reuses the same branch context (`app/Http/Middleware/HealthAuth.php:71-79`).

**Do not break:**

- Company catalog records may be company-wide while stock, bills, sessions, reports, riders, day close, and transfers are branch-bound.
- A bill's persisted branch must win over the viewer's current session when posting downstream inventory.
- `NULL branch_id` can mean legacy or organization-wide data; do not mass-assign it to the active branch without a migration policy.
- Every route-ID lookup must check both company and actor-accessible branch.

**KNOWN GAP.** Branch enforcement depends on caller correctness. Existing proposed work around archive filters, rider cash, restaurant/day-close, FBR day close, and concurrent transfers is consistent with this residual risk; task status alone is not evidence of a fix.

## 5. SaaS Admin / Super Admin architecture

**CODE TRUTH.** SaaS Admin has its own `admin_users` provider and `admin` guard (`config/auth.php:59-65`, `config/auth.php:95-98`). Admin routes intentionally need cross-company visibility, so tenant global-scope assumptions do not apply. The SaaS layer manages companies, plans, approvals, grants, usage, announcements, app updates, consultants, and operational visibility (`replit.md:26-29`).

Super-admin-only mutations must be enforced in controllers/policies, not inferred from an Admin login. Audit logs are immutable at model level: create computes a SHA-256 row hash, and update/delete throw (`app/Models/AuditLog.php:7-54`). This is row-level tamper evidence, not a chain: the hash omits some contextual fields and does not link the preceding record.

**DOCUMENTED INTENT.** Grant types are Lifetime and Temporary only; retired grace/standalone rows may be honored for compatibility but their controls must not be restored (`replit.md:26-29`). POS billing is annual-only; DI has its separate billing-cycle rules (`replit.md:16`, `replit.md:55`).

## 6. View as Company vs Manage as Company

**CODE TRUTH.** Admin impersonation is POST-only and controller-gated by `assertSuperAdmin()` (`routes/web.php:1587-1590`, `app/Http/Controllers/SaasAdmin/AdminCompanyController.php:537-541`). The controller:

- accepts only active companies;
- chooses the tenant guard from product/ERPS registry;
- selects an active company admin;
- preserves the Admin guard while logging into the tenant guard;
- regenerates the session;
- defaults every mode except literal `full` to read-only (`app/Http/Controllers/SaasAdmin/AdminCompanyController.php:541-615`).

**View as Company** blocks non-GET/HEAD/OPTIONS requests. **Manage as Company** permits real writes as the selected company admin and adds audit coverage; it is not a sandbox or restricted capability set. Identity-changing paths remain blocked, lock is a one-way downgrade, and exit logs out only the tenant guard (`app/Http/Middleware/ReadOnlyImpersonation.php:30-115`, `app/Http/Controllers/SaasAdmin/AdminCompanyController.php:639-688`).

**CONTRADICTION.** Comments say “all state-changing requests” are blocked, but enforcement is HTTP-method based. Any GET endpoint with side effects would bypass read-only protection (`app/Http/Middleware/ReadOnlyImpersonation.php:67-115`).

**Invariant:** never use Manage as Company casually. It executes production-equivalent tenant writes and magnifies any missing company/branch scope.

## 7. POS / NestPOS architecture

**CODE TRUTH.** NestPOS is the PRA-oriented product on the `pos` guard with its own routes, layouts, roles, controllers, transaction model, product catalog, restaurant workflows, local bills, day close, reporting, inventory, devices, and receipt preferences. The live sale screen is `resources/views/pos/universal.blade.php`; legacy screens are not alternative implementation targets (`replit.md:59-60`).

`PosTransaction` is company-scoped and hides archived rows by default; it stores branch, business date, invoice mode, tax snapshots, PRA state/number, submission hash, offline UUID, and receipt metadata (`app/Models/PosTransaction.php:10-79`). Archived/local-bill tools must intentionally bypass `hide_archived` and then apply company/branch filters.

Roles include POS admin/manager, cashier, waiter, kitchen, delivery/rider, and narrow audit portals. POS manager is admin-equivalent for core administration and counts against the user limit; operational restaurant roles are limit-exempt but must remain confined (`replit.md:55-56`). Feature gates should use services such as `PosFeatureService`/`PosAccessService`, not hard-coded plan IDs.

**DOCUMENTED INTENT.** Current owner focus is NestPOS PRA stabilization. DI/FBR proposals or changes are not authorized unless the owner explicitly reopens those streams (`replit.md:92-95`). Every POS page needs an in-page back affordance; receipts are English-only; admin UI is English-only; customer-facing POS may use Roman Urdu (`replit.md:15`, `replit.md:44`).

## 8. PRA architecture

**CODE TRUTH.** PRA configuration includes environment, POS identity, token, endpoint selection, and connection mode. `PraIntegrationService` resolves sandbox/production URLs and `cloud` versus `fiscal_device` behavior (`database/migrations/2026_03_05_000002_add_pra_fields_to_companies.php:9-21`, `app/Services/PraIntegrationService.php:23-53`, `app/Services/PraIntegrationService.php:324-335`).

Submission defenses reject already-numbered/local-only/dependency-blocked rows and duplicate submission hashes. Fiscal-device mode queues work for the desktop agent instead of server-direct submission. Direct transport failures become retryable offline state, while agent-handled failures remain pending. A success response without a fiscal number is failure, and the `PosTransaction` model provides a second backstop against submitted-without-number state (`app/Services/PraIntegrationService.php:268-335`, `app/Services/PraIntegrationService.php:507-571`, `app/Models/PosTransaction.php:81-123`).

PRA payload generation uses stored transaction tax snapshots, zero-rates exempt lines, allocates discounts, emits quantity-multiplied line totals, and reconciles paisa drift to the stored whole-rupee header. Returns use the return invoice type and parent reference (`app/Services/PraIntegrationService.php:65-244`).

**Invariant:** PRA tax is submitted in full even when receipt settings hide tax. Connection mode, reporting state, retry state, and receipt display are independent concerns.

## 9. FBR POS / Digital Invoice architecture

**CODE TRUTH.** FBR POS and DI are distinct systems:

- FBR POS uses the `fbrpos` guard and `FbrPosTransaction` lifecycle.
- DI uses the `web` guard, `Invoice`, FBR compliance fields/logs, PDF/share/import services, and submission via `FbrService`.
- Their invoice IDs, statuses, payloads, hashes, receipt/PDF rules, and offline behavior must not be mixed.

FBR POS provisional promotion is atomic: reporting ON becomes `invoice_mode=fbr, fbr_status=pending`; reporting OFF becomes `invoice_mode=fbr, fbr_status=NULL`, a final invoice that must not submit. Day-close uses the same split and retains retryable failures (`app/Http/Controllers/FbrPosController.php:373-420`, `app/Http/Controllers/FbrPosController.php:867-929`).

DI submission performs preflight checks, locks the row, repeats the guard, and stores an idempotency hash before payload/API work (`app/Services/FbrService.php:1233-1301`). PDF generation is centralized in `InvoicePdfService`; regulatory QR is generated only when an FBR number exists (`app/Services/InvoicePdfService.php:7-16`, `app/Services/InvoicePdfService.php:37-121`).

**CONTRADICTION.** `InvoicePdfService` is FBR-number-only for QR, while `Invoice::qr_image_url` can fall back to stored `qr_data` (`app/Models/Invoice.php:92-105`). Inspect the concrete renderer before changing QR behavior.

**DOCUMENTED INTENT.** FBR's classic create view is dead; universal is the only sale screen, while shim pins must remain (`replit.md:67-70`). DI and POS data are fully isolated (`replit.md:14`).

## 10. Desktop PRA agent architecture

**CODE TRUTH.** `pra-agent/` is an Electron/Node coordinator for PRA fiscal-device submission, server polling/callbacks, printing, caller events, and self-update. It polls invoices about every five seconds and heartbeats every thirty seconds, with overlap guards (`pra-agent/src/agent.js:224-253`, `pra-agent/src/agent.js:332-344`, `pra-agent/src/agent.js:488-513`).

Callback retries persist under the user's home directory and deduplicate by transaction ID, but are dropped after 50 failures; durability is bounded (`pra-agent/src/agent.js:119-203`). Fiscal-device PRA submission expects local IMS and treats transport failure separately from regulator rejection; success requires Code 100 and an invoice number (`pra-agent/src/agent.js:381-445`).

Printer queueing serializes the same physical-printer lane, allows different printer lanes concurrently, and applies a KOT-wave barrier (`pra-agent/src/printer-queue.js:3-19`, `pra-agent/src/printer-queue.js:49-61`, `pra-agent/src/printer-queue.js:99-155`). Missing printer identity collapses work into one default lane.

The realtime gateway authenticates with Laravel, validates canonical company IDs, scopes wake events by company/device, caps pending/rate state, and ignores client messages (`agent-realtime-gateway/gateway.js:14-32`, `agent-realtime-gateway/gateway.js:76-91`, `agent-realtime-gateway/gateway.js:140-194`).

**SECURITY FINDING.** Legacy `pra-proxy/` contains a hard-coded shared value, accepts caller-selected target URLs, and disables TLS verification; `pra-relay/` improves token sourcing but still accepts target URLs and disables TLS verification (`pra-proxy/proxy.php:12-42`, `pra-relay/relay.php:23-64`). Do not deploy, expose, or “fix forward” either without explicit security design and owner approval.

## 11. PWA/mobile architecture

**CODE TRUTH.** One service worker, `public/sw.js`, owns versioned static/runtime caches. Authenticated sale/table/waiter caches use specialized strategies and structural validation; logout/login messages purge authenticated caches (`public/sw.js:1-34`, `public/sw.js:71-103`, `public/sw.js:153-169`, `public/sw.js:229-305`). API, auth, payment-sensitive, inventory/pharmacy, and settings paths are excluded from generic runtime caching (`public/sw.js:308-340`). Navigation otherwise uses network-first with cache/offline fallback; Vite hashed assets are cache-first (`public/sw.js:342-365`).

The worker intentionally does not auto-activate a waiting version, avoiding mid-sale reloads (`public/sw.js:127-138`). It is not a general transaction queue; business offline replay lives in sale-page/local-core/agent code.

Android projects include Caller, POS, FBR POS, DI, Rider, and Waiter shells. Release/signing/Firebase material is external and must never be committed. APK release docs require downloaded-hosted-byte verification and Play permission guards, but these are manual procedures, not CI proof (`scripts/play-build-check.sh:1-25`, `scripts/apk-release-check.sh:18-45`).

**CONTRADICTION/UNKNOWN.** Release documents contain staged-versus-canonical version differences, and repository state cannot prove the currently hosted APK bytes, signature, Play status, or tester completion (`caller-app/RELEASE.md:7-20`, `docs/play/submission-checklist.md:105-166`).

## 12. Inventory / Products / Ingredients / Recipes architecture

**CODE TRUTH.** Product/recipe definitions are company-wide; stock is intended to be branch-scoped. POS inventory pages call `ensureInventoryEnabled`, and current code attempts to heal legacy `NULL` branch rows before operations (`app/Http/Controllers/PosInventoryController.php:19-33`, `app/Http/Controllers/PosInventoryController.php:111-120`).

`BranchStockService` defines stock ownership: no configured branches uses `branch_id=NULL`; branch-enabled companies use a concrete branch, and bill branch wins over session (`app/Services/BranchStockService.php:19-31`, `app/Services/BranchStockService.php:199-287`). `pos_products.stock_quantity` is a company-level mirror of branch stock plus in-transit quantities, not the preferred branch truth (`app/Services/BranchStockService.php:415-440`).

`InventoryService` validates canonical sale snapshots, binds them to company, makes posting immutable/idempotent, and expands recipe/deal components. Sales may deliberately make stock negative (`app/Services/InventoryService.php:100-373`). Healthcare pharmacy documents shared `inventory_stocks` as quantity truth and pharmacy batches as detail whose sum must reconcile to shared stock (`database/migrations/2026_09_06_100000_create_health_pharmacy_module.php:8-29`).

**CONTRADICTION.** Original inventory foreign keys target generic `products`, while current POS controllers/services use `PosProduct` IDs; `InventoryAdjustment` exposes both relations and acknowledges overloaded identity (`database/migrations/2026_02_17_150000_create_inventory_module.php:32-65`, `app/Models/InventoryAdjustment.php:36-46`). Do not alter these keys without verifying actual schema/data compatibility.

## 13. Stock-In / Adjustment / Transfer / Stock Check rules

**CODE TRUTH.**

- Stock-in and sales must post movements through inventory/branch-stock authorities; do not directly edit mirrors.
- Adjustment `add/remove/set` writes movement deltas. Removal clamps at zero, while the human adjustment row records requested quantity, so requested and actual movement can differ (`app/Http/Controllers/PosInventoryController.php:297-355`).
- Transfers validate source availability and actor access to both endpoints. New schema posts source OUT/in-transit then explicit receive; schema without transfer columns falls back to immediate paired transfer (`app/Http/Controllers/PosInventoryController.php:479-600`).
- Stock checks freeze expected quantity when opened, then post variance delta—not absolute count—under a locked header for idempotency (`app/Services/StockCheckService.php:153-188`, `app/Services/StockCheckService.php:384-436`).

**KNOWN RISKS.** Adjustment rows omit branch identity; transfer `reference_id` means destination on new OUT but source on legacy IN; receive/cancel locking was not fully established (`app/Models/InventoryAdjustment.php:9-19`, `app/Http/Controllers/PosInventoryController.php:537-594`). One-open-stock-check enforcement has no matching unique database constraint, so concurrent opens may race (`app/Services/StockCheckService.php:159-172`, `database/migrations/2026_10_01_000000_create_stock_checks.php:23-49`).

## 14. Tax calculation and fiscal invariants

**CODE TRUTH.** POS tax rates are method-sensitive and should be resolved through `PosTaxRule`, not literals. Tax pricing has three modes: exclusive, inclusive, and inclusive-card-save. `PosTaxMath` calculates taxable share, included tax, card savings, and line tax; POS header total is whole-rupee while line values remain two-decimal (`app/Services/PosTaxMath.php:5-97`, `replit.md:51-53`).

Tax fields are snapshots. Payloads, edits, promotion, returns, reports, and receipts must read the transaction's persisted `tax_rate`, `tax_inclusive`, and `tax_menu_rate`, not today's company setting. Exempt items remain zero-rate. Discounts must be allocated consistently and regulator payload totals must reconcile to the stored header (`app/Services/PraIntegrationService.php:65-217`).

Restaurant preview and settlement are intended to share `RestaurantOrderPaymentCalculator`, including payment-method normalization and exempt-item handling (`app/Services/RestaurantOrderPaymentCalculator.php:10-76`). Add regression tests before changing either path.

**Invariant:** PRA POS rounds totals to whole rupees on every write path. FBR POS and DI retain decimals (`replit.md:53`).

## 15. Invoice finalization rules

Finalization is an irreversible business transition, not a UI label.

**CODE TRUTH.** FBR POS promotion atomically claims only eligible local rows and chooses pending submission versus reporting-OFF final state inside that transition (`app/Http/Controllers/FbrPosController.php:373-420`). Day close repeats the same three-way logic and does not print receipts as a side effect (`app/Http/Controllers/FbrPosController.php:867-929`).

For PRA, fiscal serials belong only to bills actually reported to PRA. Provisionals and reporting-OFF finals use the L-series. Serial generators must compare numeric serial components and intentionally bypass archived filtering (`replit.md:48-50`). Inventory, ledger, receipt, and fiscal submission must consume one persisted final transaction snapshot and be retry/idempotency safe.

**UNKNOWN.** Static review did not fully prove every PRA creation, restaurant settlement, offline replay, return, promotion, and day-close path reaches the same finalization authority. Investigate all writers before changing status semantics.

## 16. Reporting-OFF rules

**DOCUMENTED INTENT and established FBR code truth.** Reporting OFF does not mean “local mode” for a final bill:

- PRA final: regulator mode `pra` plus `pra_status=NULL`.
- FBR final: regulator mode `fbr` plus `fbr_status=NULL`.
- Provisional remains local until promoted.

FBR promotion/day-close implements this explicitly (`app/Http/Controllers/FbrPosController.php:373-420`, `app/Http/Controllers/FbrPosController.php:867-929`). Local Bills Portal recognizes final reporting-OFF variants and applies branch scoping (`app/Http/Controllers/PosLocalBillsController.php:24-64`).

Do not submit reporting-OFF finals later merely because reporting was switched on. Do not apply FBR's reporting fee while FBR reporting is OFF. Do not retroactively rewrite prior snapshots/settings.

**UNKNOWN.** Equivalent PRA behavior was not fully proven across every writer, and whether switching PRA OFF affects already-pending rows is unresolved. Treat this as a required test matrix, not permission to infer behavior.

## 17. Printer/device architecture

Printing spans browser preferences, server print jobs, desktop polling/realtime wake, and physical devices. POS routes expose print-job creation/test, telemetry, prompt, auto-print, and printer settings (`routes/web.php:1079-1094`, `routes/web.php:1149`). Restaurant routes/services add station/KOT routing and permission gates.

**CODE TRUTH.** Desktop lanes preserve order per target printer and allow cross-printer concurrency; KOT waves add a barrier (`pra-agent/src/printer-queue.js:99-155`). Printer identity changes (for example OS queue renaming) are operationally significant. Browser print is not proof of silent-agent print, and a generated PDF is not proof of a correct 80mm/A4 physical artifact.

Biometric ADMS, caller devices, riders, Android shells, and realtime sockets are machine clients with dedicated endpoint/auth/rate-limit behavior (`routes/web.php:330-343`, `routes/web.php:920-1004`). Device secrets/tokens must never enter logs, fixtures, screenshots, commits, or PR text.

## 18. Queue/worker/scheduler architecture

**CODE TRUTH.** Jobs cover fiscal retries/submission, offline sync, imports, AI/image work, PDF/ZIP/audit packs, catalogue sync, compliance, token/trial checks, and heartbeat (`app/Jobs/`). Production architecture expects a database queue and a persistent supervised worker, restarted after deploy. However queue config defaults to `sync` if environment is absent (`config/queue.php:16`), so environment is part of the runtime contract.

The scheduler expects an external once-per-minute `schedule:run`. It schedules scheduler and queue heartbeats, sync/retry tasks, auto day-close, healthcare daily charges, logging/MySQL/Cloudflare/uptime guards, reminders, pruning, and catalogue tasks (`routes/console.php:16-81`, `routes/console.php:129-253`). `withoutOverlapping()` is used where duplicate execution is dangerous.

**UNKNOWN.** Repository code cannot prove the live cron, queue connection, worker unit, failed-job alerting, or per-queue capacity. Tests use synchronous queues, so they do not establish worker serialization/retry behavior (`phpunit.xml:17-38`).

## 19. Deployment architecture

**CODE TRUTH.** Production is a canonical Islamabad VPS deployment, not the retired cPanel host. Deployment targeting is pinned to a known-hosts file and approved-IP allowlist; anything else is refused (`scripts/lib/live-host.sh:17-36`, `scripts/lib/live-host.sh:47-86`). Exact host/user/address values are intentionally not reproduced here.

`scripts/deploy-live.sh` is the release orchestrator: remote lock, maintenance page, pull, optional Composer work, settings snapshot, forced migrations, cache/config/route/view rebuild, ownership/security repair, PHP-FPM reload/freshness proof, queue restart/proof, optional realtime restart, and application-up (`scripts/deploy-live.sh:121-190`, `scripts/deploy-live.sh:197-288`).

A Git push does not deploy. `scripts/check-live-deploy.sh` compares live revision/cache state, while operations docs require deliberate deployment (`scripts/check-live-deploy.sh:15-19`, `docs/ops/healthcare-pilot-runbook.md:38-61`).

**CONTRADICTION.** Generic rollback docs discuss migration rollback, while Healthcare's additive schema policy requires code-only rollback and leaving schema ahead (`deployment/ROLLBACK.md:67-85`, `docs/ops/healthcare-pilot-runbook.md:156-168`).

## 20. GitHub Actions architecture

**CODE TRUTH.** The only inspected workflow is `.github/workflows/build-agent.yml`. It runs on `v2026*` tags or manual dispatch, installs Node on Windows, builds the PRA Agent, zips the portable directory, and publishes executable/ZIP assets to a GitHub Release with `contents: write` (`.github/workflows/build-agent.yml:1-39`).

It does **not** run Laravel tests, browser QA, Android release guards, deploy the web application, or verify production. Because the desktop agent polls the latest GitHub Release, APKs must not be published there; Android release docs explicitly preserve this namespace separation (`rider-app/RELEASE.md:177-182`, `pra-agent/main.js:43-50`).

**UNKNOWN.** Repository inspection cannot establish branch protections, required reviews, environment rules, organization secrets, or external CI.

## 21. Production safety gates

**CODE TRUTH.** Deployment includes HEAD/preflight checks, Blade script escaping, POS white-screen and service-worker checks, maintenance/locking, cache freshness, worker freshness, approved-target checks, and a required published What's New/AppUpdate marker with emergency bypass (`scripts/deploy-live.sh:20-30`, `scripts/deploy-live.sh:371-575`).

**KNOWN GAPS.**

- Live screen smoke is warning-only and cannot fail deployment (`scripts/deploy-live.sh:68-118`).
- A failed settings baseline disables that regression guard; the post-check occurs after `artisan up` (`scripts/deploy-live.sh:167-181`, `scripts/deploy-live.sh:273-288`).
- Logging-health checks during deploy are warning-only (`scripts/deploy-live.sh:313-368`).
- Duplicate failure-code mapping can mislabel a realtime failure (`scripts/deploy-live.sh:295-307`).
- No GitHub workflow invokes these gates.

Safety therefore requires human go/no-go judgment, not merely a zero exit code.

## 22. Live Ops architecture

Operational controls include queue/scheduler heartbeats, uptime probing through CDN and origin, Cloudflare setting repair, log-health checks, MySQL connection headroom, agent-offline alerts, failed fiscal queue alerts, and Admin banners/emails (`routes/console.php:175-253`).

Healthcare readiness checks actual scheduler-written timestamps rather than trusting schedule configuration (`docs/ops/healthcare-pilot-runbook.md:67-85`, `app/Console/Commands/HealthPilotReadiness.php:230`). Public debug PHP files are prohibited because they bypass route guards (`docs/ops/healthcare-pilot-runbook.md:140-152`).

**CONTRADICTION / critical gap.** The runbook says daily `db:backup`, but code defines `backup:database`, and no scheduler entry invokes either (`docs/ops/healthcare-pilot-runbook.md:101-120`, `app/Console/Commands/DatabaseBackup.php:8-12`, `routes/console.php:129-253`). The command itself has MySQL/PostgreSQL fallback inconsistencies and does not prove off-host encrypted backup or restore testing (`app/Console/Commands/DatabaseBackup.php:24-85`). Backup/restore readiness is therefore **UNKNOWN**, not safely inferred from docs.

## 23. Testing requirements

The minimum change-specific test plan is:

1. Read the relevant memory and existing tests.
2. Reproduce the failure with the smallest failing automated test.
3. Test authorization denial, company isolation, branch isolation, and wrong-product guard.
4. Test feature OFF/ON, old/null data, saved setting preservation, and partially migrated schema where supported.
5. For money/fiscal work, test payment methods, exempt/taxable mixes, discounts, rounding, retry, duplicate/concurrent submission, reporting OFF/ON, returns, and persisted snapshots.
6. For stock, test branch ownership, negative-sale policy, concurrent writes, idempotent replay, transfer receive/cancel, and mirror reconciliation.
7. Run targeted tests, then the complete relevant suite; record skips and failures honestly.

Composer's canonical test command clears config then runs Artisan tests (`composer.json:58-60`). PHPUnit includes Unit and Feature suites but forces SQLite memory, sync queue, array session/cache/mail, and disables production observability packages (`phpunit.xml:7-38`). This is not production parity. Environment-dependent tests may skip for DomPDF interception, PDF tools, or MySQL (`tests/Feature/PosSplitPaymentPdfReceiptTest.php:381`, `tests/Feature/AiInvoiceReaderTest.php:637`, `tests/Feature/FbrPosKotReprintPermissionTest.php:582-601`).

Never describe a skipped test as a pass.

## 24. Browser QA requirements

No repository-wide browser QA command or CI matrix exists. `package.json` provides Vite build/dev only, despite Playwright Core being available (`package.json:1-25`). QA is distributed among scripts such as `scripts/mobile-check.cjs`, `scripts/pwa-refresh-check.mjs`, `scripts/live-screen-smoke.sh`, and feature-specific checks.

For changed UI, test:

- desktop and mobile widths; keyboard-only operation where applicable;
- every affected role/guard and denied role;
- branch switch/all-branch behavior;
- light/dark/theme styles;
- fresh worker and old service-worker/cache upgrade;
- online, network interruption, retry, duplicate click, back/reload;
- physical 80mm/A4/KOT printing when output changed;
- authenticated cache purge across logout/login;
- no stale settings form from service worker.

Dev HTTP checks require the documented MySQL readiness probe first; a DB-down page is not an application regression (`replit.md:86-90`). Live QA is a separate owner-authorized phase and must never be performed merely because local browser QA passed.

## 25. Existing known invariants and “do not break” rules

1. DI, PRA POS, FBR POS, and Healthcare guards/data/lifecycles remain isolated.
2. Generic fixes apply to every applicable company, but preserve existing company/branch/user settings and legacy data (`replit.md:8-10`).
3. Company and branch predicates apply to every read, write, export, queued job, nested ID, and device callback unless explicitly company-wide.
4. Reporting-OFF finals retain regulator mode with `NULL` status.
5. PRA serials are fiscal only after actual PRA reporting; local/provisional finals use L-series.
6. Tax rates/pricing/rounding come from shared authorities and persisted snapshots, never hard-coded controller/UI math.
7. PRA totals are whole rupees; line values stay two decimals.
8. Receipt visibility never changes regulator payload tax.
9. Sales may drive stock negative; transfers may not.
10. Catalog/recipes are company-wide; stock is branch-scoped.
11. Audit/ledger history is append-only; correct by reversal/void, not destructive edits.
12. Receipt and printed content is English-only.
13. The universal PRA and FBR sale screens are the live screens.
14. Standalone POS is retired; do not restore it.
15. POS visual rules prohibit blue dashboard cards and colored glow decorations (`replit.md:20-24`).
16. Any loose public-asset edit requires cache-version/query-string handling (`replit.md:80-84`).
17. POS deploys require What's New/AppUpdate and Madadgar KB synchronization (`replit.md:38-44`).
18. Secrets, QA credentials, real customer data, and signing material never enter tracked output.

## 26. Known legacy/dead/orphaned code

Treat “legacy” as compatibility-sensitive, not permission to delete:

- PRA legacy sale views are dead; `pos/universal.blade.php` is live (`replit.md:59-60`).
- FBR classic create is dead but intentional shim pins must remain (`replit.md:67-70`).
- Standalone POS surfaces are retired; legacy stored rows may remain (`replit.md:57`).
- `InventoryService::addStock()` remains a nullable-branch legacy path beside `BranchStockService` (`app/Services/InventoryService.php:432-471`).
- Transfer behavior varies based on schema-column presence (`app/Http/Controllers/PosInventoryController.php:87-107`, `app/Http/Controllers/PosInventoryController.php:562-595`).
- `pra-agent/dist`, root/source printer copies, and proxy/relay implementations have unresolved runtime status.
- Numerous receipt/printer Blade fallbacks intentionally support old records/settings (`resources/views/fbr-pos/receipt.blade.php:302-319`, `resources/views/pos/receipts/receipt_80mm.blade.php:131`).
- Root `README.md` is generic and stale as TaxNest documentation (`README.md:1-59`).

Require route/import/build/reference analysis before deleting any candidate.

## 27. Known security-sensitive areas

- Guard and company/branch isolation, especially admin bypasses and nested route IDs.
- Manage-as full access and consultant identity swapping.
- Machine endpoints excluded from CSRF/session middleware (`bootstrap/app.php:55-64`, `routes/web.php:1-31`).
- PRA/FBR tokens, SMTP, Firebase, signing keys, SSH material, QA credentials, and customer identifiers.
- Legacy PRA proxy/relay SSRF and TLS-verification risks.
- Patient confidentiality, private attachments, consent, and department scope.
- Audit completeness and guard-aware actor attribution.
- Webhooks, biometric/caller/rider APIs, device revocation, replay/idempotency, and rate limiting.
- Public debug files and downloadable exports/backups.
- Repository `backup.sql` appears to contain real-looking records; no full data-scrub audit was performed. Do not quote, copy, or expand it.

Healthcare department scope intentionally catches failures and can return empty visibility; its process cache is keyed by user and may need explicit invalidation after authorization changes (`app/Services/HealthScopeService.php:91-180`). An unmapped healthcare path requires no capability beyond panel admission, so new routes must be added to the capability map when sensitive (`app/Http/Middleware/HealthAuth.php:89-95`).

## 28. Known unresolved gaps

1. Exhaustive endpoint-by-endpoint company/branch isolation.
2. Franchise authorization beyond active login.
3. PRA reporting-OFF behavior across every creation/promotion/offline/restaurant path.
4. External regulator idempotency after network timeout.
5. End-to-end offline replay and offline UUID uniqueness.
6. Every restaurant settlement path's use of shared calculator/submission guards.
7. Actual production inventory FK identity and migration state.
8. Transfer receive/cancel concurrency and stock-check open uniqueness.
9. Ingredient global-versus-branch reconciliation.
10. Customer-ledger concurrent `balance_after` lost updates and arbitrary NTN acceptance (`app/Http/Controllers/CustomerLedgerController.php:51-117`).
11. Current queue/cron/mail/Firebase/API configuration and worker capacity.
12. Live schema/migration ledger and partially applied migration effects.
13. Effective production backup/offsite/restore proof.
14. Current hosted APK/agent versions, signatures, and Play status.
15. PWA cache/logout race and offline local-core end-to-end tests.
16. Generic versus healthcare audit completeness.
17. Full test-suite result and complete browser matrix.
18. Whether proxy/relay endpoints are deployed.

These are investigation requirements, not claims that a defect exists.

## 29. How an agent must investigate an issue before changing code

1. Restate the observed symptom, affected product, role, company/branch context, and expected invariant.
2. Read `replit.md`, the matching `.agents/memory/` topics, recent task/QA docs, and existing tests.
3. Trace route → middleware group → guard → controller → validation/policy → service → model scopes → migration/schema → queue/device/client → view.
4. Enumerate every writer and reader of the affected fields with `rg`; include imports, jobs, day close, returns, reports, exports, APIs, and legacy fallbacks.
5. Separate **CODE TRUTH**, **DOCUMENTED INTENT**, **CONTRADICTION**, and **UNKNOWN**.
6. Build company/branch/role/feature/status state tables before editing.
7. Check saved-setting and legacy/null behavior. Capture before-state for any migration or update.
8. Identify concurrency, retry, idempotency, rounding, timezone/business-date, and service-worker implications.
9. Confirm scope with the owner. Task-feed status, comments, or prior merged work do not authorize a new stream.
10. Propose the smallest root-cause change and tests; do not opportunistically refactor adjacent products.

## 30. How an agent must reproduce and test an issue

Reproduction must use authentic application behavior, never production data copied into fixtures.

1. Establish the correct local DB readiness and guard.
2. Create minimal isolated test records with explicit company, branch, role, product, feature setting, and legacy/null state.
3. Reproduce first; record exact route/action and persisted before/after state.
4. Add a failing automated regression at the lowest meaningful layer plus feature coverage for middleware/scoping.
5. Exercise OFF/ON, old/new data, allowed/denied role, own/other company, own/other branch, all-branch, duplicate/retry, and concurrency where relevant.
6. For fiscal/money/stock changes, assert persisted snapshots and totals—not only rendered text.
7. Run targeted tests, adjacent subsystem tests, then the full applicable suite. Record exact skips.
8. Run browser/device/print checks appropriate to the change. Do not infer physical-device success from unit tests.
9. Do not use live production as the reproduction environment without explicit owner-approved operational procedure.

## 31. How an agent must handle conflicts with existing code

When code and docs disagree:

1. Do not silently “align” code to a document.
2. Establish the actual reachable runtime path and persisted production-compatible contract.
3. Report both statements with citations and label the contradiction.
4. Prefer preserving data and behavior until owner/product intent is explicit.
5. Add characterization tests around current behavior before changing it.
6. Use compatibility reads and additive migrations where legacy rows exist; never reset chosen settings.
7. If two authorities exist, identify callers and converge only through a separately approved migration/refactor.
8. Stop and mark **UNKNOWN** when resolution requires live schema, secret configuration, regulator behavior, or owner policy.

Runtime code is evidence of behavior, not automatically the desired policy; documentation is evidence of intent, not automatically runtime truth.

## 32. How an agent must prepare a PR

**DOCUMENTED INTENT.** Use a clean branch per task, targeted/full tests, push the feature branch, and open a PR to `main`; never merge/deploy without owner authorization (`CLOUD_AGENT_HANDOFF.md:13-37`).

The PR must include:

- precise problem/root cause and product scope;
- files and architecture paths changed;
- before/after behavior and invariants preserved;
- company/branch/role/feature/status matrix;
- migration idempotency, rollback/forward-compatibility, and data-preservation analysis;
- security/privacy and queue/scheduler/PWA/device impact;
- exact automated/browser/device commands and results, including skips;
- screenshots only with scrubbed synthetic data;
- deployment prerequisites and post-deploy checks;
- explicit unresolved unknowns and owner decisions needed.

Never include `.env`, `.local`, credentials, tokens, private customer/patient data, signing files, database dumps, generated private artifacts, or production screenshots containing personal data.

## 33. How an agent must verify production after deployment

This phase requires explicit owner authorization and an approved operator; Cursor must prepare the checklist but must not self-deploy.

1. Confirm approved target, clean intended revision, release marker/AppUpdate, backup and restore readiness.
2. Run official preflight/deploy tooling; do not substitute raw SSH commands.
3. Verify live HEAD, route/config/view cache freshness, FPM, queue worker freshness, scheduler heartbeat, queue heartbeat, failed jobs, disk/log health, and DB connection headroom.
4. Verify settings-preservation snapshots and migration state.
5. Execute read-only smoke checks for affected panels, roles, company/branch boundaries, and feature OFF/ON.
6. Where authorized, perform a controlled synthetic transaction and verify persisted state, regulator/queue behavior, receipt, inventory, ledger, and retry idempotency.
7. Perform real printer/device/PWA/Android checks when those paths changed.
8. Observe logs/audit/alerts for an agreed window and document rollback/go-forward decision.
9. For additive Healthcare migrations, rollback code rather than destructively rolling schema back (`docs/ops/healthcare-pilot-runbook.md:156-168`).

Do not claim success from deploy exit alone because important smoke/log/settings checks are warning-only.

## 34. What must NEVER be changed without explicit owner approval

- Production data, schema, deployment, workflows, cron/systemd, DNS/CDN, mail, backups, or secrets.
- Product focus: DI/FBR/Healthcare work while owner direction limits work to NestPOS PRA stabilization (`replit.md:92-95`).
- Guard/provider/session boundaries or company/branch isolation semantics.
- Fiscal status meanings, reporting-OFF rules, invoice numbering/serials, rounding, tax snapshots, regulator payload/URLs/tokens, or retry/idempotency rules.
- DI/POS data isolation or stored product discriminators/ERPS registry.
- Pricing, package limits, annual-only POS billing, grants, approval workflow, or company feature settings.
- Existing customer settings, staff permissions, branches, saved preferences, legacy data, or real-account identifiers.
- Audit/history immutability, ledger corrections, patient confidentiality, consent, or private storage policy.
- Standalone POS retirement, universal sale-screen authority, or intentional legacy shims.
- Desktop executable identity/distribution channel, GitHub latest-release behavior, APK package/signing identity, Play permissions, or Firebase configuration.
- Printer/KOT ordering and physical output language.
- PWA cache/update semantics or public asset cache versions.
- Production host allowlist/pinned host identity or retired-host protections.
- Deletion of legacy/dead-looking files without complete reference/runtime proof.

---

## Architecture areas inspected

- Laravel bootstrap, routing, middleware, guards, providers, exception/runtime structure.
- DI, PRA POS, FBR POS, SaaS Admin, Franchise, consultant switching, Nest ERPS/Healthcare.
- Company and branch context/scoping.
- Fiscal tax, finalization, reporting-OFF, submission, PDF/receipt, offline/retry behavior.
- Products, ingredients, recipes, branch stock, movements, adjustments, transfers, stock checks, customer/supplier ledgers.
- Restaurant/KOT/printing/device, Desktop PRA Agent, realtime gateway, proxy/relay.
- PWA/service worker and Android release architecture.
- Queues, scheduler, mail, observability, deployment, rollback, backup, and GitHub Actions.
- PHPUnit, browser/device scripts, QA/runbook evidence, legacy/dead-code declarations.

## Files/directories inspected

Primary evidence came from:

- `replit.md`, `README.md`, `CLOUD_AGENT_HANDOFF.md`
- `bootstrap/app.php`, `config/`, `routes/`
- `app/Http/Middleware/`, `app/Http/Controllers/`, `app/Models/`, `app/Services/`, `app/Jobs/`, `app/Support/`, `app/Console/`
- `database/migrations/`
- `resources/views/`, `public/sw.js`
- `tests/Feature/`, `tests/Unit/`, `phpunit.xml`, `composer.json`, `package.json`
- `.agents/memory/`
- `scripts/`, `scripts/lib/`, `scripts/migration/`, `deployment/`, `docs/ops/`, `docs/qa/`, `docs/play/`
- `.github/workflows/build-agent.yml`
- `pra-agent/`, `agent-realtime-gateway/`, `pra-proxy/`, `pra-relay/`
- `caller-app/`, `pos-app/`, `fbr-pos-app/`, `di-app/`, `rider-app/`, `waiter-app/`

This was broad forensic inspection, not an assertion that every file in these large trees was line-by-line audited.

## Important invariants

- Product guards and data streams are isolated.
- Tenant scope is `company_id`; branch scope is explicit and caller-enforced.
- Admin cross-company access is intentional and highly sensitive.
- View-as is method-based read-only; Manage-as performs real audited writes.
- Fiscal state, serial, tax, rounding, and reporting-OFF meanings are persisted contracts.
- Inventory quantity truth is movement/branch-stock based; mirrors must reconcile.
- History is corrected append-only through reversal/void, not destructive mutation.
- Queue, cron, cache, agent, and device behavior are part of correctness, not optional operations.
- Saved settings and legacy rows must be preserved through generic fixes.

## Contradictions: documentation vs code and internal inconsistencies

1. `replit.md` states PHP 8.4 runtime while Composer permits PHP `^8.2` (`replit.md:4`, `composer.json:9`).
2. Read-only impersonation claims all state changes are blocked, but enforcement is HTTP-method based.
3. Inventory FKs reference generic products while POS inventory uses `PosProduct` identity.
4. Adjustment requested quantity can differ from authoritative movement delta and lacks branch on the adjustment record.
5. DI PDF QR policy differs from the Invoice accessor fallback.
6. Production MariaDB/database queues differ materially from SQLite/sync test configuration.
7. Backup command name/scheduling/runbook claims do not match code.
8. Generic migration rollback guidance conflicts with additive Healthcare code-only rollback.
9. Current queue service architecture differs from historical migration documentation.
10. Android/caller release documents contain staged/canonical version drift.
11. Root README describes a Laravel skeleton rather than TaxNest.
12. Owner PRA-only focus coexists with code/docs/tasks for other products; code presence or task status does not override owner authorization.

## Unresolved UNKNOWNs/gaps

The authoritative unresolved list is Section 28. Highest operational priorities are full company/branch route audit, production schema compatibility, concurrency on transfers/stock checks/ledgers/serials, queue/cron/mail configuration, regulator timeout idempotency, backup/restore proof, hosted mobile artifact identity, and complete test/browser/device evidence.

## Recommended Cursor workflow

Use an evidence-first, one-task branch workflow:

1. Confirm owner-authorized product scope.
2. Read this guide, `replit.md`, and matching memory topics.
3. Build the complete route-to-data call graph and state matrix.
4. Write a failing characterization/regression test.
5. Make the smallest generic fix while preserving stored settings and legacy data.
6. Run targeted, subsystem, full-app, browser, and device checks appropriate to risk.
7. Open a fully evidenced PR; do not merge or deploy.
8. Hand an explicit production checklist and unresolved UNKNOWNs to the authorized operator.

## Production-untouched confirmation

**CONFIRMED:** this deliverable was produced by read-only repository inspection. No production access or change was made; no deployment, database access/mutation, workflow change, secret operation, commit, PR, or GitHub mutation was performed.