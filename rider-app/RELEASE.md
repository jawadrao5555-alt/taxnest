# TaxNest Rider APK — Build & Release Runbook

Last updated: Aug 2026 (v1.5.0 FCM push + battery reporting)

---

## Firebase prerequisite (v1.5.0+ instant push) — one-time owner setup

Push is OPTIONAL at build time: without the config below the APK still
builds and runs exactly like v1.4.x (15-min poll notifications). To enable
instant push:

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

5. **Verify signing** — APK must be signed with the shared keystore
   (`.local/rider-signing/rider-release.p12`, alias `rider`).
   Losing this key = riders must uninstall + reinstall forever.
   Backup lives on live at `~/rider-signing/` (outside public_html).
   **NEVER commit the keystore — repo is public.**

6. **Deploy to live** (owner runs on their cPanel machine):
   ```bash
   scp rider-app/app/build/outputs/apk/release/app-release.apk \
       taxnestc@taxnest.com.pk:public_html/public/downloads/taxnest-rider.apk
   ```

7. **Deploy PHP changes**:
   ```bash
   git push origin HEAD:main       # .cpanel.yml auto-deploys
   php artisan migrate --force     # run on live via cPanel SSH
   ```

8. **Owner phone-tests the new APK** before any panel card or
   What's New announcement goes live (mandatory rollout rule).

9. **What's New announcement** — create an `AppUpdate` row in the admin
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
