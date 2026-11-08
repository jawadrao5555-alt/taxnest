# Scoped retained-source diagnostics — PR #364

## Release behavior

The existing revocable read-only reporting connection now exposes a versioned
source registry for PRA POS, Digital Invoice, FBR POS and Healthcare. Registered
sources cover sales, fiscal attempts, stock, printing, offline collection,
clinical/pharmacy associations, tenant audit, notifications, branches, support
and company-linked Live Ops. Sources are fixed allow-lists, not caller-supplied
SQL or table names. Compatibility gaps remain explicit.

Company resolution requires an exact name within the OAuth grant, with optional
company ID for duplicate names. Branch reports require actual retained branch
keys; unsupported sources never silently widen to company scope. DI fiscal
records are scoped through their invoice parent. Shared stores require the
company and product/reference namespace. Global/null-tenant rows are excluded.
Every successful tool read is audited, and revocation applies immediately.

Source reads cover at most seven calendar dates and 50 rows per immutable-ID
page. JSON export reuses those filters, stops after five pages/250 rows and
returns explicit continuation. Window totals remain separate from page totals;
an exhausted cursor does not erase retained evidence.

Daily observations are derived rows, not a replacement for original evidence.
The scheduled collector processes 100-company chunks, preserves same-run
idempotency and appends later readings for late arrivals. Only this derived
ledger is pruned after seven days. Daily coverage lists the latest receipt for
each requested date, missing dates, failed/unavailable dates and incompatible
registry versions. `all_dates_measured` means every date has a current-registry
observation, including observed zero activity; it never means all original
events were collected. `complete_collection_proven` remains false.

## Bill identity and associations

`get_diagnostic_bill_lookup` resolves an explicit exact invoice serial or an
explicitly dated PRA daily L token. Daily tokens use the existing 06:00 counter
boundary in application timezone. Stored `bill_token` and bill creation time
are queried; current receipt style and archived-row scopes cannot hide a
candidate. The local/fiscal streams may share a token: multiple candidates stay
AMBIGUOUS, with no selected bill ID. Optional branch scope is explicit. Returned
IDs identify stored candidates only; a NOT_FOUND result does not establish that
a bill was deleted or that its KOT was never queued.

`get_diagnostic_bill_evidence` v5 follows immutable bill/payment/order/stock/
fiscal/print links. PRA print jobs require bill/kot/kot_void; FBR jobs require
fbr_bill/fbr_kot. Order-only PRA print jobs/receipts require the exact same-tenant
order-to-bill link and a null transaction link. Archived parent bills remain
readable, while deleted parents are not reconstructed.

PRA offline events require `projection_status=projected`, supported sale.created
or order.settled types, and the server-written projection result's transaction
ID. A client payload ID, accepted-only inbox receipt, another event namespace,
or another tenant cannot establish a bill link. Unuploaded/unattributed events
remain unknown. FBR offline bill projection is not inferred from PRA's contract.

Healthcare follows immutable bill charges to visits, admission charges,
operations, pharmacy sales and exact sale-referenced stock movements. Matching
amounts, names and nearby times are never used to join records.

## Preserved behavior and explicit limits

No business records, checkout settings, saved numbering, printer routes, fiscal
queues, purchased entitlements or billing documents are changed. Latest main
5857d8c1a09b726d817969533d76bda6e2a68b49 includes deployed PR #363. The native
harness retains both diagnostics and purchased-billing probes.

Returned fields are versioned allow-lists: IDs, timestamps, counts and a small
set of safe Live Ops state/release references. No payloads, customer/patient
details, amounts, tokens, printer paths or raw errors are exported. Individual
source failure does not erase independent evidence.

Installed-device seven-day soak, deleted/uncollected event recovery, physical
paper output, regulator acceptance and tenant-bound backup/restore success
cannot be proved from these retained records. Backup sources explicitly return
UNAVAILABLE / NO_TENANT_BOUND_RETAINED_SOURCE. Healthcare has no retained lab-order
or per-bill print source; DI/FBR unsupported payment/printing/offline associations
remain explicit. Global release references never become tenant health proof.
These are evidence limits, not automatic successful checks. Broad #319/#337
remain planning trackers; this release does not close their whole-platform
field-collection program or assert a fix for ZFC's missing entire KOT.

## Acceptance

Local supplemental PHP 8.3.6/SQLite: Reporting plus heartbeat telemetry regression
**94 tests / 1093 assertions PASS**. Tests include daily rollover/06:00 edges,
current-style changes, archived bills, duplicate tokens/serials, exact branch,
foreign tenants, unsupported/missing schema, late arrival, same-run idempotency,
per-day missing/failure/registry mismatch, payload exclusion and unchanged rows.

The required native MariaDB probe additionally checks concurrent observation
uniqueness, source/collector parity, JSON server-projection identity, print
product namespace, daily-token lookup and source-byte preservation. Chromium
acceptance uses actual consent, PKCE, lookup-to-bill evidence, foreign denial,
read-audit counts, retained print evidence after job deletion and immediate
revocation. Native/browser acceptance is authoritative only on the final head.

Fresh hosted PHP 8.4/full suite, native MariaDB, Chromium, Agent/Node, build,
manifest, workflow safety and final validate must all pass before Draft removal.
Owner approval/merge/deploy remains separate from PR preparation.
