# PR 270: Agent security audit correction — 23 October 2026

## Failure and correction

At source `54c58468be5f21b12448f4fdf3dd970b82d5fcb9`, PR checks run
[39953525444](https://github.com/jawadrao5555-alt/taxnest/actions/runs/39953525444)
failed audit job `120413597043` at **Rebuild disposable locked verification lane**;
final validate consequently failed. The six other mandatory lanes passed on
that historical source. A fresh local `npm audit --json` reproduced Electron
42.11.3 advisories GHSA-x562-297c-g43g, GHSA-3qxv-5xgr-q7w9 and
GHSA-h3v5-h239-pg29. This was a real advisory failure, not a download transient.

Pin package, lock and Windows builder to Electron **42.11.9**. Official references:
https://releases.electronjs.org/release/v42.11.9 and
https://github.com/advisories/GHSA-x562-297c-g43g . The fresh corrected-lock audit
reports **zero vulnerabilities**. This does not imply every repository audit or
all future advisory databases are clear.

Advance Agent source identity to **1.13.22** because `v1.13.21` is an immutable
published release. No release tag, asset or Windows build was triggered.
Draft #256 also proposes 1.13.22; whichever source is released later must reconcile
its source version against actual published tags. No recovery code was copied.

Only the lock root identity/Electron specification and Electron package
version, URL and integrity change; all other **306 entries** and all dependency
edges remain byte-for-byte equivalent as parsed JSON. Counts remain 308 packages /
307 resolved sources. Reviewed Agent graph:
`6dc1d270218097c4148dbfcba6d4c93a36bb074ee20661b995b38f1134d959a5`.
The four other reviewed graphs, local-fork hashes and every audit/platform gate
remain unchanged. Release identity tests retain exact package/lock/build pins.

## Actual local verification

Environment: Node 24.19.0. Dependencies installed with `npm ci --ignore-scripts
--no-audit --no-fund`; that install does not prove Electron binary acceptance.

- Baseline `npm audit --json`: one vulnerable package, three Electron advisories.
- Corrected `npm audit --json`: exit 0, vulnerabilities total 0.
- Same platform-independent test selection as mandatory Node CI:
  `node --test --test-concurrency=1` over `pra-agent/test/*.test.js`, excluding
  only the separately gated `local-core-internet-cut.test.js`: **89/89 pass**,
  zero skips. Includes renderer parse/handler diagnostics, exact release identity,
  downloader hash rejection and existing Agent regression cases.
- Initial all-files invocation: 89 pass, one failure because the separately gated
  real Electron executable had not been installed (`spawn .../electron ENOENT`).
  Do not present this initial whole command as green.
- `bash scripts/tests/npm-lock-portability-test.sh`: all five reviewed graphs,
  private-registry rejection and local-fork integrity negative regressions pass.
- `bash scripts/tests/agent-release-chain-check.sh`: pass.
- `bash scripts/tests/rc-ci-gates-check.sh`: pass.
- `git diff --check`: pass.

Electron binary installation subsequently succeeded; `electron --version --no-sandbox`
reports 42.11.9. No Xvfb/display server is installed here; the real BrowserWindow
application harness has not been accepted locally. A version command is not that proof.

Supported Node 22 real Electron, full PHP 8.4/MariaDB, build/audit and actual
1366/390 browser acceptance require the **new exact-source CI run**, including
its own final validate. Historical source checks are not substituted.

## Scope and release blockers

This PR also contains the pre-existing settings renderer parse correction and
visible bridge/runtime error diagnostics. The renderer tests establish script
parseability and handler presence; they do not prove physical Windows button,
printer or pairing behavior. No real Agent, registry or printer was touched.

The wider #268/#269 central latest-download routing and secure tenant-scoped
first-run pairing remain specification work, not implemented acceptance.
Keep this PR draft. Existing `feature/agent-download-unified-268-269` does not meet
the owner handoff cursor/replit branch-prefix gate. Renaming an open PR head closes
that PR, so no rename, duplicate PR or gate bypass was performed. A safe owner
branch/PR disposition remains necessary before release eligibility. No source
approval, merge, deployment or production/release workflow was performed.
