# Live Ops readability follow-up — 20 October 2026

## Reproduction and scope

The review did not confirm an unreadable live Companies directory. This change
is limited to the separate Live Ops landing page. Its Propose control uses
white on Tailwind amber-600 (#d97706), a nominal 3.19:1 text contrast. The new
#92400e surface gives 7.09:1. These are colour-pair calculations, not a claim of
measured production acceptance. Existing shared muted-card tokens already help
secondary text; the scoped override makes these labels explicit and brighter.

History already rendered `None yet`: this is clearer wording, not a missing
history or data-recovery fix. Inputs/options use an explicit dark surface and
placeholder colour. Long IDs wrap inside their cards.

## Compatibility

- All six saved admin themes and both mode classes: keep stored preferences.
- Existing populated history/links and diagnostic/propose actions: unchanged.
- Empty history: identify each empty list without issuing a command.
- Admin authorization, POS guards, companies, billing and tenant settings: no changes.

## Validation

Local: `git diff --check`, `node --check scripts/rc-browser-acceptance.mjs`,
PHP 8.3 syntax lint of the changed test and seed file passed. The supported
runtime is PHP >=8.4.1; full PHPUnit/MariaDB were not run locally. Local Chromium
installation failed with an invalid/truncated download. No runtime requirements
were weakened to manufacture a passing result.

The new `admin-live-ops-readability` authenticated browser journey runs in the
existing isolated MariaDB/Chrome CI fixture, at 1366px and 390px, six themes and
two mode classes. It measures composited text and placeholder contrast and
checks page overflow and empty states without submitting diagnostic/propose
forms. Original theme/mode are restored. Existing full PHPUnit and browser
acceptance remain required. CI status must be checked on exact PR HEAD.

This PR is not deployed and is not independent production/mobile acceptance.
