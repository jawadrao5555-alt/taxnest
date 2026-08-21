# Play build banane aur sign karne ka tareeqa — TaxNest Caller ID

Task 1346. Yeh Play (AAB) wala hissa hai. Website wali APK builds ka rozana ka
tareeqa `caller-app/RELEASE.md` mein hai aur usmein koi tabdeeli nahi aayi.

---

## 1. Kya chahiye

| Cheez | Version |
|---|---|
| JDK | 17 |
| Android SDK platform | `android-36` |
| Android SDK build-tools | `36.0.0` |
| Gradle | 8.11.1 (AGP 8.5.2 + Kotlin 1.9.24 isi ke saath chalte hain) |
| Keystore | wohi shared key jo baqi apps ki hai — `.local/rider-signing/rider-release.p12`, alias `rider` |

`compileSdk 36` sab flavors par hai lekin `targetSdk 36` sirf **play** flavor
par — website builds `targetSdk 34` par hain, is liye un ka runtime behaviour
zarra barabar nahi badla (Android ki behaviour changes targetSdk se chalti hain).

`gradle.properties` mein `android.suppressUnsupportedCompileSdk=36` isi liye
hai: AGP 8.5.2 compile to sahih karta hai magar ek warning deta hai.

---

## 2. AAB banane ka command

```bash
cd caller-app
export ANDROID_HOME=/home/runner/android-sdk        # ya jahan SDK hai
export RIDER_KS=/home/runner/workspace/.local/rider-signing/rider-release.p12
export RIDER_KS_PASS="$(cat /home/runner/workspace/.local/rider-signing/password.txt)"

gradle --no-daemon bundlePlayRelease
```

Natija:
```
caller-app/app/build/outputs/bundle/playRelease/app-play-release.aab
```
Yehi file Play Console par upload hoti hai. (AAB phone par seedha install nahi
hota — testing ke liye Play ka internal-testing track istemal karein, ya `plus`
wali APK par test karein: uski har screen aur behaviour play build jaisi hi hai.)

Website ki dono APKs pehle ki tarah:
```bash
gradle --no-daemon assembleSimRelease assemblePlusRelease
```

> **Banane ke foran baad `bash scripts/play-build-check.sh` chalayein** — §3.
> Yeh AAB aur dono website APKs, dono ki jaanch ek command mein karti hai, aur
> exit 1 par upload/host bilkul na karein.

---

## 3. Build ke baad ki LAZMI jaanch — `scripts/play-build-check.sh`

AAB banne ke baad, upload se **pehle**, yeh ek command chalani hi hai:

```bash
bash scripts/play-build-check.sh
```

Bina argument ke yeh upar wale default output paths uthati hai (AAB + dono
website APKs). Apne paths dene hon to:

```bash
bash scripts/play-build-check.sh \
  --aab  caller-app/app/build/outputs/bundle/playRelease/app-play-release.aab \
  --sim  caller-app/app/build/outputs/apk/sim/release/app-sim-release.apk \
  --plus caller-app/app/build/outputs/apk/plus/release/app-plus-release.apk
```

Exit 0 = PASS. Exit 1 = **upload na karein.** Script sirf `python3` maangti hai
— AAB ka protobuf manifest, APKs ka binary manifest aur dono ke dex khud parh
leti hai, is liye SDK dobara download karne se pehle bhi chalti hai.

**Yeh FAIL karti hai agar —**

Play AAB mein:

