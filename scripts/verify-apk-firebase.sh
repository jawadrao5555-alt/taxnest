#!/bin/bash
# Prove that a built TaxNest APK really carries its Firebase (FCM) config —
# i.e. that instant push will actually work once it is installed.
#
# WHY: app/google-services.json is gitignored (public repo) and the Google
# Services plugin only applies when it exists, so a build made without it
# produces a normal-looking, correctly-signed, completely push-dead APK.
# Rider v1.6.0 shipped that way: 25 riders, zero push tokens, nobody noticed.
#
# `gradle assembleRelease` now refuses to build config-less releases (see
# scripts/lib/android-firebase-guard.gradle), but this script stands alone, so
# it also works on an APK you did NOT build here — the one already hosted on
# live, a file a rider sent back, an old beta.
#
# Usage:
#   bash scripts/verify-apk-firebase.sh <apk> [google-services.json]
#   bash scripts/verify-apk-firebase.sh rider-app/app/build/outputs/apk/release/app-release.apk
#   bash scripts/verify-apk-firebase.sh /tmp/live-rider.apk rider-app/app/google-services.json
#
#   Passing the google-services.json cross-checks the EXACT app id + api key,
#   which additionally catches "built with the wrong shell's config".
#   Without it the check is the generic evidence grep:
#       unzip -p <apk> resources.arsc | strings | grep -E "AIza|:android:"
#
# Exit: 0 = push config present, 1 = push is dead in this APK, 2 = bad usage.

set -uo pipefail

APK="${1:-}"
CONFIG="${2:-}"

if [ -z "$APK" ]; then
    echo "usage: bash scripts/verify-apk-firebase.sh <apk> [google-services.json]" >&2
    exit 2
fi
if [ ! -f "$APK" ]; then
    echo "FAIL: no such APK: $APK" >&2
    exit 2
fi
if ! command -v unzip >/dev/null 2>&1; then
    echo "FAIL: unzip is required" >&2
    exit 2
fi

# resources.arsc holds the string resources the Google Services plugin
# generates (google_app_id, google_api_key, gcm_defaultSenderId, project_id).
# NULs are stripped so a UTF-16 string pool greps the same as a UTF-8 one —
# same idea as piping through `strings`, without needing binutils.
ARSC="$(unzip -p "$APK" resources.arsc 2>/dev/null | LC_ALL=C tr -d '\000')"
if [ -z "$ARSC" ]; then
    echo "FAIL: $APK has no readable resources.arsc — is it really an APK?" >&2
    exit 1
fi

APP_IDS="$(printf '%s' "$ARSC" | LC_ALL=C grep -aoE '1:[0-9]+:android:[0-9a-fA-F]+' | sort -u)"
API_KEYS="$(printf '%s' "$ARSC" | LC_ALL=C grep -aoE 'AIza[0-9A-Za-z_-]{10,}' | sort -u)"

echo "APK        : $APK"
echo "app id     : ${APP_IDS:-<none>}"
echo "api key    : ${API_KEYS:+${API_KEYS:0:12}…}"

if [ -z "$APP_IDS" ] || [ -z "$API_KEYS" ]; then
    cat >&2 <<EOF

FAIL: no Firebase config inside this APK — push is DEAD in this build.

Every phone that installs it will fall back to whatever polling the app still
does; data-only pushes (new delivery, sync-now nudge, naya order …) can never
arrive. Do not host it.

Fix: put the owner's google-services.json at <shell>/app/google-services.json
(backup copy on live at ~/rider-signing/) and rebuild — gradle will now refuse
to produce this APK at all.
EOF
    exit 1
fi

# Optional exact cross-check against the config the build was supposed to use.
if [ -n "$CONFIG" ]; then
    if [ ! -f "$CONFIG" ]; then
        echo "FAIL: no such config: $CONFIG" >&2
        exit 2
    fi
    WANT_APP_ID="$(LC_ALL=C grep -o '"mobilesdk_app_id"[[:space:]]*:[[:space:]]*"[^"]*"' "$CONFIG" | head -1 | sed 's/.*"\([^"]*\)"$/\1/')"
    WANT_API_KEY="$(LC_ALL=C grep -o '"current_key"[[:space:]]*:[[:space:]]*"[^"]*"' "$CONFIG" | head -1 | sed 's/.*"\([^"]*\)"$/\1/')"

    if [ -z "$WANT_APP_ID" ] || [ -z "$WANT_API_KEY" ]; then
        echo "FAIL: could not read mobilesdk_app_id / current_key out of $CONFIG" >&2
        exit 1
    fi
    if ! printf '%s' "$APP_IDS" | grep -qxF "$WANT_APP_ID"; then
        echo "FAIL: APK carries app id '$APP_IDS' but $CONFIG says '$WANT_APP_ID' — wrong shell's config, or a stale build." >&2
        exit 1
    fi
    if ! printf '%s' "$API_KEYS" | grep -qxF "$WANT_API_KEY"; then
        echo "FAIL: APK api key does not match $CONFIG — stale build, rebuild after 'gradle clean'." >&2
        exit 1
    fi
    echo "config     : matches $CONFIG exactly"
fi

echo "OK: Firebase config is baked in — instant push will work in this build."
exit 0
