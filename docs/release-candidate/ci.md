# Phase G CI recovery status

**Verdict: NOT READY.** The active `.github/workflows/pr-checks.yml` was not
modified during recovery and is **NOT DELIVERED** as the Phase G required gate.
`proposed-workflows/pr-checks.yml` is a proposed, fail-closed definition only:
it has every mandatory named job and `validate` uses `if: always()` plus an
explicit success result check for each dependency. Unrecovered work is an
explicit failing `BLOCKED` gate, never a skipped success.

## 11:04–11:06 UTC recovery loss

The temporary worktree and raw execution logs disappeared. Commit `93de2ccf`
was restored, but uncommitted Phase G harness sources had to be reconstructed.
Historical observations are not fresh certification:

- A previous isolated service work-order browser run observed desktop and
  mobile create → legal transitions → invoice linkage passes.
- DI pending-verification view/absence checks rendered on both viewports, but
  observed a genuine 570px document width at a 390px mobile viewport.
- Earlier regular Hotel/Admin/Service/Health/FBR checks had partial pass
  observations; raw logs/screenshots are lost and these must be treated as
  unconfirmed.
- Historical `5014` assertions / exit 0 remains an observed prior result only;
  the reconstructed harness has **not** been recertified.

## Explicit missing artifacts

`scripts/rc-browser-fixture-seed.php` is deliberately a precise blocking
command, not a nonexistent reference. The lost original generated synthetic
Hotel/Service/Health/FBR seed implementation must be recovered before a fresh
browser fixture setup may run. It must not be replaced by demo, customer, or
production-oriented seeders. Native bulk MariaDB logs/results, full PHPUnit,
dependency audit, build/manifest, and browser evidence also require fresh
authorized runs.

Only bounded source syntax checks were performed after reconstruction; no
server, database, dependency install, workflow, full suite, or browser run was
started during recovery. The semantic proposed-DAG check requires the locked
`js-yaml` dependency; this recovered worktree has no `node_modules`, so it
correctly reports `BLOCKED` until a fresh authorized `npm ci` verification.