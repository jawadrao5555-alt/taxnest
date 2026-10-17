# PR 200: deployed-main integration rehearsal

Refreshed original HEAD baec926cbb8411b9a5c8ee7e3c21f2de0d7718c7 with deployed main 2244ee7678a5e2bc0427f2f3c2cb868e984d2ce8 on 17 October 2026. Preserve both parents; no rewriting history.

AgentController conflict resolved by retaining expired-claim quarantine, branch/device routing before poll limit, captured-device guard, atomic exclusive claim and submission_claim response. Browser seed retains identity settings plus reporting connector and production-CSP admin preview journeys. Current Agent source/version 1.13.19 is unchanged; this PR does not need another Agent release.

Compatibility: terminal overrides branch; captured identities immutable; unmapped/UID-less legacy behavior retained; tenant-owned mappings and replacement; submitted/local invoices unchanged; held/claimed invoices cannot be blindly reassigned or retried. Added combined real-DB regression for mapped-device exclusive claim, wrong/UID-less claim denial, duplicate claim denial, retry denial and expiration quarantine retaining claim/POS/device.

Local PHP, MariaDB and Chrome executables are unavailable. Node/whitespace/static checks are local evidence only. Fresh CI must exercise identity PHP regressions, full suite, native MariaDB and actual desktop/390px settings journey before owner readiness. No production/regulator requests, merge or deploy performed.
