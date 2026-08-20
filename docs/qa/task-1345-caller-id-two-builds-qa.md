# Caller ID — Play Protect fix, do builds (Task 1345, Aug 2026)

**Masla:** Google Play Protect ki *"enhanced fraud protection"* har us APK ka
install khud rok deti hai jo browser / WhatsApp / file manager se aaye **aur**
uske manifest mein in chaar mein se koi permission ho: `RECEIVE_SMS`,
`READ_SMS`, notification-listener, accessibility. Caller ID v1.0.0 ka poora dil
`NotificationListenerService` tha — is liye har shop ko (aur khud owner ko)
*"App blocked to protect your device"* aur uske baad *"App not installed"* mila.
Baqi paanch TaxNest apps mein in mein se koi permission nahi, woh theek chal rahi
hain.

**Hal:** ek code base, **do builds**, ek hi package id (`pk.taxnest.callerid`)
aur ek hi keystore — is liye ek build doosri par seedhi update ho jati hai.

| Build | Download | Kya pakadti hai | Play Protect |
| --- | --- | --- | --- |
| **clean** (default) | `taxnest-caller.apk` | sirf SIM / dialer ki incoming call (Android telephony) | koi rukawat nahi |
| **plus** | `taxnest-caller-plus.apk` | SIM **+ WhatsApp** (notification access) | install ke waqt scan band karna parta hai |

Ring ka payload, 60-second dedupe aur token/401 handling dono builds mein
**bilkul ek** hai (shared `RingReporter`) — sale screen ka popup, khata, repeat
order, plan gate: kuch nahi badla.

---

## 1. Kya shipped hua

| Change | Where |
| --- | --- |
| Do Gradle flavors (`sim` / `plus`), ek applicationId, ek keystore | `caller-app/app/build.gradle` |
| Clean build ka detector: `PHONE_STATE` broadcast receiver + `READ_PHONE_STATE` + `READ_CALL_LOG` | `caller-app/app/src/sim/` |
| Plus build ka detector: pehle wala notification listener | `caller-app/app/src/plus/` |
| Shared ring logic (payload + 60 s dedupe + 401), dedupe ab SharedPreferences mein | `RingReporter.kt`, `Prefs.kt` |
| Setup screen har build ke mutabiq — apni permission, apna build badge | `MainActivity.kt`, `activity_main.xml`, per-flavor `strings.xml` |
| Per-build update check `?build=sim\|plus` (param na ho to **plus**), semver-strict compare | `PosCallerIdController::appVersion`, `UpdateCheck.isNewer` |
| Naya version record + admin field | `caller_app_plus_latest_version`, `/api/app-version?app=caller_plus` |
| `/download`: default card ab sirf SIM ka wada karta hai + alag "WhatsApp calls bhi chahiyen?" hissa (Play Protect band/chaalu karne ke qadam) | `resources/views/downloads.blade.php` |
| POS → Customize: default clean download + khulne wala plus hissa, teenon zabanon mein | `resources/views/pos/customize.blade.php`, `lang/{en,rur,ur}/pos.php` |
| Login ab `device` mein build bhi bhejta hai ("… · clean build" / "… · WhatsApp build") | `LoginActivity.kt` — Customize par device list mein dikhta hai |

**Beta-safe gate barqarar:** har build tab hi dikhti hai jab **file bhi mojood ho
aur uska version setting bhi khali na ho**. `caller_app_plus_latest_version`
khali rakhein to WhatsApp wala hissa har jagah chhupa rehta hai.

---

## 2. Yahan (dev) verify ho chuka

- [x] Dono APK bane: `app-sim-release.apk` + `app-plus-release.apk`, dono
      `versionCode=2`, `versionName=1.1.0`, package `pk.taxnest.callerid`
- [x] Dono par wahi keystore — `CN=TaxNest Rider` (SHA-256
      `490d5c3b…2245b`), yani purane installs upar hi update honge
- [x] **Clean build ke manifest mein chaar blocked permissions mein se koi
      nahi** (`aapt2 dump xmltree` par grep khali) — sirf `READ_PHONE_STATE`,
      `READ_CALL_LOG`, `INTERNET`, network state, install + battery
