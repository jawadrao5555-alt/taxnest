# Notification system/category correction — 2026-10-25

Status: owner-authorized implementation contract only. No runtime fix or deploy readiness is claimed.

## Confirmed issue
At deployed main 6e23a10665494f557e7435b357932deb402acf13, AppUpdate::scopeForCompany already distinguishes PRA (pos/all), FBR (fbr_pos/all), DI (di) and resolved business categories. The FBR-only #277 announcement was published with audience all, so PRA recipients can see it.
Its immutable deployment receipt is fe323620af8101e7ede4620d64a407d8b7875a4f. Do not identify historical receipts by arbitrary title matching or company IDs.

## Required implementation
- Correct delivery of that exact historical receipt to FBR only while preserving stored notice identity, notification_key, dates, points, history and existing read/seen acknowledgements. Do not re-publish it as a new unread announcement.
- Audit other committed release notices for actual product/category relevance. Shared Agent functionality can legitimately target both POS products; do not classify every Agent notice as FBR-only.
- Publish future notices with explicit intended PRA/FBR/DI audience and optional actual business categories. Unknown/invalid category or audience input must fail closed rather than broadcast.
- Preserve category resolution, hotel/food-service distinctions, company-family restrictions, second-tenant isolation, bell/popup agreement and mark-seen eligibility.
- Review order-type-only notices against the actual tables/KOT/kitchen/delivery feature gates; do not guess that all POS businesses are restaurants.
- Fix the publication pipeline root cause: elaan-spec-parse.py and elaan-insert.sh currently exclude DI, while elaan-freshness-check.sh only counts pos/all receipts. Correctly targeted FBR/DI announcements must satisfy the existing release gate without broadening them to all.
- The freshness gate must bind to the committed intended scope and exact deployment receipt when supported, with explicit transitional compatibility. Wrong audience/SHA, unrelated notices, unpublished/deleted records, invalid specs, missing marker, DB/SSH errors must remain blocked. Never remove or bypass a gate.
- Preserve the meaning of legacy all (both POS products, not DI). Avoid automatic guesses from title/wording and do not reset user read state.

## Verification required
Reproduce the current PRA leak using the exact receipt and fictional companies, then retest PRA exclusion/FBR inclusion. Assert stored attributes, key, seen rows and dates remain unchanged. Same-title unrelated manual notices and shared Agent all notices must remain unaffected. Cover DI/category/family isolation, bell/popup and mark-seen.
Exercise actual parser/insertion dry-run and freshness logic with mocked local transport/DB only. Cover intended FBR/DI pass and all wrong-scope/SHA/unpublished/missing-marker/error negative cases. No production SSH or production insertion.
Run relevant existing audience/read-state/parser/freshness/idempotence tests and actual loopback rendered UI where feasible. Fresh exact-head mandatory eight CI lanes including own validate and clean mergeability are required before owner release review; docs-only green CI is not functional acceptance.

Separate from admin reports PR #278. No source changes, production actions, approval, merge or deploy in this specification commit.
