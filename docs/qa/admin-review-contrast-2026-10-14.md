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

First fresh CI reproduced a further actual failure: selected company tabs did
not update when external Alpine was unavailable (the browser fixture blocks
non-loopback dependencies). The three dashboard company tabs now use native
DOM event handling with initial aria-pressed/hidden state, removing their CDN
dependency. Existing company lists and default DI selection are preserved.
Browser checks also require exactly one visible panel matching each clicked tab.

Owner added invoice popup/print/download and strict product isolation. Invoice
references now open a native modal containing the PDF rendered from the existing
DI invoice, PRA receipt or FBR POS invoice layout. Preview/download routes require
platform admin login plus matching typed product, company and record. No tenant
impersonation, currentCompanyId override, regulator request, browser print job or
business-data mutation occurs. Archived PRA rows keep their existing hidden scope.
PDF remote resources, PHP and JavaScript execution are disabled. PDF cache is
private/no-store; inline frames are same-origin. Browser downloads retain the
product/company/record identity in a safe server-generated filename.

Product/table ownership is now enforced in dashboard counts/revenue and detail
rows/search/filter/CSV, excluding wrong-product and orphan records. This is a
 deliberate correction to previously unscoped aggregate semantics. Historical
rows themselves are not changed; invalid associations require owner data review.

Guarded admin/detail/FBR evidence regression: 33 tests / 292 assertions PASS.
Coverage includes overlapping numeric IDs across all three tables, mismatched
product/company, another same-product company, hidden archived PRA documents,
tenant-session denial, original-record preservation, and genuine PDF response
bytes/dispositions (layout rendering separately covered in CI). The authenticated
browser fixture now renders/downloads real stored documents in all three products
and rejects product/company substitutions. Fresh final-HEAD CI must pass.
