# Durable FBR POS retailer invoice references

Backlog #194. Existing last-inserted-row + 1 allocation could reuse a reference
when older rows were imported last or the highest bill was deleted. Use a
company-row lock and persistent company/prefix counter, reconciled with the
actual numeric high-water mark. Preserve FPOS/FLOCAL year prefixes; new returns
use a durable RET date sequence. Historical numbers and FBR fiscal identities
are never renamed. Counter reservations survive rollback/deletion (gaps allowed).

Migration backfills existing numeric references and adds company/invoice
uniqueness if absent. Historical duplicates stop migration for explicit
reconciliation; they are not silently deleted/renumbered. Until the counter
table exists, the company lock + observed high-water fallback preserves rollout
compatibility but cannot reserve deleted history. Do not deploy code without
its migration.

Local original controller reproduction issued 00003 after rows 00099 then
00002. Corrected current-main SQLite regression: 4 tests / 12 assertions PASS,
covering first bill, tenant isolation, local/returns/year rollover, deletion,
backfill, uniqueness and historical duplicate refusal.

A previous native 10.11 local proof passed 12 concurrent first allocations and
deleted high-water reservation. It is not the required 10.6 current release
proof. This PR now wires the same real allocator into the existing disposable
MariaDB 10.6 --all CI lab, with 12 worker processes, a separate generated lab DB,
second tenant and deletion checks. Worker refuses non-loopback/non-lab targets.
Fresh native and full-suite CI pending; no current native pass or production
migration is claimed. Syntax and whitespace checks pass. No fiscal traffic.
