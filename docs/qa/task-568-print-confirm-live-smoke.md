# Task 568 — Live smoke test: "Print se pehle poocho" (Yes/No) dialog + What's New elaan

Date: 13 Aug 2026 · Live HEAD at smoke time: `07858f9e306359f178a26494522ca40eda983ae4` (contains Task 565 commit 90c90133)

## Deploy
- Reconciled task-agent lineage divergence (`git merge -s ours origin/main`, origin had no unique content) and pushed; `scripts/deploy-live.sh` completed: live HEAD == workspace HEAD, migrate + caches + web OPcache reset, homepage 200, preflight white-screen check passed.

## Smoke procedure & results (production, real browser via Playwright tester)
QA-only accounts used (no real customer data touched):
- PRA: standing QA company id 35 (qa.fullaudit@taxnest.com.pk), /pos/invoice/create
- FBR: standing FBR test company id 20 "FBR POS Test Company" (fbrtest@taxnest.com, 0 real transactions; its admin password was reset to the private QA password — value stored ONLY outside the repo, never committed), sale screen /fbr-pos/create

Flag `print_confirm_ask` was turned ON for both companies (JSON key in `companies.pos_printer_settings`), verified baked into both live sale screens (`printConfirmAsk: true` + 4× `openPrintConfirm` in rendered HTML).

Browser results (tester verdict: **success**, screenshots jjduaw / v56q4d):
- FBR screen: after CASH payment the in-page "Print karein?" modal appeared immediately on top.
  - Esc (Nahi): dialog closed, `window.__printCalled` stayed false, NO new `auto_print=1` receipt request.
  - Enter (Haan): print chain ran — receipt request `/fbr-pos/transaction/29/receipt?auto_print=1` observed.
- PRA screen: same modal appeared after CASH payment; Esc closed it with no print request.
  - Enter (Haan) re-verified in a second run (13 Aug 2026): print chain ran — receipt request `/pos/restaurant/receipt/1782?auto_print=1&_signal=print-receipt-frame_...` observed, browser log `[printReceipt] URL= /pos/restaurant/receipt/1782?auto_print=1` (tester verdict: success, screenshot tzrgtp).
- Flag-OFF companies unaffected (feature is opt-in; default `print_confirm_ask=false` in `Company::printerSettings()`).

## Cleanup (verified on live DB after smoke)
```
company 35 print_confirm_ask=false
company 20 print_confirm_ask=false
```

## What's New elaan (live row — announcements are operational data, created per deploy per the standing runbook in .agents/memory/pos-whats-new-updates.md, not versioned migrations)
```
app_update 132: published=true audience=all title=Naya Feature: Print Se Pehle Poocho (Yes/No)
points:
  - Ab aap chahen to har bill ke baad print se pehle ek chhota sa "Print karein?" sawal aayega — Enter = Haan (print), Esc = Nahi.
  - Yeh feature OPT-IN hai: jab tak aap khud ON na karein, aap ka system bilkul pehle jaisa chalega.
  - NestPOS (PRA) mein ON karne ka rasta: Printer Settings → "Print se pehle poocho" ka switch.
  - FBR POS mein ON karne ka rasta: Receipt Settings → "Print se pehle poocho" ka switch.
  - Kaam ke liye behtareen jab kuch customers ko print chahiye aur kuch ko nahi — paper aur waqt dono ki bachat.
```
Audience must be `all` (pos-app shows 'pos'/'all', fbr-pos-app shows 'fbr_pos'/'all'). Verified rendered on BOTH live dashboards logged-in (grep count 2 each for the title on /pos/dashboard and /fbr-pos/dashboard).
