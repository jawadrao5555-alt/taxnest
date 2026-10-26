# Explicit caller Bill: consistent Delivery type

Owner video `a0fc2d7b-2d8d-43e8-b23a-650f29532ce3.mp4`: a caller without a saved customer remains Takeaway after attaching to the bill. The actual callerBillFrom method changed to Delivery only inside its matched-customer branch.

Before patch, the new regression reproduced `takeaway` instead of `delivery` (1 failed/3 passed). Move the existing feature-gated transition after either customer-attachment branch. A passive incoming ring remains non-mutating; the explicit Bill/attach action follows the same existing policy for both identities. Saved-customer identity/address, existing cart and shops without Delivery are preserved.

Local production-method caller + delivery-address regressions: 18/18 pass after patch. Browser acceptance extends the real sale/delivery journey with a fictional unsaved caller and screenshot at desktop/390px; CI pending. Native supported PHP8.4 and installed Chrome are absent on the current host, so no local full-suite/browser or independent live acceptance is claimed. No real calls, customer data or print jobs used.


## Refresh after owner-deployed #239 — 22 October

Owner handoff39782232305/job119836143170 bound PR239 approved
1015f2414e0a0331023958fc5dd4da16fc963b31 to squash
254b2ddf90b2bee561f908bec6db31468c72966a. Exact Deploy39782316531 succeeded:
job119836751918 logged extended live SHA/NestPOS marker PASS at12:58:09Z;
callback119837961523 returned succeeded for the same SHA and
approval5e33f1f0-3e6f-432c-8c55-292e8b5c600a. This is Actions acceptance,
not independent broad customer production screen acceptance.

History-preserving merge of that deployed main into this existing draft. The
only conflict was the PR CI delivery regression command: retain BOTH deployed
phone-search and this caller-order test alongside all original address tests.
No gate removed/weakened. Preserve #239 dashboard notice reading/race tests,
#238 ordinary notice helper/identity/read state and browser assertions, #236
phone/current-old/tenant regressions, #235 Agent1.13.21/Electron42.11.3 and
reviewed lock graph7160c23b726d468b39e3137c520e7e70922cef37953827119ee29b6eb9d6fc25,
immutable old tags and #227 recording/output/CI contracts. Unique ordinary
customer announcement added per handoff policy; no source Agent version bump.

Fresh reproduction runs this PR's caller method regressions against deployed
main source in a disposable temporary fixture: unsaved caller still Takeaway
instead of Delivery (1fail/3pass). Refreshed PR actual-method regressions then
pass53/53: caller4, dashboard4, acknowledgement/read-state12, phone/address22,
release identity/legacy display9, tutorial2. Node24.19.0; JS syntax, whitespace,
release-chain, all locked graphs with negative fork-integrity probes and
active/proposed fail-closed CI contracts pass. Parser dependencies reused only
for local guards; no claim that these run a supported Laravel/Chrome app.
PHP8.4/installed Chrome absent. Existing setOrderType table-switch confirmation,
recalled-order safeguards and address handling remain intact.

Fresh exact-HEAD disposable fullPHP/MariaDB/Node22/realElectron/Chrome1366+390,
locked audit/build, workflow/release gates and own final validate are required.
Old39670956471 six non-audit passes and shared old-base audit failure are
historical only. Keep draft until actual new8 pass and clean mergeability;
promotion-triggered same-HEAD checks must independently pass8 before final
owner-approval readiness. No superseded audit retry or production action.
