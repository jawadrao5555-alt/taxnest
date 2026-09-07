# 25 — Known Legacy & Contradictions

Rule: when docs and code disagree, **record both**. Never silently reconcile.

## CONTRADICTION — How many products?

| Source | Claim |
|--------|-------|
| `ProductCatalog::TYPES` / `NestErps` | **Four** product types; ERPS is fourth |
| Some comments (`IdentityScope`, CompanyGroup) | “Three product lines” |
| Mission prompt | “Exact three products” |

**Resolution for agents:** Implement against **four** + verticals. Explain “three” as historical DI/POS/FBRPOS trio.

## CONTRADICTION — Deploy authority

| Source | Claim |
|--------|-------|
| `docs/ops/github-production-deploy.md` + Actions workflows | Merge to main → deploy-production (with Environment approval) |
| `docs/ops/healthcare-pilot-runbook.md` | GitHub push deploys nothing; only `deploy-live.sh` |

## CONTRADICTION — DI FBR submission style

| Source | Claim |
|--------|-------|
| Mental model / leftover job | Queued `SendInvoiceToFbrJob` |
| Live code | Sync `submitToFbrSync`; job has **no dispatchers** |

## CONTRADICTION — FBR token expiry jobs

Two jobs / two column names; only `CheckFbrTokenExpiryJob` scheduled. `CheckTokenExpiryJob` orphan.

## CONTRADICTION — Agent heartbeat interval

`pra-agent/README.md` may say 60s; `agent.js` uses **30s**. Code wins.

## CONTRADICTION — Day-close “6AM auto”

`replit.md` shorthand vs schedule: **hourly** `pos:auto-dayclose` with per-company times.

## MISSING — `.agents/memory/`

`replit.md` says deep invariants live in `.agents/memory/` topic files. **FACT:** directory is **not present** in this repository checkout.

**UNKNOWN:** Whether memory lives only on another machine, was never committed, or was removed.

**Agent action:** Prefer code + this `docs/architecture/` + `replit.md` until memory returns.

## LEGACY — standalone POS / pricing

Standalone edition retired; `pricing_plans.product_type=standalone` may remain historically. Force `pos_integration_mode='pra'`.

## LEGACY — Agent distributor portal

Guard/routes exist; controllers return **404**.

## LEGACY — Classic FBR create blade

Dead file retained as SHIM; universal screen only.

## NAMING — “As Company”

Speech “As Company” = UI **Manage as Company** (`mode=full`).

## README

Root `README.md` is stock Laravel — not authoritative.
