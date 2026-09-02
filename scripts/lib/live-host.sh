#!/bin/bash
# THE live origin — one definition, sourced by every script that touches it.
#
# Before Sep 2026 each live script carried its own copy of the host, key and
# app path. When the origin moved to the Islamabad VPS, every one of those
# copies became a loaded gun: a deploy would SSH into the RETIRED cPanel box,
# pull, migrate and print "DEPLOY OK" while the real server received nothing.
# A wrong answer delivered confidently is worse than a failure, so the host
# now lives in exactly one file.
#
# Usage:  source "$(dirname "$0")/lib/live-host.sh"
# Then use: $LIVE_SSH_KEY $LIVE_SSH_HOST $LIVE_DIR $LIVE_PHP $LIVE_URL
#           live_ssh <cmd...>        — run a command on the origin
#           require_live_key         — fail early if the key is missing

# ------------------------------------------------------------------ identity
# Islamabad VPS (Nayatel), live since 1 Sep 2026.
#
# Addressed by IP on purpose. taxnest.com.pk is Cloudflare-proxied (its A
# record answers with Cloudflare's edge, which does not speak SSH), and any
# hostname we could use instead is one DNS edit away from silently pointing
# our deploy at a different machine. The IP is the machine.
LIVE_SSH_KEY="${LIVE_SSH_KEY:-/home/runner/workspace/.local/ssh/nayatel_vps_key}"
LIVE_SSH_USER="${LIVE_SSH_USER:-jawadrao5555}"
LIVE_SSH_IP="${LIVE_SSH_IP:-115.186.164.126}"
LIVE_SSH_HOST="${LIVE_SSH_USER}@${LIVE_SSH_IP}"
LIVE_SSH_PORT="${LIVE_SSH_PORT:-22}"

# ------------------------------------------------------------------- the app
LIVE_DIR="${LIVE_DIR:-/var/www/taxnest}"          # git checkout of origin/main
LIVE_PHP="${LIVE_PHP:-/usr/bin/php}"              # 8.4.x — NOT the old ea-php84
LIVE_WEB_GROUP="${LIVE_WEB_GROUP:-apache}"
LIVE_FPM_SERVICE="${LIVE_FPM_SERVICE:-php-fpm}"
LIVE_QUEUE_SERVICE="${LIVE_QUEUE_SERVICE:-taxnest-queue}"

# Canonical public address (matches APP_URL on the box). taxnest.com.pk still
# serves the same app through Cloudflare and must keep working, but anything
# we generate or verify uses the canonical one.
LIVE_URL="${LIVE_URL:-https://taxnest.pk}"
LIVE_ALT_URL="${LIVE_ALT_URL:-https://taxnest.com.pk}"

# ------------------------------------------------------------- state on live
# Small files the deploy toolchain owns. In the user's home, not in the app
# directory: they must survive a checkout being reset, and they must never be
# reachable over HTTP.
LIVE_STATE_DIR="${LIVE_STATE_DIR:-/home/${LIVE_SSH_USER}}"
LIVE_DEPLOY_LOCK="${LIVE_DEPLOY_LOCK:-${LIVE_STATE_DIR}/.taxnest-deploy.lock}"
LIVE_DEPLOY_MARKER="${LIVE_DEPLOY_MARKER:-${LIVE_STATE_DIR}/.taxnest-last-deploy-marker}"
LIVE_SETTINGS_BASE="${LIVE_SETTINGS_BASE:-${LIVE_STATE_DIR}/.taxnest-settings-before.json}"

# --------------------------------------------------------------------- ssh
LIVE_SSH_OPTS=(-i "$LIVE_SSH_KEY" -p "$LIVE_SSH_PORT" -o BatchMode=yes
               -o ConnectTimeout=15 -o StrictHostKeyChecking=accept-new)

live_ssh() { timeout "${LIVE_SSH_TIMEOUT:-120}" ssh "${LIVE_SSH_OPTS[@]}" "$LIVE_SSH_HOST" "$@"; }

require_live_key() {
  [ -f "$LIVE_SSH_KEY" ] || {
    echo "SSH key not found at $LIVE_SSH_KEY — cannot reach the live origin." >&2
    return 1
  }
}

# The ONE address these scripts are allowed to mutate. An allow-list, not a
# deny-list: blacklisting the old host's name only catches the spelling we
# thought of, and every override path (LIVE_SSH_IP=..., a stale checkout, a
# copy-pasted script) walks straight past it. Anything not on this list is
# refused, so a wrong target fails loudly instead of deploying somewhere.
#
# The old cPanel box is retired but still powered on (it serves mail), which is
# exactly why this matters: it answers, it accepts a git pull, and none of it
# reaches a single shop.
LIVE_APPROVED_IPS="${LIVE_APPROVED_IPS:-115.186.164.126}"

live_host_assert_not_retired() {
  local ip
  for ip in $LIVE_APPROVED_IPS; do
    [ "$LIVE_SSH_IP" = "$ip" ] && return 0
  done
  echo "REFUSING TO RUN: $LIVE_SSH_IP is not an approved live origin." >&2
  echo "Approved: $LIVE_APPROVED_IPS (the Islamabad VPS)." >&2
  echo "The retired cPanel box still answers SSH and would fake a successful" >&2
  echo "deploy. If the origin genuinely moved, update scripts/lib/live-host.sh." >&2
  return 1
}
