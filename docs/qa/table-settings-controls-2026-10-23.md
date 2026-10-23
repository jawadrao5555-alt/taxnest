# Table Setup setting controls

Owner media: AUDIO-2026-10-22-18-56-30.m4a and two Table Setup photographs.
The two option labels appear but their controls are not distinguishable in the supplied screen. Existing markup hides the real inputs and relies entirely on peer/pseudo-element styling. This change exposes native 22px checkboxes with a 44px labeled target, stable borders, light/dark colors and keyboard focus. It preserves both existing POST endpoints, checked values, CSRF, feature/admin guards and default-OFF configuration. No company is silently opted in.

Local: Node browser component test renders the actual changed markup/CSS at1366/390 light/dark and checks both controls, native check/uncheck and focus. This is component evidence, not a full Laravel screen reproduction of the photographed styling. The original styling failure has not been reproduced in a supported full local app. PHP8.4 is unavailable; standard Chrome download truncated. An isolated npm-distributed Chromium153 runs for component checks. No production/printing.

Extended existing disposable table-order CI journey: visit real Table Setup, measure both controls at desktop/mobile/light/dark, focus them, toggle/persist each through its existing POST then restore the original flag. Retain every original table, caller, phone and billing assertion. Full application/MariaDB/browser acceptance remains pending CI; keep draft. All existing eight gates unchanged.
