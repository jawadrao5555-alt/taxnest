# PRA sale notification header clearance — 27 October 2026

Owner report: on `/pos/invoice/create`, the red Caller ID notification is partly hidden behind the navigation header. Invoice/print and other messages use the same sale toast and suffer the same placement problem. This is a focused follow-up to deployed #303 / #248, based on `a7875f3cb27f1f73b7dc9ea0ebd01c3e2dc43de1`.

## Cause and change

The shared toast was inside the zoomed/clipped sale root, at `top: 1rem` / z-index 60; the PRA header is at z-index 140. Merely raising the child z-index would not escape the sale root's stacking/clipping context.

Teleport the existing shared toast to a separate body portal at z-index 150. Reuse the existing measured header/viewport positioning, which also tracks window resize. Long unbroken messages wrap within the viewport. Keep the portal separate from the Caller ID portal: sale/print feedback must remain visible while submission hides caller panels. The portal lets pointer input reach underlying controls, and provides polite status semantics. Existing error/info/success colours, messages, printing actions and 2.5-second timer are unchanged. No saved company design/settings, invoices, financial history, printer configuration or Agent data are changed.

## Reproduction and verification

- Supplemental actual-Alpine/production-markup component reproduction: original markup failed **18/18** placement cases (1366px and 390px; zoom 80/100/125%; error/info/success). At normal desktop zoom it started at y=16 beneath an 88px header and failed the paint hit-test. The same reproduction with the corrected markup passes **18/18**.
- Added independent `sale-toast-header` journey to the existing mandatory browser runner, using its existing fictional PRA cashier. All original table/caller/receipt and other journeys remain in the default suite. The journey calls the real `showToast` for each type and waits for its existing auto-hide; checks actual geometry, topmost painting, unclipped body placement, input pass-through, long messages, zoom, submission UI state and window resize. No sale/print request is sent by this new journey.
- Actual full Laravel + disposable MariaDB 10.11.14 TCP + Chromium 151 headless-shell run: **PASS desktop 1366px and mobile 390px**, zero unexpected console/network/page/HTTP errors. Four external font/CDN requests per viewport were intentionally blocked by the existing local-only harness. Screenshots visually reviewed. Local TCP guard pins loopback/port/database/exact synthetic fixture path; committed CI retains its stricter MariaDB 10.6 Unix-socket guards.
- Targeted PHP Caller ID and KOT reprint permissions: **31 tests / 100 assertions PASS**.
- Existing caller placement and real receipt typography browser tests: **3/3 PASS**.
- Vite build, browser JS parser, 592-view Blade script-escape check, Elaan specification checks and whitespace check PASS.
- Full PHP suite: final result recorded in PR evidence after completion.

An initial integrated local run passed the toast desktop checks but later timed out in an existing occupied-table workflow after delivery preview. The new independent journey avoids adding timing/state to that transactional fixture; it does not remove or relax that existing acceptance. Whole-suite hosted browser evidence is still required.

## Release boundary

Local evidence is not deployment approval. Require this PR's own current-head eight mandatory checks including validate, full PHP, exact MariaDB, full desktop/390px browser, Node 22, locked dependencies/build, workflow and release proof, plus current clean mergeability and owner approval. Source PR greens are historical. No production access, approval, merge, relay dispatch or deployment was performed. This verifies presentation locally, not physical printing, installed Agent acceptance or live customer recovery.
