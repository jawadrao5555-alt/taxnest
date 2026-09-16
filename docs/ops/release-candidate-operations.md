# Release-candidate operational recovery runbook

## Scope and safety boundary

This runbook prepares an owner-controlled production change. It does not
authorize a Cloud Agent to access production, use a production credential,
upload an archive, or change production permissions. The separate local
MariaDB proof below is limited to the explicitly allowlisted disposable Unix
socket and two synthetic databases; it is not production access.

Every production command below is **PROPOSED — NOT EXECUTED — OWNER APPROVAL
REQUIRED**. Run it from the intended host only after the owner has reviewed
the exact resolved paths, the service account read access, and the retention
destination. Do not use a production `.env` as a shell source. Pass a
root-only MySQL client option file with `--defaults-file`; this avoids ambient
`~/.my.cnf` options and keeps credentials out of command history.

The scripts neither alter tenant settings nor run migrations. They do not
delete backups, databases, remote objects, caches, or logs.

## Recovery objective proposal

These targets require owner approval; they are not silently configured:

| Tier | Maximum RPO | Target RTO | Suggested use |
|---|---:|---:|---|
| Minimum | 24 hours | 24 hours | development only |
| Standard | 4 hours | 8 hours | low-volume production |
| Conservative proposed default | 1 hour | 4 hours | fiscal and operational production |

Until an owner chooses an alternate documented tier, plan for the conservative
target: encrypted logical backups at least hourly, one offsite copy per
successful archive, daily checksum review, and a scratch restore rehearsal at
least monthly and before a significant migration. A missed RPO/RTO target is
an incident, not a reason to skip a verification.

## Permission hardening

The permission script is target-limited to exactly:

* `<app-root>/storage/backups` — existing directories `0700`, regular files
  `0600`.
* `<app-root>/bootstrap/cache` — directory `0750`; existing known cache files
  `0640`.

It refuses a missing application root, root directory, unresolved path, or a
symlink in the backup tree. It does not recursively discover a home directory,
change owner/group, or create paths. The first command is a no-change review:

```bash
bash scripts/ops/production-permissions-hardening.sh \
  --app-root /absolute/taxnest --dry-run
```

After explicit review, the owner can authorize the exact same command with
`--execute --owner-approved-production-execution`. Verify the PHP-FPM user can
read `bootstrap/cache` afterwards; restore the prior documented mode only if
that verification fails and record the reason. Do not chown reactively.

## Encrypted backup and restore rehearsal

`scripts/ops/production-backup-restore.sh` uses a 7-Zip AES archive with
encrypted headers and an interactive password prompt. A password is never
accepted in an argument, environment variable, source-controlled file, or
report. It produces an encrypted logical dump, a checksum, and encrypted
metadata containing table-count evidence. The offsite target is optional but
an unconfigured target does **not** satisfy the recovery objective.

The script accepts an absolute Laravel root, a root-only MySQL client option
file, and a simple database identifier. It requires an explicit owner flag for
`--execute`. It refuses to overwrite archives. For restore it only accepts a
new database whose name starts `taxnest_restore_rehearsal_`, verifies it does
not already exist, and never drops it. Inspect and owner-approve scratch
database cleanup separately after evidence collection.

Before the dump, the backup command records exact per-table counts, records
them again after the dump, and refuses to archive if they changed. This avoids
presenting a moving source as a rehearsable snapshot. The restore command
recomputes each count in the scratch database and fails on any mismatch.
Archive members are fixed archive-relative `database.sql.gz` and
`backup-manifest.tsv` names. Before extraction it rejects any other member;
after extraction it rejects symlinks, nested/extra files, and any member not
at those exact locations.

Proposed dry-run sequence:

```bash
# No DB, password prompt, archive, remote, or restore action occurs.
bash scripts/ops/production-backup-restore.sh backup \
  --app-root /absolute/taxnest --db-name taxnest \
  --db-defaults-file /absolute/private-mysql.cnf --dry-run

bash scripts/ops/production-backup-restore.sh restore \
  --app-root /absolute/taxnest --db-defaults-file /absolute/private-mysql.cnf \
  --archive /absolute/backup.7z \
  --scratch-db taxnest_restore_rehearsal_YYYYMMDD --dry-run
```

Before authorizing execution, confirm the MySQL server is MariaDB-compatible.
The historical schema includes a `TEXT` unique-index form that is not portable
to MySQL, and a dump is not proof of recoverability until a scratch restore
completes. Compare every table count from the encrypted manifest with the
scratch database, check the `migrations` table, and record archive and checksum
identifiers only—never customer rows, SQL, credentials, or archive passwords.

