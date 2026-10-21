# Tutorial capture preparation

Reproduction: browser acceptance captured screenshots only; there was no safe
repeatable recording path for current POS features. Adds explicit four-journey
allowlist, exact disposable fixture paths and synthetic-only context recording.
Video recording stays off for all ordinary test runs. Existing assertions and
outbound-network restrictions are retained. A successful manifest is written
only after every requested desktop/mobile flow passes. Files are staged before
the disposable fixture is removed; CI uploads only the staged review bundle.

Regular PR CI records the existing Top Selling preflight, then still runs the
entire unchanged acceptance matrix. Local opt-in/path/symlink tests pass;
JS/shell/YAML and whitespace checks pass. Real Chrome/SDK/PHP unavailable in
this workspace; exact-HEAD CI recording remains required. No production or
customer video, narrated final tutorial or public release is claimed.

CI correctly rejected an extra upload preceding sanitized evidence. Keep the
sanitized evidence artifact first and preserve its existing success gate. The
DAG guard now explicitly restricts the additional browser-only synthetic
preview upload to success(), two exact staged patterns, required manifest and
Top Selling recording preflight; arbitrary extra uploads remain rejected.

Refresh on 21 October incorporates deployed main
`d08eeacb5558e8f82d76eb18da0afd07226c0ab5` after PR #226 passed exact-SHA
live verification and its relay callback. The CI conflict retains both tutorial
recording and Android beta inventory checks. This release keeps its tutorial
announcement; #226's reporting announcement has already been deployed.
Reporting read-evidence and WhatsApp readiness browser assertions, and the
reviewed dependency security patches, remain present. Fresh eight-check CI and
synthetic recording artifacts on the refreshed exact HEAD are required.

Artifact inspection on refreshed HEAD c8e5e102 found a real upload-path defect:
the preview ZIP contained the two WebMs but omitted recording-manifest.json.
The container generated files under its runtime temporary directory, while the
upload action received host runner.temp paths; its log chose `/` as archive
root. Both recording workflows now export the real staged runtime directory
as a step output and use that output for upload. The CI contract rejects the
old host-path inputs. Re-test must inspect the resulting ZIP for both videos,
the manifest, and matching source/hash/size metadata before approval readiness.
