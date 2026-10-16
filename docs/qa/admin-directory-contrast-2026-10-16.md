# Admin directory readability

Tracks #209. Owner screenshot of /admin/companies showed translucent dark-red
alert utilities leaving white/pale-red text on a pale surface. The dark company
rows also used gray-600 owner text and 10px action/status labels. The screenshot
was visually inspected; raw customer imagery and personal data are not committed.

Scope the fix to the company directory: opaque high-contrast alert, readable
owner/NTN/date text on the existing dark table, solid status/action labels,
13px functional copy and explicit input/option/placeholder colours. Existing
six saved themes, product labels, filters and action routes remain intact.
No settings or business data are changed; production is not accessed.

Add fictional rows for all five statuses plus an offline silent-print company.
Authenticated desktop/390px browser proof computes actual foreground/background
contrast (including alpha composition) >=4.5 in all six themes/light-dark and
checks minimum 12px text, placeholders/options, GET filtering and View pointer
reachability. General browser, native database and full CI must pass before
approval readiness. Local PHP/native Chrome are unavailable; no local runtime
pass is claimed. Node syntax and whitespace pass.

This is a separate screen-only PR from #197. More owner screenshots are expected
and will extend this same scope after inspection. Draft pending fresh browser
results and any additional supplied screens. No merge or deployment.

Fresh browser run 38865058060 reproduced dark-mode failures on the alert's POS
badge (1.63:1) and Silent print metadata (1.97:1). taxnest-ui.css uses an
html.dark + main + gray-text selector with higher specificity than the initial
scoped alert text rule. Bind that rule to the admin body so it wins in both
modes. Keep the contrast assertion unchanged; add the same safe read-only
directory journey to required early preflight for desktop/mobile feedback.

Run 38866621145: actual contrast/size/options/filter/pointer assertions PASS
on desktop and mobile across every theme/mode. The generic surface check
then compared pathname to a query-bearing path. Declare expectedPaths as
/admin/companies while retaining the search URL and actual filter assertions;
no contrast assertion or native UI interaction is weakened.
