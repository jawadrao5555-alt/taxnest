---
name: POS sale-screen boot performance
description: Why the sale screen splash stuck ~15s on real shops — unbounded customer x-for + Alpine CDN fallback race; rules to keep boot fast.
---

# POS sale-screen boot performance (Jul 2026 splash-stuck incident)

Real shop (11k+ saved customers, weak PC) saw the "NestPOS load ho raha hai" splash stuck ~15s online AND offline. Two independent root causes:

1. **Unbounded x-for over a baked list.** The customer-picker `filteredCustomers` getter returned the ENTIRE `allCustomers` array when the search box was empty → Alpine rendered 11k+ rows at boot → ~20MB DOM, `alpine:initialized` took seconds even on a fast CPU. Fix: slice(0,50) in BOTH branches of the getter; search still scans the full list.
   - **Rule:** any x-for over server-baked data (customers, products, held orders) must be capped or virtualized. Big shops WILL have thousands of rows; dev test companies never do.
2. **Alpine CDN fallback race.** The layout armed a CDN-Alpine fallback via a blind `setTimeout(1500)` in `<head>`. On slow PCs still parsing HTML at 1.5s, CDN Alpine booted MID-PARSE before `restaurantPos()` was defined → hundreds of ReferenceErrors, dead screen, double `alpine:init`. Fix: arm the fallback on DOMContentLoaded + delay, and set the `__alpineStarted` flag synchronously at injection.
   - **Rule:** never arm script-fallback timers from `<head>` with a blind timeout; wait for DOMContentLoaded so component functions exist first.

**Why:** dev/test companies are tiny, so both bugs were invisible until a real customer scaled up. → see `eloquent-missing-attribute-null.md` (same lesson: fresh/large-scale e2e catches what seasoned test companies can't).

**How to verify:** Playwright with CDP CPU throttling (`Emulation.setCPUThrottlingRate`) + count `pageerror` events and `alpine:init` firings; measure DOM size. Slow-PC races only reproduce at rate ~6.

**Deploy note:** sale-screen HTML is fingerprinted with `filemtime(universal.blade.php)`, so offline-first cached clients self-refresh after a view-only deploy — no SW CACHE_VERSION bump needed for blade-only changes.

## Baked customer list is CAPPED (Aug 2026, Task-100 era)
Both universal sale screens (PRA + FBR) bake at most ~500 customers — the most-recently-active subset, name-sorted — with a `customersBakedPartial` flag in x-data. Server customer-search endpoints are the source of truth (picker modal server-searches when partial); the baked subset is only the instant/OFFLINE fallback. **Deliberately NOT in the boot fingerprint** — new customers must never force a cached-screen reload; blade mtime covers code changes.
**How to apply:** never restore a full `PosCustomer::...->get()` bake; any new customer UI on the sale screen must work from server search + treat allCustomers as partial. Offline: search fetch failures fall back to filtering the baked subset (never blank the dropdown).
