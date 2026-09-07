# Live TaxNest Operations

Restores practical LIVE NestPOS PRA ops (investigate → explain → owner-directed fix → verify) **without** giving Cursor Cloud Agents production SSH, DB, or secrets.

## Roles

| Role | May do | Must not |
|------|--------|----------|
| Cloud Agent | Request diagnostics / propose remediations via `gh workflow`; read redacted artifacts; explain | Hold prod secrets; execute mutations silently; arbitrary SQL/shell |
| GitHub Environment `production` | Run allow-listed artisan/API with secrets after manual approval | Accept freeform commands from agents |
| Super-admin `/admin/live-ops` | Same diagnostics + approve/execute in browser | Expose secrets to Cloud Agent env |
| Desktop Agent | Execute allow-listed `pending_commands` from heartbeat | Arbitrary remote shell |

## Cloud Agent commands

```bash
# Investigate
bash scripts/cloud-live-ops-request.sh --operation=COMPANY_DIAGNOSTIC --company-id=35

# Propose fix (does NOT execute)
bash scripts/cloud-live-ops-remediate-request.sh --mode=propose \
  --action=ENQUEUE_AGENT_COMMAND --company-id=35 \
  --params='{"command_type":"RESYNC"}' \
  --proposal='Agent offline; resync when back'

# After owner says "Fix it" AND supplies phrase + Environment approval:
bash scripts/cloud-live-ops-remediate-request.sh --mode=approve_execute \
  --action-id=<ULID> --phrase=OWNER_APPROVES_LIVE_OPS_FIX
```

Owner approval phrase: `OWNER_APPROVES_LIVE_OPS_FIX`

## Diagnostic operations

`COMPANY_HEALTH`, `BILLING_SUMMARY`, `BILLING_BY_COMPANY`, `PRA_HEALTH`, `AGENT_HEALTH`, `PRINTER_HEALTH`, `ERROR_SUMMARY`, `COMPANY_DIAGNOSTIC`, `PROBLEMATIC_COMPANIES`

## Remediation allow-list

**Low:** `ENQUEUE_TEST_PRINT`, `FORCE_AGENT_UPDATE_ADVERTISE`, `REFRESH_OPERATIONAL_STATE`, `RETRY_ONE_PRA_INVOICE`  
**Medium:** `REBIND_ASSIGNED_PRINTER`, `ENQUEUE_AGENT_COMMAND`  
**High (denied):** regenerate API key, disable agent, destructive DB, bulk billing, arbitrary SQL/shell/artisan/SSH, credential changes

## Agent commands

`STATUS_REFRESH`, `RESYNC`, `UPLOAD_REDACTED_LOGS`, `TEST_PRINT`, `PRINTER_REFRESH`, `SAFE_AGENT_RESTART`

## Production secrets (Environment only)

- Existing: `PRODUCTION_SSH_PRIVATE_KEY`
- Optional preferred path: `LIVE_OPS_RUNNER_TOKEN` (≥32 chars) + `LIVE_OPS_BASE_URL` (`https://taxnest.pk`)

Set `LIVE_OPS_RUNNER_TOKEN` in production `.env` to match the Environment secret.

## Security

See `docs/ops/live-ops-security.md`.
