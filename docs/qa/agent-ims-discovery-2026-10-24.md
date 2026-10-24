# FBR IMS discovery after pairing

Owner reports successful FBR pairing but missing IMS download portion. The deployed imsMode helper returns null for authenticated FBR cloud-mode heartbeat. This explains that configuration's hidden section, not proof of the reporter's live setting (no production access).

Correction: any online authenticated FBR company can discover/check/explicitly install its local IMS, regardless of current reporting mode. Show an explicit unchanged-reporting-mode explanation for cloud/unknown mode. No company routing/configuration is changed. PRA fiscal-only visibility, disconnected/unknown-product fail-closed rules, official installer implementation, IPC/security boundaries remain unchanged. No automatic install is introduced.

Local tests: node --test pra-agent/test/ims-visibility.test.js:3/3 PASS. Real Chromium rendered production Agent settings through scripts/agent-build-display-smoke.mjs: FBR cloud/fiscal modes show missing-service install button, PRA cloud/disconnected hide; existing build-date/missing/failed IPC and no-write/pageerror assertions PASS. IPC transport is synthetic; no real installation/download/printer/production action.

Fresh exact-HEAD CI and packaged Windows acceptance required. Existing installed Agents need a subsequently owner-authorized packaged release/update; deploying server source alone does not update an installed desktop binary. This PR does not publish or replace any release tag/assets. Source release identity remains unchanged pending release preparation; reconcile with immutable published identity before packaging for distribution.
