# Admin dashboard review contrast and selection

Owner screenshots showed unreadable dark text on dark native select options on
the light dashboard-detail canvas. Apply filters also inherited the light
canvas text on a saturated dark button. The Dashboard sidebar item lost its
selected state when opening a metric detail route. Company-detail navigation
already selected Companies correctly and remains unchanged.

Use native surface/text tokens for detail inputs, selects and options, an
opaque dark primary button with white text, and explicit selected status chips
with a checkmark. Dashboard detail routes stay under the selected Dashboard
navigation. Company tabs expose aria-pressed and use a solid selected fill and
underline. Existing six saved admin theme choices remain intact; no settings,
billing queries, company credentials, fiscal transport or data are changed.

The new navigation/filter regression failed before correction because
aria-current was absent. After correction the admin detail and existing admin
smoke suite pass: 18 tests / 121 assertions using scripts/rc-safe-run.
PHP/Node syntax and diff checks pass. Existing 13-card browser journeys remain;
the same authenticated desktop/mobile journey now switches all company tabs,
checks selected navigation/status and clearing the status filter, and checks
computed foreground/background contrast >= 4.5 for actual controls/options in
all six themes in light and dark mode. Browser/native/full CI on the final HEAD
must pass before readiness. Local Chrome/MariaDB socket restrictions from the
prior release remain; no local browser or live verification is claimed.

Owner screenshots were reviewed as supplied; raw customer screenshots and
personal data are not committed. This is a follow-up to merged PR #201.
