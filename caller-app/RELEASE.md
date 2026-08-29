# TaxNest Caller ID APK — Build & Release Runbook

Last updated: 29 Aug 2026 (v1.7.0 — LAN Mode: the ring reaches the counter over
the shop's own WiFi when the internet is down; v1.6.0 stopped the popup on
OUTGOING calls; v1.5.0 brought the real number back on the plus build)

> **BUILT BUT NOT YET THE DEFAULT: v1.7.0 (versionCode 8), 29 Aug 2026.**
> Only the two **versioned** files are on the server —
> `taxnest-caller-1.7.0.apk` and `taxnest-caller-plus-1.7.0.apk`. The canonical
> names (`taxnest-caller.apk` / `taxnest-caller-plus.apk`) and both admin
> version settings are deliberately **still on 1.5.0**, so no shop phone is
> prompted to update yet: 1.7.0's LAN lane cannot be proved in this container
> (it needs a real phone, a real counter PC and the line actually pulled), so
> the owner tests the versioned URLs on his own phone first. Finishing the
> rollout afterwards is three commands: `cp` the versioned files over the
> canonical names, flip both settings to `1.7.0`, run
> `php artisan apps:check-release-drift --app=caller --app=caller_plus`.
>
> **v1.6.0 (versionCode 7) was never hosted at all** — it goes to shops inside
> the 1.7.0 rollout, exactly the way 1.2.0 and 1.3.0 rode out inside 1.4.0.

> **Previously hosted: v1.5.0 (versionCode 6), since 23 Aug 2026.**
> Both website APKs (`taxnest-caller.apk` + `taxnest-caller-plus.apk`) and both
> admin version settings are on 1.5.0, so a phone on any older build sees the
> update prompt. 1.5.0 is what fixed `No phone` on the plus build; the 1.4.0
> rollout before it (21 Aug 2026) was what first carried the 1.2.0 disclosure
> screen and the 1.3.0 language switch to shops. Building the code without this
> step ships nothing: the website keeps serving the old file until someone
> rebuilds, re-hosts and flips the settings.
>
> The hosted bytes were re-verified on 21 Aug 2026 (Task 1387) by downloading
> both canonical URLs and running both guards plus the three-locale badge check
> over the **downloaded** files — see "Prove the hosted file, not the local one".
> The language switch was announced to POS shops the same day. The owner also
> reported the required physical-phone QA as passed: install/update, English /
> Roman Urdu / Urdu switching and persistence, Urdu RTL, and the WhatsApp
> disclosure's five points in all three languages. The recorded checklist is
> `docs/qa/task-1387-caller-id-languages-qa.md`; phone model and Android version
> were not supplied, so that owner-reported result is not device-specific.

> **Play (AAB) ka hissa is file mein NAHI hai.** Play build banane, sign karne,
> uske key ke faisle aur Console ke saare form: `docs/play/` — khaas tor par
> `docs/play/signing-and-build.md`. Yeh file website wali dono APKs ka rozana ka
> runbook hai; usmein Task 1346 se koi tabdeeli nahi aayi (wohi permissions,
> wohi `targetSdk 34`, wohi self-update, wohi keystore).

---

## Why there are TWO builds

Google Play Protect's **"enhanced fraud protection"** automatically blocks the
install of any sideloaded APK (browser / WhatsApp / file manager) whose manifest
declares one of exactly four permissions:

| blocked permission | in our app? |
|---|---|
| `RECEIVE_SMS` | no |
| `READ_SMS` | no |
| `BIND_NOTIFICATION_LISTENER_SERVICE` (notification access) | **yes — this was the whole app** |
| `BIND_ACCESSIBILITY_SERVICE` | no |

v1.0.0 was built entirely on `NotificationListenerService`, so **every** shop
(and the owner) got *"App blocked to protect your device"* followed by
*"App not installed"*. The other five TaxNest APKs are unaffected — none of them
declares a blocked permission.

Fix = one code base, two Gradle product flavors, **same `applicationId`
(`pk.taxnest.callerid`) and same keystore**, so either APK installs in place
over the other:

| flavor | download name | detects | Play Protect |
|---|---|---|---|
| `sim` ("clean", **default**) | `taxnest-caller.apk` | SIM / dialer incoming calls, via `PhoneStateReceiver` + `READ_PHONE_STATE` + `READ_CALL_LOG` | **no block** — none of the four permissions |
| `plus` | `taxnest-caller-plus.apk` | SIM **+ WhatsApp**. From v1.5.0 the SIM half runs on the same `PhoneStateReceiver` as the clean build (real number, saved contact or not) and `CallListenerService` handles WhatsApp only; a WhatsApp name is turned into a number through `ContactNumberLookup` (`READ_CONTACTS`) | blocked until the shop turns the scan off for the install |

`READ_CALL_LOG` is not optional: on Android 9+ `EXTRA_INCOMING_NUMBER` is empty
without it. Neither it nor `READ_PHONE_STATE` is on the blocked list.

