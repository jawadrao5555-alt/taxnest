# Plain language for the Agent build-date notice

The owner's screenshot shows the already-published bullet:
`Build date packaging se aye gi; metadata na ho to unavailable bataya jaye ga.`
This is confusing customer copy, not evidence of a missing build-date defect.

Render that exact legacy bullet as:
`Agent banne ki tareekh Settings mein nazar aayegi. Purane Agent mein yeh tareekh na mile to "Build date available nahi" likha hoga.`

The customer display helper is used in POS/FBR POS featured notices and bells,
DI bell and shared detail modals. Stored points, notification IDs, content keys,
targeting, publish dates, seen/read records and admin historical copy are
untouched. Other wording/languages pass through unchanged. No new announcement
is inserted and nobody is marked unread again. The Agent executable, version,
build-date calculation, packaging and company/printer settings are unchanged.

AGENTS.md now requires ordinary shop-owner language for future customer notices:
what changed, where to find it and any required action. Internal technical
details belong in PR/QA records. This is an authoring requirement, not a claim
of automatic rewriting or review of every historical notification.

The real PHP display helper was executed locally against the legacy text and
unrelated copy; mapping and input preservation passed. Added PHPUnit coverage
checks the stored text/content identity remain unchanged and other languages
pass through. PHP syntax and git diff --check passed. Host PHP 8.3 is only a
syntax/helper check, not full PHP 8.4 Laravel acceptance. Fresh exact-HEAD CI and
desktop/mobile browser rendering remain pending; this PR stays draft. No
production data, credentials, notification sends, merge or deployment used.

## Refresh after deployed #235 — 22 October

Merged main `5a8ac35cc4a670dd77e5322c929d9e4a022ec666` into the existing
cursor branch without conflicts/history rewriting. Agent 1.13.21, Electron
42.11.3 manifest/lock/build, pinned release identity and reviewed lock graph
7160c23b726d468b39e3137c520e7e70922cef37953827119ee29b6eb9d6fc25 remain unchanged;
#227 tutorial recording/output-path contracts and all eight gates are retained.

The existing disposable notification fixtures now include the exact legacy
bullet in one routine notice and both duplicate featured receipts. Actual
Chrome notification journeys assert the plain replacement and absence of the
legacy sentence in POS/FBR featured popups, all three product bells and detail
modals at desktop1366/mobile390. Existing failed-acknowledgement retry, product
isolation, seven routine notices, duplicate consolidation and persistent read
state assertions remain. Screenshots capture plain featured and detail text.
These new assertions fail if the original helper/Blade correction is absent;
no backend notification/send/read-state behavior is changed by this addition.

The standard deployment announcement is unique to this release and written in
ordinary customer language. It does not rewrite or re-alert the old notice:
existing IDs/content keys/published dates/targeting/read state stay unchanged.
The old 'no new announcement' statement above describes the display helper;
normal owner-approved deployment still uses the repository-required release
announcement.

This fresh host has no PHP or installed Chrome; no new local PHP/application/
browser acceptance is claimed. The original helper execution above was a
historical run, not repeated here. Local Node release/build-display9/9,
tutorial recording2/2, release-chain matrix, locked graphs/negative integrity
checks, active/proposed CI contracts, browser JS syntax and whitespace pass.
Root locked JS dependencies were installed without lifecycle scripts for parser
checks; supported PHP8.4, Node22, MariaDB and real Chrome acceptance require
fresh exact-HEAD disposable GitHub CI. Keep draft until all eight pass; promotion
starts a new same-HEAD run whose own eight checks/validate must pass too.

#235 exact production deploy, SHA/NestPOS marker verification and relay callback
passed; its Windows build/assets succeeded. This PR does not prove installed
Windows update/recovery/fleet behavior or diagnose Offline Agent warnings.
No production/customer access, real print, merge, approval or deploy was done.
#237 remains HOLD. #236 correction proceeds separately at owner request.
