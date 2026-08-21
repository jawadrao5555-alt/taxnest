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

---

## 3. Build ke baad ki jaanch (har dafa)

```bash
export PATH=$ANDROID_HOME/build-tools/36.0.0:$PATH
cd caller-app/app/build/outputs

# 1) Play AAB mein blocked permission bilkul nahi honi chahiye
mkdir -p /tmp/aabx && rm -rf /tmp/aabx/* && cd /tmp/aabx
unzip -q .../app-play-release.aab
strings base/manifest/AndroidManifest.xml | grep -E "REQUEST_INSTALL_PACKAGES|BATTERY" && echo "FAIL" || echo "OK: koi blocked permission nahi"

# 2) targetSdk 36 hona chahiye
python3 -c "d=open('/tmp/aabx/base/manifest/AndroidManifest.xml','rb').read(); i=d.find(b'targetSdkVersion'); print(d[i:i+22])"

# 3) self-update ka code AAB mein nahi hona chahiye
strings /tmp/aabx/base/dex/classes.dex | grep -c UpdateCheck     # 0 hona chahiye

# 4) website builds mein wohi code MOJOOD hona chahiye (regression guard)
unzip -p apk/plus/release/app-plus-release.apk classes.dex | strings | grep -c UpdateCheck   # > 0

# 5) dono APKs ka signer wohi purana
apksigner verify --print-certs apk/sim/release/app-sim-release.apk | grep "SHA-256 digest"
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
