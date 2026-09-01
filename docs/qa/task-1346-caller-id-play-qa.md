# Caller ID — Play Store ke qabil (Task 1346, Aug 2026)

**Masla:** Play Protect ki *enhanced fraud protection* sirf un apps ka install
rokti hai jo browser / WhatsApp / file manager se aayen. **Play Store se aane
wali app par yeh rukawat hai hi nahi.** Aur WhatsApp call dekhne ka koi doosra
Android API mojood nahi (WhatsApp call VoIP hai) — is liye poori app (SIM +
WhatsApp) sirf tab bila-rukawat chal sakti hai jab woh Play se install ho.

Play par jaane ke liye do cheezein rasta rok rahi thin:
1. App khud ko update karti thi (`REQUEST_INSTALL_PACKAGES`) — Play ki policy
   mein self-update jaiz istemal ki list mein nahi.
2. App Android 14 (API 34) target karti thi — 31 Aug 2026 se nai app ke liye
   Android 16 (API 36) lazmi hai.

**Hal:** ek teesri build (`play` flavor), wohi package id, wohi code —
bas self-update ka hissa us build mein compile hi nahi hota, permission manifest
se nikli hui hai, aur target Android 16 hai. **Website wali dono builds bilkul
pehle jaisi hain.**

---

## 1. Kya shipped hua

| Change | Where |
| --- | --- |
| Teesra flavor `play` (`create("play")` — Groovy `plus` wala trap yaad rakhein) | `caller-app/app/build.gradle` |
| `compileSdk 36` sab par; `targetSdk 36` **sirf** play par (website builds 34 par hi) | `caller-app/app/build.gradle`, `gradle.properties` |
| Self-update ka code ab `src/web/java` mein = sirf sim + plus; play ke liye no-op `Updater` | `src/web/java/UpdateCheck.kt`, `src/web/java/Updater.kt`, `src/play/java/Updater.kt` |
| Play manifest se `REQUEST_INSTALL_PACKAGES` aur `REQUEST_IGNORE_BATTERY_OPTIMIZATIONS` `tools:node="remove"` se nikali | `src/play/AndroidManifest.xml` |
| Battery button ab runtime par faisla karta hai: permission ho to seedha dialog, warna Android ki battery list + samjhane wala toast | `MainActivity.openBatterySettings()` |
| **Prominent disclosure screen** — notification access maangne se pehle poori screen: kya parha jayega, kya nahi, kahan jayega, kyun; agree / abhi nahin | `src/notif/java/NotificationDisclosureActivity.kt`, `res/layout/activity_notification_disclosure.xml` |
| Consent ke baghair settings nahi khulti — `Detector.request()` ab disclosure se guzarta hai; qubooliyat `Prefs` mein mehfooz | `src/notif/java/Detector.kt`, `Prefs.kt` |
| Notification wala detector + service ab shared source set mein (plus + play dono) | `src/notif/java/`, `src/notif/res/` |
| **Sirf elaan-shuda daira:** ab notification tab hi parhi jati hai jab woh phone ki apni calling app (default dialer / system telephony / ACTION_DIAL wali app) ya WhatsApp ki ho. Pehle sirf "call category" dekhi jati thi, is liye kisi bhi doosri VoIP app (Messenger, Telegram, Viber, Skype) ki call ka naam/number bhi "sim" ban kar chala jata tha — yeh disclosure aur privacy policy se ziyada tha | `src/notif/java/CallSourceRules.kt`, `CallListenerService.kt`, dono notif manifests ka `<queries>` block |
| **Consent ke baghair kuch nahi:** service pehle `gateOpen()` poochhti hai (signed-in + disclosure par "agree") aur us se pehle notification kholti tak nahi. Android ki notification access seedha Settings se ON ho jaye (ya app-data clear ho) to app "band" hi dikhati hai aur agla "Ijazat dein" tap disclosure par le jata hai | `CallSourceRules.gateOpen()`, `CallListenerService.handle()`, `Detector.granted()` |
| Un dono faislon ke JVM unit tests (14 tests, dono notif flavors par) | `src/notifTest/java/CallSourceRulesTest.kt`, `gradle testPlusReleaseUnitTest testPlayReleaseUnitTest` |
| Edge-to-edge insets (Android 15+ par lazmi, targetSdk 36) | `Ui.applyBarInsets()`, `MainActivity`, `LoginActivity`, dono layouts |
| Login ka `device` ab teen tarah ka build pehchanta hai (clean / WhatsApp / Play) | `LoginActivity.kt` |
| Version 1.1.0 → **1.2.0**, versionCode 2 → **3** (teenon flavors) | `caller-app/app/build.gradle` |
| Public **privacy policy** safha, jismein Caller ID ka apna hissa (kya jama hota hai, kya kabhi nahi, 48 ghante mein khud delete) | `resources/views/privacy.blade.php`, `/privacy` |
| Public **account/data deletion** safha (khud delete karne ke raste + darkhwast ka tareeqa + timelines) | `resources/views/data-deletion.blade.php`, `/data-deletion` |
| Footer mein dono naye link; contact page ka privacy hissa unhi par bhejta hai; dono safhay sitemap mein | `components/site-footer.blade.php`, `contact.blade.php`, `routes/web.php` |
| Play Console ka poora material: listing ka matn, Data safety ke jawabat, icon + feature graphic, signing/build runbook, owner ki submission checklist | `docs/play/` |

