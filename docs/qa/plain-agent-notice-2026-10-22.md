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
