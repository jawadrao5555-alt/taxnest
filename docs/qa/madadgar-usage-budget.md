# B08-R07: Madadgar usage, budget and escalation observation

Scope: metadata-only per-company support-assistant events. No provider activation,
credentials migration, real calls, external delivery or invented commercial rates.
Existing local-first behaviour, bounded read scopes and escalation confirmation
remain unchanged. Other AI products (invoice OCR etc.) retain their separate
credit ledgers; this page does not claim to aggregate their costs.

The UTC monthly page shows provider reservations (not inferred dispatches), errors, blocked requests, reported
tokens, observed latency, known estimated USD cost, committed allowance and bounded
latest events. It reuses actual confirmed escalation IDs/status/timestamps without
copying titles or private conversations. An assistant answer never marks solved.
Legacy usage is not reconstructed. Missing rates/tokens/cost are unknown, not zero.
Local/cache/scoped events identify no provider call; no fabricated provider usage.

Rates are explicitly configured USD per million input/output tokens for the exact
requested model gpt-4o-mini. There are no default rates or default cap. Rates are
snapshotted per request; later changes cannot rewrite past estimates. Estimates
are not provider invoices. Sensitive-config SaaS admins configure with fresh
persisted authorization and immutable audit; tenant owners can only observe their
own company. Read-only impersonation cannot configure. Provider settings and keys
are not modified by this surface.

Budget reservation occurs before HTTP, under the company row mutex with CURRENT
locking reads of the entire UTC month. It reserves serialized UTF-8 request bytes
(including system, messages and tools) plus 4096 framing tokens, and 600 output
tokens. The fixed request shape is limited to 32 messages and input bound100000;
the actual provider max_tokens is600. This intentionally conservative estimate
trades more rejected requests for safety. Reported usage beyond the bound puts
future capped calls on HOLD. This is not tokenization or an invoice guarantee.
Unknown or failed transport usage retains its allowance; no automatic timeout
release can turn an uncertain paid request into free budget. A configured cap with
missing rates or unreserved unknown monthly usage fails closed. Local answers
remain available. Correcting unresolved allowance requires reviewed provider
billing evidence and a future audited reconciliation path; no blanket reset UI.

Only numeric tokens, latency, allowlisted errors, model/source, rate/cost snapshots
and company identity are added. No questions, answers, tool arguments, credentials,
HTTP bodies or raw exception text enter these tables. Existing chat retention is
unchanged. Company deletion cascades these additive metadata tables.

Local tests use Http::fake/preventStrayRequests: unknowns, cap denial, retained
uncertain reservations, exact/idempotent settlement, historical rate snapshots,
model wrapper output cap, tenant isolation, stale owner/admin denial and immutable
configuration audit. Native probe independently starts two old-snapshot workers:
exactly one allowance200 and one budget429 under the configured cap, unknown
failure retains allowance; no provider request occurs. Native and desktop/mobile
execution must be separately evidenced on the final CI head; source tests alone
are not native/browser completion. Operational rollback must preserve usage and
budget evidence, not drop tables after recorded spend.

Partial schema loss after feature installation fails closed before provider HTTP,
including a surviving policy table or migration ledger with missing events table.
Local/scoped answer telemetry failure returns usage_observation=unavailable and a
fixed safe log notice; it never destroys the computed answer or bypasses provider
budget gating. A reservation is not proof of dispatch and is labelled accordingly.