- [x] Clean build mein `PhoneStateReceiver` manifest-registered aur `exported`
      (app band ho to bhi Android jagata hai)
- [x] Plus build mein `CallListenerService` + `BIND_NOTIFICATION_LISTENER_SERVICE`
      mojood, aur `READ_PHONE_STATE`/`READ_CALL_LOG` bilkul nahi
- [x] PHPUnit: 65 tests / 185 assertions PASS (Caller ID ke chaaron suites,
      `AppVersionEndpointTest` naye `caller_plus` key ke sath, teen-zabaan
      key-sync test)
- [x] White-screen preflight (static): saare Blade views compile + `php -l`,
      teenon locales ki keys poori, koi bare `__()` script ke andar nahi
- [x] `/download` dev par 200

**Live par host ho chuka (20 Aug 2026):**

| File | Size | Kya hai |
| --- | --- | --- |
| `/downloads/taxnest-caller.apk` | 4,599,207 | **clean build** — yehi default card ka link hai (purani na-install honay wali file replace ho gayi) |
| `/downloads/taxnest-caller-plus.apk` | 4,599,943 | plus (SIM + WhatsApp) |
| `/downloads/taxnest-caller-1.1.0.apk` / `…-plus-1.1.0.apk` | wahi | versioned copies (record) |
| `/downloads/taxnest-caller-1.0.0.apk` | 4,597,179 | purani build — rollback ke liye pari hai |

Dono HTTPS par 200, md5 local build se match, valid APK container.

**Abhi baqi — go-live switch (owner ke test ke baad):**

1. PHP changes task merge ke sath khud live chale jate hain (cPanel auto-deploy).
2. Owner phone-test (section 3).
3. Admin → Settings mein do fields: `caller_app_latest_version = 1.1.0` aur
   `caller_app_plus_latest_version = 1.1.0`. **Jab tak yeh nahi hote:** card par
   version `1.0.0` likha rahega (link nayi clean APK ka hi hai), WhatsApp wala
   hissa chhupa rahega, aur kisi phone par update banner nahi aayega.
4. Elaan (section 4 ka text).

---

## 3. Owner ke phone-test — yeh sirf asli phone par prove hota hai

Do phone (ya ek phone do dafa) chahiyen. Har build ke baad app **uninstall**
karne ki zaroorat nahi — dono ek doosri par update ho jati hain.

### A. Clean build — sab se ahem test (bina block ke install)

| # | Kya karna hai | Theek hone ka matlab |
| --- | --- | --- |
| 1 | Phone par Chrome se `taxnest.com.pk/download` kholein → **TaxNest Caller ID** APK download karein | Download shuru ho jaye |
| 2 | File par tap kar ke **Install** karein (Play Protect **ON** rehne dein) | **Koi "App blocked to protect your device" nahi** — app seedhi install ho jaye. Yeh is poore task ka asal maqsad hai. |
| 3 | App kholen → apni POS admin login se sign in | Shop ka naam upar dikhe |
| 4 | Pehli screen par likha padhein | Badge: *"یہ بلڈ: صرف سِم (عام) کالیں پکڑتی ہے"* + Roman line — yani build apni had khud bata rahi hai |
| 5 | **کال کی اجازت دیں** button daba kar Phone + Call logs ki ijazat dein | Line hari ho jaye (✔). Ijazat na dein to number khali aata hai. |
| 6 | Battery wali line par button daba kar allow karein | ✔ ho jaye |
| 7 | **Test ring** dabayein | Sale screen par foran popup |
| 8 | Doosre phone se is phone par **normal SIM call** karein | Ghanti bajte hi popup — naam, khata, visits, pichla order, repeat-order aur quick-save buttons pehle jaise |
| 9 | Wohi call **turant dobara** karein | Sirf **ek** popup (60-second dedupe kaam kar raha hai) |
| 10 | App ko background mein chhorein / phone **restart** kar ke seedha call karein | Popup phir bhi aaye (receiver app band hone par bhi chalta hai) |
| 11 | Isi phone par **WhatsApp call** karein | Popup **nahi** aana chahiye — yeh clean build ki maloom had hai, kharabi nahi |
| 12 | App khol kar upar dekhein | Koi update banner nahi (installed 1.1.0 = latest 1.1.0) |

