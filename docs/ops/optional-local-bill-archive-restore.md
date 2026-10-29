# Optional local-bill support copies: retention and restore

This feature is optional and OFF until the real company owner confirms a retention
duration and consent expiry. It is not statutory fiscal/accounting retention and
is not a hidden second sales ledger. Platform administrators cannot opt owners in.

Only a future eligible local-bill deletion under active consent produces a copy.
The snapshot contains stored monetary/item facts and minimized payment methods
and amounts; customer contact information, notes, tokens and regulator payloads
are excluded. Changing today's prices or settings never recalculates old copies.

## Storage and expiry

Four separate SQL tables hold preferences, append-only consent events, encrypted
snapshots and access audit. Copies never enter live-sales row/count/total queries.
Each consent has a distinct random encryption key, APP_KEY wrapped in a private
0700 keyring with 0600 files, outside the public disk. The configured directory
must not use symlinks. Do not include it in ordinary SQL backups.

Reads exclude expired copies immediately, independently of cron health. The
`local-support-copies:cleanup --limit=100` scheduler removes bounded expired
batches and destroys expired consent keys once no copy references them. Audit
events make key cleanup progress without rewriting consent wording or scope.
Stop future retention keeps already-created copies until their original expiry.
Purge destroys all that company's archive keys before deleting optional SQL rows;
partial failure is fail-closed and purge may be retried. It does not alter bills,
stock, fiscal identities, accounting history or saved company settings.

## Backup facts and limits

Repository fact: `backup:database` writes SQL and normally keeps the latest seven
SQL files; its download path returns before pruning. Deployment rollback guidance
also references pre-deploy SQL dumps under `~/backups`, with no repository-proven
rotation schedule. The separate owner-run encrypted helper
`scripts/ops/production-backup-restore.sh` explicitly never deletes local/remote
backup archives and limits restore rehearsal to a new scratch database. Its
offsite retention needs a separate operator record. The broad private-backup
helper can include `storage/app/private`, so whole-file archives must be treated
as old-keyring restores, not SQL-only restores. Actual operator/host/volume backup rotation has **not** been
verified. Seven files does not mean seven days. Do not promise instantaneous
removal from backups or an unverified deletion date.

SQL-only restores do not restore destroyed keys when the current keyring is kept.
Reads still enforce each copy's original expiry. Restoring an old SQL dump and
an old keyring together can resurrect decryptable ciphertext. Such a restoration
must stay offline until the quarantine step below completes. No application can
guarantee deletion against an operator restoring an arbitrary old whole-machine
image and exposing it without this restore procedure.

## Required restore procedure (owner/operator, never a Cloud Agent on production)

1. Keep restored application traffic, workers and schedulers disabled. Do not
   expose a restored app or old-volume image to customers.
2. Preserve the current private keyring independently of the SQL restore. Do not
   overwrite it with archived keys. For a whole-volume restore, isolate the image
   and its keys until quarantine is complete.
3. Restore approved financial/application data using the existing rollback
   procedure. Install the current reviewed code and migrations while offline.
4. Run `php artisan local-support-copies:restore-quarantine --confirm="Quarantine optional copies"`.
   This destroys every restored consent key, disables optional retention and
   discards optional encrypted copies only. Its result is a prerequisite to
   exposing the restored instance. On any error, keep traffic/workers disabled,
   correct the private storage problem and retry; do not bypass it.
5. Confirm optional copies count is zero and no preference is enabled. Confirm
   representative live financial rows and saved company settings match the
   intended restore. An owner must explicitly grant fresh consent before new
   optional copies can be retained.
6. Record operator/time, restore source, quarantine result and financial checks
   in the restore change record, then follow existing release/live verification.

The quarantine command intentionally loses optional copies rather than reviving
revoked or expired retention after a restore. Consent/access audit remains an
audit trail. It is not a permission to restore, approve or deploy production.

## Verification boundaries

Disposable tests must prove default OFF, prospective-only consent, tenant/branch
export isolation, fiscal/return/pipeline exclusions, atomic deletion rollback on
key/storage failure, stop versus purge, bounded cleanup, tamper rejection and
SQL-plus-old-keyring restore followed by quarantine. Native MariaDB races and
actual desktop/390px owner/admin journeys are release prerequisites; SQLite alone
does not establish transaction-lock or browser acceptance.
