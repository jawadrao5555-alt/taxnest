# Dashboard notification reading — owner video review

Source: owner video `6f08d460-037d-4ebf-bd68-fac2d7ddf2fd.mp4` shows a notification closing while being read. The restaurant dashboard unconditionally reloads after its 30-second HEAD probe; opening a notification does not stop that reload. This is a code-confirmed cause, not a claim that every possible dismissal has been diagnosed.

Compatibility matrix:
- Open featured notice/history detail/bell: skip automatic refresh.
- Notice opened during a pending HEAD response: do not reload.
- All overlays dismissed or hidden: existing periodic refresh resumes.
- Failed reachability/concurrent timer: retain current no-reload/no-duplicate behavior.
- Read acknowledgements, notification audience, tenant selection and stored settings are unchanged.

Local `node --test scripts/tests/dashboard-notice-reading.test.mjs`: 4/4 pass against the actual production methods, including the in-flight race. `node --check scripts/rc-browser-acceptance.mjs` and `git diff --check` pass.

The existing real Chrome restaurant journey now opens the actual bell and notification detail on the dashboard, invokes the actual refresh handler, asserts the notice survives, and captures desktop/mobile screenshots. That acceptance is pending GitHub CI; local supported PHP8.4 and installed Chrome are absent; no local application/browser acceptance was run. Do not call the customer issue independently live-verified. No production or customer data was accessed.


## Deployed-base refresh — 22 October

Owner-approved #236 HEAD765284568ab5cb2cfe6528189d37a2ce9de1b0fb was bound by
handoff39754253018/job119742152696 to squashe41fdc93e69c013b6223adae50a228f5d051dcfe.
Exact Deploy39754324935 succeeded: job119742677814 logged extended live SHA and
NestPOS markers PASS at09:20:32Z; callback119743551171 returned succeeded for
that SHA and approval228fc76a-4156-43ca-938a-832ed85bd8ce. This is Actions
acceptance, not independent broad customer live screen acceptance.

Merge this deployed main into the existing #239 branch with history intact.
No merge conflicts. Preserve #236 phone search/current-old/tenant collections,
#238 ordinary notice display, immutable notification identity/read state and
actual browser regressions, #235 Agent1.13.21/Electron42.11.3 and reviewed graph
7160c23b726d468b39e3137c520e7e70922cef37953827119ee29b6eb9d6fc25,
and #227 tutorial recording/output/fail-closed CI contracts. Add a unique ordinary
customer announcement for this release as required by handoff policy. No Agent
version bump, stored settings, read acknowledgement or server query changes.

Fresh Node24.19.0 actual dashboard/notification methods, existing phone/address,
release identity/legacy build display and tutorial tests are rerun below in PR
verification. Native PHP/Chrome absent on this host, so real app, desktop390 and
MariaDB acceptance must come from fresh disposable exact-HEAD CI. Do not count
old-head six green jobs or old shared audit failure as current evidence. All8
including own validate and clean mergeability required before promotion; any
promotion-triggered same-HEAD run must pass its own8 before final readiness.

Fresh local result:49/49 passed (dashboard4, notification acknowledgement/read
state12, phone/address22, release/build display9, tutorial2). Release-chain, all
locked dependency graphs and negative local-fork integrity, active/proposed CI
contracts, JS syntax and whitespace pass. npm ci --ignore-scripts only installed
parser/build dependencies; it is not application, Electron or browser acceptance.