### B. Plus build — WhatsApp wala rasta

| # | Kya karna hai | Theek hone ka matlab |
| --- | --- | --- |
| 13 | `/download` par neeche **"Caller ID — WhatsApp calls bhi chahiyen?"** hissa kholein | Chaar qadam Roman Urdu mein + APK ka button |
| 14 | Bina Play Protect band kiye APK install karne ki koshish karein | Yahan block aa sakta hai — yehi wajah hai ke qadam likhe hain (agar block na aaye to aur behtar) |
| 15 | Likhe qadam par Play Protect ka scan **OFF** kar ke install karein | Install ho jaye |
| 16 | Install ke foran baad Play Protect **wapas ON** karein | App chalti rahe |
| 17 | App kholen (sign-in barqarar rehna chahiye, wohi package hai) | Badge: *"سِم (عام) کالیں اور واٹس ایپ کالیں — دونوں"* |
| 18 | Notification access dein → **Test ring** | Popup aaye |
| 19 | **WhatsApp call** karein | Popup, header **hara** (WhatsApp) |
| 20 | Normal **SIM call** karein | Popup, sim wala header |
| 21 | POS → Customize → device list dekhein | Phone ke naam ke sath *"· WhatsApp build"* likha ho |

### C. Update prompt — sab se nazuk cheez

| # | Kya karna hai | Theek hone ka matlab |
| --- | --- | --- |
| 22 | Admin → Settings mein **sirf** "Latest Caller ID Android App Version" ko `1.1.1` kar dein (plus wala 1.1.0 hi rehne dein) | **Clean** wale phone par update banner aaye, **plus** wale phone par **na** aaye |
| 23 | Ab ulta karein: clean = 1.1.0, plus = 1.1.1 | Sirf **plus** wale phone par banner |
| 24 | Dono ko wapas 1.1.0 kar dein | Kisi phone par banner nahi |
| 25 | Plus phone par banner se update dabayein | Download hone wali file `taxnest-caller-plus.apk` ho — clean nahi (warna WhatsApp detection chupke se khatam ho jati) |

Qadam 22–25 sab se zaroori hain: yeh sabit karte hain ke plus wala phone kabhi
clean build par down-grade nahi hoga.

### Agar koi cheez kaam na kare

- Clean build par **number hi khali** aa raha hai → Phone + **Call logs** dono
  ki ijazat chahiye; Android 9+ par call log ke baghair number nahi milta.
- Kuch dair baad calls aana band → battery optimisation phir ON ho gayi
  (Xiaomi/Oppo/Vivo aksar karte hain), app se dobara allow karein.
- Popup bilkul nahi → POS → Customize mein Caller ID switch aur **Unlimited**
  package check karein (plan gate pehle jaisa hai).
- Phone badalna ho → Customize se us device ka **Revoke**; baqi phone chalte
  rahenge.

---

## 4. Live par jane ke qadam (main session)

Poori tafseel `caller-app/RELEASE.md` mein hai. Khulasa:

1. `gradle -p caller-app assembleSimRelease assemblePlusRelease` (RIDER_KS env ke sath)
2. Verify block chalayein — clean build mein koi blocked permission na ho
3. `scp` → `public_html/public/downloads/taxnest-caller.apk` aur `…-plus.apk`
4. PHP deploy (`git push origin HEAD:main`)
5. Owner phone-test (upar wali list)
6. Admin settings: `caller_app_latest_version = 1.1.0`,
   `caller_app_plus_latest_version = 1.1.0`
7. Roman Urdu elaan (`scripts/elaan-insert.sh`, audience `pos`) — wajah bhi
   likhi ho: "Google ki nai security ki wajah se app install nahi ho rahi thi;
   ab do version hain."
