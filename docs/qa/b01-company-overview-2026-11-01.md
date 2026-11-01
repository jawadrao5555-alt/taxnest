# B01 company-account overview checkpoint

Source: planning #316 under master #301. This is the first implementation checkpoint, not completion of B01.

## Compatibility matrix prepared before implementation

| Case | Required behavior |
|---|---|
| Expired paid term | Stored dates/price visible; activation cannot renew it |
| Valid grant and company suspension | Show the two decisions separately |
| Multiple active subscriptions | Follow existing newest-active access authority, flag competing rows |
| Legacy missing price/reason/actor | Unknown remains unknown; no current-price substitution |
| Retry / concurrent / stale requests | Atomic state and audit; one event; stale form cannot undo newer cycle |
| Other company / ordinary company user | Company-scoped records and existing admin guard |
| Existing unversioned status POST | Preserve compatibility; unspecified reason stays unknown |
| Desktop/mobile, themes/modes | Reachable controls, bounded tables, readable evidence |

## Original reproduction and root cause

At main f6ec94b1, actual company GET did not expose the stored 2025 subscription period / PKR 12,499 price. Supplied suspension reason became null. The view only displayed plan/cycle; status controller discarded reason and wrote state/audit separately. Both initial failing HTTP feature reproductions passed after implementation.

## Change

Read-only overview shows the current and historical stored subscriptions, trial/grant evidence, actual charges/payments/proofs and admin account events. The existing access service is the authority. Company control remains separate from subscription allowance and role/feature/quota/system gates. No balance is inferred from proof verification.

Transitions lock the company and commit dual flags plus audit evidence in a transaction. Versioned forms require a reason. Legacy requests remain compatible. Idempotent retries preserve the original actor/reason/time. Lifecycle event IDs protect stale forms through same-second suspend/activate cycles. Matching timestamps prevent reusing a prior suspension reason for an unrecorded cycle.

Independent pagination and source queries remain company-scoped. Arbitrary audit metadata is not rendered. Existing settings, plans, dates, grants and prices are preserved. No migration or new billing policy.

## Evidence

Before host disconnection: 11 targeted tests / 951 assertions passed; 76 existing admin/status/form/type/viewer tests / 551 assertions passed. Pinned Node 22.23.2 asset build, syntax checks and CI DAG contract passed. Full local PHPUnit started but the host disappeared during execution; its final result is unknown and is not claimed.

The final publication was reconstructed through GitHub after the execution environment became unavailable. The final exact content therefore requires fresh CI, including the full PHP suite. CI results and exact SHA belong in the PR description.

Local AF_UNIX creation returned EPERM. Browser discovery also failed on Chromium SingletonSocket. Required CI remains fail-closed, with no egress/socket bypass:
- MariaDB lane: six actual controller workers, one transition/event, stale activation denied, subscription/grant and second company unchanged; explicit PASS marker required.
- Chrome lane: separate synthetic company for 1366/390 px, actual scoped proof queue/tabs, reasoned suspend/activate, escaped reason, actor/time/history, unchanged expired term, six themes/two modes with >=4.5 contrast and bounded tables.
- Final full PHPUnit and all original required lanes remain enabled.

## Remaining scope and release boundary

Payment allocation/reconciliation, trial/grace policy, versioned entitlements/pricing, commissions, full staff activity/operational filters and product migration remain outside this checkpoint. Planning #316 and master #301 stay open.

Elaan retains the existing POS audience and uses plain language about admin review; it does not claim new customer renewal rules.

Owner merge/deploy and Actions live verification are separate. A green PR is not LIVE VERIFIED.

## First exact-head CI findings and correction

Run 41236241326 passed native MariaDB concurrency, dependency/build, workflow safety,
release-chain and agent/Android checks. Its full PHP run found:
- A reconstructed foreign subscription fixture omitted required start_date. The
  fixture now supplies its stored period, without weakening the schema.
- Admin DI review primes AiPageCreditService's per-process ledgerReady probe.
  Later AI Reader tests rebuild a legacy schema without that ledger. The common
  test setup now calls the existing forgetLedgerProbe() reset alongside other
  schema-cache resets; production AI behavior is unchanged.
- Three older POS fixture classes used wall-clock now() while assuming daytime
  and mid-month. The CI ran after midnight PKT, inside the real 06:00 business-day
  cutoff. They now freeze an explicit mid-month noon and restore time at teardown.
  A new actual rider-receipt regression explicitly tests 01:00 against both
  previous-business-day and calendar-day windows, preserving cutoff coverage.

All tests and required lanes must run again on the corrected exact head.
