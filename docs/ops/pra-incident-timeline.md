# PRA print incident timeline — first implementation for #310

This is the first scoped implementation, not completion of issue #310 or a
complete SaaS recording system. It extends the existing owner-authorized
read-only reporting connection; normal permitted reads do not need Live Ops.
Deployment does not establish an installed ChatGPT/OAuth connection.

## Operation

Call `get_pra_incident_timeline` on the existing `/api/reporting/mcp` connection:

```json
{"company_name":"Exact permitted company name","date_from":"2026-10-30","date_to":"2026-10-30","bill_id":100,"limit":50}
```

Resolve ambiguous names with `find_companies` and supply its permitted
`company_id`. Optional filters are `branch_id`, `bill_id`, `order_id`, `job_id`;
subsequent pages use `after_id`. Maximum seven calendar days and fifty events
per page. Dates use the configured reporting timezone (Asia/Karachi by default)
and select server receipt time, not saved business date or an inferred shift.
Pages contain structured JSON suitable for a host/client report; a dedicated
download UI, natural-language host routing and video correlation are not added
by this slice. Existing billing/item/tax tools remain separate.

## Evidence and compatibility

- The existing server `pos_print_evidence` stream records claim, content,
  no-document/result and selected device/rejection observations. New records
  preserve company/product, validated same-company bill/order references and
  known branch attribution independently of print-job cleanup. No old row is
  backfilled using guessed present-day relations.
- Actual Eloquent job creation records `enqueued`; an actual saved restaurant
  order-to-bill change records `order_bill_linked`. The latter permits earlier
  order/KOT evidence to be found by bill after job/order deletion. Original
  event bill IDs remain null if not known then. Reassignments and associations
  are not proof every attempt printed that final bill.
- No new Agent protocol, offline queue, fiscal retry, printer routing, financial
  mutation, company setting or credential is introduced. Legacy schemas still
  record the old stream; the new reader reports unavailable until the approved
  correlation migration is installed. FBR uses the existing shared recorder,
  but this query adapter refuses FBR/DI/ERP companies explicitly.
- Read tools recheck the existing grant/owner/company scope. Successful reads
  use the existing access audit without copying report contents. No arbitrary
  SQL, shell, print, resend or remediation operation is exposed.
- Responses allow-list event/outcome/stage and timing values. Customer names,
  contacts, raw errors, printer/host names, claims and regulatory payloads are
  not exported. Existing bounded legacy operational context storage is retained;
  this is not a new raw device-log upload path.
- Failed file logging no longer prevents the independent DB evidence insert.
  DB recording remains best effort and must not change business results. Health
  writes run after commit to avoid a new company-wide lock inside sale/result
  transactions; rollbacks leave no phantom collection health.

## Retention, resource budget and gaps

The existing thirty-day policy is centralized in `PrintJobEvidence`.
Scheduled cleanup now selects/deletes at most 1,000 strictly older-than-thirty-day
rows per five-minute run, with a second cutoff check. Seven-day and exact
thirty-day records survive. No print job, bill or settings row is pruned here.
An expired backlog can remain longer when scheduler throughput is insufficient;
pressure must never shorten the retention window to reclaim space.

An indexed company/time/bill query and a fifty-event page bound limit reads.
There is no proven production capacity reservation or durable disconnected
upload buffer in this slice. At 100,000 events/day, thirty days means roughly
three million event rows before indexes and backups; actual row sizes/rates,
disk headroom, cleanup throughput and failure alerting require owner-side
operational acceptance. Do not advertise a capacity guarantee from this estimate.

Coverage always says **partial**, never complete/healthy merely because no
events exist. It reports available event range, legacy unattributed count,
first/last correlated collection, best-effort failure count and last failure.
DB outages or enclosing rollback can prevent even failure health being stored.
Physical printing remains unknown, including `spool_accepted` and legacy
`agent_acknowledged`. A missing callback is missing evidence, not proof of KOT loss.

Uncovered: direct SQL housekeeping transitions, full sale/item/fiscal/stock
lifecycles, browser printing, local/offline Agent activity, sequence-gap/durable
upload guarantees, FBR/DI/Healthcare/future ERP adapters, global incident review,
complete business-day boundaries, video interpretation, dedicated export UI,
production scheduler/capacity/backup and installed-client acceptance. Seven-day
complete history is **not proven** and absent past events cannot be recovered.

## Release and operator boundary

Owner Admin approval is required. Do not bypass the exact receipt/announcement
gate. The preceding #312 deployment failed its Elaan preflight; that is a separate
known release blocker, not fixed or waived by this diagnostic implementation.
Only successful exact-SHA Actions deployment/live verification/callback can
establish live status. No production access or dispatch is performed by this PR.
