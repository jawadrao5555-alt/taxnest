# Admin usage accuracy — local regression evidence

## Failure and change

Reading the usage page wrote `now()` to every company's cached activity timestamp, so idle companies appeared just active. It also treated other products' missing PRA counters as zero and refreshed all tenants with per-company writes.

Usage is now a read-only, paginated snapshot of visible companies, batched by company ID. Last Staff Activity uses the latest recorded staff login or POS session heartbeat; no record remains unavailable. Existing cached timestamps are ignored, not deleted. PRA completed bill counts/amounts, active terminals and inventory counts remain PRA-only; non-applicable or missing sources display a dash. An available empty source remains zero. Archived bills retain the existing hidden-bill policy. The company detail compatibility entrypoint is read-only too.

## Local evidence

- Original implementation reproduced the false activity: a staff record two days old became the current time merely by reading usage.
- Targeted usage, admin page and dashboard/detail suite: **25 tests, 250 assertions passed**, PHP 8.4.27, SQLite in-memory.
- Coverage includes repeated reads without writes, an untouched other tenant, absent cached/source tables, real zero versus unavailable, draft/archived bill exclusions, and 55 companies paginated 50 + 5.
- `git diff --check` passed. No data migration or activity backfill; previously fabricated cached timestamps cannot recover history that was never recorded.

The bill total is shop billing volume, not SaaS income or net revenue. Broader per-product usage analytics and the separate Accounts module are outside this correction. Browser/MariaDB and CI status are stated separately in the PR. No merge or deployment was performed.
