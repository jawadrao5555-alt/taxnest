# Subscription list review

The short live browser review gave inconsistent empty-state/pagination notes.
The current view actually says `No subscriptions found.` and only renders
pagination when companiesPage has multiple pages. Both list and empty state
use the same paginator. The reported coexistence has not been reproduced;
this test-only PR does not alter queries or assert a production fix.

Regression scenarios use the actual authenticated route: an empty database;
21 separate subscribed companies across two pages; an inactive filter with no
matching companies. Assert paginator totals, displayed count/empty state and
no subscription writes. Existing tests for renewed-company deduplication and
second-company history remain unchanged.

Local PHP syntax lint and diff checks pass. Supported PHP >=8.4.1 runtime and
dependencies are unavailable locally; full PHPUnit is pending exact-HEAD CI.
No billing, pricing, subscription assignment or tenant setting changes.
