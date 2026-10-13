# Direct read-only company reports

The existing Live Ops issue bridge is still approval-gated and remains unchanged. This separate owner-authorized reporting connector answers company/date queries directly through authenticated MCP tools. A PR or deployment does **not** connect a ChatGPT account by itself.

## Connect after deployment

1. Production migrations must succeed. `APP_URL` must be the exact canonical HTTPS site URL; resource/token audience is derived from configuration, never an incoming Host header.
2. In an OAuth-capable MCP client, add `<APP_URL>/api/reporting/mcp`. For ChatGPT, availability of custom connectors/developer mode depends on the account/workspace; its host-side approval policy is separate from TaxNest.
3. Sign in through the existing SaaS admin login. Only `AdminUser::isSuperAdmin()` may approve access. Choose selected companies or all billing companies (including future companies).
4. Manage/revoke from **Administration → Read-only Reports** (`/admin/reporting/connections`). Grant lifetime is 30 days, access tokens 60 minutes; expired grants require reconnecting. No owner password is supplied to the client.
5. Confirm a real OAuth handshake and compare a known bill/date against the existing company reports before calling the deployed connection verified. Native/synthetic tests do not establish an installed ChatGPT connection.

The default exact callback is `https://chatgpt.com/connector_platform_oauth_redirect`. OAuth metadata advertises issuer identification and PKCE S256. If the client supplies a different callback, set `REPORTING_OAUTH_REDIRECT_URIS` to comma-separated **exact trusted callbacks**; no wildcard or arbitrary redirect registration is accepted. The browser fixture uses an isolated loopback callback only; it never contacts ChatGPT or a regulator.

## Tools and evidence

| Tool | Supported questions |
|---|---|
| `find_companies` | Resolve exact company names within owner-approved scope |
| `get_company_billing_summary` | Bill count, sales, returns, net billing, stored sales tax, fiscal states, reported/unreported counts and net amounts, item tax classifications |
| `list_company_bills` | Paginated bills, fiscal number/status, local invoice number, reported/unreported, sale/return and document status filters |
| `get_bill_details` | A company/date-scoped bill and up to 100 items per page, price/HS/unit/tax evidence where stored |
| `get_company_tax_items` | Paginated taxable, exempt, zero-rate or unknown items; sale/return evidence, rates and quantities |
| `get_company_payment_summary` | Stored POS cash fields and separate hotel payment/refund/deposit movements |
| `get_company_operational_summary` | Safe current branch-stock/product-stock counts, print-job states and at most 100 agent heartbeat records |

Company and dates are explicit in each result, with request-time `as_of`, currency and timezone. Default date is today in **Asia/Karachi**; maximum range 93 days. Calendar `created_at` is the default; stored POS `business_date` can be requested. Branch, terminal and payment-method filters require actual source columns. Agent/stock snapshots are current; print-job dates use calendar `created_at` explicitly. Missing evidence is unavailable/null, not an invented zero.

Financial totals include completed POS or locked DI documents; draft/failed/cancelled status counts are separate. POS reported means `submitted` plus a nonempty fiscal number. DI production reporting means `production` plus a nonempty FBR number; sandbox/simulated documents never count as production-reported. DI billing totals include all locked environments, with fiscal-state splits and an explicit caveat. No independent regulator lookup is performed.

Credit notes/returns are stored positive values and subtract once from net billing/tax. A credit note does not establish a payment refund. Item counts are invoice rows, with sale/return rows separate; quantities may mix units. Exempt, zero-rate and missing classification are separate. Bill sales tax, additional item taxes, withholding and levies are not silently added together. Payment-method invoice values and POS cash fields cannot establish a universal collected balance; hotel ledger movements remain a separate evidence stream.

## Access boundary

- Public OAuth authorization-code clients only: exact callbacks, PKCE S256, five-minute single-use codes and resource-bound tokens.
- Raw codes/access/refresh tokens are random and stored only as SHA-256 hashes. Refresh tokens rotate; replay revokes the grant and commits that revocation.
- Current owner role, expiry, revocation, audience and company scope are rechecked on each MCP request. Tokens are accepted only through the Bearer header.
- Foreign/malformed Origin headers are rejected. JSON-only stateless HTTP; no SSE session storage required. Machine endpoints omit web sessions and CSRF; consent/revoke retain owner session and CSRF.
- Every successful tool read records tool, actor, grant, company and period, without report contents or credentials. Consent/token/audit records are the connector's only writes; business tables are SELECT-only.
- No invoice create/edit/delete, submission/retry, printing, shell, arbitrary SQL or Live Ops remediation tools. Buyer names/CNIC/phone, fiscal raw responses, API keys and printer claim credentials are excluded.
- Existing fiscal configuration, saved tax/payment settings, agent contracts and Live Ops approval workflows are preserved.

## Verification

Local HTTP regression coverage exercises scope, amounts, returns, reported/unreported filters, DI production/sandbox distinction, tax classifications, pagination, date bounds, unavailable evidence, company ambiguity, exact/literal search, OAuth PKCE/audience/code replay, refresh replay, owner role changes, expiry/revocation, metadata, Origin and protocol behavior. It snapshots business invoices to prove reads preserve them.

Required CI browser acceptance executes owner consent and revoke buttons at desktop/mobile widths, exchanges a real local PKCE authorization code, reads known synthetic invoices on native MariaDB, denies a foreign company and verifies the revoked token returns 401. Screenshots/diagnostics use disposable loopback fixtures. The native migration job exercises the new tables and idempotent migrations. Actual deployed ChatGPT handshake and live bill comparison remain owner-side acceptance after release.

Official protocol references: [OpenAI OAuth](https://developers.openai.com/plugins/build/auth), [MCP authorization](https://modelcontextprotocol.io/specification/2025-11-25/basic/authorization), [MCP Streamable HTTP](https://modelcontextprotocol.io/specification/2025-11-25/basic/transports).
