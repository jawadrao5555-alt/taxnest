# Accurate order-type instructions

Owner videos 4ba007ac-34ea-4d27-8e20-6058ba6dcacc.mov and 05b540f4-f9da-4ce6-8279-c379a95c388d.mov show the "F2 to cycle" hint and no order-type change. The live keyboard handler intentionally sends F2 to product search (including exits from guided/table overlays), as documented by earlier owner requirements. The order-type hint/chip is stale.

Remove F2 from that control and give plain English/Urdu click-selection instructions. Do not change F2's existing search shortcut, order selection, guided flow or saved settings. Browser acceptance presses F2 on the real sale screen, checks actual search focus, unchanged order type and no misleading F2/cycle label, then continues catalog/table regressions. JS syntax/whitespace pass locally; supported local PHP/app/browser unavailable, actual browser acceptance pending CI. This corrects misleading instructions; it does not claim a separately observed hardware keyboard failure is diagnosed.

The F1 shortcut panel contained the same stale F2 order-cycle label; it now correctly says Product Search using existing translations.

## 25 October current-base refresh

Merged owner-deployed main `079a2d02ef7a0b06e7da3abf0fb42b0ee1510fb9` into the existing branch, retaining history. The only Blade conflict combined the deployed `data-tn-order-type-switcher` marker with this PR's corrected title. Deployed 14px/44px controls, inset focus, caller/address safeguards, ordinary Parcel-note flow from #276, and Agent 1.13.23/Electron 42.11.16 remain present. No shortcut handler changed. Main's current dependency/release identity is inherited, with no independent dependency edits.

Local focused regressions: 45/45 passing across caller order type, dashboard, acknowledgement/read state, delivery address/phone and tutorial contracts. Browser script syntax, whitespace and Agent release-chain guards pass. Root parser dependencies installed from lock with `npm ci --ignore-scripts`; initial sparse checkout lacked the local braces package, corrected by materializing `tools/` before successful install. Full supported Laravel/Chrome acceptance for this branch is still required in its new exact-head PR run. Old October22 six-lane results are historical only. No production/customer acceptance or deployment claim.

Current-base verification also passes `npm run build`, the active/proposed fail-closed CI contract guard, and all five reviewed locked dependency graphs. The first lock-graph attempt lacked two sparse-checkout files; materializing those unchanged files made the complete guard pass. Build output is local-only and is excluded from the intentional commit/tree. Source/handler inspection confirms no keyboard runtime change; this branch's new actual Chrome F2/search assertions remain mandatory before readiness.

Final refresh merged current owner-deployed `main` at
`fe323620af8101e7ede4620d64a407d8b7875a4f` (#277). The only conflict remained
the expected one-release `deploy/elaan.yml`; this PR keeps its own unique POS
announcement. #277's Agent heartbeat/IMS identity code and regression test are
inherited unchanged. The F2 handler and all fiscal, saved-setting, Agent and
submission behavior remain outside this PR's application diff.
