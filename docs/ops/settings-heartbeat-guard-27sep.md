# Retained settings guard: Agent heartbeat observations

## Evidence and scope

The owner ran a read-only diagnostic on 27 September 2026 after restoring
website availability. It reported server SHA
`3774398caa72a03b50ad97ab63552ff18e3edda9`, maintenance OFF, zero proposed
restores and five `unsupported_column` refusals: `companies.agent_version`
on four companies and `companies.agent_offline_mode` on one company.
The current report contains no printer-routing refusal. It does not establish
the cause of any historical missing kitchen ticket.

`AgentController::heartbeat()` writes both fields from the running Agent's
heartbeat. The offline telemetry migration describes that field as a reported
PC toggle used for visibility; it is not the server's Agent enable/disable or
PRA submission policy. The same heartbeat also reports/clears the last update
attempt through `agent_update_target`, `agent_update_stage` and
`agent_update_error`. All five are observations, not protected server settings.

The fix excludes those exact five top-level **companies** columns from new
captures and from both sides of comparisons, including schema-column comparison
against retained pre-fix baselines. No `agent_*` wildcard is introduced. There
is no data migration, customer configuration write, new restore authority,
baseline replacement, deployment bypass or automatic maintenance toggle.

## Compatibility matrix

| Configuration | Expected result |
|---|---|
| Four Agents report newer versions; one reports a changed offline toggle | Clean comparison; no restore required |
| Retained legacy baseline still contains the five observation columns | Clean against new capture; no false dropped-column failure |
| Update attempt progresses or stale update error clears | Observation only; actual stored telemetry remains untouched |
| Older schema/Agent omits these columns | Existing supported behavior; no required migration |
| Another tenant's routing, tax, features or Agent policy changes | Report the real setting and refuse automatic restore |
| Same column names on another table or nested inside saved JSON | Remain protected |
| A future Agent setting is added, or a real setting/table disappears | Remain protected |
| Known `users.pos_custom_access` service_jobs-only append plus telemetry | Only the existing hash-checked permission repair is eligible |

## Verification and release boundary

The standalone smoke uses the real snapshot service with fictional snapshots;
`--expect-regression` reproduces the five-refusal shape against the old code.
Laravel feature tests exercise schema discovery, real captures, retained JSON
files, the snapshot/restore commands and preservation of current database rows.
No production snapshot values or credentials are copied into tests or artifacts.

Local PHP is 8.3 with no Laravel vendor installation. Standalone checks and PHP
syntax checks are local evidence; the full PHP 8.4, MariaDB and browser lanes
must pass on the actual PR head in CI. Website recovery and a green PR do not
constitute a successful deployment. Owner approval, protected deployment and
Actions live verification remain required. Guest House #124 stays on hold until
the deployment blocker is resolved and its final head is validated.

Local evidence before publication:

- The unmodified #126 service reproduced the five-refusal pattern with
  `php scripts/tests/settings-heartbeat-smoke.php --expect-regression`.
- The changed service passed 43 standalone heartbeat/baseline/protection
  assertions and all 31 earlier printer-telemetry regression assertions.
- PHP syntax checks and `git diff --check` passed.
- `bash scripts/tests/deploy-unattended-safety-check.sh` passed; the deploy
  workflow, approval provenance and production access boundaries are unchanged.
- Twelve Laravel feature cases are added for CI. No local full PHPUnit,
  MariaDB, browser or production success is claimed.
