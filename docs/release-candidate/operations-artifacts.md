# Phases B (production permission assets), J and K — evidence ledger

**Scope owner:** release-candidate operations/artifacts
**Baseline:** `0677abbc3fec912b3011dd6449be6a17263db84d`
**UTC window:** 2026-09-16 (local, disposable-only worktree)
**Production action:** none. **PROPOSED — NOT EXECUTED — OWNER APPROVAL REQUIRED**

## Deliverables

| Phase | Files | Outcome |
|---|---|---|
| B | `scripts/ops/production-permissions-hardening.sh`, `docs/ops/release-candidate-operations.md` | Default-dry-run, explicit-owner, exact-path permission plan for backups and Laravel cache; symlink/unresolved/broad-root refusal. |
| J | `scripts/ops/local-encrypted-backup-rehearsal.sh`, `scripts/ops/local-mariadb-recovery-rehearsal.sh`, `scripts/ops/production-backup-restore.sh`, `scripts/ops/monitoring-readiness-guard.sh`, runbook | Authenticated local archive/recovery-path proof and actual synthetic MariaDB dump/transfer/tamper-rejection/scratch-restore proof, plus owner-only production dry-run plans for encrypted/offsite backup and scratch-only restore. |
| K | `scripts/ops/repository-artifact-guard.sh`, `scripts/ops/branch-equivalence-inventory.sh`, `docs/release-manifests/artifact-inventory.json`, runbook | Staged leak/artifact gate, checksum/provenance inventory, and read-only patch-id branch-equivalence evidence. |

## Source/evidence classification

The retained forensic source manifest is timestamped
`2026-09-16T09:20:19.337Z` at the baseline SHA. The sanitized GitHub snapshot
is timestamped `2026-09-16T09:22:39.215Z`. No production filesystem listing was
available in the isolated worktree, so the audit's “82 production-untracked
files” cannot honestly be named or reclassified individually here. That is a
remaining owner-run, read-only evidence requirement; no production directory
was listed or modified.

The following classifications use only current tracked-file metadata and the
sanitized evidence:

| Class | Current evidence / handling |
|---|---|
| Safe to retain | Source, documented migration PHP, supported release-manifest metadata, and public static assets after normal review. |
| Generated/rebuildable | `vendor/`, `node_modules/`, application caches, `public/build/`, `pra-agent/dist/`, temporary backup workspaces, and local database runtime directories. They must not establish release provenance. |
| Stale/superseded candidate | `routes-update.zip` (repository introduction 2026-03-27); Rider beta APK; legacy copied PRA ZIP. Retain pending owner confirmation and build/release mapping—nothing was deleted. |
| Security-sensitive candidate | `cookies.txt`, `fbr_retry_log.txt`, `database/production_data_export.sql`, historical deployment SQL, generated invoice helpers, and all unattested binary artifacts. Metadata-only pattern counting found sensitive-token/customer-data indicators in the first three; contents were not printed or copied. Existing concurrent remediation removes the unsafe tracked artifacts; this scope adds a guard against recurrence. |
| Hosted artifact issue | `public/downloads/TaxNest-PRA-Agent-Windows.zip` is a tracked symlink to an absent ignored build output in this checkout. It is classified release-blocked until the manifest, final bytes, and build/source SHA are independently attested. |

### Artifact digest register

The inventory captures SHA-256 values and the Git revision introducing each
tracked legacy binary. `repository-introduction-only` is deliberately not
equivalent to build provenance; the tracked guard fails closed in that state.
No claim is made that an APK/ZIP is fit to host or matches a source build.

### Branch/ref sprawl

The sanitized baseline GitHub snapshot contained 81 remote branches: 70
diverged, 10 behind (therefore ancestor candidates), and one ahead. The ten
behind candidates were `cursor/ci-elaan-spec-insert`,
`cursor/elaan-unique-title-login-diag-0f83`,
`cursor/fix-automerge-clean-status`,
`cursor/github-actions-production-deploy-0f83`,
`cursor/live-ops-daily-observability-0f83`,
`cursor/live-verify-login-diagnostics-0f83`, `cursor/pr-auto-merge`,
`cursor/sw-dirty-worktree-0f83`, `docs/taxnest-master-guideline`, and
`pra-offline-fix-deploy`. Local read-only ref enumeration observed 1,161
local/remote heads; that larger figure includes agent/worktree local refs and
is not a remote-cleanup list. Diverged refs require stable patch-id comparison
because squash merges invalidate ancestor-only conclusions.
`branch-equivalence-inventory.sh` emits:

