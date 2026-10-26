# Admin theme and notice readability

## Reproduction

On a fully migrated local MariaDB database with synthetic admin/company fixtures, all six theme choices produced the same workspace canvas (`rgb(244, 246, 243)`). The validation summary used pink text over a transparent red utility surface; compositing the real ancestor backgrounds gave approximately **1.01:1** contrast. The new semantic-notice rendering regression also failed against the original layout.

## Change

- Keep the existing six choices and saved `admin_theme` preference; map each to a distinct light workspace palette and apply the existing accent to primary controls and legacy dark cards. Existing native light ledgers retain their surface contract. Invalid saved theme keys fall back to Indigo; unavailable browser storage does not prevent applying a palette.
- Give legacy page headings and functional metadata deliberate foregrounds for their surface. Plans tabs expose their selected state and use a readable active colour.
- Use opaque error/success/warning notices for layout-level flash, validation and operational health messages. Error remains red and success green across themes; they do not inherit the selected accent. Validation uses `role="alert"`, success `role="status"`.
- CSS is scoped to the admin shell. No POS themes, saved company settings, prices, subscriptions, permissions or notification records are migrated.

## Verification

PHP 8.4.27: **32 tests, 292 assertions passed** across admin page, billing and dashboard/detail tests, including message content and semantic roles. Blade PHP syntax and `git diff --check` pass.

Local Chrome 151/MariaDB 10.11.14: validation summary contrast improved to **8.71:1** for all six palettes, and each choice now reaches the workspace. All six actual workspace/card palettes, title and active/inactive tab contrast, input text, warning headings, tab switching, mobile navigation closure and invalid stored-theme fallback passed. Measured text cases meet 4.5:1 or better. External utility CDN resources in this disposable fixture are supplied from locked local Alpine and compiled CSS, following the repository's existing premium UI harness. No real regulator endpoint or live account is used.

This is scoped verification of the shared shell and affected screens, not a claim that every admin modal or product screen has been visually recertified. No merge/deploy was performed; CI status is stated in the PR.
