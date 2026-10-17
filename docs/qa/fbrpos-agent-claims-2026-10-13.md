# Exclusive FBR POS agent delivery

Backlog #194. Pending polling previously exposed the same invoice to multiple
agents. Conditional company/bill-scoped claim UPDATE wins once before dispatch;
new agents carry the additive claim token through direct and queued callbacks.
Mismatched modern tokens are rejected; supported legacy callbacks without the
new field still work. Claimed invoice expiry becomes verification_pending,
not a blind redispatch; valid late acceptance can still reconcile it.

Manual fail-queue/individual retry and retry jobs re-check active claims at the
UPDATE rather than trusting stale instances. Editing locks/revalidates failed
state before mutating lines. Central service/scheduler guards avoid clearing a
live agent claim. Additive claimed-at migration is idempotent; stored settings,
issued numbers and older agent APIs remain intact.

Current guarded local PHP: 13 tests / 68 assertions PASS (one winner across
stale readers, tenant isolation, local/fiscal exclusions, timeout quarantine,
stale retry refusal, modern mismatch and legacy callback support).
Actual agent.js VM callbacks: 2 tests PASS for token preservation on accepted
and transport-failed results. Native 12-process one-winner proof is wired into
the disposable MariaDB --all CI lab; current native/full CI is pending.

This PR remains DRAFT until company-scoped audited verification recovery in
#194 is available: expired/ambiguous holds must have a practical safe operator
resolution path before deployment. It does not fix duplicate POSTs made wholly
inside an unsupported/modified client, nor claim central FBR verification.
No live credentials, fiscal submission or deployment. No UI/schema reset.
