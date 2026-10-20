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