| # | Kya mila | Kyun ghalat hai |
|---|---|---|
| 1 | `REQUEST_INSTALL_PACKAGES` | self-update — Play ki Device and Network Abuse policy mein jaiz nahi |
| 2 | `REQUEST_IGNORE_BATTERY_OPTIMIZATIONS` | seedha battery "allow?" dialog Play par mana |
| 3 | `FOREGROUND_SERVICE`, `FOREGROUND_SERVICE_DATA_SYNC`, `POST_NOTIFICATIONS`, `RECEIVE_BOOT_COMPLETED` | call back (Task 1381) — sirf website builds ka hissa |
| 4 | `READ_PHONE_STATE`, `READ_CALL_LOG` | in ke saath Play ka Call Log declaration form lazmi ho jata hai |
| 5 | dex mein `UpdateCheck`, `CallerApp`, `DialWatchService`, `DialActivity`, `DialBootReceiver`, `PhoneStateReceiver` | website-only code Play build mein compile ho gaya |
| 6 | `targetSdk` 36 se kam — ya parha hi na ja sake | Play ki 31 Aug 2026 wali shart |

Website ki dono APKs mein (**ULTA** regression):

| # | Kya mila | Kyun ghalat hai |
|---|---|---|
| 7 | `REQUEST_INSTALL_PACKAGES` **gayab** | website phone ke paas store nahi — self-update mar jata hai aur har shop isi version par phans jati hai |
| 8 | `UpdateCheck` class **gayab** | wohi baat, code ki taraf se |
| 9 | `targetSdk` 34 nahi raha | website builds jaan-boojh kar 34 par hain; Android ka behaviour targetSdk se badalta hai |

**Yeh checklist ka qadam kyun nahi, script kyun:** play aur website builds ka
farq kahin aisi jagah likha hua nahi jise compiler pakar sake — woh sirf is
baat se banta hai ke `caller-app/app/build.gradle` mein kaunsa source set kis
flavor mein jata hai. Ek nai class `src/main/java` mein rakh dena (bajaye
`src/web/java` ke), ya ek permission `src/main/AndroidManifest.xml` mein daal
dena, teenon builds mein chala jata hai — build green rehti hai, AAB upload ho
jati hai, aur pata hafton baad Play ke reject par chalta hai.

Sirf AAB bani ho (website APKs is dafa nahi banayin) to `--aab-only`;
website-only release mein `--apks-only`. Asal guard poori command hai — upload
ya host karne se pehle bina in flags ke dobara chalayein.

Play apni shart barhaye to pehle `--min-target-sdk 37` se chala kar dekh lein,
phir `play` flavor ka `targetSdk` build.gradle mein bump karein.

Script ka apna regression test (fixtures khud banata hai, SDK ki zarurat nahi):

```bash
bash scripts/tests/play-build-check-test.sh
```

### Iske ilawa (script ke daayre se bahar)

```bash
# Website ki dono APKs par blocked-permission + signer + version guard
bash scripts/apk-release-check.sh \
  caller-app/app/build/outputs/apk/sim/release/app-sim-release.apk \
  caller-app/app/build/outputs/apk/plus/release/app-plus-release.apk

# Signer khud dekhna ho (SDK chahiye)
$ANDROID_HOME/build-tools/36.0.0/apksigner verify --print-certs \
  caller-app/app/build/outputs/apk/sim/release/app-sim-release.apk | grep "SHA-256 digest"
```

Expected signer (shared rider key):
`490d5c3bae13abb212bfe2a33abac66e8387e623cc0ecd724c5fdd8ffb72245b`

---

## 4. Play App Signing — yeh faisla pehli upload par hota hai

Package id teenon builds ka ek hi hai: `pk.taxnest.callerid`. Android sirf usi
soorat mein ek build ko doosri par update karta hai jab **signature bhi wohi**
ho. Play par app banate waqt do raste hain:

### Rasta A (SIFARISH) — apni mojooda key Google ko dein
App create karte waqt "Use an existing app signing key" chunain aur PEPK tool
se `rider` key export kar ke upload karein.

```bash
# pepk.jar aur encryption_public_key.pem dono Play Console usi page par deta hai
java -jar pepk.jar \
  --keystore=.local/rider-signing/rider-release.p12 \
  --alias=rider \
  --output=play-signing-key.zip \
  --include-cert \
  --rsa-aes-encryption \
  --encryption-key-path=encryption_public_key.pem
# keystore aur key ka password poochega — dono .local/rider-signing/password.txt wala
```
(Agar pepk PKCS12 par aitraaz kare to pehle JKS bana lein:
`keytool -importkeystore -srckeystore rider-release.p12 -srcstoretype PKCS12 -destkeystore rider.jks -deststoretype JKS`)

