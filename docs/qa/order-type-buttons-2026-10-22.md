# Readable order-type controls — refreshed 22 October 2026

Owner source: `4ba007ac-34ea-4d27-8e20-6058ba6dcacc.mov`. Dine In / Takeaway / Delivery labels were small and faint. This existing PR increases labels from 12 to 14 CSS pixels, sets a 44 CSS-pixel minimum height, and darkens inactive text. No order handler, feature gate, saved appearance setting, tenant query, fiscal calculation or printer behavior changes.

## Prerequisite and baseline

Owner-approved #240 HEAD `233d85cf2c57e49464bf696ad352e23bab3e29ea` was squash-merged as `db5ab4c75b149229dfe4e8efd6aefed6801e005f`. Owner handoff run39806479305/job119920952468 binds the approved PR/head to that squash and exact Deploy39806568026. Deploy job119921620336 logged `CI LIVE VERIFY: PASS` for matching SHA and extended NestPOS markers at2026-10-22T15:40:23Z. Callback119922882116 returned succeeded for the same SHA/approvalc2ff1067-8635-4385-9a93-9cd7c9034189. This is Actions proof, not independent customer production screen acceptance. No production action by this agent.

Refresh history-preservingly merges this deployed main into the existing PR branch. Preserve #240 caller/Delivery/address/cart safeguards and tests; #239 dashboard reading/in-flight refresh; #238 ordinary notice identity/audience/read state; #236 formatted-phone/current-old/company-scoped search; #235 Agent1.13.21/Electron42.11.3/package-lock/build/pinned identity/graph7160c23b726d468b39e3137c520e7e70922cef37953827119ee29b6eb9d6fc25; immutable release tags and #227 tutorial/output/CI contracts. Unique ordinary customer announcement added. No unrelated draft changes or Agent1.13.22 copied from #256.

## Reproduction and correction

Fresh inspection of the actual deployed Blade switcher confirmed `text-xs` (compiled Tailwind .75rem), no44px minimum, and gray500/gray400 inactive text. This establishes the source size problem, not a fresh rendered-browser reproduction. The old inactive palette alone measures4.63:1 in light and5.78:1 in dark at rest; do not misreport those as failing4.5:1.

Review also found the previous draft's dark inactive hover applied light gray100 behind gray200 text (calculated1.13:1). Add dark:hover:bg-gray700 (8.33:1). Its external focus outline was clipped by the retained overflow-hidden switcher; move the2px outline inside the button with black in light/white in dark. Selected purple600 focus contrast is3.90:1/5.38:1 respectively. Palette ratios and generated CSS are source checks, not browser acceptance.

## Compatibility and actual browser coverage

Use the existing real rendered table-order journey at1366/390. Preserve all original address, unsaved-caller, table claim, payment-enabled, KOT deletion and cashier-handoff assertions. The added checks use actual pointer hover for each button in both modes, wait for real CSS transitions, measure computed font/minimum/target height and text contrast>=4.5:1; traverse buttons backward with real Shift+Tab (forward Tab retains the existing product-grid shortcut) and verify focus-visible/inset outline>=2px with contrast>=3:1. Verify exactly one aria-pressed state and Dine In selection after each actual table claim. Capture light/dark desktop/mobile screenshots and restore the previous appearance mode. No arbitrary Tailwind dimensions or changed keyboard bindings. F2's misleading hint is a separate PR.

## Local commands and results

Node24.19.0. Native PHP8.4/installed Chrome absent. A fresh Playwright headless Chromium download failed as a truncated/non-ZIP response, so full local application/browser acceptance was not run. Existing root parser/Tailwind dependencies reused solely for checks; no dependency/lock changes. Actual supported acceptance remains required in fresh disposable GitHub CI.

- `node --test scripts/tests/caller-order-type.test.mjs scripts/tests/dashboard-notice-reading.test.mjs scripts/tests/notification-acknowledgement.test.mjs scripts/tests/notification-bell-unread.test.mjs scripts/tests/delivery-phone-search.test.mjs scripts/tests/delivery-address-state.test.mjs scripts/tests/tutorial-recording.test.mjs pra-agent/test/build-info.test.js`:48/48pass.
- `node --test pra-agent/test/build-info.test.js pra-agent/test/release-manifest.test.js`:9/9pass (4 build-info overlap,53 unique passing regressions total).
- `bash scripts/tests/agent-release-chain-check.sh`:allpass.
- `bash scripts/tests/npm-lock-portability-test.sh`:all five reviewed graphs and private-source/local-fork negative probes pass; Agent graph7160... unchanged.
- `bash scripts/tests/rc-ci-gates-check.sh`:active/proposed mandatory DAG/container/runtime contracts pass.
- `node node_modules/tailwindcss/lib/cli.js -i resources/css/app.css -o /tmp/taxnest-buttons241.css --minify`:compiled; confirms standard14px/inset-focus utilities. Browserslist warns existing caniuse data is old; no dependency update attempted.
- `node --check scripts/rc-browser-acceptance.mjs` and `git diff --check`:pass.
- An extra initial build-downloader compatibility test could not resolve absent local app-builder-lib. This is an unrun optional local builder test, not a green result or evidence against runtime; full locked Agent/build verification remains mandatory in CI. No audit/platform/test weakened to hide it.

