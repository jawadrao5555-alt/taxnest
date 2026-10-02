# TaxNest structured audit — checkpoint 1

Audited commit: `063859f56343fca4a0226086b6693710e3decc8e` (30 September 2026).
Status: preliminary source audit; not a complete security certification or product acceptance.
Scope: tenant and branch context, POS roles and quotas, Guest House pricing/folio boundaries, Agent print-claim and update helpers.
The original checkpoint changed no application behavior. The follow-up below implements branch-context and month-end quota corrections on the draft PR; no tenant settings, production data or deployment was changed.

## Evidence levels

- Source finding: a concrete inconsistent code path traced in the pinned source. PHP runtime reproduction remains outstanding.
- Concurrency risk: a possible request interleaving shown by the write path; no MariaDB concurrency reproduction yet.
- Policy decision: intentional existing behavior which needs reconciliation with owner requirements.
- Tested: only commands explicitly listed below. Existing CI green does not prove uncovered scenarios.

## Prioritized findings

### A01 — P1: branch identity is chosen from guard order instead of the active panel

Source: `app/Services/BranchContextService.php:316-326`; `app/Http/Middleware/PosAuth.php:40-41,226-231`; `app/Models/Scopes/CompanyScope.php:29-50`.

`currentUser()` always chooses the first signed-in identity in fbrpos → pos → health → web order. PosAuth independently binds currentCompanyId from the pos identity.
With simultaneous FBR/POS sessions for different companies, branch lookup can combine the FBR identity with the POS tenant scope. For ordinary users these conflicting company predicates return no branch. The service then returns null, which branch-filter consumers interpret as company-wide.

Example to reproduce: company A's branch-confined POS manager and company B's FBR session in the same browser; request the POS hotel stay list. The branch service must use the POS manager and retain A's allowed branch, rather than resolving B or returning an unrestricted null context.
Impact is a branch confinement gap within the active POS company; cross-company record disclosure has not been demonstrated.

Required fix/test: explicit active-panel identity, matching company context, two simultaneous guards with different tenants and roles, and a same-company dual-session case. Preserve legitimate owner ALL and branch-less legacy companies.

### A02 — P1: manager auto-selection can return a branch rejected by its own access check

Source: `app/Services/BranchContextService.php:99-107,183-195,351-371`.

Manager access uses the branch_user pivot, but autoSelectBranch chooses default/head-office/first-active without checking that set. getActiveBranchId calls setActiveBranch, ignores a false result, and returns the rejected candidate anyway.

Example to reproduce: manager's pivot contains branch B, default_branch_id is active branch A, session branch is absent. accessibleBranches contains only B; setActiveBranch(A) returns false; getActiveBranchId still returns A. A similar path occurs when the default is absent and head office is outside the pivot.

Required fix/test: choose from accessible branches and honor assignment failure. Include stale default, inactive pivot branch, no permitted branch, and unchanged owner/cashier behavior. A denied branch must never become company-wide access merely by returning null.

### A03 — P2: paid branch slot enforcement is not atomic

Source: `app/Http/Controllers/PosBranchController.php:60-92`; `app/Services/PlanLimitService.php:520-575`.

store performs count-based canAddBranch and then Branch::create outside a transaction/tenant lock. With one slot remaining, requests R1 and R2 can both pass the same count and both insert. First-branch head-office selection is also a separate exists check, so two initial requests can both mark themselves head office.

Required reproduction: two real database connections synchronized after the quota check; verify actual row count and head-office uniqueness.
Required fix/test: serialize quota check and insert using a stable company-level lock; include paid slots, overrides, single-request behavior, and separate tenants. Review branch stock adoption in the same consistency boundary.
Evidence level: concurrency risk, not an observed production overage.

### A04 — P2 review item: POS bill quota check happens before the creation transaction

Source: `app/Http/Controllers/PosController.php:3681-3705,3867+`.

The final bill quota is checked before DB::beginTransaction. Inspecting the creation path did not find a company quota recheck inside the transaction. Different counters can pass the same final available quota before either persists a completed bill. Serial/deal locks do not by themselves protect the earlier quota decision.
Required follow-up: include observers, all finalization paths and MariaDB interleaving before declaring a universal defect. Keep provisional replay, final promotion, returns and deleted-final counters intact.

## Policy items — do not silently change

- Deactivating a cashier's branch deliberately moves the cashier to head office; PosMultiBranchScopeTest explicitly expects this. It is not an accidental regression. Decide whether the owner's intended branch confinement requires reassignment approval instead.
- PosFeatureService::planAllows deliberately grants addon-only features during active free-access overrides and fails open for specific schema-lag conditions. This needs a compatibility decision against strict paid entitlement; do not revoke historical grants automatically.
- Branch filters deliberately include legacy NULL branch rows. That is a migration/access policy, not proof of a tenant leak.
- replit.md still contains older package prices and the retired Pro tier. Treat current code/catalog as authority; documentation needs alignment.