Faida: Play se install hui app aur website wali APK ka signature ek — shop
chahe to website APK se Play wali par (ya ulta) **bina uninstall kiye** ja sakti
hai. Nuqsan: yehi key Google ke paas bhi chali jati hai (Google usay apne
secure store mein rakhta hai).

### Rasta B — Google apni nai key banaye (default)
Aasan hai, magar Play wali app ka signature website APK se **alag** hoga. Us
soorat mein:
- Website APK wale phone par Play version install karne ke liye pehle app
  uninstall karni paregi (aur ulta bhi) — warna
  `INSTALL_FAILED_UPDATE_INCOMPATIBLE`.
- Uninstall se app ka local data (login token) chala jata hai — shop ko dobara
  sign-in karna hoga. Bill ya customer ka koi data nahi jata (woh server par hai).

**Faisla:** Rasta A. Agar owner Rasta B chunta hai to downloads page par saaf
likhna hoga ke "Play se install karne se pehle purani app uninstall karein".

Ek baar app signing key set ho jaye to badalna bohot mushkil hai — pehli upload
se pehle tay karein.

### Upload key
`bundlePlayRelease` jis key se sign karta hai (rider key) wohi **upload key**
ban jati hai. Rasta A mein upload key aur app signing key ek hi hain. Key kho
jaye to Play upload key reset kar deta hai, magar app signing key kabhi nahi —
is liye `.local/rider-signing/` ka backup owner ke paas apne pass rehna chahiye
(repo public hai, keystore kabhi commit na ho).

---

## 5. Version numbers

- `versionCode` ab **4**, `versionName` **1.3.0** (teenon flavors ka ek hi) —
  Task 1382 (English / Roman Urdu / Urdu ka switch) ke sath bump hua.
- Play par har nai upload ka `versionCode` pichli se **bara** hona lazmi hai.
  Agli Play release: `versionCode 5` / `1.3.1` (ya jo bhi), aur website builds
  bhi usi number par chali jayengi — yeh theek hai.
- **Website par host shuda APKs abhi bhi 1.1.0 hain** aur `/api/app-version` map
  bhi 1.1.0 keh raha hai. Jab tak owner nai APKs bana kar host nahi karta, kisi
  phone ko jhoota update banner nahi aayega (comparison semver-strict hai).
  Jis din nai website APKs host hon:
  1. `assembleSimRelease assemblePlusRelease` se banayein,
  2. `public/downloads/` mein rakhein (RELEASE.md ka tareeqa),
  3. `routes/web.php` ke `/api/app-version` map mein `caller` aur `caller_plus`
     dono ka version 1.3.0 kar dein — warna purane phone update nahi dekhenge.

---

## 6. Agar kabhi "clean (sim)" build Play par bhejni pare

(Sirf us soorat mein jab Google notification-access wali build ko Call Log
policy ke tehet reject kar de — `docs/play/data-safety.md` §4.)

`sim` flavor ka AAB banane ke liye ek arzi tabdeeli chahiye hogi, kyunki `sim`
website build hai aur usmein self-update mojood hai:

1. `app/build.gradle` mein `sim` ke sourceSets se `src/web/java` hata dein,
2. `src/sim/AndroidManifest.xml` mein play wali tarah
   `<uses-permission android:name="android.permission.REQUEST_INSTALL_PACKAGES" tools:node="remove" />`
   daalein,
3. `targetSdk = 36` sim par bhi karein,
4. `gradle bundleSimRelease`.

Behtar hai us waqt `simplay` naam ka chautha flavor bana lein taake website wali
`sim` build bilkul na chhirre.
