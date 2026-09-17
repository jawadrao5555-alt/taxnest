#!/usr/bin/env bash
# Install Chromium into the explicit disposable cache used by the isolated
# browser fixture. This must not depend on the install process's HOME.
set -Eeuo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
STATE_INPUT="${RC_BROWSER_STATE_ROOT:-}"
STATE_BASE="$(realpath -m -- "$STATE_INPUT")"
STATE_BASE_RE='^/tmp/taxnest-rc-browser-[0-9]+(-[A-Za-z0-9_.-]+)?$'
CACHE_ROOT="$STATE_BASE/playwright-cache"

[[ -n "$STATE_INPUT" && "$STATE_INPUT" == /* && "$STATE_BASE" =~ $STATE_BASE_RE ]] ||
    { echo 'rc-playwright-install: RC_BROWSER_STATE_ROOT must be the exact disposable /tmp browser state root' >&2; exit 2; }
[[ ! -L "$STATE_INPUT" && ! -L "$CACHE_ROOT" ]] ||
    { echo 'rc-playwright-install: browser state/cache symlinks are not allowed' >&2; exit 2; }

mkdir -p "$CACHE_ROOT"
chmod 700 "$STATE_BASE" "$CACHE_ROOT"
cd "$ROOT"
PLAYWRIGHT_BROWSERS_PATH="$CACHE_ROOT" \
    bash scripts/npm-bootstrap-pinned.sh exec -- playwright install --with-deps chromium
PLAYWRIGHT_BROWSERS_PATH="$CACHE_ROOT" node scripts/lib/playwright-cache.mjs "$CACHE_ROOT"
printf 'PLAYWRIGHT_BROWSERS_PATH=%s\n' "$CACHE_ROOT"