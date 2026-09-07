# 20 — Testing

## Layout (FACT)

| Path | Role |
|------|------|
| `tests/Unit` | Small pure tests |
| `tests/Feature` | Majority — HTTP + DB (~400+ files) |
| `tests/Feature/LiveOps` | Live Ops + authorization A–F |
| `tests/Support`, `Concerns`, `Fixtures` | Helpers |
| `scripts/tests/*` | Bash/Node contract checks (Elaan, etc.) |
| `pra-agent/test/*` | Agent JS unit tests |

## PHPUnit config

In-memory SQLite; `QUEUE_CONNECTION=sync`; see `phpunit.xml`.

## CI gap (FACT / CONTRADICTION)

`pr-checks.yml` runs **scripted safety checks**, not the full PHPUnit suite.

**INFERENCE:** Full suite is run by agents/devs locally or selectively; do not assume GitHub PR checks equal `php artisan test`.

## Patterns to copy

- LiveOpsTestCase minimal schema harness
- Feature tests that assert tenant isolation and role confinement
- Browser smoke scripts under `scripts/` (loopback-only)

## When changing architecture-sensitive code

Prefer adding Feature tests that prove: guard, company_id isolation, role denial, and negative paths — not only happy path.
