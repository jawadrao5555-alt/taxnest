# Agent version and actual build date

Owner requests only version and build date; no source commit in the UI. Source review found main.js hard-coded BUILD_TIMESTAMP to 20260906-1. Existing get-version IPC exists, but the old label is tiny and its setup shares a large settings script. Local native Electron/Chrome reproduction is unavailable.

Generate ignored build-info.json via packaging lifecycle hooks for build/build:win/publish:win. Metadata contains schema, package version and packaging UTC timestamp only. Bundle the file and verify the actual app.asar content matches generated version/date before Windows release packaging completes. Runtime reads metadata without generating dates; missing, malformed or version-mismatched metadata yields an explicit unavailable date. Existing heartbeat build string remains bounded and update version comparisons remain app.getVersion().

Isolated display script initializes the real Agent header independently; two readable rows show running version and local date/time, with UTC tooltip. No source commit shown. No config writes or changes to tenant/device/printer, fiscal sync, LAN, pairing or updater policy.

Version is bumped to immutable 1.13.20; v1.13.19 is unchanged. Lockfile comparison confirms only root version identity changed and dependencies/integrities stay byte-equivalent after JSON normalization. Reviewed portability graph updated for that version-only change. Tag 1.13.20 was absent at preparation time.

Local 83/83 platform-independent Agent tests PASS, including actual filesystem packaging-date persistence, mismatch/corruption/missing handling, real display module and IPC-failure fallback. Lock guard, Node syntax and release-chain proofs PASS. New required Chrome smoke loads the real settings HTML on loopback with synthetic Electron IPC, checks normal/missing/failing identity, unchanged config values and no write calls. Its execution and full exact-HEAD CI are pending. This is a simulated IPC browser test, not an installed Windows/Electron/physical-printer test.

Draft while #210 is first in the owner queue. Refresh against its deployed main and require all exact-HEAD checks before approval. Actual Windows build/publication and installed-PC verification happen after owner approval/relay. No production/customer credentials, regulator requests, merge or deploy by agent.

First CI browser attempt stopped before rendering: standalone Node invocation did not inherit the disposable Playwright cache selected inside rc-browser-fixture.sh. Run the identity smoke through the existing fixture safe_browser lane (including socket/cache validation and egress isolation), rather than exposing a system browser or bypassing the sandbox. Replacement exact-HEAD CI must prove the rendering assertions.

## 19 October refresh after PR216

Integrate current main26f63f49835032fe06b3a3ff9a81e29115d3e13e after the owner-approved search release. Preserve PR210 contrast plus PR215 address and PR216 complete-search code and required regression/browser journeys. Only release announcement conflicts; retain this PR's version/build-date announcement. No changes to the identity implementation, device/printer settings, updater or regulator paths during refresh.

Eight focused actual build-info/display/release-manifest tests PASS again both plain and under egress blocking. Browser harness syntax and whitespace pass locally. Full platform-independent Agent, native/PHP and real settings-HTML browser proof must pass on the NEW exact HEAD before owner approval; previous greens are historical only. Source remains1.13.20 with no source commit in the UI. Windows package/publication and installed-PC confirmation remain after owner approval/relay.
