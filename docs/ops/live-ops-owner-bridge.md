# Live Ops owner-command bridge (ChatGPT / GitHub remote control)

Lets the owner say:

- `Aaj ki report do.`
- `Pizza Master check karo`
- `ZFC ka printing issue solve karo`
- `Sab companies check karo aur jahan issue ho solve karo`

No company IDs. No GitHub artifact download. No production secrets in chat.

## How ChatGPT should call this

**Preferred (works without Actions write):** open a GitHub issue titled:

```
[TAXNEST-OPS] Aaj ki report do
```

or comment the same phrase on an existing `[TAXNEST-OPS]` issue. The commenter must be the repository owner. Pull-request comments are ignored.

The workflow `.github/workflows/live-ops-owner-bridge.yml` parses the text locally (allow-list only), then runs the existing Live Ops production path (HTTPS runner token or pinned SSH artisan). It posts the **owner-readable report** back on the issue and uploads artifact `live-ops-owner-command`.

**If the GitHub App can dispatch workflows**, minimum extra permission is **`actions: write`** (not contents/admin). Then ChatGPT can `workflow_dispatch` this workflow or `live-ops-diagnose.yml`. Do **not** create or paste a PAT.

`repository_dispatch` event type `taxnest-live-ops` with `{ "text": "Aaj ki report do" }` is also allow-listed.

## What the engine does

1. Parse natural language → allow-listed intent/operation.
2. Resolve company **name** (exact, account_code, case-insensitive, normalized punctuation, safe unique prefix/partial). Ambiguous names return the matching shop names and **do not** operate.
3. Run existing diagnostics (`DAILY_OPS`, `COMPANY_DIAGNOSTIC`, `PRINTER_HEALTH`, …).
4. Format a business report (billing, bills, printing, agent, PRA, issues).
5. For **solve** intents: only **low-risk operational** remediations (for example test print / PRA retry) may execute on the trusted runner. Medium (printer rebind, agent commands) and high-risk (tax, auth, tenant isolation, migrations, secrets, destructive DB) stay **BLOCKED**.
6. Ordinary **code** fixes still deploy through the existing owner-approval **relay** + Owner Merge & Deploy + exact-SHA Deploy Production. `scripts/live-ops-safe-auto-merge.sh` classifies the PR diff; `AUTO_DEPLOY` paths may be auto-approved **on the relay**. It does **not** squash-merge or dispatch Deploy Production itself.

Statuses: `INVESTIGATING` → `DIAGNOSED` → `FIXING` / `TESTING` / `READY_TO_DEPLOY` / `DEPLOYING` / `VERIFYING` → `RESOLVED` | `BLOCKED` | `FAILED`. Max autonomous fix iterations: **3**.

## Cloud Agent helper

```bash
bash scripts/cloud-live-ops-request.sh --command='Aaj ki report do'
bash scripts/cloud-live-ops-request.sh --command='Pizza Master check karo'
bash scripts/cloud-live-ops-request.sh --operation=COMPANY_DIAGNOSTIC --company-name='Pizza Master'
bash scripts/live-ops-fetch-report.sh --run-id=123
```

If `workflow_dispatch` returns HTTP 403, the helper exits 3 and prints the issue-bridge instructions. It never asks for a PAT or production password.

## Security

- Allow-listed operations only (same catalog as Live Ops Diagnose).
- No arbitrary workflow names, no arbitrary shell, no production SSH from Cursor/ChatGPT.
- Secrets remain in GitHub Environment `production` (`LIVE_OPS_RUNNER_TOKEN`, `LIVE_OPS_BASE_URL`, `PRODUCTION_SSH_PRIVATE_KEY`).
- Reports are redacted (passwords, tokens, keys, cookies, Authorization).
- Tenant isolation is unchanged: every company-scoped query uses the resolved `company_id` internally.

## Genuine limitations

- Environment `production` may still have **required reviewers**. That is a GitHub settings control; this repository cannot remove it. Until reviewers are cleared (or a `actions: write` App dispatches after an already-approved environment), a human may need to click **Review deployments** once.
- ChatGPT cannot `workflow_dispatch` without **actions: write**. The issue bridge is the fallback (`issues: write` only).
- High-risk code still requires the normal owner-approval relay (admin password / existing panel). Ordinary print/UI/retry diffs may use `Live Ops Safe Auto Merge` then the existing relay dispatch.
