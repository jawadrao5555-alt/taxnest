# Live Ops — Phase 0 policy & safety

## Non-negotiable

1. Cloud Agents never receive production SSH keys, DB URLs/credentials, `LIVE_QA_PASS`, agent API keys, PRA passwords, private keys, or `LIVE_OPS_RUNNER_TOKEN`.
2. Diagnosis never auto-triggers remediation.
3. Explicit owner instruction is required before any mutation (`OWNER_APPROVES_LIVE_OPS_FIX` + Environment `production` required reviewers and/or admin UI). Deploy Production is a **different** Environment (`production-deploy`) and must not share this reviewer list.
4. No arbitrary SQL, shell, or artisan from agent-supplied strings.
5. Tenant isolation: company-scoped reads/writes always filter `company_id` (+ NestPOS `product_type=pos` scope).
6. Redact secrets/tokens/passwords/cookies/private keys from reports and audit metadata.
7. High-risk actions are deny-by-default and unavailable on the Live Ops path.

## Risk tiers

| Tier | Examples | Gate |
|------|----------|------|
| Read-only | All diagnostic operations | Actions/admin; redacted output |
| Low | Test print, force update advertise, refresh state, retry one failed PRA | Owner phrase + approval |
| Medium | Rebind to agent-reported printer only; enqueue allow-listed agent command | Owner phrase + approval |
| High | Key regen, disable agent, destructive repair, bulk billing, SSH, credentials | Not available via Live Ops |

## Audit

Every diagnostic request, proposal, approval, execution, verification, and agent-command completion writes `live_ops_audit_events` (and mirrors to `admin_audit_logs` when an admin id is present). Secrets are never stored in audit rows.
