# FBR POS ambiguous transport outcomes

Backlog #194. A timeout/reset/HTTP 5xx or malformed acknowledgement does not
prove IMS rejected a submitted invoice. New agent envelopes identify FBR POS;
those ambiguous outcomes report outcome_unknown, including callback queue
replay. Old timeout/reset messages are recognized server-side. Connection
refusal/name resolution failure remains safely queued. PRA transport semantics
stay unchanged. Code 100 without a fiscal number is held for reconciliation.

Direct FBR POS transport errors, HTTP 5xx, empty/non-JSON acknowledgements and
unexpected submission exceptions become verification_pending, not an automatic
re-POST. Retry jobs and the central submission service refuse existing holds.
Definitive response-free connectivity failures retain normal retries. Fiscal
identity and original USIN remain unchanged.

Latest-main guarded local callback suite: 12 tests / 67 assertions PASS.
Actual agent.js VM: 5 PASS, covering timeout, connection refusal, HTTP503,
legacy PRA behaviour and unknown-outcome callback replay. Syntax/whitespace
checks pass. Direct external transport was not executed locally; no regulator
invoice or live credentials were used. Fresh full/native/browser CI pending.

DRAFT until the audited company-scoped recovery flow from #194 is available,
and response identity correction #195 is merged. A hold must not become a
permanent dead end; explicit reconcile-accepted/reconcile-not-accepted evidence
will provide the safe operator path in its separate PR. Claims #197 must be
combined/tested in sequence; overlapping files require an actual merge and
fresh regression, not copying one full service over another.
