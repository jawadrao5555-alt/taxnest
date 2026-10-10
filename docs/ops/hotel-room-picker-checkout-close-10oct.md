# Hotel room picker and checkout Close follow-up — 10 October

Baseline: deployed PR172/main a1fdc30ad6132cdd7c1f34a5800a16a9d25059dd. No production browser/DB/SSH or secrets accessed.

## Reproduction
- The shipped check-in controller fetched every active in-service room, and the form rendered every fetched room. Existing POST date-overlap validation did not make the dropdown available-only.
- A shipped-controller Node VM regression retained a stale unavailable room selection against the baseline and passes after availability refresh. This is client behavioral evidence, not a PHP runtime claim.
- A shipped receipt-controller regression against baseline failed to navigate after explicit Close following successful checkout; the corrected controller passes. Draft/collection Close remains on the current page.
- PHP/Composer and Chrome executable are unavailable in scratch. Required isolated CI executes the HTTP, MariaDB and real Chrome evidence.

## Correction and compatibility
- The initial direct check-in view and a front-desk-authorized date refresh endpoint share one guest-free availability query. Tenant/active branch, active/in-service rooms, open stay/assignment date overlaps and walk-in housekeeping are enforced.
- Actual checked-in occupancy blocks a walk-in regardless of scheduled departure. Booking and reserved check-in enforce that again under the existing room lock, including stale UI/concurrent requests. A reservation outside overlapping dates still remains possible.
- Date changes refresh real option choices. An unavailable selection is cleared, and a failed availability read invalidates old quotes.
- Confirmed checkout keeps its receipt open for printing. Explicit Close then navigates to the native Hotel Dashboard. Draft/check-in/collection Close and standalone historical receipt reprints retain their behavior.
- No tax/PRA settings, fiscal snapshots, saved printer/paper routing, tenant configuration or application customer-amount logic changes.

## Coverage
- Eleven shipped-controller Node tests, including the six PR172 guards; JavaScript syntax checks.
- Three real HTTP regressions: occupied/dirty/free picker, actual checkout then cleaning reappearance; reservation/assignment dates, adjacent future availability and tenant/branch isolation; overdue occupancy refusing new walk-in and reserved check-in.
- Real Chrome at 1366px and 390px: actual occupied room absent from check-in options; completed checkout remains in receipt until Close, then actual Dashboard URL.
- See PR body for exact-head final required CI results. Physical paper and external PRA acceptance remain owner live checks.

PR170's customer-amount review is separate. Owner approval/relay is required; this branch is not merged or deployed by the agent.