Ring behaviour is **identical** in both builds — payload, 60-second dedupe and
401 handling live in the shared `RingReporter`; each flavor only contributes a
detector. Never fork that logic per flavor.

### …and a THIRD build for Google Play (Task 1346)

`play` = the same SIM + WhatsApp detection as `plus`, but built for the Play
Store, and **never hosted on the website**:

| | `sim` / `plus` (website) | `play` (Play Store) |
|---|---|---|
| self-update | yes (`src/web/java`) | **no** — `REQUEST_INSTALL_PACKAGES` removed via `tools:node="remove"`, and `src/web/java` is not in its source set. Play forbids self-updating APKs |
| battery permission | `REQUEST_IGNORE_BATTERY_OPTIMIZATIONS` (direct dialog) | removed; the app opens the battery-optimisation **list** instead (`MainActivity.openBatterySettings()` decides at runtime, not from `BuildConfig`) |
| `targetSdk` | 34 | **36** — Play's requirement for new apps from 31 Aug 2026 |
| `compileSdk` | 36 for all flavors (compile-only; behaviour is driven by `targetSdk`) | |

Shared source sets keep the two notification builds identical — never fork them:

```
src/web/java    → UpdateCheck + real Updater,       (sim, plus)
                  CallerApp + DialWatchService +
                  DialActivity + DialBootReceiver
src/web/res     → the call-back (dial) strings      (sim, plus)
src/notif/java  → CallListenerService, Detector,
                  NotificationDisclosureActivity    (plus, play)
src/notif/res   → the strings both notif builds use (plus, play)
src/telephony/  → PhoneStateReceiver — SIM ring     (sim, plus)
                  from Android telephony (v1.5.0)
src/plus/java   → ContactNumberLookup — WhatsApp
                  naam → number, contact list se    (plus)
src/play/java   → no-op Updater,
                  no-op ContactNumberLookup         (play)
```

That table **is** the contract Google accepted, and nothing in the build fails
if it drifts — a class dropped into `src/main/java` instead of `src/web/java`,
or a permission added to `src/main/AndroidManifest.xml`, silently lands in the
Play build too. `scripts/play-build-check.sh` (Task 1364) is the guard: it
fails on any of that in the AAB, and on the reverse regression in the two
website APKs. Run it after every build — see "Verify before hosting" below and
`docs/play/signing-and-build.md` §3.

Each flavor's own `res/values*/strings.xml` holds **only** the keys that differ
from the shared sets — today just `build_badge`. The same key in two res dirs of
one source set fails the build with *"Duplicate resources"*, so `build_badge`
lives in `src/plus/res` + `src/play/res` and must never be added to
`src/notif/res`.


### Call back from the POS — website builds only (Task 1381)

From v1.4.0 the counter phone can also **place** a call, not just report one.
POS queues a dial request; the phone claims it and shows a high-priority
notification; one tap opens the system dialer with the number in it.

- **Website only.** All of it lives in `src/web/java` + `src/web/res`, and the
  manifest half is duplicated in `src/sim/AndroidManifest.xml` and
  `src/plus/AndroidManifest.xml`. **`src/play/` is untouched** — the Play build
  compiles none of it and declares none of its permissions. Keep it that way
  (call back in the Play build is its own future task); if you edit one website
  manifest, edit the other.
- **New permissions:** `FOREGROUND_SERVICE`, `FOREGROUND_SERVICE_DATA_SYNC`,
  `POST_NOTIFICATIONS`, `RECEIVE_BOOT_COMPLETED`. **None** of them is on Play
  Protect's blocked four, so the clean APK still sideloads without a block.
- **No `CALL_PHONE`, ever.** `DialActivity` fires `ACTION_DIAL`, so the cashier
  taps once more in the dialer. Auto-dial is deliberately out of scope; adding
  `CALL_PHONE` would also change what the disclosure and the Play Data safety
  answers have to say.
- **Polling, not long-poll:** `GET /dial-requests?notif=1|0` every ~5 s. The
  server sends `poll_ms` in every response, so the interval is tunable
  **without an app release**. That poll is also the "this phone can take a call
  back right now" heartbeat — a phone on an older app never polls it, so POS
  shows the copy-the-number fallback instead of promising a call that will not
  happen.
- **`notif` is not decoration.** With notifications off (permission denied on
  Android 13+, or the user switching the app/channel off later) `notify()`
  fails **silently** — no exception, nothing on screen. So the phone reports
  `areNotificationsEnabled()` **and** the offer channel's importance on every
  poll; the server keeps `dial_seen_at` (the app IS new) but clears
  `supports_dial`, and POS falls back with *"phone par notification band hai"*
  instead of a lying "sent". The app also toasts the same thing once per launch
  when it notices it is muted. **Any new way of surfacing the offer must feed
  this flag** — an un-checked delivery path is exactly the dead end this task
  exists to remove.
- **Old app on the counter phone is not an error**: POS says *"phone ki app
  purani hai, update karein"* and enlarges the number with a copy button.
- **`/dial-result` is bound to the claiming device**, not just the shop: a
  second paired phone cannot close a request delivered to the counter phone.

### Three languages (Task 1382)

