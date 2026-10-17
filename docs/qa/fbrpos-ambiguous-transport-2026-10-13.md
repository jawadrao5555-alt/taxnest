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

## 16 October current-main integration

Merged deployed main 6f89f1d84e30e0e17fb248852889d19713c7a054 into the
existing PR history. Resolved five conflicting files by preserving #195
response identity, #197 exclusive claims/retry guards and both regression
sets alongside unknown-outcome quarantine. Owner recovery #199 is deployed;
accepted/rejected evidence remains owner-audited and no blind retry is added.
Code-100 invalid/missing identities and acknowledgements missing a code are
held even when an old callback labels them unsuccessful. Direct submission
returns the same held state it persists, including lowercase response codes.

Before-fix VM reproduction against main: 4 fail / 2 pass. After correction:
79/79 platform-independent Agent tests PASS; focused release/claim/unknown
suite 12/12 PASS. Callback tests include wrong claim denial, matched unknown
callbacks, legacy replay refusing demotion and blocked reclaims/retries.
Existing main owner recovery and tenant isolation tests remain present.
Lock portability/malicious URL/local-fork and exact-SHA Agent release-chain
checks PASS. Node syntax and whitespace PASS. Local PHP/MariaDB/Chrome are
unavailable; fresh exact-HEAD CI must provide application/native/browser proof.
No real regulator/IMS request or live company access was made.

Version is 1.13.19 because immutable 1.13.18 is already released. Package and
both lock root versions align; structural comparison proves every other lock
field unchanged, with only its reviewed fingerprint rebaselined. No saved
company/printer/device/tax configuration changes. Supported older agents retain
callback support; new agents add product-specific ambiguity and claim evidence.
Actual Windows packaging/publication and installed PC acceptance remain after
owner approval. Earlier DRAFT dependency notes are superseded by this deployed
recovery/identity/claims integration; readiness still requires fresh CI.

Direct ambiguous outcomes retain their exclusive claim while changing to hold;
there is no intermediate pending/unclaimed state for a competing worker.
Definitive connection refusal/rejection still releases the claim for retry.

Added a direct-service regression with overridden transport (no network): six
ambiguous responses traverse the actual submission service; a database-write
observer rejects any intermediate pending/unclaimed state, and retry/claim
checks must remain blocked with exactly one fake transport request. The narrow
transport method is protected to permit this isolated test. Local PHP is
unavailable; this new application regression awaits fresh CI execution.

## 17 October refresh after owner-approved preview release

Merged main 8ecfb8667dc9b2560d84525a45093319a08f4d86 (#212) into
this PR history. Production run 39007005447 passed deployment, live SHA/markers
verification and relay callback. The only merge conflict was deploy/elaan.yml;
retain this upcoming FBR protection release's unique announcement. All #212
same-origin PDF fixes, production-CSP browser fixture/preflight and evidence
capture remain. No FBR/application/Agent/test implementation changed relative
to previously passing cff5dd8c; compare verifies those paths are identical.
Fresh local Agent suite 79/79 PASS, lock portability/release-chain/Node syntax
and whitespace checks PASS. Local PHP/MariaDB/Chrome execution unavailable;
combined fresh exact-HEAD CI is required before owner approval. Do not reuse
previous HEAD's green checks. No production access, merge/deploy/build dispatch
by this refresh; Windows 1.13.19 publication remains after owner approval.
