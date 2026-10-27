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

## Refresh after owner deployment #302 (27 October)

The branch now includes main `f6040daee73b5d375fdf4fd72ec0b48767b1a175` (owner deploy [40428839823](https://github.com/jawadrao5555-alt/taxnest/actions/runs/40428839823): apply, exact-SHA live verification and relay outcome all passed). Published PR history and approval-read recovery remain intact. The release announcement describes this theme release only; earlier announcements remain in deployment history.

The old browser failure (run 40332158344) was reproduced: the directory contrast reader rejected Chromium's `color(srgb ...)` serialization. Normalize those channels to the existing RGB scale and retain alpha compositing, the 4.5:1 threshold, and the small-text/placeholder checks. Unknown or non-finite colours still fail the audit. Once the parser ran, actual Chrome exposed View links at 2.42:1 and a Rejected badge at 1.21:1: generic themed legacy-card rules overrode native directory foregrounds. Exclude the native directory from those rules and preserve the action's semantic foreground.

Fresh local checks on the merged working tree:

- Guarded PHP admin page/billing/detail/MySQL-colour suite: **32 tests / 292 assertions**, 28 existing deprecations.
- Locked asset build passes with local Node 24.22.1; JavaScript syntax, release-announcement parser and whitespace checks pass. Node 22 remains a required fresh CI gate.
- **Actual Chrome Headless Shell 151.0.7922.34 + disposable MariaDB 10.11.14**, loopback TCP only (this workspace disallows Unix sockets). The repository's unchanged directory journey assertions pass at 1366px and 390px through six palettes × light/dark, including native badges/actions/options/placeholder contrast, GET filtering and action hit targets. Thirteen intentionally blocked external-resource diagnostics per viewport were recorded; no page errors or unexpected request failures passed the gate. Mandatory MariaDB 10.6 CI is unchanged and must pass separately.
- A separate actual-browser Plans check uses a real invalid local Plans POST and rendered server validation summary. Error text stays red at **8.71:1** in all six palettes at both widths; six distinct workspace backgrounds, stored choice, invalid-theme fallback, selected tab state, tab switching and mobile navigation closure pass with no page errors. Locked local compiled CSS/Alpine substitute only for external CDN dependencies, matching the original harness approach.

Fresh **all eight** final-head CI checks including validate, full PHP, actual desktop/mobile browser, native MariaDB, Node22, audit/build and release checks plus clean mergeability are still required before deploy readiness. No production mutation or settings migration was performed by the agent. This PR does not implement the remaining #296 security/timeline requirements.
