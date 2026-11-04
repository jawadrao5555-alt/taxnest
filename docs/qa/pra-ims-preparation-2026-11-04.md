# PRA login: FBR IMS preparation before reporting

## Report and reproduction

The owner supplied Agent and Tax Integrations screenshots: a connected PRA
company has FBR reporting disabled, a selected local connection, an online PC
and no detected service. The Agent hides its entire IMS setup panel.

On deployed main da2a0956, `imsMode` returns null for PRA cloud connections
unless the server reports an enabled local FBR profile. For PRA local mode it
only offers PRA. Preparing FBR therefore depends on enabling reporting first.
Two synthetic configuration regressions reproduce these outcomes before the
fix. A third test reproduces missed endpoint-scoped WCF metadata. These are
separate causes: the screenshots alone cannot prove why the owner's local
service is absent, or that it publishes metadata at the fallback path.

## Change

Authenticated PRA cloud accounts can prepare/check FBR IMS while reporting is
disabled. PRA local accounts display PRA plus optional FBR preparation. The
existing independent server dispatch/profile authorization remains unchanged.
Unknown/disconnected identities remain hidden and cannot invoke installation.
The UI explains preparation versus reporting and no longer tells every owner
to select Production or promises that a green listener starts queued reporting.

Discovery tries bounded service-level and endpoint-level WCF metadata GETs.
Windows registration parsing supports the known endpoint-specific path.
Generic HTML, incomplete metadata, DTD/entity documents, external hosts and
unrelated registered paths do not establish a compatible FBR service. No
invoice POST is used for discovery; no cloud token is sent to a local probe.

Agent release identity is 1.13.32. Only root version fields change in the npm
lock; dependency versions/integrity/edges remain identical. No tenant data,
PRA mode, token, pairing identity, printer or installed IMS environment is
changed by exposing preparation.

## Compatibility and acceptance

| Case | Acceptance |
|---|---|
| PRA cloud, FBR disabled | visible optional FBR setup, explicit Re-check/install only |
| PRA local, FBR disabled | retain PRA status with optional FBR preparation |
| PRA enabled alongside / standalone FBR | retain compatible SOAP and existing JSON flow |
| Unknown/legacy identity or disconnected | no optional install capability |
| Default / registered nonstandard port | bounded loopback metadata, no fiscal POST |
| Endpoint-scoped metadata only | find service even when service-level metadata is 404 |
| Generic listener / unsafe metadata | remain undetected |
| Existing paired settings | visibility calculation does not mutate input |

Pre-fix: 3/3 new configuration/discovery tests failed. Post-fix focused checks
pass (exact counts and CI results in PR body). Actual loopback HTTP regression
exercises a WCF-shaped host with endpoint-only metadata, not only an Axios mock.
Existing real Agent HTML browser journey is extended for reporting-disabled PRA
cloud and local accounts, explicit install, Re-check and unknown/disconnected
identities. The fixture never installs software or submits a fiscal invoice.

Local Chrome launch is blocked by missing Chromium; Playwright download retries
return invalid/truncated ZIPs. PHP is unavailable, so no local Laravel/full PHP
suite or MariaDB acceptance is claimed. Required hosted browser, Windows,
full PHP/DB, Agent and release checks remain intact. A broad local Agent run
exited without a completed summary; it is not a broad pass claim.

## Field verification after owner release

Verify 1.13.32 on the shop PC; check the visible IMS panel without enabling
reporting, then Re-check. Refresh Tax Integrations after the next heartbeat.
If metadata remains unavailable, investigate the installed service's actual
listener and WSDL state; do not infer failure from reporting being disabled.
Independently confirm POS ownership, installed environment and central receipt
before an explicitly approved pilot. No real fiscal submission or live setting
change was performed for this patch. Owner approval/relay only after green CI.