---

## 2. Yahan (dev) verify ho chuka

- [x] `assembleSimRelease assemblePlusRelease bundlePlayRelease` — BUILD SUCCESSFUL
- [x] **Play AAB** (`app-play-release.aab`, 3.9 MB): `targetSdk 36`, `minSdk 26`,
      versionCode 3 / 1.2.0
- [x] AAB ke manifest mein sirf `INTERNET` + `ACCESS_NETWORK_STATE` —
      **`REQUEST_INSTALL_PACKAGES` nahi**, battery permission nahi
- [x] AAB ke dex mein `UpdateCheck` class **hai hi nahi** (self-update ka code
      Play build mein jata hi nahi)
- [x] AAB mein disclosure activity + notification listener service mojood, aur
      bundle rider key se signed (jarsigner verified)
- [x] **Consent gate + package allow-list ke unit tests: 14 tests, dono notif flavors par
      (`testPlusReleaseUnitTest` + `testPlayReleaseUnitTest`) — sab pass.**
      Inmein Messenger / Telegram / Viber / Skype / Zoom / Botim / IMO ki
      CATEGORY_CALL notification ka **rad hona** bhi shamil hai, aur OEM dialers
      ka qubool hona bhi
- [x] **Website builds mein koi farq nahi:** dono APKs ab bhi `targetSdk 34`,
      wohi permission list (`REQUEST_INSTALL_PACKAGES` sameth), `UpdateCheck`
      mojood, wohi signer `CN=TaxNest Rider` (SHA-256 `490d5c…2245b`), ~4.4 MB
- [x] `/privacy`, `/data-deletion`, `/contact` teenon **bina login** 200 dete
      hain aur baqi marketing safhon jaise dikhte hain; dono naye URL sitemap
      mein hain
- [x] Poora PHP test suite: **2452 tests, 11829 assertions — OK**

---

## 3. Owner ke phone par test (yeh baqi hai)

AAB phone par seedha install nahi hota. Do raste hain:
- **Behtar:** Play Console → Internal testing par AAB chadha kar apne phone par
  Play se install karein (asal Play tajurba, koi Play Protect warning nahi).
- **Jaldi:** website wali **plus** APK par test kar lein — screens bilkul wohi
  hain (sirf badge ki Roman line aur self-update banner ka farq hai).

### A. Play build (internal testing se)
1. [ ] Play se install — koi "App blocked" ya "App not installed" nahi aana chahiye
2. [ ] Login: QA shop ke email/password se sign in ho jaye
3. [ ] Main screen: notification wala button dabate hi **pehle disclosure screen**
       aaye (Android ki list seedhi na khule)