The whole UI is English / Roman Urdu / Urdu, chosen **in the app**, never from
the phone's system language:

```
res/values/            → English      (default — a fresh install opens in English)
res/values-b+ur+Latn/  → Roman Urdu   (`ur-Latn`; a real BCP-47 tag, and LTR)
res/values-ur/         → Urdu script  (the pre-1.3.0 text, unchanged)
```

`Lang.kt` holds the codes and builds the locale `Context`; `BaseActivity`
applies it in `attachBaseContext` and wires the three-way picker
(`view_lang_switch.xml`, on the login and main screens). The choice is stored in
the existing `caller_prefs` under `ui_lang`, so it survives logout and app
updates.

Two rules that break the build or the translation if ignored:

- **Every new Activity extends `BaseActivity`**, not `AppCompatActivity` — an
  `AppCompatActivity` screen silently stays in the phone's system language.
  Outside an Activity (e.g. the `DownloadManager` receiver in `UpdateCheck`)
  read strings via `Lang.wrap(appCtx).getString(...)`.
- **Every translatable key must exist in all three files of the source set that
  declares it.** `MissingTranslation` is fatal in `lintVitalRelease`, and an
  override supplied only in `values/` leaves Urdu/Roman mode showing the
  inherited `src/main` text. Language-neutral keys (`app_name`, `login_title`,
  `welcome_fmt`, `lang_*`) stay `translatable="false"` in `values/` only.

