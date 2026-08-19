# TaxNest FBR POS APK (WebView shell) — Build & Release Runbook

Last updated: Aug 2026 (v1.1.0 FCM push wiring, Task #1283)

The shell mirrors the PRA POS shell (`pos-app/RELEASE.md`) and the rider-app
toolchain (`rider-app/RELEASE.md`). This file covers the FBR-specific bits.

---

## Firebase prerequisite (v1.1.0+ push) — one-time owner setup

Push is OPTIONAL at build time: without the config below the APK still builds
and runs exactly like v1.0.x. To enable push (fail-queue alert / day-close
reminder from Task 1275's server side):

1. **Use the EXISTING Firebase project** (the rider one — do NOT create a
   second project; the server credential `storage/app/firebase/rider-fcm.json`
   is project-wide and already covers this app).
2. Firebase console → Project settings → **Add app** → Android, package name
   **`pk.taxnest.fbrpos`** → download **`google-services.json`** → drop it at
   **`fbr-pos-app/app/google-services.json`**.
   It is gitignored (public repo — NEVER commit it). Keep a copy with the
   keystore backup at `~/rider-signing/` on live.
   The gradle plugin applies itself automatically when the file exists.
3. **No new server credential needed** — rider-fcm.json is a project-level
   service account and sends to every app in the project.
4. Build WITHOUT the file = push stays dormant (Push.kt catches the missing
   Firebase init silently); everything else works. **A build made without the
   file must be REBUILT after the file is dropped in** for push to activate.

Server endpoints the shell talks to (Task 1275):
- register: `POST /fbr-pos/app/fcm-token` (session cookie + header
  `X-TaxNest-App: fbrpos`)
- clear on logout: `POST /api/fbr-pos-app/fcm-token/clear` (token possession)

---

## Prerequisites & build

Same toolchain as the rider app: nix jdk17, `/home/runner/android-sdk`
(platforms;android-34 + build-tools;34.0.0), gradle-8.7 under
`/home/runner/tools` — all outside the workspace, re-download after container
reset (~10 min). Local build ONLY (git token lacks workflow scope — no CI).

```bash
export JAVA_HOME=$(dirname $(dirname $(readlink -f $(which java))))
export ANDROID_HOME=/home/runner/android-sdk
export RIDER_KS=$PWD/.local/rider-signing/rider-release.p12
export RIDER_KS_PASS=$(cat .local/rider-signing/password.txt)

/home/runner/tools/gradle-8.7/bin/gradle -p fbr-pos-app assembleRelease
```

Output APK: `fbr-pos-app/app/build/outputs/apk/release/app-release.apk`
**Same shared keystore as ALL TaxNest APKs** (alias `rider`).

## Release checklist

1. Bump `versionCode`/`versionName` in `fbr-pos-app/app/build.gradle`.
2. Build signed APK; `apksigner verify --print-certs` must show the shared key
   (CN=TaxNest Rider).
3. Host as a versioned BETA first:
   `scp … public_html/public/downloads/taxnest-fbr-pos-<ver>.apk`
   **NEVER GitHub Releases** (desktop agents self-update from releases/latest).
4. **Owner phone-tests the beta** (mandatory rollout rule). For a push
   release: fail-queue alert + day-close reminder arrive with the app CLOSED,
   tap opens the app, logout stops pushes, downloads/uploads/fullscreen video
   still work.
5. Only after owner sign-off: copy the beta over
   `public_html/public/downloads/taxnest-fbr-pos.apk`, bump the FBR shell
   latest-version setting (admin panel) so old shells (UA
   `TaxNestFBRPosApp/<ver>`) see the update banner, and create the What's New
   `AppUpdate` row.

## Version history

| Version | versionCode | Notes |
|---------|-------------|-------|
| 1.0.0   | 1           | Initial WebView shell (clone of pos-app) |
| 1.0.1   | 2           | Download cookie rule |
| 1.0.2   | 3           | Fullscreen video + update check |
| 1.1.0   | 4           | **FCM push wiring** (Task #1283) — fail-queue alert / day-close reminder; token register on login / clear on logout; needs the Firebase prerequisite above, builds fine without it. Beta at `/downloads/taxnest-fbr-pos-1.1.0.apk` (built WITHOUT google-services.json — push dormant until owner's Firebase drop-in + rebuild). |
