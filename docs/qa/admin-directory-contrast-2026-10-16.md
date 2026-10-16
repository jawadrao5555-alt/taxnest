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
