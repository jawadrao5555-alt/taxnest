# 13 — Queues, Events, Scheduler

## Queue config

**FACT:** Default production pattern = database queue; workers implied by ops scripts (`taxnest-queue`). Tests force `QUEUE_CONNECTION=sync` (`phpunit.xml`).

Named queues observed: default, `bulk`, `zip`, medicine catalogue self-queue.

## Jobs inventory (FACT — `app/Jobs`)

| Job | Dispatch / schedule | Notes |
|-----|---------------------|-------|
| `QueueHeartbeatJob` | every 5 min | |
| `NightlyComplianceCronJob` | daily 02:30 | |
| `CheckFbrTokenExpiryJob` | daily 06:00 | live |
| `CheckTokenExpiryJob` | **never scheduled** | orphan |
| `CheckTrialExpiryJob` | daily 03:00 | |
| `SyncPosOfflineInvoicesJob` | every 2 min | |
| `SyncFbrPosOfflineInvoicesJob` | every 2 min | |
| `ProcessInvoiceImportBatchJob` | import UI | |
| `SeedBulkSubmitBatchJob` / `BulkSubmitInvoiceJob` | DI bulk | queue `bulk` |
| `ComplianceScoringJob` / `IntelligenceProcessingJob` | DI submit paths | |
| `BuildAuditPackJob` / `BuildInvoiceZipJob` / `PrerenderInvoicePdfsJob` | zip queue | |
| `ProcessBulkAiImageJob` | AI import | |
| `SyncMedicineCatalogueJob` | weekly + resume | |
| `RetryFailedFbrInvoicesJob` | admin | |
| `RetryFbrPosSubmissionJob` | FBR sale fail path | |
| `SendInvoiceToFbrJob` | **no callers** | orphan — DI uses sync |

## Scheduler authority

**FACT:** `routes/console.php` only (no `app/Console/Kernel.php`).

Also schedules artisan commands: day-close autos, agent offline alerts, uptime watch, mail probes, prunes, fair-use, etc.

## Events / listeners

**FACT:** No `app/Events` or `app/Listeners` directories.

**FACT observers:** `InvoiceItemObserver`, `HealthAuditObserver` (registered in `AppServiceProvider`).

**FACT:** Inline `Login` / `Failed` listeners in `AppServiceProvider` → security log + last login + hazri.

## Idempotency patterns

- DI API `client_reference`
- Live Ops remediation `idempotency_key`
- Agent command `idempotency_key`
- POS `offline_uuid`

## Worker relationship

**INFERENCE:** VPS supervisor runs queue workers; exact unit names not in application code (ops UNKNOWN without server access). Scheduler requires `schedule:run` cron.