The safe local cryptographic/recovery control is exercised without application
or customer data:

```bash
bash scripts/ops/local-encrypted-backup-rehearsal.sh --local-synthetic --execute
```

It creates three fixed synthetic records in a new temporary directory, creates
an authenticated AES-256-CBC/PBKDF2 encrypted archive, decrypts it into another temporary
directory, verifies checksums, a synthetic migration marker, and the three-row
count, then removes the temporary data. Before decrypting, it moves only the
encrypted archive into a transfer directory and deletes the original synthetic
records and plaintext tar; this proves recovery does not accidentally read the
source. Its decrypted tar member list must be exactly the three expected
relative regular files; it also rejects a symlink or any restored file outside
the exact payload directory. The script derives distinct encryption and HMAC
keys from an ephemeral local master key, verifies the HMAC before decrypting,
and proves ciphertext tampering is rejected. It does not contact a database or
network.

### Executed local MariaDB recovery proof

`scripts/ops/local-mariadb-recovery-rehearsal.sh` is a stronger, still
non-production test. It accepts only the local
`/tmp/taxnest-rc-mariadb-browser-<uid>/run/mariadb.sock` Unix-socket shape and
an absolute MariaDB binary directory. It invokes clients with `--no-defaults`,
socket-only protocol, and no imported environment or credential file. It
refuses existing database names and can create/drop only
`taxnest_rc_recovery_source` and `taxnest_rc_recovery_restore`.

The executed 2026-09-16 rehearsal used MariaDB `10.6.22-MariaDB` through the
allowlisted local socket. It created a tiny synthetic InnoDB parent/child
schema, unique indexes, and two/three synthetic rows; dumped, encrypted,
HMAC-authenticated, transferred, removed the plaintext dump, detected a
tampered ciphertext before import, restored into the second database, and
compared counts, ordered data SHA-256 digests, foreign-key metadata, index
metadata, orphan count, and `CHECK TABLE`. Both temporary databases were
removed after a successful result.

This is **database restore proof**, whereas the three-record archive rehearsal
is **archive/recovery-path proof**. Neither proves that a TaxNest production
database can be restored: the local schema is deliberately minimal, it does
not use production data, credentials, extensions, storage, RPO/RTO load, or
the historical `TEXT` unique-index edge case. A production scratch restore
remains owner-run evidence.

## Monitoring readiness

No endpoint, secret, customer payload, or fiscal response belongs in this
repository. `scripts/ops/monitoring-readiness-guard.sh` validates only the
presence of private configuration references, and never calls them:

* queue lag/dead-letter depth;
* scheduler heartbeat;
* realtime heartbeat;
* disk and memory;
* database connection saturation;
* TLS expiry;
* agent-version population;
* failed/stale fiscal queues; and
* an alert route with an accountable owner.

Run it without arguments to view the required key names. The optional
configuration file must be absolute, untracked, non-symlinked, and mode `0600`
or stricter. It rejects values labelled password/token/secret/private key.
Alert reports should include time, state, aggregate count, and correlation ID;
they must not include invoice payloads, taxpayer identifiers, cookies,
credentials, raw fiscal responses, or SQL dumps.

## Artifact and repository guard

Run `bash scripts/ops/repository-artifact-guard.sh` before staging a change.
It rejects newly staged cookies, secret-bearing mobile configuration, ad-hoc
fiscal retry output, database dumps/backups, and binaries lacking an inventory
entry. `--tracked` also checks the recorded checksums and fails closed when a
legacy artifact lacks independently attested build/source provenance. That
failure is intentional: do not host or promote the artifact until an owner
supplies a reproducible build record and a reviewer changes its status to
`build-attested`.

`docs/release-manifests/artifact-inventory.json` is an inventory, not a claim
that legacy binaries have a reproducible source build. It records their
repository-introduction revision and checksum separately from the missing build
attestation.

## Branch cleanup evidence

Use the read-only inventory command before proposing branch cleanup:

```bash
bash scripts/ops/branch-equivalence-inventory.sh origin/main > /secure/location/branch-equivalence.tsv
```

An ancestor relationship alone is insufficient after squash merges. The script
labels an ancestor as a candidate and separately checks stable Git patch IDs
for non-ancestor branches. A `review-for-squash-equivalence` row still needs
PR discussion, release/tag dependency, and owner review; a
`retain-pending-review` row must never be deleted from this output.