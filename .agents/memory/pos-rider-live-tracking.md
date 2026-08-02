---
name: Rider LIVE Tracking (Unlimited)
description: GPS tracking architecture — app token scheme, plan gate deviation, TZ trap, GitHub release collision, retention
---

# Rider LIVE Tracking (Aug 2026) — Unlimited exclusive

Server half lives in PosRiderTrackingController: stateless JSON API for the
TaxNest Rider Android app (`/api/rider-app/v1/*`) + admin live map
(`/pos/riders/tracking`, Leaflet self-hosted in public/vendor/leaflet per
one-CDN perf rule).

## Rules / decisions
- **Plan gate `rider_tracking_enabled` defaults FALSE** — deliberate deviation from
  the fail-open convention. **Why:** pos-only premium gate; future pos plan rows
  (Pro Max etc.) must not silently unlock it; non-pos products never read it.
  Matrix: Unlimited=1, every other pos plan=0; active-trial rule still grants it.
- **App auth:** rider logs in with his EXISTING portal login (users row,
  pos_role='pos_rider'). Token = `riderId|random48`, stored as SHA-256 in
  pos_riders.app_token. Login ROTATES it → one active device per rider (feature,
  not bug). Gates re-checked on EVERY call so downgrade cuts uploads instantly.
- **Client epoch → app TZ:** `Carbon::createFromTimestampMs()` yields UTC; storing
  its wall time makes recorded_at 5h off vs now(). Always
  `->setTimezone(config('app.timezone'))` before storing client timestamps.
- **Carbon 3 signed diffs:** `now()->diffInSeconds(past)` is NEGATIVE — wrap abs()
  or diff from the older side (seconds_ago bug class).
- **pos_riders carries denormalized last_lat/lng/located_at + on_duty** so the 20s
  admin poll never scans the points table. Every locations write must update them.
- **Retention:** points >30 days purged via 1/200 lottery on upload (no cron
  dependency on live). Offline-buffered points older than 7 days rejected.
- **Locations require duty ON (409 otherwise)** — app flips its local state to
  match server on 409.

## APK distribution — DO NOT use GitHub Releases
Desktop agents self-update from `releases/latest` of jawadrao5555-alt/taxnest
(AgentManagementController::latestReleaseInfo). **Any new release in that repo
becomes "latest" and agents would try to install it.** Rider APK must ship as
workflow ARTIFACT → scp to live public/downloads/ (own version endpoint), never
as a repo release.

## Dev testing
Standing dev test rider: company 11, login tracktest.rider11@test.pk /
ridertest123 (pos_rider). To simulate plans, flip pricing_plans
rider_tracking_enabled (restore: Unlimited=1, rest=0). Downgrade-cutoff test:
flip matrix off mid-session → next upload must 403 plan_locked.

## Building the APK — LOCAL build (no CI possible)
- Git origin token has NO `workflow` scope → pushing `.github/workflows/*` is
  REJECTED ("OAuth App … without workflow scope"), and the GitHub connector's
  credentials are withheld in this workspace. CI yml preserved as
  `rider-app/ci/build-rider-app.yml.example` — do not move it back unless the
  token gains workflow scope.
- Local toolchain WORKS on Replit (aapt2 runs fine): nix `jdk17` +
  `/home/runner/android-sdk` (cmdline-tools latest, platforms;android-34,
  build-tools;34.0.0, licenses accepted) + `/home/runner/tools/gradle-8.7`.
  These live OUTSIDE the workspace → gone after container reset; re-download
  (~10 min) when needed.
- Build: export JAVA_HOME (from `which java`), ANDROID_HOME,
  RIDER_KS=.local/rider-signing/rider-release.p12,
  RIDER_KS_PASS=$(cat .local/rider-signing/password.txt), then
  `gradle -p rider-app assembleRelease`.
- **Stable signing key** = in-place app updates work. Keystore + password in
  `.local/rider-signing/` (gitignored — repo is PUBLIC) with backup on live at
  `~/rider-signing/` (outside public_html). Losing BOTH = riders must
  uninstall/reinstall forever — guard the backups.
- Release = scp APK to live `public_html/public/downloads/taxnest-rider.apk`,
  bump APP_LATEST_VERSION const in PosRiderTrackingController (+ versionName/
  versionCode in rider-app), deploy. App polls /version and shows update banner.