4. [ ] Disclosure par "abhi nahin" → app chalti rahe, permission na mange
5. [ ] Disclosure par agree → Android ki notification-access list khule, wahan
       "TaxNest Caller ID" on karein → wapas app mein halat sabz ho jaye
6. [ ] Battery button → Android ki battery-optimisation **list** khule aur toast
       samjhaye ke app dhoond kar "restrict na karein" chunna hai
7. [ ] "Test call" button → POS sale screen par pop-up aaye
8. [ ] Asal SIM call → pop-up aaye. Asal WhatsApp call → pop-up aaye
8b. [ ] Kisi doosri app ki call (Messenger / Telegram / IMO — jo bhi phone par
        ho) → POS par pop-up **na** aaye. Yeh jaan-boojh kar hai: app sirf
        phone ki apni dialer aur WhatsApp ki call parhti hai, aur disclosure
        mein bhi yehi likha hai
8c. [ ] **Bina disclosure ke access:** app ka data clear karen (Settings → Apps
        → TaxNest Caller ID → Storage → Clear data), phir Android Settings se
        seedha notification access ON karen aur app mein login karen — disclosure
        screen dekhe baghair. App ijazat "band" dikhaye aur SIM/WhatsApp call par
        POS par koi pop-up **na** aaye. "Ijazat dein" par disclosure khule; agree
        karte hi (access pehle se ON hai, is liye settings dobara nahi khulenge)
        status "chal rahi hai" ho jaye aur agli call par pop-up aaye
9. [ ] App band kar ke (recents se hata kar) dobara SIM + WhatsApp call — dono
       phir bhi pakri jayen
10. [ ] **Koi update banner na aaye** (Play build khud ko update nahi karti)

### B. Android 16 ki behaviour changes (naya targetSdk)
11. [ ] Login aur main screen: koi button ya tehreer status bar / neeche ke
        navigation bar ke neeche na chhupe (edge-to-edge)
12. [ ] Back gesture: disclosure screen se back → main screen; login se back →
        app band. Kahin atke nahi
13. [ ] Phone ghuma kar (landscape) dekh lein ke screen theek rahe
14. [ ] Screen band kar ke 10 minute chhor dein, phir call karayen — pop-up
        phir bhi aaye (battery restriction wala masla)

### C. Website builds — kuch nahi tootna chahiye
15. [ ] Purani clean (sim) APK wale phone par SIM call → pop-up pehle jaisa
16. [ ] Purani plus APK wale phone par WhatsApp call → pop-up pehle jaisa
17. [ ] Website wali build par update banner ka nizaam pehle jaisa chal raha ho
        (jab yeh checklist likhi gayi thi hosted APKs 1.1.0 thay, is liye koi
        naya banner nahi aata tha; 21 Aug 2026 se website 1.4.0 host karti hai —
        Task 1362 — is liye purane phone par ab banner aana CHAHIYE)

### D. Website ke naye safhay (kisi bhi phone/computer par)
18. [ ] `taxnest.pk/privacy` bina login khule aur Caller ID ka hissa saaf ho
19. [ ] `taxnest.pk/data-deletion` bina login khule
20. [ ] Kisi bhi marketing safhe ke footer mein dono link mojood aur chalu hon

---

## 4. Kya nahi badla

- Website ki dono APKs ka behaviour, permissions, keystore, update-check
  contract (`?build=sim|plus`, param na ho to plus) — kuch nahi chhera gaya
- Server ki taraf Caller ID ki logic, plan gate, POS ka pop-up, device list,
  khata/repeat-order — kuch nahi badla
- Baqi paanch Android apps (POS, FBR POS, DI, Waiter, Rider) — un mein koi
  tabdeeli nahi, woh website se hi milti rahengi

## 5. Owner ke apne qadam (code ka kaam nahi)

`docs/play/submission-checklist.md` — Play Console account ki qism ka faisla
(personal ki 12-tester/14-din shart vs organization ka D-U-N-S), $25 adaigi,
verification, listing bharna, AAB upload, review ka intezar, aur reject hone ki
soorat mein tayyar jawabat. Phone se liye jane wale 4 screenshots ka tareeqa
`docs/play/store-listing.md` §5 mein hai.