## Reviewed protections and limits

- CompanyScope adds tenant predicates when currentCompanyId is bound. Agent print content/results use agent_company and company-constrained queries.
- Hotel room removal locks the room, refuses open stays, and archives historically referenced rooms.
- Hotel folio append/settlement uses stay locks and idempotency keys.
- POS and Hotel quote code applies tax after discount; exclusive whole-rupee tax matches existing POS convention. No blanket tax-calculation failure was inferred.
- Agent print results fence upgraded callbacks with claim tokens and ignore non-printing state; tokenless legacy compatibility still requires topology regression review.
- Local update helper regression command passed:
  `node --test pra-agent/test/update-install-result.test.js pra-agent/test/update-retry.test.js`.
  Two test files passed. This does not prove a real Windows installation or printer output.
- No PHP CLI or Composer is present in this audit workspace. PHPUnit/MariaDB/HTTP/browser reproductions were not executed here.

## Remaining audit passes

1. Dynamic branch/guard reproductions, authorization on controllers not yet reviewed, and full package/add-on write-path coverage.
2. Billing concurrency, replay, cash/card changes, discount snapshots and receipt/report reconciliation.
3. KOT claim/recovery across one/many counters, legacy callbacks, restarted Agent and print uncertainty. Do not attribute Pizza Master's historical missing KOT to a new finding without its logs.
4. Windows update orchestration and installation evidence; reference UI and Guest House reception scenarios.
5. Queues, notification audiences, data retention, performance and recovery boundaries.

Owner action: no approval relay or production action for this audit checkpoint. This report is evidence for prioritized follow-up, not a behavior fix.

## Follow-up implementation — 30 September 2026

Baseline Actions run 36687500011 at HEAD 5b073dddcd6cef0ada9f324c3724529c4fee38e8 reproduced A01 (actual branch NULL, expected 2) and A02 (actual branch 1, expected 2). Full PHPUnit: 5202 tests, 40957 assertions, 3 failures, 28 deprecations, 9 skipped. The third failure was the existing monthly-quota clear test on the last calendar day. This is CI evidence, not local or production reproduction.

Corrections under test:
- Resolve the authenticated panel in POS/FBR/Health/DI middleware and carry it in the shared branch switch form. Validate the requested guard before binding its company. Old single-login switch requests remain valid; ambiguous requests are refused.
- Honor a failed default selection and fall back to accessible assignments. Managers with branches but no accessible assignment receive 403 instead of an unrestricted NULL branch.
- Use half-open calendar-month ranges for deleted-final DATE ledgers. SQLite date-cast values can contain a time suffix on the last day; an inclusive date-string upper bound excluded them. Pin quota-clear regression dates to mid-month, last day and leap day. Runtime confirmation of this correction is pending CI.

Compatibility matrix:
| Configuration | Required behavior | Coverage |
|---|---|---|
| POS cashier plus FBR login, same/different tenant | POS cashier branch and rights retained | Active-panel regression provider |
| Manager default outside pivot | Select accessible pivot, never rejected default | Original A02 regression |
| Manager with no assignment, company has branches | Refuse rather than company-wide access | New no-assignment regression |
| Shared switch, multiple guards | Act under explicitly authenticated panel | New switch regression |
| Requested guard signed out | 403 | New switch regression |
| Old single-login form | Still switches allowed branch | New legacy regression |
| Owner ALL, inactive-cashier HQ fallback, branchless audit/legacy | Existing policy preserved | Existing branch suite |
| Clear current-month finals on last/leap day | No restored quota; older bills excluded | Date-provider quota regression |

No quota concurrency change or dependency advisory suppression is included here. A03/A04 remain separate reproduction work. Baseline dependency audit also reported low-severity Laravel GHSA-jh5r-qr3c-85q8 and Flysystem GHSA-cxf4-7mrp-vvpr; dependency remediation remains pending.

Local PHP/Composer, MariaDB application runtime and Chrome HTTP smoke are unavailable in this partial workspace. Post-fix PHPUnit and browser verification must be read from the new CI result. Do not approve/relay this draft while those checks are unresolved.

## Compatibility correction — 2 October 2026

Post-fix run 36726838178 failed with 2 errors and 10 failures. Returning middleware 403 for an unassigned manager conflicted with stock's existing DENIED_BRANCH_ID=-1 empty-view contract; lean schemas without branch_user also failed before authorization handlers could respond. Branchless companies now exit before resolving assignments; absent pivot table yields no mappings. Read context returns -1 for a branch-enabled manager without access, while stampBranchId refuses writes with 403. applyToQuery's denied context never includes legacy NULL rows. Synthetic browser Hotel manager is explicitly assigned the fixture's Hotel branch rather than depending on the old invalid fallback. No real user/tenant assignment is changed.

New CI must validate this correction. Local runtime bootstrap was attempted; package-manager sandbox permissions prevent normal system installation. Dependency audit remediation remains pending; no advisory suppression.
