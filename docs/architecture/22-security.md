# 22 — Security

## Trust boundaries

1. Browser sessions per guard  
2. SaaS AdminUser vs company User (separate tables)  
3. Agent API keys (company-scoped)  
4. Runner tokens (Live Ops)  
5. GitHub Environment secrets (production deploy / Live Ops)

## Hardening observed (FACT)

- `ForceHttps` / HTTPS assumptions in production
- Rate limiting middleware on company panels
- Impersonation orphan cleanup (login lockout fix)
- Redaction in Live Ops diagnostics (`LiveOpsRedactor`)
- Readonly impersonation write block
- High-risk Live Ops actions denied by allow-list
- Public repo: never commit credentials (`.local/` gitignored)

## Authn/Authz summary

See `07` + `08` + `09`. No Policies/Gates — mistakes happen when a new route forgets middleware or company_id filter.

## Secrets locations

| Secret | Where it should live |
|--------|----------------------|
| DB / APP_KEY | Server `.env` |
| PRA/FBR tokens | `companies` columns (tenant) |
| Agent API keys | `companies.agent_api_key` |
| LIVE_OPS_RUNNER_TOKEN | production `.env` + GitHub Environment |
| PRODUCTION_SSH_PRIVATE_KEY | GitHub Environment only |
| SMTP / WhatsApp tokens | SystemSetting / company settings (encrypted where implemented) |

## Threat notes for agents

- Do not weaken tenant filters “to make admin easier”
- Do not invent a second role system
- Do not put production passwords in tracked files
- Treat Manage-as as production write access
