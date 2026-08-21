# POS se hi call back karein — phone test checklist (Task 1381, Sep 2026)

**Masla:** counter par ek call chal rahi ho to baqi calls miss ho jati hain. POS
sirf batata tha ke kis ka phone aaya tha (Haaliya calls) — call **wapas** karne
ka koi rasta software mein nahi tha. Cashier ko mobile uthana, number dhoondna
aur haath se milana parta tha, aur us call ka POS par koi record nahi bachta tha.

**Hal:** counter ka wohi paired Android phone ab call laga sakta hai. POS par
"Call back" dabate hi phone par ek **tap-to-dial** notification aati hai; ek tap
par dialer number ke saath khul jata hai (call lagane ke liye phone par ek aur
tap — yeh jaan boojh kar hai, auto-dial nahi). Sath hi wohi customer bill par
attach ho jata hai aur call "call back kiya" ke tor par nishan lag jati hai.

---

## 1. Kya shipped hua

| Change | Where |
| --- | --- |
| Dial request queue (pending → delivered → dialed/failed/expired, 2 minute expiry) | `pos_caller_dial_requests` — migration `2026_09_05_100000_add_pos_caller_dial_back.php` |
| "Call back kiya" ka waqt | `pos_caller_events.called_back_at` |
| Phone dial le sakta hai ya nahi | `pos_caller_devices.dial_seen_at` (app nai hai) + `supports_dial` (us par notification dikh bhi sakti hai) |
| POS ka button endpoint (plan gate + company toggle, cashier bhi kar sakta hai) | `PosCallerIdController::dialBack` — `POST /pos/api/caller-dial` |
| Phone ke do API | `GET /api/caller-app/v1/dial-requests`, `POST /api/caller-app/v1/dial-result` |
| Popup / har recent-calls row / attached customer card par button + fallback card | `resources/views/pos/universal.blade.php` |
| Phone app ka call-back hissa (website builds only) | `caller-app/app/src/web/` + sim aur plus manifests, v1.4.0 |
| Nai strings teen zabanon mein | `lang/{en,rur,ur}/pos.php`, app ki `src/web/res/values*/strings.xml` (en / Roman Urdu / Urdu) |

**Gate:** sirf Caller ID wali shops — plan mein Caller ID **aur** POS → Customize
ka toggle ON. Dono mein se ek bhi band ho to button hi nahi dikhta (server bhi
403 deta hai).

---

## 2. Pehle yeh karein (release)

1. Website ki dono APK (clean + plus) v1.4.0 par build kar ke live par host
   karein — `caller-app/RELEASE.md`.
2. Admin → Settings → App versions: **dono** khaane `1.4.0` karein
   (`caller_app_latest_version` aur `caller_app_plus_latest_version`).
   Yeh kiye baghair signed-in phone khud update nahi hoga.
3. Counter ke phone par app khol kar update banner se update karein.

---

## 3. Phone test — yeh chalayen

| # | Test | Ummeed |
| --- | --- | --- |
| 1 | **Ring:** kisi doosre number se counter ke phone par call karein | POS sale screen par popup — naam/number, aur ab "Call back" ka button bhi |
| 2 | **Miss:** call kaat dein, popup band kar dein | Haaliya calls (ghanti wala button) mein row mojood, uske saath call-back ka gol button |
| 3 | **Call back (popup se):** popup par "Call back" dabayein | POS: "counter ke phone par bhej diya" · phone par 2–5 second mein notification · tap par dialer number ke saath khulta hai · customer bill par attach ho gaya |
| 4 | **Call back (list se):** Haaliya calls ki row ka gol button dabayein | Wohi notification; row par sabz "✔ call back kiya · waqt" aa jata hai |
| 5 | **Call back (attached customer card se):** customer bill par laga ho, card ka call button dabayein | Wohi notification (customer pehle se laga hua hai, dobara nahi lagta) |
| 6 | **Phone offline:** counter ka phone band kar dein / internet band, phir POS se call back | POS par bara number + "Number copy karein" ka card — dead end nahi. Copy dabayein, number clipboard mein aana chahiye |
| 7 | **Purani app wala phone:** ek phone par 1.3.0 (ya us se purani) rakh kar call back karein | POS crash **nahi**; saaf paigham "phone ki app purani hai, update karein" + wohi number/copy card |
| 7b | **Notification band:** counter ke phone ki Settings → Apps → TaxNest Caller ID → Notifications OFF karein, 10 second ruk kar POS se call back karein | POS par "phone par notification band hai — settings se on karein" + wohi number/copy card (jhoota "bhej diya" **nahi**). Phone par app kholen to ek toast bhi wohi baat kahe. On karne ke baad agla call back normal chale |
| 8 | **Der se jaagna:** phone band rakh kar POS se call back karein, 3 minute baad phone chalu karein | Koi notification **nahi** aani chahiye (purani request khud khatam) |
| 9 | **Do call back peechay peechay:** alag alag numbers par do dafa dabayein | Phone par sirf **aakhri** number ki notification |
| 10 | **Phone restart:** phone restart kar ke, app kholay baghair, POS se call back | Notification phir bhi aani chahiye |
| 11 | **Cashier:** cashier ke login se call back | Chalna chahiye (counter par cashier hi call handle karta hai) |
| 12 | **Doosri shop:** doosri shop ke phone par kabhi koi notification na aaye | Sirf apni shop ka number, apni shop ke phone par |
| 13 | **Toggle OFF:** POS → Customize se Caller ID band karein | Sale screen par call-back ka koi button nahi |
| 14 | **Zaban:** POS ki zaban en / Roman Urdu / Urdu — teenon par button, toast aur card | Har jagah usi zaban mein, Urdu mein Latin lafz na hon |

---

## 4. Play build — chhua na jana (zaroori)

Yeh feature **sirf website wali builds** mein hai. Play Store wali build ka
source set aur manifest bilkul waise ke waise hain (Play wali submission par
koi asar nahi). Rebuild karte waqt yeh check zaroor chale — kuch print na ho:

```bash
BT=/home/runner/android-sdk/build-tools/36.0.0
PLAY=caller-app/app/build/outputs/apk/play/release/app-play-release.apk
$BT/aapt2 dump permissions $PLAY \
  | grep -E "FOREGROUND_SERVICE|POST_NOTIFICATIONS|RECEIVE_BOOT_COMPLETED" \
  && echo "STOP — call back Play build mein chala gaya" || echo "play OK"
```

Play build mein call back baad mein alag task se aayega — abhi jaan boojh kar
nahi daala, taake maujooda submission par asar na pade.

---

## 5. Jo is task mein **nahi** hai

- Mobile se **khud** ki gai (outgoing) call ka POS par aana — is ke liye call-log
  permission chahiye jo Play build mein nahi aa sakti.
- WhatsApp par call back.
- FBR POS sale screen ka caller popup (alag task).
- Bina tap ke khud-ba-khud call lag jana — phone par ek tap hamesha rahega.
