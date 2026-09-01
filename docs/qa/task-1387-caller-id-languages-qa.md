# Caller ID — teen zabanon wali app phone par (Task 1387, Aug 2026)

**Kya rollout hua:** app ki zaban ab **English / Roman Urdu / Urdu** hai (v1.3.0,
Task 1382). Yeh apne version number par kabhi host nahi hui — shops tak **v1.4.0
(versionCode 5)** ke rollout ke andar pohanchi (Task 1362), jo saath mein 1.2.0
ka disclosure screen aur 1.4.0 ka call back bhi le kar gaya.

Website ki dono APK aur dono admin version settings **1.4.0** par hain, is liye
purani build wale signed-in phone ko update ka banner khud nazar aata hai.
Elaan (What's New) 21 Aug 2026 ko POS shops ko chala gaya — AppUpdate row 183.

Poora runbook: `caller-app/RELEASE.md`. Yeh file usi ke **step 6** (owner ka
phone test) ka record hai.

---

## 1. Machine par verify ho chuka (21 Aug 2026)

Yeh sab **live par jo file website de rahi hai usi ke bytes** par chala — local
build par nahi. Dono canonical URL dobara download kar ke:

| Check | Natija |
| --- | --- |
| `scripts/apk-release-check.sh --expect-version 1.4.0 --expect-code 5` | clean build **PASS**; plus build **PASS WITH 1 KNOWN EXCEPTION** (notification listener — sirf isi build ko ijazat hai) |
| Play Protect ki chaar blocked permissions — clean build mein | koi nahi |
| Signature | dono par `CN=TaxNest Rider` (shared rider key) — is liye purani app ke **upar** update chalti hai, uninstall ki zaroorat nahi |
| Version | dono `1.4.0` / `versionCode 5`, `caller-app/app/build.gradle` se match |
| `scripts/play-build-check.sh --apks-only` | **PASS** — dono website builds par self-update aur `targetSdk 34` barqarar |
| `build_badge` teenon zabanon mein | clean: `()` + `(b+ur+Latn)` + `(ur)`, SIM wali wording · plus: wohi teen, WhatsApp wali wording |
| Plus build ka disclosure screen | `disclosure_title / lead / read / send / control / privacy / agree` — saaton keys teenon zabanon mein mojood |
| md5 | website se download ki hui file == server par canonical naam == `taxnest-caller-1.4.0.apk` / `taxnest-caller-plus-1.4.0.apk` |
| Rollback | `taxnest-caller-1.1.0.apk`, `taxnest-caller-plus-1.1.0.apk` (aur clean 1.0.0) server par mojood |
| Admin settings + public API | `caller_app_latest_version` = `caller_app_plus_latest_version` = `1.4.0`; `/api/app-version?app=caller` aur `?app=caller_plus` dono `1.4.0` |

**Yeh sirf itna sabit karta hai ke tarjuma APK ke andar packed hai** — yeh nahi
ke phone par app install hoti hai, screen par sahi zaban dikhti hai, chunaav
mehfooz rehta hai, ya purani app upar se update ho jati hai. Woh neeche wala
phone test hi bata sakta hai.

---

## 2. Phone test — owner ko yeh chalana hai

Dono builds chahiyen: `taxnest.pk/download` se **default (clean)** APK aur
**"WhatsApp wali"** APK.

### A. Install / update ka raasta

| # | Test | Ummeed |
| --- | --- | --- |
| A1 | Naye (ya app-free) phone par default APK install karein | Koi *"App blocked to protect your device"* nahi — seedhi install ho jaye |
| A2 | Jis phone par **purani** app (1.1.0) ho, usay chalu karein aur sign in rehne dein | Update ka banner aata hai; Update dabane par purani app ke **upar** install hoti hai — uninstall nahi karna parta, login aur setup wahi rehta hai |
| A3 | WhatsApp wali APK install karein (Play Protect ka scan band kar ke, jaise download page par likha hai) | Install ho jaye; yeh clean build ke upar hi chadh jaye (dono ka signature ek hai) |

### B. Zaban (asal cheez)

| # | Test | Ummeed |
| --- | --- | --- |
| B1 | **English phone** par fresh install kholen | App **English** mein khule |
| B2 | **Urdu phone** par fresh install kholen | App phir bhi **English** mein khule — app phone ki zaban nahi dekhti |
| B3 | Login screen par **Roman** dabayein, phir **Urdu** | Screen usi waqt badle. Urdu mein layout **dayen se bayen**, Roman mein **bayen se dayen** |
| B4 | Sign in karein, main screen se zaban badlein, phir **app poori tarah band kar ke dobara kholen** | Wohi zaban barqarar |
| B5 | Log out kar ke dobara log in karein | Wohi zaban barqarar |
| B6 | Har zaban mein main screen ki har line parhein: status, battery line **aur uska toast**, permission line **aur uska toast**, "Send a test ring" **aur uska toast**, "Last call sent: …", update ka banner, Log out | Koi ek line doosri zaban mein atki hui na ho |
| B7 | **WhatsApp build** par notification-access ka disclosure screen teenon zabanon mein kholen | Har zaban mein **paanchon** points mojood — koi point ghayab ya chhota kiya hua nahi |

### C. Purana kaam na toote

| # | Test | Ummeed |
| --- | --- | --- |
| C1 | Dono builds par ek SIM call | Sale screen par popup — naam, khata, pichla order (pehle jaisa) |
| C2 | WhatsApp build par ek WhatsApp call | Popup aaye |

Call back (v1.4.0) ka apna checklist alag hai:
`docs/qa/task-1381-caller-id-call-back-qa.md`.

---

## 3. Natija (owner ka phone test — 21 Aug 2026)

| Khana | Jawab |
| --- | --- |
| Tareekh | 21 Aug 2026 |
| Phone(s) — model + Android version | Owner ne model/version share nahi kiya |
| A. Install / update | **PASS** — dono chal gaye |
| B. Zaban | **PASS** — English fresh-open behavior, Roman/Urdu switch, RTL, restart aur logout/login persistence sab theek |
| B7. WhatsApp disclosure | **PASS** — paanchon points teenon zabanon mein poore |
| C. Purana kaam | **PASS** — dono builds test mein theek |
| Jo masla mila | Koi masla report nahi hua |

> Phone model aur Android version record nahi ho saka, lekin owner ne install/update,
> language persistence, RTL, logout/login aur WhatsApp disclosure ki required
> checks pass report ki hain. Rollout ab phone-tested hai.
