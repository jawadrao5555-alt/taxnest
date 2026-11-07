# B07 — Security, permissions, privacy, retention and recovery

**Primary source records:** #296 authentication, #300 boundaries, #312 consent/restore safeguards; audit S32-S40.

**Dependencies:** Shared foundation for all bulks. Coordinate UI/enrollment B08, access decisions B01 and diagnostic tools B06.

## Delivery contract

This is an owner-requested bulk scope/acceptance record, dated 30 October 2026 (Asia/Karachi), under master #301. **Documentation/planning only: no runtime fix, test execution, readiness, deployment or completion is claimed by this update.** The owner explicitly requested related bulk PRs; preserve that grouped delivery while keeping separable internal checkpoints and dependencies.

- Reconcile current code, merged sources and deployed-version evidence before implementing; a missing control in a read-only role is not automatically a missing feature.
- Reported media/UI evidence is an investigation input. Reproduce with fictional local tenants before claiming root cause. Preserve earlier evidence classification and unresolved ambiguity.
- Universal fix for compatible tenants; preserve saved settings, permissions, paid terms, immutable financial/fiscal history and supported legacy Agents. Missing values receive safe defaults only; existing values are not silently reset.
- Reuse canonical services/policies. Optional changes require the established explicit selection/consent; ordinary bug fixes do not need a new switch for every symptom.
- Validate the original failure, a previously working configuration, a second tenant and relevant one/many branch/device/printer topology. Use real local Chrome/MariaDB when affected, plus meaningful negative/concurrency/rollback tests.
- Ready means reviewed exact implementation head, relevant acceptance, clean mergeability and latest required CI including validate. Documentation CI is not feature acceptance.
- Deployed and Live verified are separate states. Owner release approval remains required; physical Windows/printer, central fiscal and connected-client outcomes require actual evidence beyond generic CI.
- No production credentials/customer datasets, raw media or private amounts in this public repository. No customer communications, settings changes, merge, deployment, fiscal retry, reprint or grant change is authorized by this document.
- #237 remains HOLD. Source PRs remain traceable; this plan does not automatically close or merge them. Unrelated earlier holds remain intact.

## Evidence ledger

| Checkpoint | Implementation PR / exact head | Reproduction and retest | Required CI / UI / DB / device | Owner release / deployed SHA / live proof | Remaining gaps / state |
|---|---|---|---|---|---|
| Scope captured 30 Oct 2026 | This planning record | Not executed here | Document coverage review only | None | Queued; original whole-outcome checks remain open |

For each implementation stage, add actual evidence and its date. Do not overwrite historical rows or mark the whole bulk complete after one partial slice.

## Required scope

- [ ] B07-R01 — Secure single-use expiring owner password reset, consistent policy, notification and session invalidation; avoid support sharing reusable owner credentials.
- [ ] B07-R02 — Complete failed-login limits plus two-step enrollment/challenge/recovery/anti-replay with safe existing-owner onboarding; inspect already-shipped #293 rather than duplicating it.
- [ ] B07-R03 — Role/capability separation for owner, billing, support, operations and release; server authorization across UI/API/files/exports/jobs/websockets and company/branch boundaries.
- [ ] B07-R04 — Read-only vs full-access impersonation with actor/target/reason/session boundaries, before/after write audit and telemetry classification; no hidden writes under read-only access.
- [ ] B07-R05 — OAuth/viewer/share/device/consultant expiry and revocation across sessions, refresh/background jobs and cached access; no secret exposure or enumeration leaks.
- [ ] B07-R06 — Safe supported app distribution and release provenance; investigate detection issues instead of normal instructions to disable device protection; no unsupported malware/safety claim.
- [ ] B07-R07 — Data-flow/consent/retention inventory for AI, support, Caller ID, messaging and Healthcare data; minimal collection, scoped access and versioned disclosures.
- [ ] B07-R08 — Company deletion request lifecycle with verified requester, export/hold/purge/retention evidence and accurate deadlines; distinguish optional copies from required fiscal/accounting evidence.
- [ ] B07-R09 — Backup freshness and actual restore drill with RPO/RTO, tenant restore and revocation/deletion replay; preserve #312 optional-copy restore quarantine/keys policy and disclose physical backup limitations.
- [ ] B07-R10 — Separate billing destinations, SMTP/secrets, app release and AI config surfaces with masked secrets, audited sensitive changes and structured safe controls.

## Bulk acceptance

- [ ] B07-A01 — Local real-app cross-tenant/branch/role denial covers every changed endpoint including exports/files/jobs; read-only impersonation cannot mutate.
- [ ] B07-A02 — Reset expiry/reuse, login throttling, MFA replay/recovery and existing-owner migration cannot create bypass or accidental permanent lockout.
- [ ] B07-A03 — Revoked connections/devices/viewers cannot refresh or continue background reads.
- [ ] B07-A04 — Restore drill proves scoped recovery and reapplied revocations/deletions; optional copies cannot reappear contrary to the documented quarantine.
- [ ] B07-A05 — No blanket security-protection disable advice or private customer/secrets in repo/logs; consent and key/purge failure semantics verified.

## Audit coverage — primary owner

| Audit ID | Original classification | Required investigation/outcome |
|---|---|---|
| S32 | P1 · LIVE | Secure owner password reset and consistent password/session policy |
| S33 | P1 · LIVE | Safe app distribution without default protection-disable guidance |
| S34 | P1 · TEST | Granular roles, MFA, reauthentication and tenant isolation |
| S35 | P1 · LIVE/TEST | Impersonation actor/target, permissions and meaningful audit |
| S36 | P1 · TEST | Viewer/share/OAuth/consultant/device scope and revocation |
| S37 | P1 · LIVE/TEST | Deletion lifecycle, required retention and restore replay |
| S38 | P1 · TEST | Backup freshness and verified restoration/RPO/RTO |
| S39 | P2 · LIVE/DESIGN | Privacy/data-flow inventory for assistant/support/health/device surfaces |
| S40 | P2 · LIVE | Separate sensitive settings, masking and audited configuration changes |

## Named acceptance scenarios — planned, not passed

| Test | Scenario | Required pass evidence |
|---|---|---|
| T20 | Company soft-delete/restore/purge | Required financial/fiscal lineage retained; restored tokens/sessions safely handled |
| T21 | Backup restore | Tested recovery time, data-loss window, tenant boundary and deletion replay |
| T22 | Cross-tenant and branch access | UI/API/export/file/share/websocket/job denies unauthorized IDs |
| T23 | Read-only impersonation | State-changing endpoints blocked; telemetry differentiated; actor retained |
| T24 | Password reset/email change | Single-use expiry, notified owner, session policy and audit verified |
| T25 | MFA/rate limits/manager PIN | Authorized QA checks, recovery and lockout do not create permanent denial loop |
| T26 | OAuth/device/consultant revocation | Access and refresh stop across sessions; no stale background access |

Cross-bulk cases remain coordinated: MT12 spans receipt preservation (B03) and unread updates (B08); T29/T33/T34 also inform B04/B05; B07's denial/revocation controls apply to every product. These references do not create duplicate implementation ownership.
