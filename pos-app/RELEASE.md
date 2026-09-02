# TaxNest POS APK (WebView shell) — Build & Release Runbook

Last updated: Aug 2026 (v1.1.0 FCM instant push)

The shell mirrors the rider-app toolchain and rules — see `rider-app/RELEASE.md`
for the full prerequisite setup. This file covers the POS-specific bits.

---

## Firebase prerequisite (v1.1.0+ instant push) — one-time owner setup

**A release build REFUSES to run without this config** (shared guard
`scripts/lib/android-firebase-guard.gradle`): `assembleRelease` fails at
`verifyFirebaseConfigPresent` when `app/google-services.json` is missing, and
after packaging it re-opens the finished APK to prove the config really landed
inside it. Debug builds are never gated. The rider shell shipped a push-dead
v1.6.0 exactly because this was optional — the file is gitignored, so any
build in a fresh container hits the same trap.

To enable instant push (naya order / order tayyar / day-close):

1. **Use the EXISTING Firebase project** (the one created for the rider app —
   do NOT create a second project; the server credential
   `storage/app/firebase/rider-fcm.json` is project-wide and already covers
   this app).
2. Firebase console → Project settings → **Add app** → Android, package name
   **`pk.taxnest.pos`** → download **`google-services.json`** → drop it at
   **`pos-app/app/google-services.json`**.
   It is gitignored (public repo — NEVER commit it). Keep a copy with the
   keystore backup at `~/rider-signing/` on live.
   The gradle plugin applies itself automatically when the file exists;
   nothing else to edit.
3. **No new server credential needed** — `rider-fcm.json` (rider-app step 3)
   is a project-level service account and sends to every app in the project.
4. Build WITHOUT the file = push stays dormant (Push.kt catches the missing
   Firebase init silently); everything else works.

**Status (Aug 2026, v1.1.0 release):** done. `pk.taxnest.pos` is registered in
the existing `taxnest-rider` Firebase project; the config lives at
`pos-app/app/google-services.json` (gitignored) with the backup copy on live at
`~/rider-signing/google-services-pos.json`. The project-wide server credential
`storage/app/firebase/rider-fcm.json` is in place on live (chmod 600, outside
the docroot) and verified — the service account exchanges a Google OAuth token
successfully, so pushes actually send.

Never accept either file through chat attachments: `attached_assets/` is
committed to the public repo. `attached_assets/*.json` is gitignored for this
reason.

---

## Prerequisites & build

Same toolchain as the rider app (`rider-app/RELEASE.md`): nix jdk17,
`/home/runner/android-sdk` (platforms;android-34 + build-tools;34.0.0),
gradle-8.7 under `/home/runner/tools` — all outside the workspace,
re-download after container reset (~10 min).

```bash
export JAVA_HOME=$(dirname $(dirname $(readlink -f $(which java))))
export ANDROID_HOME=/home/runner/android-sdk
export RIDER_KS=.local/rider-signing/rider-release.p12
export RIDER_KS_PASS=$(cat .local/rider-signing/password.txt)

/home/runner/tools/gradle-8.7/bin/gradle -p pos-app assembleRelease
```

Output APK: `pos-app/app/build/outputs/apk/release/app-release.apk`

**Same shared keystore as ALL TaxNest APKs** (alias `rider`) — losing it
breaks in-place updates for everything. NEVER commit it (public repo).

---

## Release checklist

1. **Code merged & version bumped** — `pos-app/app/build.gradle`:
   `versionCode` N+1 (never reuse), `versionName "X.Y.Z"`.
2. **Server side deployed** — push to origin (then deploy with `bash scripts/deploy-live.sh`),
   (deploy-live.sh runs `migrate --force` itself), verify the live commit.
3. **Build the signed APK** (above); `apksigner verify --print-certs` must show
   the shared key. Then verify it before it leaves the box — one command, no
   SDK needed:
   ```bash
   bash scripts/apk-release-check.sh pos-app/app/build/outputs/apk/release/app-release.apk
   ```
   Must print **PASS**: no Play Protect blocked permission in the manifest
   (`RECEIVE_SMS`, `READ_SMS`, `BIND_NOTIFICATION_LISTENER_SERVICE`,
   `BIND_ACCESSIBILITY_SERVICE` — any one of them silently makes the APK
   impossible to install from the website, the Caller ID v1.0.0 incident),
   signature = the shared `rider` key, and the version matching
   `pos-app/app/build.gradle` (a stale APK re-uses its `versionCode` and never
   updates a phone in place). The shell must never hit the Caller ID listener
   exception — if it does, a feature added a blocked permission.
4. **Verify push is really inside the APK** — the gradle guard already checked
   it, but re-run on the exact file you are about to upload:
   ```bash
   bash scripts/verify-apk-firebase.sh \
       pos-app/app/build/outputs/apk/release/app-release.apk \
       pos-app/app/google-services.json
   ```
   Must print `OK: Firebase config is baked in`. By hand:
   `unzip -p <apk> resources.arsc | strings | grep -E "AIza|:android:"` —
   **silence means push is dead; do not host the APK.**
