# 26 — Future Agent Working Guide

## BEFORE CHANGING ANYTHING

1. **Read** `docs/architecture/README.md` + the topic files for your subsystem.  
2. **Identify product** — `di` | `pos` | `fbrpos` | `erps` (+ vertical).  
3. **Identify company context** — how `currentCompanyId` is bound on this path.  
4. **Identify guard** — `web`/`pos`/`fbrpos`/`health`/`admin`/`agent.auth`/…  
5. **Identify role/permission** — `users.role`, `pos_role`, `health_role`, AdminUser role, custom access.  
6. **Trace existing workflow** — UI → route → middleware → controller → service → DB (see `11-business-workflows.md`).  
7. **Identify authoritative data** — do not overwrite SNAPSHOTs or fiscal numbers casually (`10-database-domain-model.md`).  
8. **Identify dependents** — `23-cross-module-dependencies.md`.  
9. **Check tests** — existing Feature coverage; CI may not run PHPUnit.  
10. **Smallest safe change** — preserve settings; no parallel systems.  
11. **Test** — PHPUnit targeted + related suite.  
12. **Browser-test** where UI changes (loopback only for Cloud Agents).  
13. **Verify tenant isolation** — wrong company / wrong role negative tests.  
14. **Verify side effects** — jobs, audits, prints, fiscal calls.  
15. **Verify deploy implications** — Elaan, migrations, queue workers, agent version skew.

## MUST NOT invent (already provided)

| Temptation | Existing mechanism |
|------------|-------------------|
| New staff↔company pivot | `users.company_id` only; groups for admin insight |
| New permission framework / Policies | Middleware + helpers |
| New product_type for a Nest ERPS vertical | `NestErps::VERTICALS` registry |
| New Super Admin cross-login without audit | View as / Manage as impersonation |
| Parallel Live Ops auth | SaaS `AdminUser::isSuperAdmin()` + existing company model |
| Remote shell to shops | Desktop Agent allow-listed commands + print queue |
| SMS gateway | Email + WhatsApp patterns |
| Queued DI FBR as “the” path | Sync `submitToFbrSync` today |

## Owner preference shortcuts

- Default focus: **NestPOS PRA** unless told otherwise (`replit.md`).  
- Do not open FBR/DI advance work while owner streams are closed.  
- Issue log vs implement only on command.  
- Public repo: no secrets in git.

## Reconstruction self-check

Before merging: “Could another agent explain this subsystem’s data model, auth, workflow, failures, and tests from the architecture docs + code citations alone?” If no — expand docs or tests first.
