# Phase B — security and artifact review

## Scope and evidence

This review covers the Laravel application, the PRA Electron agent, checked-in
archives/data exports, and every checked-in JavaScript lockfile.

The machine-readable, sanitized before/after audit evidence is committed as
`docs/release-candidate/phase-b-security-evidence.json`. It records the
authoritative `HEAD:composer.lock` baseline (25 advisories) separately from
the current lock (0); this avoids falsely describing the baseline finding
count as a new discovery or a no-op.

The archive hashes in that evidence were recorded with UTC timestamps before
removal. The large PRA agent ZIP was Git-LFS hydrated content, so its listed
SHA-256 is the archive/LFS object digest (not the digest of the small Git-LFS
pointer text).

| Check | Result | Evidence |
| --- | --- | --- |
| PHP dependency advisories | Pass | `composer audit --format=json` returned no advisories and no abandoned packages. |
| Root JavaScript lockfile | Pass after compatible parent/lock refresh | `npm audit --json --package-lock-only` reports 0 findings. |
| `pra-agent` JavaScript lockfile | Pass after compatible-range lock refresh | `npm audit --json --package-lock-only` reports 0 findings. |
| `artifacts/mockup-sandbox` lockfile | Pass after compatible-range lock refresh | `npm audit --json --package-lock-only` reports 0 findings. |
| `agent-realtime-gateway` lockfile | Pass | Audit reports 0 findings. |
| `tools/video-pipeline` lockfile | Pass | Audit reports 0 findings. |
| Current tracked credential/artifact scan | Pass | `scripts/verify-repository-artifacts.sh` exits 0 and emits paths/rules only. |
| FBR close API failure containment | Pass | Feature test asserts a real database failure produces a stable generic JSON error and metadata-only log entry. |

The first root remediation attempt exposed a missing optional
`@tailwindcss/oxide-wasm32-wasi@4.1.18` artifact at the internal registry. It
was not treated as a firewall bypass or an accepted advisory: the compatible
`@tailwindcss/vite` parent was refreshed from 4.1.18 to 4.3.3, whose
platform-specific optional artifacts are available from the configured
registry. The normal package manager then completed the advisory refresh.

## Dependency findings and owners

| Finding | Exposure / disposition | Owner and required closure |
| --- | --- | --- |
| Root: `concurrently` → `shell-quote` (high) | Resolved to reviewed patched lockfile versions; post-install audit is clean. | Frontend build owner: retain lockfile and re-audit in CI. |
| Root: `postcss` (high/moderate), plus `browserslist`, `nanoid`, `baseline-browser-mapping`, `postcss-selector-parser` | Resolved by normal compatible lockfile update; post-install audit is clean. | Frontend build owner: retain lockfile and re-audit in CI. |
| PRA Electron chain: `electron`, `@xmldom/xmldom`, `extract-zip`, `js-yaml`, `tar`, `brace-expansion`, `undici` | Refreshed inside existing semver ranges; post-refresh audit is clean. The resolved Electron build tooling requires current Node 22.12+ (current LTS), while the local Node 20 audit emitted engine warnings. | Desktop release owner must use Node 22.12+ in the packaging/CI job and record its `npm ci` + build result before publishing an agent release. |
| Mockup sandbox build dependencies | Refreshed inside existing semver ranges; post-refresh audit is clean. | Frontend build owner: retain the post-refresh lockfile and re-run its audit in CI. |

There are no accepted high/critical dependency exceptions and no advisory
suppression/override was added.

## Sensitive material and artifact cleanup

Removed from the tracked tree:

- Browser cookie export and raw FBR retry log/runner scripts.
- Generated invoice helper scripts and the root `routes-update.zip` remnant.
- `database/production_data_export.sql`.
- The complete `database/deploy/2026-04-24-production-sync/` bundle, including
  customer/company, invoice, branch, user, and subscription synchronization
  material.
- The unmanifested checked-in PRA agent ZIP and its public-download symlink.

`.gitignore` prevents these local artifact classes from returning, and
`scripts/verify-repository-artifacts.sh` fails on tracked cookie exports,
ad-hoc fiscal retry outputs, generated invoice helpers, production
dump/synchronization bundles, unmanifested archives, and credential-pattern
candidates. The guard intentionally prints only path and rule names, never
the matched material.

## Historical exposure follow-up (not complete until performed)

The repository-history scan found prior commits containing the removed browser
cookie/FBR diagnostics and production data bundles. It also found a historical
Firebase JSON file that is no longer in the current tree; the current
`FcmKeyPresentLogDedupeTest` key-shaped value is an explicitly non-functional
test fixture and is excluded by exact path from the guard.

Before the RC is published, the security owner must:

1. Revoke active sessions represented by the historical browser cookie export;
   rotate/reissue any associated fiscal-portal token or credential and
   re-authenticate the integration.
2. Review the removed fiscal logs/data bundle in a restricted incident process
   to identify affected tenants; notify them under the applicable incident
   policy if exposure is confirmed.
3. Rotate the Firebase service-account credential if the historical JSON
   contained a private key, then verify production uses the replacement through
   the approved secret store.
4. Coordinate a protected-branch history rewrite/purge with repository
   administration, then invalidate old clones, forks, CI caches, and release
   artifacts that retain those blobs.
5. Attach the rotation and history-purge change references to this RC ledger.

These actions are intentionally not executed from source control or test code.

## Test evidence

The committed, non-secret `.env.testing` fixture supplies a complete isolated
test bootstrap before PHPUnit loads `phpunit.xml`. Use `--env=testing`; a
developer's local `.env` remains ignored and is rejected by the repository
guard:

```text
php artisan test --env=testing tests/Feature/FbrPosDayCloseUndispatchedDeliveryTest.php
13 tests, 66 assertions; no failures.

php artisan test --env=testing tests/Feature/FbrPosProductExcelThirdScheduleTest.php \
  tests/Feature/InvoicePdfRenderResilienceTest.php
12 tests, 50 assertions; no failures.

php artisan test --env=testing tests/Unit/RepositoryArtifactGuardTest.php
2 tests, 18 assertions; no failures and no warnings.
```

The command now runs without the former Dotenv `file_get_contents(.env)`
warning. A source-reference scan found no retired artifact path in
test/bootstrap code. `RepositoryArtifactGuardTest` replaces any implied
retired-helper behavior with explicit absence assertions and a successful
execution of the repository guard. Syntax validation also passed for the
changed controller, test, and artifact guard.

## Installed dependency readiness

Normal (non-bypassed) dependency installation completed:

```text
root:                   npm ci --ignore-scripts       171 packages
pra-agent:              npm ci --ignore-scripts       355 packages
agent-realtime-gateway: npm ci --ignore-scripts         1 package
root build:             npm run build                 passed
```

`@playwright/test` 1.62.1 is now an explicit, locked root dev dependency
alongside the already locked 1.62.1 Playwright core, so CI browser suites have
their runner package. PRA Electron's install scripts remain intentionally
disabled in this Node 20 workspace because the resolved Electron toolchain
requires Node 22.12+; the desktop packaging job must install it with Node 22
instead of bypassing its engine requirement.