5. **Host as a versioned BETA first**:
   `scp -i .local/ssh/nayatel_vps_key … jawadrao5555@115.186.164.126:/var/www/taxnest/public/downloads/taxnest-pos-<ver>.apk`
   (host/path per `scripts/lib/live-host.sh` — the old cPanel box is retired
   but still answers, so an scp aimed there succeeds and reaches nobody)
   **NEVER GitHub Releases** — desktop agents self-update from
   `releases/latest` of this repo and would try to install the APK.
6. **Owner phone-tests the beta** (mandatory rollout rule). For a push
   release: every notification type arrives within seconds with the app
   CLOSED, tapping it opens the app, logout stops pushes, and
   downloads / uploads / fullscreen video still work.
7. **Only after owner sign-off**: copy the beta over
   `public_html/public/downloads/taxnest-pos.apk`, bump the
   `pos_app_latest_version` SystemSetting (admin panel → Settings) so old
   shells (UA `TaxNestPOSApp/<ver>`) see the update banner, and create the
   What's New `AppUpdate` row announcing the release.

---
## Version history

| Version | versionCode | Notes |
|---------|-------------|-------|
| 1.0.0   | 1           | Initial WebView shell (downloads with session cookie, uploads, target=_blank, external schemes, Urdu offline page, rotation-safe) |
| 1.0.1   | 2           | Download cookie rule — first-party only, agent-installer links rewritten to public endpoint |
| 1.0.2   | 3           | Minor shell fixes |
| 1.0.3   | 4           | Fullscreen video (onShowCustomView/onHideCustomView) |
| 1.0.4   | 5           | Shell polish |
| 1.1.0   | 6           | **Instant push (FCM)** — naya order → cashiers, order tayyar → waiter, day-close summary → owner/manager; token upload on login / clear on logout; needs the Firebase prerequisite above, builds fine without it |
| 1.1.1   | 7           | **Blank-screen recovery** — branded boot screen, paint watchdog, empty-document probe, 5xx + renderer-death handling, recovery card (retry / reset app data / reason line), retry on resume. Shared contract for all four shells: `docs/android-shell-recovery.md` |

---

## Rollout status — v1.1.1 (24 Aug 2026): LIVE

- Built with the Firebase config in place (`Firebase config verified inside
  app-release.apk` — push stays alive) and `apk-release-check.sh` PASS: shared
  `rider` key, no blocked permission, version matches `build.gradle`.
- Promoted on the owner's go-ahead (no phone test — same call he made for
  1.1.0): `/downloads/taxnest-pos-1.1.1.apk` copied over the stable
  `/downloads/taxnest-pos.apk` (sha256 `c2361e74…`, previous stable kept as
  `taxnest-pos-prev-1.1.0.apk`), `pos_app_latest_version` = `1.1.1`.
- Same day, same way: **FBR POS 1.1.1** (`fbrpos_app_latest_version` = `1.1.1`,
  prev = `taxnest-fbr-pos-prev-1.0.2.apk`) and **DI 1.0.2**
  (`di_app_latest_version` = `1.0.2`, prev = `taxnest-di-prev-1.0.1.apk`);
  waiter 1.0.3 had been promoted earlier the same day.
- One What's New row (audience `all`, so both the POS and FBR panels show it)
  announces the update and how to install it.
- Rollback for any of the three = copy the `*-prev-*.apk` back over the stable
  filename and set the matching `*_app_latest_version` back.
- Note on `verify-apk-firebase.sh`: run against the OLD 1.1.0 APK it reports an
  api-key mismatch. That is a rotated Firebase Android key, not a bad build —
  the baked `mobilesdk_app_id` still matches `pk.taxnest.pos`. Only ever verify
  the APK you are about to ship against the CURRENT `google-services.json`.

---

## Rollout status — v1.1.0 (20 Aug 2026): LIVE

- Signed APK (sha256 `7a5d3933…f3d7`, shared `rider` key) hosted as
  `/downloads/taxnest-pos-1.1.0.apk` **and** promoted over the stable
  `/downloads/taxnest-pos.apk` (previous stable kept as
  `taxnest-pos-prev-1.0.3.apk` on live for rollback).
- `pos_app_latest_version` = `1.1.0` and the What's New elaan row were applied
  by migration `2026_08_20_130000_rollout_pos_apk_v110.php` (idempotent).
- Server side verified on live: FCM service-account credential authorises
  (OAuth 200 + `messages:send` returns INVALID_ARGUMENT for a junk token), and
  device register/clear works end-to-end for a real POS login.
- Owner promoted **without** the usual pre-release phone test (his call —
  "test phir karwa lenge, issue hua to bata dunga"). If a report comes in,
  rollback = copy `taxnest-pos-prev-1.0.3.apk` back over `taxnest-pos.apk` and
  set `pos_app_latest_version` back to `1.0.3` in admin → Settings.
