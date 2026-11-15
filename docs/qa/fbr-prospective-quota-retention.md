# Prospective FBR paid quota deletion retention

Scope: only new-policy subscriptions with a valid immutable lifecycle snapshot. Legacy subscriptions keep their previous counters and deletion behavior. This is exact quota evidence, not cash history, fiscal authority, or an archive of old invoices.

## Reproduced gap

At source `53c4713`, a synthetic paid retail FBR company with a two-bill limit and one completed local sale had one remaining slot. The actual authorized manual provisional-delete route removed the sale and incorrectly restored two slots. The existing day-close provisional wash deletes the same counted rows.

## Implementation

The existing locked manual-delete and day-close wash transactions now capture immutable company/transaction identity, original creation time and countable sale status before deletion. The new forward-only `prospective_fbr_quota_retention` table is additive. Live-row anti-join prevents double counting. Quota uses the same current-month/first-paid boundary as live rows, so trial and previous-month rows do not consume paid allowance; early renewal cannot reset that boundary. No historical count is inferred or backfilled. Missing schema or inconsistent retained evidence stops the prospective deletion instead of silently freeing allowance.

Existing stock restoration, cash totals, report data, receipt access, fiscal eligibility and official submission paths are unchanged. Only eligible local/non-fiscal rows are accepted by the capture helper. Authorized company purge includes this company-scoped evidence; bin/restore do not rewrite it.

## Verification

`FbrProspectiveQuotaRetentionTest` exercises the actual authorized manual-delete route, actual day-close wash, duplicate deletion response, anti-join, transactional rollback, immutable conflict, trial/old-month exclusion, early renewal, official-row rejection, legacy behavior, missing schema and tenant-scoped purge. Synthetic data only; HTTP fake asserts no fiscal calls. Native MariaDB concurrency acceptance is separately required and must not be claimed from SQLite results.
