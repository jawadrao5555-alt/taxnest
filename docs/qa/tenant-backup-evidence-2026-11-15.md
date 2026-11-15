# Tenant-bound retained backup metadata (#319)

This is a narrow working source adapter, not a claim of complete backup coverage.
The old global `backup:database` dump is deliberately not attributed to tenants:
it has no tenant manifest or retained source identity.

## Implemented contract

- `POST /api/diagnostics/backup-evidence/{source UUID}` is stateless and default-deny.
  Trusted deployment configuration must enroll that opaque source UUID with an
  enabled flag, SHA-256 bearer-token hash, and explicit permitted company IDs.
  This change enrolls nobody and sets up no production credential.
- Only tenant ID, event UUID, reported status (`retained`, `failed`, `deleted`),
  offset-qualified event time, and optional producer-reported retained-until are
  accepted. Unknown fields, artifact paths, payloads, secrets and purported
  completeness/restore assertions are rejected. Requests are bounded to 4 KiB,
  60/minute per source plus the route IP limiter; events cannot be over 90 days
  old or more than five minutes ahead.
- The source/event identity is unique. Identical retries return the original
  receipt time; changed retries return 409 without replacing the original.
  Disabled/revoked enrollment refuses new/retried ingestion immediately.
- Retained rows carry server receipt time, reported event/retention time and an
  explicit `authenticated_producer_report` provenance. Evidence expiry is a
  retention-policy deadline (90 days by default, bounded 7–365), not proof that
  the underlying backup still exists or was deleted. No purge is scheduled by
  this patch; existing diagnostic data is not deleted.
- Existing OAuth tenant grants/name/ID resolution authorize reads. Branch reads
  remain unavailable because a tenant-wide backup report does not prove branch
  scope. Safe metadata only is returned, never the internal deduplication hash.
  Empty sources are `NO_RETAINED_EVENTS`, absent schema is `UNAVAILABLE`; retained
  reports remain `PARTIAL`. `complete` and `seven_day_complete` remain false.

## Remaining external evidence

An authorized operator must register an actual trustworthy producer, bind its
manifest to explicit tenants, and demonstrate receipt collection/retention over
real elapsed time. Backup byte integrity, artifact survival/deletion, restore
execution, RPO/RTO, device seven-day completeness and production health remain
unverified. An authenticated report of `retained` is not independently verified
backup success. No global dump contents, production paths, credentials or live
backup operations were accessed for this implementation.

## Verification

Synthetic feature tests cover tenant denial, source revocation/default-off,
replay and changed-data rejection, redaction, invalid fields/time, rate/size
bounds, and truthful partial/empty states. The existing native diagnostic probe
now exercises the registered empty source plus synthetic idempotent ingestion
and scoped reporting. Native execution remains a hosted-CI requirement.
