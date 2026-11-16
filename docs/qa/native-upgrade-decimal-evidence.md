# Native upgrade comparator failure: exact evidence

Failed run: https://github.com/jawadrao5555-alt/taxnest/actions/runs/43699005351
Job: 132765737318; sanitized artifact: 14155542734.
Evidence files: `upgrade-before.json`, `upgrade-after-mismatch.json`.
All records below are disposable synthetic RC fixtures, not live company IDs.

| Table.field | Synthetic row / company | Before PDO value | After PDO value |
|---|---|---|---|
| inventory_stocks.quantity | id=1 / company=2 | `17.00` | `17.0000` |
| inventory_stocks.quantity | id=2 / company=3 | `29.00` | `29.0000` |
| inventory_movements.quantity | id=1 / company=2 | `17.00` | `17.0000` |
| inventory_movements.balance_after | id=1 / company=2 | `17.00` | `17.0000` |
| inventory_movements.quantity | id=2 / company=3 | `29.00` | `29.0000` |
| inventory_movements.balance_after | id=2 / company=3 | `29.00` | `29.0000` |

Each value is a JSON/PDO string. Source migrations define the old storage as
DECIMAL(15,2), and the new storage as DECIMAL(18,4). The failed artifact did NOT
capture information_schema, so these physical types are source-verified, not
runtime-verified for that run. The corrected fixture captures before/after
information_schema evidence and asserts those exact types on the next run.

Only other differences: subscriptions id=1 / company=2 pricing_plan_id integer
20→22 and updated_at `2026-09-14 11:00:00`→`2026-11-15 14:25:08`. These are the
already allowed Business→Kaarobar package backfill; subscription company, active
flag and term remain strict invariants. No new exceptions were introduced.

The exact downloaded JSON comparison passes when ONLY the three widened inventory
fields have trailing fractional zeroes removed, with the existing subscription
exceptions. No fields or rows are dropped, and nonzero fractional changes fail.
No float conversion or rounding is used. All other preserved metadata matches.
This is a representation comparator defect, not evidence of data loss.

Local comparator regression: 1 test / 9 assertions PASS, including 0.0001 changes
and large decimal values. Native fixture PHP syntax PASS. These do not replace
native execution. The failed run stopped at preservation; callback race was NOT
reached and must not be reported as passed. Native runner keeps this fail-closed
gate. Independent `--upgrade` and `--concurrency` modes can provide separate logs;
both must pass, and independent callback success cannot override preservation
failure. No native rerun or final integrated CI success is claimed here.
