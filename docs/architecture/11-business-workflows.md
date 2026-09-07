# 11 — Business Workflows

Trace model used (omit layers marked NOT PRESENT):

```text
USER → SCREEN → ACTION → ROUTE → GUARD → AUTH → MIDDLEWARE
  → COMPANY/BRANCH CONTEXT → AUTHORIZATION → CONTROLLER → VALIDATION
  → SERVICE → MODEL/DB → (EVENT/JOB/EXTERNAL) → AUDIT → UI
```

Laravel Policies/Gates: **NOT PRESENT**. Domain Events directory: **NOT PRESENT**.

---

## W1 — NestPOS sale (cash/card)

| Layer | Present? | Detail |
|-------|----------|--------|
| Start | Yes | `pos/universal.blade.php` sale screen |
| Who | pos_cashier / manager / admin (confined roles blocked) | |
| Route | `POST /pos/invoice/store` → `PosController::storeInvoice` | |
| Guard | `pos.auth` | |
| Context | `currentCompanyId`, optional branch | |
| Business | Tax math `PosTaxMath`; whole-rupee total; provisional vs final; reporting ON/OFF rules | |
| DB | Insert `pos_transactions` (+ items/payments) | |
| External | If reporting ON: PRA via `PraIntegrationService` or queue for agent (`fiscal_device` / agentHandlesPra) | |
| Side effects | Optional print job enqueue; hazri already from login | |
| Failure | Transport → offline queue / pending; never casually mark fiscal failed for transport | |
| Idempotency | `offline_uuid` paths | |

---

## W2 — PRA submit / retry

| Start | Sale path, offline sync job, manual retry, Live Ops remediation |
| Service | `PraIntegrationService` |
| Modes | cloud direct vs fiscal_device (agent local IMS) |
| Audit | `pra_logs` |
| Scheduler | `SyncPosOfflineInvoicesJob` every 2 min |

---

## W3 — Silent print

| Start | Sale/KOT/test print creates `pos_print_jobs` |
| Wake | `PrintJobWakePublisher` → gateway (opt-in) |
| Agent | Long-poll claim `/api/agent/print-jobs` + content + result |
| Snapshot | `target_printer`, `device_uid` stamped at enqueue |

---

## W4 — Day close (NestPOS)

| Start | UI close or `pos:auto-dayclose` hourly |
| Effects | Z-report, cash recon, local-bill wash policy, opening float |
| Constraint | Fiscal numbers / non-NULL pra_status never washed |

---

## W5 — DI invoice submit to FBR

| Start | Invoice show Submit |
| Controller | `InvoiceController::submit` → **`submitToFbrSync`** (sync HTTP) |
| Jobs | Intelligence/compliance may queue; **SendInvoiceToFbrJob orphaned** |
| Audit | `fbr_logs`, activity |
| API twin | `DiInvoiceApiController` draft|submit with `client_reference` idempotency |

---

## W6 — FBR POS sale

| Start | `fbr-pos/universal.blade.php` via `/fbr-pos/create` |
| Controller | `FbrPosController::store` |
| Fiscal | FBR IMS / agent; retries `RetryFbrPosSubmissionJob` |
| Offline sync | `SyncFbrPosOfflineInvoicesJob` |

---

## W7 — Super Admin View/Manage as Company

Documented in `05` / `06`. Layers: Admin UI → assertSuperAdmin → dual-guard session → ReadOnlyImpersonation → (optional) LogImpersonatedWrites → Exit.

---

## W8 — Subscription access check

| Service | `SubscriptionAccessService::hasAccess()` |
| Order | lifetime → usage_free → temporary/grace → active dates/trial |
| Enforcement | Middleware / controllers / PlanLimitService for quotas |

---

## W9 — Desktop Agent heartbeat + Live Ops command

| Start | Agent timer 30s |
| Route | `POST /api/agent/heartbeat` |
| Auth | `agent.auth` |
| Out | telemetry update; return `pending_commands`, `force_update` |
| Follow-up | `/api/agent/command-result` |

---

## Negative path checklist (apply per workflow)

Unauthorized · wrong company · wrong branch · missing record · invalid input · duplicate · concurrent · expired session · API failure · timeout · queue failure · partial failure · retry · rollback.

Documented examples exist heavily in Feature tests (`tests/Feature/*`).
