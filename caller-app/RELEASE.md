# TaxNest Caller ID APK — Build & Release Runbook

Last updated: Aug 2026 (v1.2.0 — third `play` flavor for Google Play, Task 1346)

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
| `plus` | `taxnest-caller-plus.apk` | SIM **+ WhatsApp**, via `CallListenerService` (notification access) | blocked until the shop turns the scan off for the install |

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
src/web/java    → UpdateCheck + real Updater        (sim, plus)
src/notif/java  → CallListenerService, Detector,
                  NotificationDisclosureActivity    (plus, play)
src/notif/res   → the strings both notif builds use (plus, play)
src/play/java   → no-op Updater                     (play)
```

Each flavor's own `res/values/strings.xml` holds **only** the keys that differ
(`build_badge_roman`) — the same key in two res dirs of one source set fails the
build with *"Duplicate resources"*.

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

### Verify before hosting (60 seconds, do not skip)

```bash
BT=/home/runner/android-sdk/build-tools/36.0.0
SIM=caller-app/app/build/outputs/apk/sim/release/app-sim-release.apk
PLUS=caller-app/app/build/outputs/apk/plus/release/app-plus-release.apk

# 1. The clean build must contain NONE of the four blocked permissions.
$BT/aapt2 dump xmltree --file AndroidManifest.xml $SIM \
  | grep -iE "RECEIVE_SMS|READ_SMS|BIND_NOTIFICATION_LISTENER|BIND_ACCESSIBILITY" \
  && echo "STOP — blocked permission leaked into the clean build" || echo "clean OK"

# 2. Clean build has the telephony receiver; plus build has the listener.
$BT/aapt2 dump permissions $SIM     # READ_PHONE_STATE + READ_CALL_LOG expected
$BT/aapt2 dump permissions $PLUS    # neither of those expected

# 3. Both signed with the shared rider key, same package + version.
$BT/apksigner verify --print-certs $SIM  | grep "certificate DN"   # CN=TaxNest Rider
$BT/apksigner verify --print-certs $PLUS | grep "certificate DN"
$BT/aapt2 dump badging $SIM  | head -1
$BT/aapt2 dump badging $PLUS | head -1   # both: versionCode='3' versionName='1.2.0'

# 4. Website builds must KEEP targetSdk 34 and their self-update code —
#    only the play flavor drops them.
$BT/aapt2 dump badging $SIM  | grep targetSdkVersion    # '34'
$BT/aapt2 dump badging $PLUS | grep targetSdkVersion    # '34'
$BT/aapt2 dump permissions $SIM | grep REQUEST_INSTALL_PACKAGES   # must be present
```

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
# point the canonical names the download page uses at the new builds
scp $SCP caller-app/app/build/outputs/apk/sim/release/app-sim-release.apk   $H:public_html/public/downloads/taxnest-caller-1.1.0.apk
scp $SCP caller-app/app/build/outputs/apk/plus/release/app-plus-release.apk $H:public_html/public/downloads/taxnest-caller-plus-1.1.0.apk
ssh ${SCP/-P/-p} $H "cd public_html/public/downloads \
  && cp -f taxnest-caller-1.1.0.apk taxnest-caller.apk \
  && cp -f taxnest-caller-plus-1.1.0.apk taxnest-caller-plus.apk \
  && chmod 644 taxnest-caller*.apk"
```

Rollback = copy `taxnest-caller-<old>.apk` back over the canonical name.

v1.1.0 is already hosted and live (20 Aug 2026): `taxnest-caller.apk` serves the
clean build, `taxnest-caller-plus.apk` the plus build, versioned copies sit
beside them and `taxnest-caller-1.0.0.apk` stays as the rollback file.

Then deploy the PHP side and flip the two version settings in
**SaaS admin → Settings → App versions**:

| setting | value | controls |
|---|---|---|
| `caller_app_latest_version` | `1.1.0` | the default /download card + POS → Customize button (clean APK) |
| `caller_app_plus_latest_version` | `1.1.0` | the "WhatsApp calls bhi chahiyen?" section + plus phones' update prompt |

Both gates need the **file on disk AND the setting non-empty**, so an empty
setting hides that build everywhere — that is the beta-safe switch.

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
3. Build both website flavors + run the verification block above.
4. scp both APKs to live `public_html/public/downloads/`.
5. Deploy PHP (`git push origin HEAD:main`; `.cpanel.yml` auto-deploys).
6. **Owner phone-tests both builds** — see
   `docs/qa/task-1345-caller-id-two-builds-qa.md`.
7. Flip the two version settings in admin.
8. What's New elaan (Roman Urdu, with the reason).

---

## Version history

| Version | versionCode | Notes |
|---------|-------------|-------|
| 1.0.0 | 1 | Initial release — notification listener only (SIM + WhatsApp). **Uninstallable from the website once Play Protect's enhanced fraud protection rolled out.** |
| 1.2.0 | 3 | **Third flavor `play`** (Task 1346) for the Google Play Store: no self-update, no `REQUEST_INSTALL_PACKAGES`, no battery permission, `targetSdk 36`, edge-to-edge insets. Both notification builds (`plus` + `play`) gained the **prominent disclosure** screen before notification access. Website APKs unchanged in behaviour — same permissions, same `targetSdk 34`, same self-update. **Hosted website APKs are still the 1.1.0 files**; they only move to 1.2.0 when the owner rebuilds, re-hosts and flips the admin version settings. |
| 1.1.0 | 2 | **Two builds** (Task 1345): `sim` = clean telephony build, installs with no Play Protect block, default download; `plus` = the old SIM + WhatsApp behaviour. Shared `RingReporter` (payload + 60 s dedupe + 401 handling, dedupe moved to SharedPreferences so a fresh receiver process cannot double-post), per-build setup screen + build badge, per-build update check, `device` string now records which build a phone runs. |
