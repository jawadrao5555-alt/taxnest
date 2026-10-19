# Notification read state and product targeting

Reported: ZFC unread count remains 10 and NEW remains after opening a notice.
Attachment paths were unavailable; this reproduction uses the reported behavior
and the real Alpine component, not claimed screenshot inspection.

Before change, opening detail issued zero acknowledgement requests; the new
component regression failed 0 !== 1. Acknowledgement previously happened only
on closing detail and swallowed errors. The detail component also read the wrong
dataset property: data-tn-seen-endpoint maps to tnSeenEndpoint, not seenEndpoint. It now saves the selected notice on
opening, changes state after server success, and offers retry on failure.
Unread totals and scrollable history cover all eligible seven-day notices
instead of only ten rows. Read entries remain re-openable and older unread
entries do not require a refresh to become reachable.
Repeated acknowledgements remove an ID once, not an arbitrary count decrement.
Automatic featured popups retain the existing latest-ten horizon: expanding
manual history must not start an older featured popup queue.

## Compatibility matrix

| Case | Expected |
| --- | --- |
| PRA / FBR normal owner | Read selected notice on open; persisted across reload |
| Failure, double open, reopen | No optimistic read, retry possible, one decrement |
| Read-only impersonation | Can view; no acknowledgement writes |
| Other user / company / product | Read states and audience remain isolated |
| Legacy all audience | Remains both POS panels only, does not expand into DI |
| DI | Explicit DI audience; sectors from saved sector_type, never POS defaults |
| POS category and hotel modules | Existing category/family resolver retained |
| Existing announcements | No title-based guessing, re-dating, mass-read or retargeting |

Admin now includes DI system and sector targeting beside the existing PRA/FBR
category selector and actual-recipient preview. DI has a bell and selected-ID
acknowledgement via its own web guard. Existing POS settings are not rewritten.
New notices must choose their receiving system in the UI. Old broad announcements
remain as originally saved; edit their audience explicitly when appropriate.

Local Node tests: 12 pass, including original open/read failure regression and
existing featured retry tests. JavaScript syntax, shell syntax and diff whitespace
checks pass. PHP and Chrome are not installed locally; required CI must run full
backend and disposable MariaDB/Chrome desktop/mobile tests before owner approval.
New browser coverage opens real notification details, checks NEW removal and
count persistence; DI fixture verifies product isolation. No production/ZFC
account data or customer messaging was accessed. Not live verified.

Follow-up: refreshed on merged Caller PR #214 (d93596d). Browser rapid bell
close/open exposed an outside-click race: the toggle sat outside the dropdown
dismissal boundary. The boundary now wraps the toggle and menu together on
PRA/FBR/DI; pointer acceptance remains mandatory with no forced clicks.

## Refresh after #217 — 19 October

Merged main 6dd506910cd1d8da87f59d69a10a03cda077e1b1, preserving Rider sync, Caller recovery, public guide/pricing, catalog/address fixes and their checks. Only the current release announcement conflicted.

The last failed full-suite assertion expected the old inline audience query in each layout; the shared bell service now owns that exact audience/published/window predicate. Updated the existing DomainMoveNotice guard to assert the new shared call and predicate while preserving every overlay-suppression assertion. No eligibility/suppression behavior is removed to satisfy the guard.

Browser failure root cause: the targeted notification preflight and the later full acceptance reused the same users. The first run correctly saved featured/routine reads; the second then incorrectly required a fresh featured popup and seven unread routines. Give preflight desktop/mobile their own synthetic users, separate from full-suite desktop/mobile users. Keep all real read-on-open, failed-save retry, NEW removal, count decrement, idempotence, reload, history and cross-product assertions unchanged. No production read state is reset and no browser assertion is weakened.

Local refresh result: 17/17 Node notification acknowledgement/read-count and Rider map tests pass under rc-safe-run; browser script syntax and git whitespace checks pass. PHP/Chrome unavailable locally; exact-HEAD isolated CI results remain required.