Lint enforces only the easy half of that second rule. It fires when a key is
absent from a locale file of the module; it says **nothing** when a flavor
overrides a key in `values/` and skips `values-ur/`, because falling back to
`src/main`'s Urdu line is legal resource resolution. English reads right, and
the shop sees the clean build's wording the moment it switches to Urdu.
`bash scripts/caller-lang-check.sh` (Task 1388) is the guard for exactly that
blind spot — see [Verify before hosting](#the-language-guard-source-tree-no-build-needed).

The disclosure screen is fully translated: all five points say exactly the same
thing in all three languages. Never shorten or soften one language's copy — the
Play review reads whichever one is on screen.

Both notification builds now show a **prominent disclosure** screen before
notification access is requested (Play User Data policy). `Detector.request()`
opens that screen; only its agree button calls `Detector.openSettings()`. Never
bypass it.

**No consent, nothing read.** `CallListenerService.handle()` starts with
`CallSourceRules.gateOpen(token != null, Prefs.notifDisclosureAccepted(...))`
and returns before touching the notification. Android lets a user switch
notification access on straight from system Settings (and clearing app data
wipes the consent flag), so OS access alone is never treated as consent —
`Detector.granted()` in the notif builds is `listenerEnabled() && consent`, so
the app also shows "off" and offers the disclosure again in that state.

**Declared scope == enforced scope.** `CallSourceRules.classify()` is the single
gate: a notification is read only if its category is `CATEGORY_CALL` *and* its
package is WhatsApp / WhatsApp Business or one of the phone's calling apps
(default dialer from `TelecomManager`, an `ACTION_DIAL` resolver — hence the
`<queries>` block in both notif manifests — or a named system telephony package
in `KNOWN_DIALER_PKGS`). Package **names are never pattern-matched**: a rule
like "anything ending in `.phone`" would let any app in. Category alone is
**not** enough either:
before Task 1346 any VoIP app's call notification was captured and reported as
`sim`, which was wider than the disclosure, the privacy policy and the Play Data
safety answers. Widen that gate only by widening those three texts in the same
change. JVM tests: `gradle -p caller-app testPlusReleaseUnitTest
testPlayReleaseUnitTest` (`src/notifTest/java`, no emulator needed).

### Gradle trap (cost one failed build)

`productFlavors { plus { ... } }` silently creates **no flavor** — Groovy
resolves `plus` to `Object.plus()` and the build dies with
*"Task 'assemblePlusRelease' not found"*. All three flavors therefore use the
explicit form: `create("sim") { }` / `create("plus") { }` / `create("play") { }`.

---

## Prerequisites (outside the workspace — re-download after container reset)

```bash
# ~10 min total, once per container lifetime. Background jobs die with
# ShellExec — run these as foreground chunks.

# 1. JDK 17 (via nix)
nix-env -iA nixpkgs.jdk17
export JAVA_HOME=$(dirname $(dirname $(readlink -f $(which java))))

# 2. Android SDK command-line tools (curl needs -L; the zip's inner dir must be
#    RENAMED to latest/; sdkmanager rejects packages combined with --licenses)
mkdir -p /home/runner/android-sdk/cmdline-tools
cd /home/runner/android-sdk/cmdline-tools
curl -sLO https://dl.google.com/android/repository/commandlinetools-linux-11076708_latest.zip
unzip -q commandlinetools-linux-11076708_latest.zip && rm *.zip
mv cmdline-tools latest
export ANDROID_HOME=/home/runner/android-sdk
yes | latest/bin/sdkmanager --licenses
# android-36 / build-tools 36 are required since Task 1346 (compileSdk 36)
latest/bin/sdkmanager "platforms;android-36" "build-tools;36.0.0"

# 3. Gradle (curl needs -L — services.gradle.org redirects)
mkdir -p /home/runner/tools && cd /home/runner/tools
curl -sLO https://services.gradle.org/distributions/gradle-8.11.1-bin.zip
unzip -q gradle-8.11.1-bin.zip && rm *.zip
```

---

## Build BOTH builds

```bash
cd /home/runner/workspace
export JAVA_HOME=$(dirname $(dirname $(readlink -f $(which java))))
export ANDROID_HOME=/home/runner/android-sdk
# absolute path — file() in build.gradle resolves relative paths against app/
export RIDER_KS=/home/runner/workspace/.local/rider-signing/rider-release.p12
export RIDER_KS_PASS=$(cat .local/rider-signing/password.txt)

/home/runner/tools/gradle-8.11.1/bin/gradle -p caller-app assembleSimRelease assemblePlusRelease
```

(The Play bundle is a separate task — `bundlePlayRelease`, see
`docs/play/signing-and-build.md`. Building it does not touch these two APKs.)

Outputs (~4.6 MB each, ~1m 15s on a warm daemon):

```
caller-app/app/build/outputs/apk/sim/release/app-sim-release.apk    → taxnest-caller.apk
caller-app/app/build/outputs/apk/plus/release/app-plus-release.apk  → taxnest-caller-plus.apk
```

### Verify before hosting (one command, do not skip)

```bash
bash scripts/apk-release-check.sh \
  caller-app/app/build/outputs/apk/sim/release/app-sim-release.apk \
  caller-app/app/build/outputs/apk/plus/release/app-plus-release.apk
```

This is the guard v1.0.0 did not have. Per APK it FAILS loudly on:

1. **any of Play Protect's four blocked permissions** anywhere in the manifest —
   `<uses-permission>` *and* `android:permission` on a service, which is how the
   notification listener is declared;
2. **a signature that is not the shared rider key** (`CN=TaxNest Rider`) — a
   wrong key means no in-place update, every phone would have to uninstall;
3. **a version that does not match `caller-app/app/build.gradle`** — a stale APK
   built before the bump re-uses its `versionCode`, and a re-used `versionCode`
   never updates a phone.

It prints package + `versionName`/`versionCode` for each file and needs only
`python3` (manifest and signing block are parsed directly — no Android SDK, so
it also works before the SDK is re-downloaded after a container reset).

**The plus build is the one allowed carrier of the listener**, so the script
reports it as a named EXCEPTION — *"PASS WITH 1 KNOWN EXCEPTION"*, never a
silent pass — and still FAILS if that same file is named `taxnest-caller.apk`,
which is exactly the v1.0.0 incident. The clean `sim` build must come back with
a plain PASS. `--strict` turns the exception into a failure; `--expect-version` /
`--expect-code` assert the version you meant to ship.


### …and the Play-drift guard (also one command, also do not skip)

```bash
bash scripts/play-build-check.sh --apks-only
```

Same two APKs, a different question: are they still **website** builds? It
FAILS if either one has lost `REQUEST_INSTALL_PACKAGES`, lost the `UpdateCheck`
class, or moved off `targetSdk 34` — i.e. if a "tidy up the Play build" edit
stripped self-update from the builds that have no store to fall back on. Drop
`--apks-only` when a Play AAB was built in the same run: the full command also
proves the AAB carries none of that (`docs/play/signing-and-build.md` §3).

### The language guard (source tree, no build needed)

```bash
bash scripts/caller-lang-check.sh
```

Run it **before** you build — it reads `caller-app/app/src/*/res/values*/strings.xml`
directly, needs no SDK and no APK, and takes about a fifth of a second. Every
`gradle -p caller-app …` build also runs it by itself: the `checkStringLanguages`
task in `app/build.gradle` hangs off `preBuild`, so a half-translated string
fails the build instead of reaching a phone.

It FAILS on the four ways a screen silently stays in the wrong language, none of
which `MissingTranslation` can see:

1. a translatable key that a source set declares in `values/` but not in its
   `values-ur/` or `values-b+ur+Latn/` — the **silent fallback**: that build
   shows `src/main`'s wording in Urdu while English looks perfect. It names the
   flavor and both source sets (*"key `build_badge` takes English from
   `src/plus/res` but Urdu from `src/main/res`"*), and it sees an override split
   across two res dirs of one flavor, which `src/plus` + `src/notif` + `src/web`
   makes easy to do;
2. the mirror image — a key overridden in `values-ur/` only, leaving English
   inherited — and a `translatable="false"` key that got translated anyway;
3. a **`values-ur/` line with no Urdu-script character** (a copy-paste that
   never got translated), and Urdu script sitting in the Roman file. A line that
   must stay in Latin — a brand name such as "TaxNest Caller ID" — is waived
   with `<!-- lang-check: allow-latin (why) -->` on the line above it;
4. anything it cannot verify: a missing locale file, a duplicate key, a
   `<plurals>`/`<string-array>` it does not read, or a `productFlavors` block it
   cannot parse. "Could not check" is a failure here, never a pass.

The flavor map comes out of `app/build.gradle`, so a new flavor or a new
`res.srcDirs` entry is covered without touching the script.

This replaces the by-hand `aapt2 dump resources | grep <key>` pass (looking for
a bare `()`, a `(ur)` and a `(b+ur+Latn)` line per key), which needed a finished
APK and someone remembering to look.

Caller-specific extras — these need the SDK's `aapt2` and neither script covers
them:

```bash
BT=/home/runner/android-sdk/build-tools/36.0.0
SIM=caller-app/app/build/outputs/apk/sim/release/app-sim-release.apk
PLUS=caller-app/app/build/outputs/apk/plus/release/app-plus-release.apk

# a. Clean build has the telephony receiver; plus build has the listener AND
#    (since 1.5.0) telephony + contacts too — notification-only detection never
#    sees a saved contact's number, so plus reads the number from telephony and
#    resolves a WhatsApp name through contacts. Both builds therefore carry
#    READ_PHONE_STATE + READ_CALL_LOG; only plus adds READ_CONTACTS. (Before
#    1.5.0 plus had none of the three — do not "fix" a build back to that.)
$BT/aapt2 dump permissions $SIM     # READ_PHONE_STATE + READ_CALL_LOG expected
$BT/aapt2 dump permissions $PLUS    # those two + READ_CONTACTS expected


# b. Language switch (Task 1382): each build must show its OWN badge. That the
# b. Language switch (Task 1382): each build must show its OWN badge. That the
# b. Language switch (Task 1382): each build must show its OWN badge. That the
#    badge (and every other key) exists in all three locales is now
#    scripts/caller-lang-check.sh's job — this grep is only the "right wording
#    in the right APK" spot check.
$BT/aapt2 dump resources $SIM  | grep -A3 "string/build_badge"   # SIM-only wording
$BT/aapt2 dump resources $PLUS | grep -A3 "string/build_badge"   # WhatsApp wording


# c. Call back (Task 1381) is IN both website builds.
$BT/aapt2 dump permissions $SIM  | grep FOREGROUND_SERVICE_DATA_SYNC   # present
$BT/aapt2 dump permissions $PLUS | grep FOREGROUND_SERVICE_DATA_SYNC   # present
```

(`targetSdk 34`, `REQUEST_INSTALL_PACKAGES` and the `UpdateCheck` class used to
be three more `aapt2` greps here, plus a "did call back leak into the Play
build?" one. All of them are now `scripts/play-build-check.sh`, which fails
loudly instead of asking you to read a grep's output — and needs no SDK.)

Equal `versionCode` on both flavors is fine — Android only refuses a true
downgrade, and swapping builds is a same-signature in-place update.

---

## Host + go live

APKs are **never committed** (public repo, disk quota) and **never published to
GitHub Releases** (desktop agents self-update from `releases/latest`).

> SSH/scp must target **`cpanel.taxnest.com.pk`** (DNS-only). `taxnest.com.pk`
> is Cloudflare-proxied — port 22 there just times out and looks like "live is
> unreachable". Note `scp` takes `-P 22`, `ssh` takes `-p 22`.

```bash
SCP="-i .local/ssh/cpanel_deploy_key -P 22 -o BatchMode=yes -o StrictHostKeyChecking=accept-new"
H=taxnestc@cpanel.taxnest.com.pk

# keep a versioned copy (the previous build stays as a rollback file), then
# point the canonical names the download page uses at the new builds.
# V = the versionName you just built — never re-use the previous release's name.
#
# BETA UPLOAD (what 1.7.0 did on 29 Aug 2026): run ONLY the two scp lines and
# stop. The versioned URLs are then downloadable for a real-phone test while
# every shop stays on the canonical file it already has. Run the ssh line and
# flip the settings only AFTER that test passes.
V=1.7.0
scp $SCP caller-app/app/build/outputs/apk/sim/release/app-sim-release.apk   $H:public_html/public/downloads/taxnest-caller-$V.apk
scp $SCP caller-app/app/build/outputs/apk/plus/release/app-plus-release.apk $H:public_html/public/downloads/taxnest-caller-plus-$V.apk
ssh ${SCP/-P/-p} $H "cd public_html/public/downloads \
  && cp -f taxnest-caller-$V.apk taxnest-caller.apk \
  && cp -f taxnest-caller-plus-$V.apk taxnest-caller-plus.apk \
  && chmod 644 taxnest-caller*.apk"
```

Rollback = copy `taxnest-caller-<old>.apk` back over the canonical name.

**Prove the hosted file, not the local one.** `cp` on the server and a green
local check say nothing about what the website actually serves, so re-download
the two canonical URLs and run the same guard over the downloaded bytes:

```bash
cd /tmp && curl -sLO https://taxnest.com.pk/downloads/taxnest-caller.apk \
        && curl -sLO https://taxnest.com.pk/downloads/taxnest-caller-plus.apk
cd /home/runner/workspace && bash scripts/apk-release-check.sh \
  --expect-version 1.4.0 --expect-code 5 \
  /tmp/taxnest-caller.apk /tmp/taxnest-caller-plus.apk
```

During a beta upload the canonical names still hold the OLD build, so download
the **versioned** URLs instead and expect the new numbers — that is what proves
the upload, e.g. for 1.7.0:

```bash
cd /tmp && curl -sLO https://taxnest.com.pk/downloads/taxnest-caller-1.7.0.apk \
        && curl -sLO https://taxnest.com.pk/downloads/taxnest-caller-plus-1.7.0.apk
cd /home/runner/workspace && bash scripts/apk-release-check.sh \
  --expect-version 1.7.0 --expect-code 8 \
  /tmp/taxnest-caller-1.7.0.apk /tmp/taxnest-caller-plus-1.7.0.apk
```

md5 the downloads against the build outputs too — equal md5 is the only proof
the canonical name points at the new build and not at a half-finished upload.

As of 29 Aug 2026 the canonical names serve **1.5.0** and the newest files on
the server are the versioned `taxnest-caller-1.7.0.apk` /
`taxnest-caller-plus-1.7.0.apk` (beta upload — see the box at the top).

v1.4.0 was hosted and live (21 Aug 2026, Task 1362): `taxnest-caller.apk` served
the clean build, `taxnest-caller-plus.apk` the plus build, `taxnest-caller-1.4.0.apk`
/ `taxnest-caller-plus-1.4.0.apk` are the versioned copies, and the 1.1.0 (and
clean-only 1.0.0) files stay beside them as rollback.

Then deploy the PHP side and flip the two version settings in
**SaaS admin → Settings → App versions**:

| setting | value | controls |
|---|---|---|
| `caller_app_latest_version` | `1.4.0` | the default /download card + POS → Customize button (clean APK) |
| `caller_app_plus_latest_version` | `1.4.0` | the "WhatsApp calls bhi chahiyen?" section + plus phones' update prompt |

Both must equal the `versionName` you just hosted. A setting left one release
behind is invisible: the new file is on the server, the download card still
advertises the old version and **no phone gets an update banner**, because
`UpdateCheck.isNewer` compares against the setting, not against the APK.

Both gates need the **file on disk AND the setting non-empty**, so an empty
setting hides that build everywhere — that is the beta-safe switch.

**Prove all three numbers agree (Task 1412).** Build, upload and the admin
setting are three separate manual steps, and the Caller ID 1.4.0 rollout sat
stuck at 1.1.0 for weeks because one of them was skipped with nothing to flag
it. After flipping the settings, run the reconcile check — it reads the live
site over HTTP (no SSH) and the hosted APK itself, so it catches a build that
never went live, a setting flipped before the upload, or a hosted file that is
still the old version:

```bash
php artisan apps:check-release-drift            # all six apps
php artisan apps:check-release-drift --app=caller --app=caller_plus
```

For each app it prints `build=` (from `app/build.gradle`) vs `advertised=`
(the live `/api/app-version` setting) vs `hosted=` (the versionName inside the
downloaded APK) and **exits non-zero** unless all three match. Note that
`/api/app-version` and the in-app `/version` endpoint now also refuse to
advertise a version the hosted file does not contain (Task 1413), so a
flip-before-upload no longer nags phones into re-installing the same bytes —
but this command is how you confirm the release is actually finished.

Finally create the What's New elaan (`scripts/elaan-insert.sh`, audience `pos`)
— `scripts/deploy-live.sh` refuses to deploy without a fresh announcement.

---

## Update-check contract (do not break)

The app calls `GET /api/caller-app/v1/version?build=sim|plus`:

- `build=sim` → `caller_app_latest_version` + `taxnest-caller.apk`
- `build=plus` → `caller_app_plus_latest_version` + `taxnest-caller-plus.apk`
- **no `build` param → plus.** Every v1.0.0 install in the wild is a
  notification-listener build; defaulting to the clean APK would silently strip
  its WhatsApp detection.

The in-app compare is semver-strict (`UpdateCheck.isNewer`), so a beta phone
ahead of the server never sees a phantom update banner.

`/api/app-version?app=caller` (clean) and `?app=caller_plus` are the public
twins of the same map — `tests/Feature/AppVersionEndpointTest.php` locks both.

---

## Release checklist

1. Source changes committed.
2. `versionCode` +1 and `versionName` bumped in `caller-app/app/build.gradle`
   (shared by all three flavors).
3. `bash scripts/caller-lang-check.sh` — must print PASS before you build. (The
   build runs it too, but a source-tree failure is cheaper to read than a failed
   build.)
4. Build both website flavors, then **both** APK guards (+ the caller-specific
   `aapt2` extras above):
   - `bash scripts/apk-release-check.sh <sim> <plus>` — the sim APK must print
     PASS; the plus APK must print PASS WITH 1 KNOWN EXCEPTION and nothing else.
   - `bash scripts/play-build-check.sh --apks-only` — must print PASS (both APKs
     still have self-update and `targetSdk 34`). If a Play AAB was built in the
     same run, drop `--apks-only` so the bundle is checked too.
5. scp both APKs to live `public_html/public/downloads/` (versioned name +
   canonical name), then re-run the guard on the **downloaded** canonical URLs
   and md5-match them against the build outputs.
6. Deploy PHP (`git push origin HEAD:main`; `.cpanel.yml` auto-deploys).
7. **Owner phone-tests both builds** — see
   `docs/qa/task-1345-caller-id-two-builds-qa.md`. From 1.3.0 also check the
   language switch:
   - Fresh install on an **English phone** and on an **Urdu phone** — both must
     open in **English** (the app ignores the system language).
   - Tap Roman, then Urdu, on the login screen: the screen changes instantly,
     and Urdu lays out right-to-left while Roman stays left-to-right.
   - Sign in, switch the language on the main screen, then **kill the app and
     reopen it** — the chosen language is still there. Same after a log out and
     log back in.
   - Walk the main screen in each language: status line, battery line + its
     toast, the permission line + its toast, "Send a test ring" and its toast,
     "Last call sent: …", the update banner and Log out. No line may stay stuck
     in another language.
   - On the **WhatsApp build**, open the notification-access disclosure in all
     three languages — all five points must be there in each.

   From 1.4.0 also walk the **call back** checklist —
   `docs/qa/task-1381-caller-id-call-back-qa.md`: ring, miss, call back from the
   popup / the list / the customer card, phone offline, a phone still on the old
   app, and the Play build untouched.

   **Completed for the 21 Aug 2026 v1.4.0 rollout (Task 1387):** the owner
   reported PASS for install/update, the English / Roman Urdu / Urdu switch,
   Urdu RTL, restart and logout/login persistence, and all five disclosure
   points in all three languages. See
   `docs/qa/task-1387-caller-id-languages-qa.md`. The phone model and Android
   version were not captured, so this is an owner-reported QA record rather than
   device-specific evidence.
8. Flip the two version settings in admin, then
   `php artisan apps:check-release-drift --app=caller --app=caller_plus` —
   build, live site and hosted APK must all show the same versionName (Task
   1412). Non-zero exit = the release is not finished.
9. What's New elaan (Roman Urdu, with the reason).

---


## Version history

| Version | versionCode | Notes |
|---------|-------------|-------|
| 1.7.0 | 8 | **LAN Mode — the ring survives a dead internet line.** Until now a ring only ever went to the cloud, so the moment the shop's line dropped the counter popup stopped with it. The phone can now also post the ring **straight to the shop's own PC** over the same router: new `LanClient` (agent discovery across the paired host's ports, pairing with a 6-digit code shown in the NestPOS agent window), `LanPairActivity` + its screen, `Prefs` fields for the paired host/token, and a `RingReporter` that tries LAN and cloud as two lanes for the same ring. Three rules that must not be "simplified" later: (1) **the LAN lane is capped at a fixed 8 s wall clock** (`RING_BUDGET_MS`) — a ring is reported from a detector Android will not wait on forever, and the cloud lane still needs its turn afterwards; (2) the phone only ever talks to a **private** address (`10.x`, `172.16–31.x`, `192.168.x`, `169.254.x` link-local) — `127.x` is deliberately **not** on the list, because another app on the same phone could otherwise stand up a listener, harvest the LAN token and read customer numbers in cleartext; (3) every ring carries an `offline_uuid` so the same call arriving on both lanes is stored once — server side that is a unique key on `pos_caller_events`, and the insert catches the lost race and answers `duplicate` instead of a 500 the phone would retry. The uuid is checked **first**, the ~20 s number/name heuristic second, and that order must stay: a uuid is minted per *report attempt*, not per call, so Android's telephony-then-notification reposts legitimately carry different uuids and only the heuristic catches those. Needs the desktop agent on **v1.11.0+** (LAN Mode switch + named device list). Cable or WiFi makes no difference — only "same router" does. **Built 29 Aug 2026, versioned files uploaded, canonical names and both settings still on 1.5.0** pending the owner's real cable-pull test. |
| 1.6.0 | 7 | **No popup on an OUTGOING call.** A dialer notification does not say which direction the call is going — once it connects, all that is left on it is a name and a timer, which reads exactly like an incoming call, so the counter got a customer popup every time the shop rang somebody. Telephony now decides: `OFFHOOK` with no `RINGING` before it means **we** dialled, and that call reports nothing. The code had already landed earlier but never reached a build — the APKs were stuck on versionCode 6, which is the whole reason this row exists separately. **Never hosted on its own**; it reaches shops inside the 1.7.0 rollout. |
| 1.4.0 | 5 | **Call back from the POS** (Task 1381) — website builds only. New `CallerApp` + `DialWatchService` (foreground `dataSync`, ~5 s poll of `GET /dial-requests`, interval server-tunable via `poll_ms`) + `DialActivity` (tap → `ACTION_DIAL`, never `CALL_PHONE`) + `DialBootReceiver`, all in `src/web/`. Four new permissions, **none** on Play Protect's blocked list. The poll carries a `notif` flag (notifications enabled + offer channel not muted) — a muted phone stays `dial_seen_at`-fresh but loses `supports_dial`, so POS falls back to the copy-number card instead of a silent "sent", and the app toasts the reason once per launch. `/dial-result` is bound to the device that claimed the row. **`src/play/` untouched — the Play build gets no call back and no new permission.** Server side: `pos_caller_dial_requests` queue + `called_back_at` on ring events. Bump `caller_app_latest_version` **and** `caller_app_plus_latest_version` to `1.4.0` so signed-in website phones self-update — until then a phone on an older build makes POS show the "app purani hai" fallback, which is expected, not a bug. **Built, hosted and both settings flipped on 21 Aug 2026 (Task 1362)** — this is the build the website serves today, and it is the first hosted APK to carry the 1.2.0 disclosure screen and the 1.3.0 language switch. |
| 1.5.0 | 6 | **The number comes back on the plus build.** A shop that installed the plus APK started seeing `No phone` on ordinary SIM calls: the plus build had no telephony detector, so a SIM ring was only ever read from the dialer's *notification*, and a dialer shows the saved contact's **name** where the number would be. Two changes, both plus-only: (1) `PhoneStateReceiver` moved out of `src/sim/java` into a new shared `src/telephony/java` source set that `sim` **and** `plus` compile (the whole `src/sim/java` could not be added — `Detector.kt` exists in both sets and would be a redeclaration), so the plus build reports SIM rings from Android telephony with the real number; `CallListenerService` now drops dialer notifications whenever those telephony permissions are granted, or one ring would post twice. (2) WhatsApp still gives a name and no number for saved contacts, so `ContactNumberLookup` (new, `src/plus/java`, `READ_CONTACTS`) resolves that name against the phone's own contact list — exact display-name match only, and only when every match shares one number, otherwise it stays name-only. `ExtraPerms` (in `src/main`) asks for whatever of `READ_PHONE_STATE` / `READ_CALL_LOG` / `READ_CONTACTS` **this APK actually declares**, so the clean and Play builds are untouched by it. **`src/play/` gets none of it** — no telephony source set, no contacts permission, a no-op `ContactNumberLookup` beside the no-op `Updater`; `scripts/play-build-check.sh` already fails the AAB on `PhoneStateReceiver` or `READ_CALL_LOG`, so the guard covers the regression. Contacts never leave the phone: only the caller's own number is posted. |
| 1.3.0 | 4 | **Language switch** (Task 1382): the whole app is now English / Roman Urdu / Urdu, picked from a compact three-way selector on the login **and** main screens. **A fresh install opens in English** whatever the phone's language is; the choice is saved on the phone and survives app restarts, logout/login and updates. Every user-visible line is translated — login and its errors, status, battery and permission lines with their toasts, the test-ring button and toast, "Last call sent: …", the update prompts and their download toasts, log out, and the whole "how does this work" paragraph — plus the notification-access **disclosure screen** in both notification builds, saying exactly the same five things in all three languages. The two-line build badge became one translated line per build (the old Roman recap lines are gone — the user picks a language now). Detection, permissions and the POS payload are untouched. **Never hosted under its own version number** — it reached shops inside the 1.4.0 rollout (Task 1362), and the What's New elaan telling shops the app now speaks all three languages went out on 21 Aug 2026 (Task 1387). |
| 1.0.0 | 1 | Initial release — notification listener only (SIM + WhatsApp). **Uninstallable from the website once Play Protect's enhanced fraud protection rolled out.** |
| 1.2.0 | 3 | **Third flavor `play`** (Task 1346) for the Google Play Store: no self-update, no `REQUEST_INSTALL_PACKAGES`, no battery permission, `targetSdk 36`, edge-to-edge insets. Both notification builds (`plus` + `play`) gained the **prominent disclosure** screen before notification access. Website APKs unchanged in behaviour — same permissions, same `targetSdk 34`, same self-update. This version was **never hosted on its own** — the website stayed on 1.1.0 until the 1.4.0 rollout (Task 1362) carried its disclosure screen to shops. |
| 1.1.0 | 2 | **Two builds** (Task 1345): `sim` = clean telephony build, installs with no Play Protect block, default download; `plus` = the old SIM + WhatsApp behaviour. Shared `RingReporter` (payload + 60 s dedupe + 401 handling, dedupe moved to SharedPreferences so a fresh receiver process cannot double-post), per-build setup screen + build badge, per-build update check, `device` string now records which build a phone runs. |

#    in the right APK" spot check.
$BT/aapt2 dump resources $SIM  | grep -A3 "string/build_badge"   # SIM-only wording
$BT/aapt2 dump resources $PLUS | grep -A3 "string/build_badge"   # WhatsApp wording


#    badge (and every other key) exists in all three locales is now

#    badge (and every other key) exists in all three locales is now

#    in the right APK" spot check.
$BT/aapt2 dump resources $SIM  | grep -A3 "string/build_badge"   # SIM-only wording
$BT/aapt2 dump resources $PLUS | grep -A3 "string/build_badge"   # WhatsApp wording

