# TaxNest full maturity release-candidate ledger

## Authority and immutable baseline

Owner assignment: `TaxNest_Replit_Master_Implementation_Command_1789552061256.md`,
read in full on 2026-09-16. This integration is **NOT MERGED — NOT DEPLOYED**.

- Audited and freshly fetched main: `0677abbc3fec912b3011dd6449be6a17263db84d`.
- Fetch and clean worktree creation: 2026-09-16T09:49:09Z, exit 0.
- Worktree: `/tmp/taxnest-maturity-rc`.
- Branch: `replit/taxnest-full-maturity-rc-20260916`.
- Original workspace and its pre-existing encrypted backup are not implementation targets.
- GitHub revalidation: 2026-09-16T09:50:20Z, main unchanged, no open PRs.
- Hotel hotfix remains three ahead of main: `0288a777255f2774daca7c541dbcf044ad8eaf89`.

## Operational boundary

All implementation, dependency installation and verification are isolated development work.
No production queries, invoice submissions, workflow dispatch, merge, release publication,
service changes, file changes or permission changes are authorized by this ledger.
Every production procedure is **PROPOSED — NOT EXECUTED — OWNER APPROVAL REQUIRED**.
Tests must use synthetic data and refuse external fiscal network access.
Generated evidence must not contain credentials, personal data or customer payloads.

## Acceptance matrix

Source existence is not acceptance. Each domain needs positive behavior, denied-role,
cross-company, cross-branch, feature-off and failure-path evidence where applicable.

| Domain | Minimum release-candidate acceptance |
|---|---|
| DI | authoritative acknowledgement, environment provenance, ambiguity hold, immutable submission, idempotent batch/retry, redacted diagnostics |
| PRA POS | serialized fiscal submission, explicit lock failure, replay-safe billing, consistent day-close and access gates |
| FBR POS | safe errors, product/tenant isolation, submission and inventory controls |
| Retail | billing, inventory identity, returns and branch ownership without restaurant leakage |
| Restaurant/KOT/KDS | persisted print intent, multi-counter claim isolation, retry/callback-loss handling; hardware evidence distinguished |
| Hotel | canonical/fallback recognition, front desk/outlet separation, role-specific routes, stay/folio tenant ownership |
| Pharmacy | POS and Health domains distinct, batches/expiry/stock and branch authorization |
| Inventory | stock movement reconciliation, numbering/constraints and native database tests |
| Services | all 30 typed workflows with legal transitions, operational fields, ownership, invoice linkage and mobile forms |
| Health/Hospital | patient/clinical/financial access, product/branch isolation, OPD/IPD, pharmacy, accounting and readiness evidence |
| Billing | existing annual/product rules and saved entitlements preserved; no silent settings rewrite |
| Admin | authenticated and scoped management/reporting; impersonation preserves boundaries |
| Printing/agents | explicit artifact provenance, lease/retry tests, diagnostic health dimensions, no false hardware certification |
| Offline/realtime | dedupe, poison handling, callback recovery and polling fallback |
| Mobile/PWA | role-appropriate desktop/mobile journeys, artifact provenance guard, critical actions visible |
| Deployment controls | existing safety gates preserved; stable validate job aggregates every mandatory PR gate |
| Recovery | dry-run permission policy, encrypted synthetic backup/restore, integrity verification, RPO/RTO and monitoring runbooks |

## Phase ownership and evidence index

