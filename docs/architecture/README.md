# TaxNest Architecture Knowledge Base

**Purpose:** Permanent reverse-engineering reference so a future engineer or Cursor Cloud Agent can understand TaxNest behavior without rediscovering the SaaS from scratch.

**Authority order (when sources disagree):**
1. **Running application code** (controllers, middleware, models, routes, migrations, tests)
2. **`replit.md`** (product/ops map — may lag code)
3. **This knowledge base** (must be updated when code proves it wrong)
4. Older comments / marketing language

**Scope of this folder:** Documentation only. No application behavior changes were made to produce it.

**How to use:** Start with `00-system-overview.md`, then `26-future-agent-working-guide.md`. Deep-dive by topic number. Every important claim is labeled **FACT** / **INFERENCE** / **UNKNOWN**.

**Related (not replaced):**
- `/workspace/replit.md` — short product map + owner rules
- `/workspace/AGENTS.md` — Cloud Agent operating rules
- `/workspace/docs/ops/*` — deploy, Live Ops, healthcare runbooks
- `/workspace/.agents/memory/*` — **referenced by `replit.md` but NOT present in this repository checkout** (see `25-known-legacy-and-contradictions.md`)

## Index

| # | File | Topic |
|---|------|-------|
| 00 | `00-system-overview.md` | What TaxNest is; ecosystem map |
| 01 | `01-saas-architecture.md` | SaaS control plane hierarchy |
| 02 | `02-three-products.md` | Product lines (canonical four; historical three) |
| 03 | `03-company-organization.md` | Company → owner → roles → branches |
| 04 | `04-super-admin.md` | SaaS Super Admin architecture |
| 05 | `05-view-as-company.md` | View-only impersonation |
| 06 | `06-as-company.md` | Manage-as (full-access) impersonation |
| 07 | `07-authentication.md` | Guards, login, sessions |
| 08 | `08-authorization.md` | Roles, middleware, no Policies/Gates |
| 09 | `09-tenant-isolation.md` | company_id, scopes, Super Admin exception |
| 10 | `10-database-domain-model.md` | Domain entities + data classes |
| 11 | `11-business-workflows.md` | Major E2E workflows |
| 12 | `12-state-machines.md` | Status / lifecycle machines |
| 13 | `13-queues-events-scheduler.md` | Jobs, schedule, observers |
| 14 | `14-external-integrations.md` | FBR, PRA, WhatsApp, mail, OpenAI |
| 15 | `15-mobile-pwa-desktop-agents.md` | Apps, PWA, Electron, companion apps |
| 16 | `16-printing-devices.md` | Print jobs, devices, printers |
| 17 | `17-billing.md` | Plans, subscriptions, limits |
| 18 | `18-settings.md` | Settings layers |
| 19 | `19-audit-observability.md` | Audit surfaces |
| 20 | `20-testing.md` | Test layout + CI gaps |
| 21 | `21-deployment.md` | GitHub Actions + VPS |
| 22 | `22-security.md` | Security model |
| 23 | `23-cross-module-dependencies.md` | Dangerous coupling |
| 24 | `24-architectural-invariants.md` | Must-not-break rules |
| 25 | `25-known-legacy-and-contradictions.md` | Docs≠code, orphans |
| 26 | `26-future-agent-working-guide.md` | Before you change anything |
| — | `27-live-ops-integration-points.md` | Where Live Ops must plug in (no implementation) |