* `superseded-candidate` only for an ancestor;
* `review-for-squash-equivalence` only when a stable patch ID intersects;
* `retain-pending-review` otherwise.

These are classifications, not deletion authorization. The owner must review
associated PR state, release/tag dependencies, and retention obligations before
any remote cleanup.

## Commands and exact outcomes

| UTC | Command | Exit | Result |
|---|---|---:|---|
| 2026-09-16T10:18:31Z | `bash -n scripts/ops/*.sh` (via regression check) | 0 | Six operational scripts passed Bash syntax validation. |
| 2026-09-16T10:18:31Z | `python3 -m json.tool docs/release-manifests/artifact-inventory.json >/dev/null` | 0 | Artifact inventory parses as JSON. |
| 2026-09-16T10:18:31Z | `bash scripts/ops/local-encrypted-backup-rehearsal.sh` | 0 | Confirmed default no-write dry-run. |
| 2026-09-16T10:18:31Z | `bash scripts/ops/local-encrypted-backup-rehearsal.sh --local-synthetic --execute` | 0 | Synthetic AES-256-CBC/PBKDF2 archive was transferred, original synthetic records/plaintext tar removed, then independent strict-member/symlink/location/decrypt/checksum/migration/count round trip passed with 3 records. |
| 2026-09-16T10:18:31Z | `bash scripts/ops/monitoring-readiness-guard.sh` | 0 | Confirmed no-network dry-run and required monitoring-key list. |
| 2026-09-16T10:18:31Z | `bash scripts/ops/repository-artifact-guard.sh` | 0 | Staged-change guard passed against an empty index; no files were staged by this scope. |
| 2026-09-16T10:13:11Z | `bash scripts/ops/repository-artifact-guard.sh --tracked` | 1 expected | Fail-closed on the tracked cookie export, production data export, fiscal retry log, and missing legacy artifact bytes; no `.env.example` or schema false positive after guard refinement. |
| 2026-09-16T10:18:31Z | `bash scripts/tests/release-candidate-operations-check.sh` | 0 | Re-ran full local operational regression, including assertion that production archives fixed relative member names rather than stale absolute dump paths. |
| 2026-09-16T10:28:27Z | `bash scripts/ops/local-mariadb-recovery-rehearsal.sh --local-synthetic --execute --socket /tmp/taxnest-rc-mariadb-browser-1000/run/mariadb.sock --mariadb-bin /nix/store/y6mixsnc4fcrdrfvfdlyr8j2s7qh3ff9-mariadb-server-10.6.22/bin` | 0 | Actual local synthetic MariaDB 10.6.22 dump → authenticated encryption → transfer/plaintext removal → tamper rejection → scratch restore completed. Counts (2 accounts, 3 invoices), ordered data checksums, FK/index metadata, orphan query, and `CHECK TABLE` matched. |
| 2026-09-16T10:28:30Z | Read-only allowlisted-schema query over the same Unix socket | 0 | Confirmed both synthetic recovery databases were removed by cleanup. |
| 2026-09-16T10:28:36Z | `bash scripts/ops/local-encrypted-backup-rehearsal.sh --local-synthetic --execute` | 0 | Repeated archive/recovery-path proof with distinct derived encryption/HMAC keys; moved archive recovery, source removal, and tampered-ciphertext rejection passed before decrypt. |
| 2026-09-16T10:29:58Z | `bash scripts/tests/release-candidate-operations-check.sh` plus `git diff --check` | 0 | Seven operational scripts parsed; authenticated local archive tamper/recovery regression and archive-relative backup guard assertions passed; no whitespace errors. |

## Remaining blockers / owner decisions

1. The named 82 production-untracked paths and their checksums are not in the
   retained sanitized evidence. Owner-approved, read-only production inventory
   is required before individual classification or cleanup.
2. Legacy tracked binaries have checksums but not independently attested
   build/source SHA. Rebuild or retrieve release evidence before hosting.
3. Choose and configure a RPO/RTO tier, durable encrypted offsite destination,
   retention/legal-hold policy, restore owner, and private monitoring source
   references. No default was written to tenant settings or production config.
4. A real TaxNest/MariaDB production scratch restore, checksum/count comparison,
   historical-schema compatibility review, and PHP-FPM post-permission read
   test remain owner-run production evidence. The local database recovery proof
   is deliberately synthetic and does not prove production recovery.