| Phase | Requirements / deliverable | Implementation and evidence |
|---|---|---|
| A | baseline, findings, issue/category/acceptance ledger and traceability | this ledger; final-report.md; traceability.md |
| B | dependency audit/upgrades, safe fiscal errors/logging/locks, release validation, secret/artifact controls, dry-run permissions | security/dependency, DI, agents and recovery phase reports |
| C | minimal Hotel canonical resolver, legacy/off/non-Hotel preservation, role/company/branch and browser acceptance | category/Hotel phase report |
| D | F01–F11 source fixes, simulated outcomes, native concurrency, production query runbook | DI and database phase reports |
| E | manifests, diagnostic dimensions, claims/replay/printing/realtime, Android provenance and safe builds | agents phase report |
| F | disposable MariaDB full/upgrade migration, schema/integrity/concurrency and SELECT-only runbook | database phase report |
| G | mandatory split CI jobs, stable fail-closed validate, safe local/network harness | CI phase report |
| H | preserve 46 commercial profiles plus general; 12 existing + 18 additional service workflows; generated coverage | category phase report |
| I | separate Health product clinical/financial/role/tenant/branch verification | Health phase report |
| J | encrypted local recovery rehearsal, dry-run production tools, monitoring and RPO/RTO | recovery phase report |
| K | 82-artifact classification, ref/PR equivalence, prevent sensitive/generated artifact commits | recovery/security phase reports |
| Verification | all mandatory commands, exact counts, upstream reconciliation and diff review | final-report.md; evidence/ |
| Delivery | focused checkpoint commits, isolated branch, one Draft PR, explicit blockers and stop | final-report.md |

## Audit findings requiring an evidence-backed verdict

R01 dependency advisories; R02 required-CI coverage; R03 backup permissions;
R04 Hotel canonical category; R05 exception disclosure; R06 PRA lock fallback;
R07 ambiguous ZIP selection; R08 installed artifact provenance; R09 recovery evidence;
R10 stale Android/generated inputs; R11 waiting Live Ops; R12 schedule observation;
R13 cached configuration permissions; R14 category-count discrepancy.

F01 synthetic success; F02 permissive acknowledgement; F03 environment labeling;
F04 ambiguous transport replay; F05 endpoint/confirmation discrepancy; F06 sensitive logs;
F07 privileged fiscal identity changes; F08 mutation/submission race;
F09 fiscal hash coverage; F10 batch accounting idempotency; F11 reservation/timeout consistency.

No finding is closed by this initial ledger. Final classifications must use
Closed / Reduced / Blocked / Not applicable and cite executed evidence.

## Explicit business-scope decision

Preserve all 46 marketed business profiles and the `general` fallback.
`general` is not a marketed vertical. Shared configurable service workflows provide
operational tracking, not regulated clinical, legal, financial, educational or licensing claims.
No existing tenant settings are silently rewritten to adopt a new workflow.

**Why:** The owner explicitly authorized conservative configurable defaults while
prohibiting invented business rules and destructive category normalization.

## Workflow source integrity

The workflow copies include all seven mandatory lanes, the native-seven
supplement, Node 22.23.2 with npm 10.9.2, isolated digest-pinned MariaDB 10.6.23
containers, and fail-closed sanitized evidence publication. These files and
hashes identify source, not a successful CI run or permission to deploy.

Current byte-identical active/proposed workflow hashes are:

| Workflow | SHA-256 | Git blob |
|---|---|---|
| `build-agent.yml` (both paths) | `0eb9ccc78d9d53ce6beb5ae6ad3d3f0437f6da9e31187ed4b9962615ee5ed6be` | `ea95479996ae6a24f8b36176df3f5e065e673aec` |
| `pr-checks.yml` (both paths) | `f3450fe4d7ce7871264f84654fad6e1d703940fccb49cb50755c94088fc435cd` | `f788f3c2d1ce39a9fc98b932b7ee4c88ed96501e` |

Regenerate the workflow correction patch against its authoritative base with:

```bash
git diff b4f7f1610eb3a3c012b76eda599ef43c22abbba5 -- .github/workflows | gzip -n \
  > docs/release-candidate/proposed-workflows/workflow-authorization.patch.gz
sha256sum .github/workflows/build-agent.yml \
  docs/release-candidate/proposed-workflows/build-agent.yml \
  .github/workflows/pr-checks.yml \
  docs/release-candidate/proposed-workflows/pr-checks.yml \
  docs/release-candidate/proposed-workflows/workflow-authorization.patch.gz
```

The resulting current patch SHA-256 is
`54b932741f01cf27cc7094309429b46c1ec7612cdeea77e4b90a725aa69c496f`.
The prior `recertification/workflow-authorization.json` records tool
capability for the older proposal only. It is not authorization for this
final-source proposal and does not attest this new proposal hash.