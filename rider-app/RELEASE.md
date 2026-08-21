# TaxNest Rider APK — Build & Release Runbook

Last updated: Aug 2026 (v1.7.0 live-tracking reliability)

---

## Firebase prerequisite (v1.5.0+ instant push) — one-time owner setup

**A release build REFUSES to run without this config.** `assembleRelease`
fails at `verifyFirebaseConfigPresent` when `app/google-services.json` is
missing, and after packaging it re-opens the finished APK to prove the config
really landed inside it. Debug builds (`assembleDebug`) are never gated — that
is the way to check the shell still compiles without the owner's file.

Why the gate exists: **v1.6.0 shipped without it.** The APK installed fine,
looked completely normal and was correctly signed — and push was stone dead.
25 riders live, not one push token, so new-delivery alerts and the "sync now"
nudge could never reach a single phone. Nothing failed loudly, so nobody
noticed for weeks. The file is gitignored (public repo), so **any** build made
in a fresh container walks straight into the same trap.

To enable instant push:

1. **Create a free Firebase project** at https://console.firebase.google.com
   (any name, e.g. "TaxNest Rider"; Analytics not needed).
2. **Add an Android app** with package name **`pk.taxnest.rider`** and
   download **`google-services.json`** → drop it at
   **`rider-app/app/google-services.json`**.
   It is gitignored (public repo) — keep a copy with the keystore backup at
   `~/rider-signing/` on live. The gradle plugin applies itself automatically
   when the file exists; nothing else to edit.
3. **Server credential** — Firebase console → Project settings →
   Service accounts → *Generate new private key* (JSON). Upload it to live at
   **`storage/app/firebase/rider-fcm.json`** (outside public_html web root,
   dir is gitignored). **NEVER commit this file — it is a private key.**
   Alternative: base64 the JSON into `FIREBASE_CREDENTIALS_JSON` in `.env`.
4. No server restart needed — the push service picks the file up per request;
   while it's absent, pushes are silently skipped and the poll covers.

---

## Prerequisites (outside the workspace — re-download after container reset)

```bash
# ~10 min total. Run once per container lifetime.

# 1. JDK 17 (via nix)
nix-env -iA nixpkgs.jdk17
export JAVA_HOME=$(dirname $(dirname $(readlink -f $(which java))))

# 2. Android SDK command-line tools  (verified Aug 2026 — curl needs -L,
#    the zip's inner dir must be RENAMED to latest/, and sdkmanager rejects
#    packages combined with --licenses in one call)
mkdir -p /home/runner/android-sdk/cmdline-tools
cd /home/runner/android-sdk/cmdline-tools
curl -sLO https://dl.google.com/android/repository/commandlinetools-linux-11076708_latest.zip
unzip -q commandlinetools-linux-11076708_latest.zip && rm *.zip
mv cmdline-tools latest
export ANDROID_HOME=/home/runner/android-sdk
yes | latest/bin/sdkmanager --licenses
latest/bin/sdkmanager "platforms;android-34" "build-tools;34.0.0"

# 3. Gradle 8.7 (curl needs -L — services.gradle.org redirects)
mkdir -p /home/runner/tools
cd /home/runner/tools
curl -sLO https://services.gradle.org/distributions/gradle-8.7-bin.zip
unzip -q gradle-8.7-bin.zip && rm *.zip
```

---

## Build

```bash
export JAVA_HOME=$(dirname $(dirname $(readlink -f $(which java))))
export ANDROID_HOME=/home/runner/android-sdk
export RIDER_KS=.local/rider-signing/rider-release.p12
export RIDER_KS_PASS=$(cat .local/rider-signing/password.txt)

/home/runner/tools/gradle-8.7/bin/gradle -p rider-app assembleRelease
```

Output APK:
```
rider-app/app/build/outputs/apk/release/app-release.apk
```

A good release build prints **both** of these lines. If you do not see them,
the APK is push-dead — do not host it:

```
Firebase config OK for pk.taxnest.rider (project …, app 1:…:android:…)
Firebase config verified inside app-release.apk (app 1:…:android:…) — instant push is live in this build.
```

Deliberate config-less compile check (throwaway, **never** shippable):

```bash
… assembleRelease -PallowPushlessRelease=true
```

It prints a loud warning and renames the output to `app-release-NO-PUSH.apk`,
so the usual path simply does not exist and cannot be uploaded by muscle
memory. Guard source: `scripts/lib/android-firebase-guard.gradle` (shared by
the POS and FBR POS shells too).

---

## Release checklist

1. **Code changes merged** — all source edits committed and pushed.

2. **Version bump** — `rider-app/app/build.gradle`:
   ```
   versionCode  N+1          (integer, never reuse)
   versionName  "X.Y.Z"
   ```

3. **Server latest-version setting** — the `rider_app_latest_version`
   SystemSetting (admin panel; Task 443) must be set to `X.Y.Z`, matching
   `versionName` exactly (app polls `/api/rider-app/v1/version` and shows an
   update banner when the server version is semver-newer). Bump it ONLY
   after the APK is hosted, or riders get a banner pointing at the old file.

4. **Build the APK** (see above).

