# Table Setup setting controls

Owner media: AUDIO-2026-10-22-18-56-30.m4a and two Table Setup photographs.
The two option labels appear but their controls are not distinguishable in the supplied screen. Existing markup hides the real inputs and relies entirely on peer/pseudo-element styling. This change exposes native 22px checkboxes with a 44px labeled target, stable borders, light/dark colors and keyboard focus. It preserves both existing POST endpoints, checked values, CSRF, feature/admin guards and default-OFF configuration. No company is silently opted in.

Local: Node browser component test renders the actual changed markup/CSS at1366/390 light/dark and checks both controls, native check/uncheck and focus. This is component evidence, not a full Laravel screen reproduction of the photographed styling. The original styling failure has not been reproduced in a supported full local app. PHP8.4 is unavailable; standard Chrome download truncated. An isolated npm-distributed Chromium153 runs for component checks. No production/printing.

Extended existing disposable table-order CI journey: visit real Table Setup, measure both controls at desktop/mobile/light/dark, focus them, toggle/persist each through its existing POST then restore the original flag. Retain every original table, caller, phone and billing assertion. Full application/MariaDB/browser acceptance remains pending CI; keep draft. All existing eight gates unchanged.

Fresh CI found that the newly added settings test used the existing CASHIER
session; the settings route correctly refuses cashier management. Correct the
fixture to log in the already-seeded same-company owner in a separate context,
then close that context. Preserve the original cashier role/session and every
setting/save/focus/size assertion; no production permission is broadened.
The first audit lane also found newly published Electron advisories against the
unchanged shared main pin 42.11.3 (including GHSA-x562-297c-g43g). Do not bypass
that audit or copy unrelated dependency changes into this UI PR. Remains draft;
new exact-head CI required, not a browser/full-check acceptance claim.
