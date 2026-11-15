# Madadgar bounded company reads: source #301 / #321

This increment adds deterministic, audited read tools to the existing Madadgar, not a new AI provider or a general autonomous agent. No provider settings, keys, product permissions, fiscal routes or production records are changed.

## Supported initial capability

Only a persisted active `company_admin` with owner POS role (`pos_admin` or no POS role), authenticated in the same POS/FBR guard and company, may use these exact commands (case insensitive):

- `today billing`, `today sales`, `aaj ki sales`: retained completed billing, returns and stored net tax.
- `today collections`, `today payments`, `aaj ki collections`: School/Hospital immutable payment receipts; retail stored cash fields explicitly not a universal collected total.
- `today tax breakdown`, `aaj ka tax breakdown`: retail stored item taxable/exempt/zero-rate/unknown classes. Missing classification is unknown; zero is not assumed exempt. No current law/rate advice.
- `stock summary`, `inventory summary`: PRA POS tracked/nonpositive/untracked stock row counts, only when its inventory module is available. No mixed-unit sum, stock healing or physical-stock assertion.
- `recipe summary`: PRA POS active recipe structure counts with tenant-matching product and ingredient joins, gated by the existing recipe module. No preparation/cost inference. Company-only catalogue read is unavailable when a campus filter is active.
- `today day close`, `aaj ka day close`: School/Hospital saved closing snapshot count/cash/count/variance, not a recomputed receipt total. Retail closing remains unsupported.

Cashiers, managers, other products, arbitrary identifiers/dates, individual student/patient/customer rows and broad natural-language data queries are **not** supported by this initial capability. Unknown questions retain explicit unsupported guidance. Public guide and support escalation behavior remain separate.

## Boundaries and evidence

Existing injection, foreign-company, current membership/product and local fiscal guards run before reads. School/Hospital fiscal submission remains OFF/blocked; local tax/fiscal questions cannot trigger a read or submission. The active campus is resolved through the shared branch authority. An invalid/stale explicit campus is refused rather than broadened; company-wide scope requires the actual owner context. Source timestamps explicitly name the stored/reporting timezone used by the existing report adapter, not an invented company timezone.

Fixed adapters reuse `CompanyReadReports` and `LocalCollectionReports`. Queries accept no prompt-generated SQL, dates or identifiers. Responses whitelist numeric fields and known classifications; stored company/product/ingredient/patient/student text is never interpreted or sent to a model. Missing schema, missing audit store and query/audit failures fail closed without returning partial amounts or invented zeroes.

A read and its tenant/user/campus/intent/date/source/result-hash audit are one transaction. No read-result global cache exists. Customer history stores the scoped result and provenance. Follow-on company support calls do not send prior chat history to the provider, preventing private read responses being transmitted in a later conversation turn. The audit table has no customer mutation/read endpoint; existing history remains user/company/session scoped.

Synthetic tests cover two companies, current role changes, unauthorized campus, injection, source/date, retained audit/history isolation, School saved close versus later receipts, tax classification unknowns, numeric-only stock/recipe output, no stock mutation, missing schema/audit, and fake provider isolation. Existing Madadgar guard tests remain required.

This does not complete the whole AI programme: arbitrary question understanding, cashier-specific reads, ERP reads, broad operational tooling, issue deduplication, issue-to-PR execution, live rollout and hosted desktop/mobile/native acceptance remain outside this bounded increment. No real provider request or live company read was used for development.