5. **Verify the APK before it is hosted** — one command, no SDK needed:
   ```bash
   bash scripts/apk-release-check.sh rider-app/app/build/outputs/apk/release/app-release.apk
   ```
   It must print **PASS**. It fails on any of Play Protect's four blocked
   permissions (`RECEIVE_SMS`, `READ_SMS`, `BIND_NOTIFICATION_LISTENER_SERVICE`,
   `BIND_ACCESSIBILITY_SERVICE`) — one of those in the manifest makes the APK
   **impossible to install from the website** (the Caller ID v1.0.0 incident,
   found only when the owner tried it on his own phone) — on a signature that is
   not the shared keystore (`.local/rider-signing/rider-release.p12`, alias
   `rider`), and on a version that does not match `app/build.gradle` (a stale
   APK re-uses its `versionCode`, so phones never update in place).

   Losing that key = riders must uninstall + reinstall forever. Backup lives on
   live at `~/rider-signing/` (outside public_html).
   **NEVER commit the keystore — repo is public.**

6. **Verify push is really inside the APK** — the Gradle guard already checked
   this, but re-run it on the exact file you are about to upload:
   ```bash
   bash scripts/verify-apk-firebase.sh \
       rider-app/app/build/outputs/apk/release/app-release.apk \
       rider-app/app/google-services.json
   ```
   Must print `OK: Firebase config is baked in`. Raw evidence if you prefer it:
   `unzip -p <apk> resources.arsc | strings | grep -E "AIza|:android:"` —
   **silence there means push is dead and the APK must NOT be hosted.**

7. **Deploy to live** (owner runs on their cPanel machine):
   ```bash
   scp rider-app/app/build/outputs/apk/release/app-release.apk \
       taxnestc@taxnest.com.pk:public_html/public/downloads/taxnest-rider.apk
   ```

8. **Deploy PHP changes**:
   ```bash
   git push origin HEAD:main       # .cpanel.yml auto-deploys
   php artisan migrate --force     # run on live via cPanel SSH
   ```

9. **Owner phone-tests the new APK** before any panel card or
   What's New announcement goes live (mandatory rollout rule).
   For a push release, "works" includes: a notification actually arrives with
   the app CLOSED.

10. **What's New announcement** — create an `AppUpdate` row in the admin
   panel after owner sign-off so the in-app popup fires.

---
## Key constraints (do NOT skip)

- **Never publish to GitHub Releases.**  The desktop agent polls
  `releases/latest` of this repo for self-updates; any new release
  becomes "latest" and agents would try to install the APK.
  Host at `public_html/public/downloads/taxnest-rider.apk` only.

- **Same keystore for all TaxNest APKs** (rider + POS shell + any future
  FBR/DI shells).  Shared alias `rider`.

- **No CI possible** — git token lacks `workflow` scope so pushing
  `.github/workflows/*` is rejected. CI yml preserved as
  `rider-app/ci/build-rider-app.yml.example` for reference only.

---

## Version history

| Version | versionCode | Notes |
|---------|-------------|-------|
| 1.0.0   | 1           | Initial release |
| 1.1.0   | 2           | Delivery list + maps links + /me improvements |
| 1.2.0   | 3           | **Offline route buffering** — buffer cap 5000 pts (~27h), 401 preserves queue (token-only evict), backend dedupe on (rider_id, client_ts_ms) |
| 1.3.0   | 4           | **Offline buffering hardened** — drain outside duty (onResume + login + NetworkCallback); offline end-duty queued + reconciled on reconnect; server accepts past-timestamp buffered points when duty=OFF (per-point gate); removeFirst(stored) precise trim; regression guard restricted to live (non-offline) points only |
| 1.4.x   | 5–7         | **New-delivery notifications** — 15-min DeliveryCheckWorker poll + DeliveryNotifier dedupe (Touseef case); in-app APK update download |
| 1.5.0   | 8           | **Instant push (FCM)** — data-only push through the same DeliveryNotifier dedupe (poll stays as fallback); FCM token rotates with login/logout; **battery %** on location points → admin map "battery kam hai" badge. Needs the Firebase prerequisite above; builds/runs fine without it |
| 1.7.0   | 10          | **Live tracking reliability** (Task #1359) — 15-min network-constrained `SyncWorker` (drains the buffer even when the app is closed / the duty service was killed), `DutyWatchdog` restart + ongoing tap-to-resume notification when Android blocks a background FGS start, 2-min stationary heartbeat, last-sync line on the home screen and in the duty notification (red + reason when late), battery-optimisation / autostart gate at duty-on with a warning chip, and a server-sent data-only `sync_now` push the moment the live map sees a rider go silent (throttled 5 min/rider, no cron). Queue acknowledgement is now timestamp-anchored (`PointQueue.ackBatch`) so a cap-trim during an upload cannot delete un-uploaded points |
| 1.6.0   | 9           | ⚠️ **built WITHOUT google-services.json — push dead on every phone it reached** (silent; found Aug 2026, cause of the release gate above). **Delivered button** (Task #1160) — rider marks his own assigned/dispatched bill delivered from the delivery card (confirm dialog, Urdu/English). Additive `POST /deliveries/{id}/delivered` returns the refreshed `/me` payload (shared `applyMePayload` re-render); 404 also carries the payload so a reassigned bill resyncs the list instead of error-looping |
