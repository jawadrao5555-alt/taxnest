# Public website accuracy

Public audit found PRA homepage 14,999 versus package table 19,999, retired plan names, a grocery PRA demo, an outdated APK size and stronger compliance/privacy claims than the inspected implementation supports.

Compatibility: current package table/gates/registration and sale discounts remain authoritative; no tenant settings, plan rows, prices, agent contracts or APK metadata are changed. Homepage price reads the same sellable plans and sale_price accessor as the public comparison table, including admin sale changes and the no-plan case. Existing privacy requests/contact links remain intact.

Changes: public content and privacy disclosures; loopback Chrome checks at 1366 and 390px wired into the existing isolated browser fixture. Preserve Business+ attendance wording but defer Restaurant/Kitchen inclusion to the live, database-driven comparison instead of asserting a contradictory package minimum.

Local reproduction: repository templates reproduce the audited mismatches. PHP and Chrome are unavailable in this workspace (`command -v php` returns no executable), so rendered verification/full PHPUnit must run in isolated GitHub CI. Local Node syntax and git diff whitespace checks run; these are not rendered browser evidence. CI Chrome checks visit each changed page, require HTTP 200 and assert corrected customer-visible claims. Live rendering requires owner approval/relay and Actions live verification.

Remaining: real signed Caller/Rider phone releases, WhatsApp delivery, provider acceptance and production accounts are outside this content PR. FBR copy is limited to the confirmed held-order KOT flow; no new feature is promised. Contact address and human support hours remain existing admin settings, rather than invented details.

CI found the existing minimal homepage smoke has no pricing_plans table. Guard the optional starting-price lookup with Schema::hasTable; that environment shows the existing 'See PRA pricing' fallback rather than a 500. Existing ExampleTest remains unchanged and is the missing-schema regression. Public rendered pages already passed in isolated Chrome on the prior HEAD; full exact-HEAD checks must rerun after this fix.
