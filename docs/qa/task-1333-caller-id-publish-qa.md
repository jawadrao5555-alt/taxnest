# Caller ID App — Publish & Live QA (20 Aug 2026)

Released **TaxNest Caller ID v1.0.0** (`pk.taxnest.callerid`, Android 8+, 4.4 MB).
The APK was already hosted; this task added the admin release control, verified the
whole chain against live, and switched the download cards on.

---

## 1. What shipped

| Change | Where |
| --- | --- |
| "Latest Caller ID Android App Version" field | `/admin/settings` → Mobile App (APK) card, next to the POS / DI / FBR POS / Waiter / Rider fields |
| `caller` key added to the in-app update endpoint | `/api/app-version?app=caller` (was answering `unknown app`) |
| Release flipped ON | `caller_app_latest_version = 1.0.0`, saved through the admin Settings form |
| Elaan published | What's New row #179, audience `pos`, Roman Urdu, 8 points |

**Beta-safe gate (unchanged behaviour, now reachable):** empty version = the
`/download` card and the POS → Customize download button both stay hidden. Any
value reveals both. Caller ID itself remains an **Unlimited-package** feature and
still needs the per-shop toggle — publishing the APK does not hand anyone the feature.

---

## 2. Server-side verification — 46/46 PASS

Simulated the phone app against the **live** API using the standing QA company
(id 35) only. It was temporarily put on Unlimited, then restored to its exact
previous state (plan, admin override, toggle, test bill, test customer, rings,
devices — all reverted; verified after teardown).

**Pairing & auth**
- [x] App sign-in with the shop's admin login returns a device token; shop name correct
- [x] Wrong password rejected (`invalid_credentials`)
- [x] Made-up / spoofed token rejected (401)
- [x] Second phone pairs with its own token — the first phone is **not** kicked out
- [x] Revoke from POS → Customize kills only that phone (revoked phone → 401; the other keeps working)

**Ring → sale-screen popup**
- [x] Known-number ring accepted
- [x] Immediate re-ring of the same caller collapsed — **exactly one** popup, not two
- [x] Popup carries the matched customer's name, readable local number (`0300-1234567`), khata balance (Rs 1,500), visit count and last-order date
- [x] Poll cursor does not replay a call that was already shown
- [x] Paired phone reported online (no false "phone offline" warning)
- [x] Unknown number → no match, so the popup offers quick-save with the number prefilled
- [x] WhatsApp call flagged as `whatsapp` (green header) vs SIM as `sim`

**Actions on the popup**
- [x] Start-bill, repeat-order, save-customer and missed-call-log controls all rendered on the live sale screen (`callerIdOn: true` baked in)
- [x] Repeat-last-order returns the caller's real previous line (QA Chicken Burger)
- [x] Missed/recent call log lists both calls from the last 24h
- [x] Existing regression suites green before publish: 52 PHPUnit tests / 113 assertions across the 4 Caller ID suites, `pos-caller-repeat-check.mjs` PASS, `plan-gate-check.php` 226 assertions PASS

**Plan lock — no leakage**
- [x] Non-Unlimited shop: sale-screen poll returns no caller data at all
- [x] Non-Unlimited shop: call log locked, repeat-order endpoint 403
- [x] Non-Unlimited shop: phone app told `plan_locked` on both ring and new pairing
- [x] Non-Unlimited shop: admin cannot switch the feature ON; Customize shows the upgrade lock instead of the toggle
- [x] Live audit of every POS shop: only 4 pass the Caller ID gate and **all four are explicit admin grants** (3 temporary, 1 on the QA company) — no package leaks it

**Distribution**
- [x] `/download` shows the Caller ID card with `Android 8+ · APK · 4.4 MB · v1.0.0`
- [x] APK link returns HTTP 200, full 4,597,179 bytes, md5 matches the hosted build, valid APK container
- [x] POS → Customize shows the download button for an Unlimited shop
- [x] `/api/app-version?app=caller` and the app's own `/version` both report `1.0.0`; an unknown app key still 404s
- [x] Saving the version through the real admin controller left every other setting untouched and wrote an audit-log entry; `1.0.0-beta` correctly rejected by validation

Nothing failed. No fixes were needed to the Caller ID feature itself.

---

## 3. Sirf phone par check hone wali cheezein (owner ke liye)

Server ka poora chain test ho chuka hai. Neeche wali baatein **asli phone**
ke baghair prove nahi hoti — please ek baar khud chala kar dekh lein.

**Pehle: install aur sign in**
1. Phone par `taxnest.com.pk/download` kholein → **TaxNest Caller ID** APK download karein.
2. Install karein (Android "unknown sources" ki ijazat maangay to allow kar dein).
3. App kholein → apni **POS admin login** (wohi email aur password) se sign in karein.
4. App **notification access** maangay ga → Allow kar dein. Yeh na dein to WhatsApp calls detect nahi hongi.
5. App **battery optimisation** hatane ko kahay ga → wo button daba kar Allow kar dein.

**Ab yeh 6 cheezein check karein**

| # | Kya karna hai | Theek hone ka matlab |
| --- | --- | --- |
| 1 | App mein **Test ring** button dabayein | Sale screen par foran popup aana chahiye. Yahin se pata chal jaye ga ke phone aur server juray huay hain. |
| 2 | Kisi doosre phone se is phone par **normal (SIM) call** karein | Ghanti bajte hi sale screen par popup — grahak ka naam, khata aur pichli order. Naya number ho to "grahak save karein" wala option. |
| 3 | Usi number se **WhatsApp call** karein | Wahi popup, magar header **hara** (WhatsApp) dikhna chahiye. Yeh notification access par depend karta hai. |
| 4 | App ko band kar ke (background mein) phone thori dair chhorein, phir call karein | Popup phir bhi aana chahiye. Na aaye to app kholein aur battery wali line "ON" hai ya nahi dekh lein. |
| 5 | Phone **restart** karein, sign in kiye baghair seedha call karein | Popup aana chahiye — service reboot ke baad khud chalti hai. |
| 6 | App khol kar upar dekhein | Abhi **koi update banner nahi** aana chahiye (installed 1.0.0 = latest 1.0.0). Banner sirf tab aata hai jab admin panel mein version barha diya jaye. |

**Agar koi cheez kaam na kare**
- Sirf WhatsApp calls miss ho rahi hain → phone settings mein notification access dobara check karein.
- Kuch dair baad calls aana band ho jayein → battery optimisation phir se ON ho gayi ho gi (Xiaomi/Oppo/Vivo aksar karte hain); app se dobara Allow kar dein.
- Popup bilkul nahi aata → POS → Customize mein Caller ID switch ON hai ya nahi dekhein, aur package Unlimited hona zaroori hai.
- Phone gum ho jaye ya badalna ho → POS → Customize se us device ka **Revoke** daba dein; baqi phone chalte rahenge.

**Note:** yeh feature sirf **Unlimited package** walay shops ko milta hai. Baqi
packages ko Customize page par upgrade ka message dikhta hai — unka data kabhi
load nahi hota (live par verify kiya gaya).