## Acceptance boundary

Keep draft until ALL EIGHT fresh exact-HEAD mandatory checks, actual light/dark1366/390 original journey and clean mergeability. After promotion require any new same-HEAD run's own8 including validate. Old39671297518six non-audit successes and shared old Electron audit failure are historical, not readiness proof. Update the PR body with exact published HEAD/tree and actual fresh results. No actual customer screen, real telephony, Windows installation, physical print or independent live UI acceptance claimed. Stop for fresh owner approval; no agent merge/deploy. #237 HOLD; #256 has no owner HOLD but separate Windows acceptance gaps.

## Fresh CI failure and correction — 22 October

ExactHEADbe6eca512ab62d134f5533df2b5dd18dc935fdbf run39808505138 completed with six non-browser mandatory lanes successful. Browser119928085193 failed at the first desktop keyboard check: focused=false, outline3px/offset0; its earlier real hover checks had passed. The new test had incorrectly assumed native forward Tab between pills, but actual handleSearchKeys intentionally consumes Tab and enters product-grid mode. Preserve that established shortcut; verify native backward Shift+Tab focus of all three pills, retaining every focus/contrast assertion, plus explicitly assert forward Tab still enters the grid and Escape returns. No handler or keyboard behavior changed.

The actual loaded public/css/premium-pos.css shared button:focus-visible selector outranks the Tailwind utilities, so they could not guarantee the intended inset outline. Add a strictly switcher-scoped rule after that generic rule for2px black/light, white/dark and-2px inset. Keep generic controls unchanged. This is a genuine stylesheet correction exposed by CI review, not a reason to weaken the browser assertion. New HEAD requires fresh all8, including actual original desktop/mobile tests; the failed head must not be rerun. No production action.

After correction, all53 existing focused Node tests pass again in one run. Disposable evaluation of the actual handleSearchKeys method passes2/2 for forward-Tab grid routing and native backward-Tab availability; parsed actual loaded premium CSS confirms the scoped light/dark inset rules. These local checks do not substitute for the new real browser run. NativePHP8.4/Chrome remain absent on this host; no local full-app acceptance claim.

## Second fresh CI result — 22 October

HEADc410b012f05b321877b0c1124bb3844a0cb42e57 run39812695207 finished: five mandatory lanes succeeded, browser119942905899 and native MariaDB119942905390 failed; validate consequently failed. The real desktop light AND dark hover/focus/selected-state checks now passed. Full original journey/mobile acceptance did not complete.

Browser failed after those checks waiting for the added forward-Tab grid assertion. Actual enterGridMode correctly returns immediately when displayItems is empty; this isolated table-order company has no ordinary menu products. Preserve the guard. Supply one temporary synthetic in-memory deal (same actual UI/catalog methods as the existing catalog fixture), type its name through the real search field and wait for its actual displayed item before asserting Tab/grid then Escape. Temporarily show that in-memory grid without calling a preference-saving toggle, then restore the original catalog/query/showProducts flag in finally. Do not skip or weaken the keyboard assertion or change runtime keyboard/catalog behavior.

Native MariaDB failed before any database lab: setup-php suppliedPHP8.4.27 but php could not load libargon2.so.1, so pdo_mysql installation failed and the named "Install MariaDB 10.6 and locked PHP dependencies" step failed before any database lab. Add Ubuntu's libargon2-1 to the existing isolated-container runtime prerequisites, with the existing provision-contract check requiring it. Retain pinned MariaDB image/PHP8.4/Node22 and every platform/audit gate. This supplies a required shared library; it is not a test skip, dependency-audit bypass or production infrastructure change. New exact HEAD requires fresh all8; do not rerun superseded failed head.

Local retest for this correction:53/53 focused Node regressions pass; actual enterGridMode method2/2 covers empty-list no-op and non-empty focus. CI provision check passed38 isolated discovery and12 startup cases but its socket-state subtest could not run because nativePHP is absent; do not report that whole local command green. Active/proposed CI contracts, five reviewed lock graphs/negative fork probes and release-chain checks pass; shell/JS syntax and whitespace pass. Fresh root npm ci --ignore-scripts installed parser dependencies only. Actual browser/DB acceptance remains fresh CI's responsibility. New clone needed because the previous worktree's shared Git metadata had been pruned; unrelated media remains excluded.
