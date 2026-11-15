# SaaS platform expense records (#301 B01/R07)

Bounded record-only ledger, separate from tenant sales and Healthcare expenses.
Only a currently persisted SaaS `super_admin` may view or write it; no provider,
transfer, bank payment, refund, tax classification or balance is executed or
inferred. No company entitlement, subscription, rate or commission rule changes.

An expense has an explicit date, lowercase category slug, reference, optional
escaped notes, positive exact PKR paisa, actor snapshot and timestamp. Date-entry
and audit timestamp semantics use the displayed app timezone. The free-form
category slug introduces no assumed accounting taxonomy. There are no attachment or
bank/account/card credential fields; the notes label warns against entering secrets.

Entries are append-only through the application. A full reversal copies the
original amount/category and retains a unique original link; it cannot reverse
another reversal or predate the original. Corrections require a separate new
expense after reversal. No delete/edit endpoint exists. The forward migration
retains records on rollback and reapplication. Actor removal cannot cascade
financial history.

Actor-row locking, current locking identity reads, request UUID + normalized
fingerprint and a unique reversal constraint protect retries and concurrent
writes. Changed data with a used ID returns409. Audit is mandatory within the
same database transaction; failure rolls back the entry. Bounded database
transaction retry performs no external actions. The browser stores a pending
request only in its same-tab session, never auto-submits on refresh, and retries
the original identity explicitly after a lost response. Definite validation
failure permits correction; uncertain responses retain the retry identity.

The admin page filters by dates, exact category/reference, displays expense,
reversal and net recorded totals, immutable actor/request history and preserved
originals. These are entry-date totals, NOT cash/bank balance or tenant income.
Routes retain normal web CSRF middleware and response no-store protections.

Synthetic PHP tests cover exact large/decimal money, zero/negative/precision
rejection, retry/change conflicts, reversal once-only/full amount, date boundary,
audit rollback, demoted actor/read-only denial, original history, escaped notes,
filtered totals, no edit/delete route and no tenant-payment writes. Native
concurrency and desktop/mobile browser journeys are separately required; neither
syntax checks nor SQLite tests certify native races, deployment or live use.

Local final backend run (PHP8.4, egress-blocked SQLite): 9 tests / 62 assertions
PASS. Native MariaDB and browser acceptance remain pending and must be recorded
against the final integrated commit, not inferred from this focused result.
