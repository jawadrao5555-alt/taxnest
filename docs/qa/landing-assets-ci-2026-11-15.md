# Exact-source landing assets

CI run 43695401627, browser job 132754687949, failed in
`public-website-checks/landing-top.mjs:15`: the Menu remained visible at
1366px. The step is named DI periods/Smart Packing but public checks execute
first, so this failure does not establish a DI billing defect or a DI pass.

The template already used `xl:hidden` / `xl:flex` after #376 reconciliation.
The committed manifest still served app-DrAowDHg.css, which contains neither
rule. The browser lane installed dependencies but did not build assets;
the separate audit/build job's filesystem is not shared with that lane.

Rebuilt the manifest CSS and made the browser lane build its exact checkout
before fixture setup. Added a manifest-bound parsed CSS contract for the
1280px hide/show rules. It fails against the previous asset and passes against
the rebuilt one. The actual landing browser assertions are unchanged, including
390/768/1024/1366 visibility, clipping, overlap and short-screen menu checks.

Local Vite build, CSS contract, YAML parse and diff check pass. A real Chromium
reproduction attempt was blocked by this host's `socket() failed: Operation
not permitted` process-singleton restriction; no local browser PASS claimed.
Fresh exact-head hosted browser acceptance remains mandatory. No live action,
merge, deploy or fiscal transport occurred.
