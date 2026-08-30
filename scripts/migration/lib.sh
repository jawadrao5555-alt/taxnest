#!/usr/bin/env bash
# Shared configuration and helpers for the TaxNest server migration.
#
# Nothing in here touches the live server. Source it, do not run it.
#
# Override any value by creating .local/migration.env (gitignored):
#   DST_HOST=203.0.113.10
#   DST_USER=root
#   DST_APP=/var/www/taxnest
#   DST_DB_NAME=taxnest
#   DST_DB_USER=taxnest

set -uo pipefail

MIG_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
REPO_ROOT="$(cd "$MIG_DIR/../.." && pwd)"
WORK="$REPO_ROOT/.local/migration"
mkdir -p "$WORK"

[ -f "$REPO_ROOT/.local/migration.env" ] && . "$REPO_ROOT/.local/migration.env"

# ---------------------------------------------------------------- source
# The current live server (shared cPanel, Phoenix USA).
SRC_KEY="${SRC_KEY:-$REPO_ROOT/.local/ssh/cpanel_deploy_key}"
SRC_HOST="${SRC_HOST:-cpanel.taxnest.com.pk}"   # never taxnest.com.pk: proxied by Cloudflare, port 22 dead
SRC_USER="${SRC_USER:-taxnestc}"
SRC_PORT="${SRC_PORT:-22}"
SRC_APP="${SRC_APP:-/home/taxnestc/public_html}"
SRC_PHP="${SRC_PHP:-/usr/local/bin/ea-php84}"
SRC_RSYNC="${SRC_RSYNC:-/home/taxnestc/bin/rsync}"  # self-compiled; cPanel ships none

# ----------------------------------------------------------- destination
# The new VPS. Empty until .local/migration.env is filled in.
DST_KEY="${DST_KEY:-$REPO_ROOT/.local/ssh/newserver_key}"
DST_HOST="${DST_HOST:-}"
DST_USER="${DST_USER:-root}"
DST_PORT="${DST_PORT:-22}"
DST_APP="${DST_APP:-/var/www/taxnest}"
DST_PHP="${DST_PHP:-/usr/bin/php}"
DST_RSYNC="${DST_RSYNC:-rsync}"
DST_DB_NAME="${DST_DB_NAME:-taxnest}"
DST_DB_USER="${DST_DB_USER:-taxnest}"

# -------------------------------------------------------------- payload
# Everything the app owns that git does NOT carry. Order matters only for
# readability. Paths are relative to the app root on both hosts.
PAYLOAD_DIRS=(
  "public/downloads"              # 45 APKs + desktop agent zip — customers download these
  "public/videos"                 # tutorial videos
  "public/annex-invoices"
  "storage/app/public"            # logos, product images, AI photos (symlinked as public/storage)
  "storage/app/private"           # invoice PDFs, audit packs, payment proofs, invoice zips
  "storage/app/firebase"
  "storage/app/import-holds"
  "storage/app/mpdf"
)

# storage/app/private/invoice-pdfs (271 MB) is a regenerable cache, but it is
# copied anyway: an opt-out would have to be mirrored in the verification pass
# too, and a verifier that skips part of the payload is worth less than the
# bandwidth it saves.

# Deliberately NOT copied: storage/framework/{cache,sessions,views} (rebuilt),
# storage/logs, storage/app/tmp-bulk-pdf (scratch), vendor/, node_modules/,
# ~/repositories (2.2 GB of stale clones), .cagefs, .composer.

# -------------------------------------------------------------- helpers
_c() { [ -t 1 ] && printf '\033[%sm' "$1" || true; }
say()  { _c "1;36"; printf '==> %s\n' "$*"; _c 0; }
ok()   { _c "1;32"; printf '  OK   %s\n' "$*"; _c 0; }
warn() { _c "1;33"; printf '  WARN %s\n' "$*"; _c 0; }
bad()  { _c "1;31"; printf '  FAIL %s\n' "$*"; _c 0; }
die()  { bad "$*"; exit 1; }

# accept-new, not no: the key is pinned on first contact and verified on every
# later connection, so a substituted host cannot quietly receive the database
# stream or the payload half way through the migration.
src_ssh() {
  ssh -i "$SRC_KEY" -p "$SRC_PORT" -o BatchMode=yes -o StrictHostKeyChecking=accept-new \
      -o ConnectTimeout=20 "$SRC_USER@$SRC_HOST" "$@"
}

dst_ssh() {
  [ -n "$DST_HOST" ] || die "DST_HOST is not set. Create .local/migration.env first (see README)."
  ssh -i "$DST_KEY" -p "$DST_PORT" -o BatchMode=yes -o StrictHostKeyChecking=accept-new \
      -o ConnectTimeout=20 "$DST_USER@$DST_HOST" "$@"
}

need_dst() {
  [ -n "$DST_HOST" ] || die "DST_HOST is not set. Create .local/migration.env first (see README)."
}

# The helper scripts that have to live ON the servers. Staged outside the app
# root so nothing in the git checkout is ever disturbed.
TOOL_FILES=("manifest.sh" "dbstat.php" "mycnf.php" "dumpwrap.sh" "syncrun.sh")

# stage_tools src|dst
stage_tools() {
  local key host port user files=()
  case "$1" in
    src) key="$SRC_KEY"; host="$SRC_HOST"; port="$SRC_PORT"; user="$SRC_USER" ;;
    dst) need_dst; key="$DST_KEY"; host="$DST_HOST"; port="$DST_PORT"; user="$DST_USER" ;;
    *)   die "stage_tools: expected 'src' or 'dst'" ;;
  esac
  local f
  for f in "${TOOL_FILES[@]}"; do files+=("$MIG_DIR/$f"); done
  ssh -i "$key" -p "$port" -o BatchMode=yes -o StrictHostKeyChecking=accept-new \
      "$user@$host" "mkdir -p ~/migration-tools" || return 1
  scp -q -i "$key" -P "$port" -o StrictHostKeyChecking=accept-new \
      "${files[@]}" "$user@$host:migration-tools/" || return 1
  ssh -i "$key" -p "$port" -o BatchMode=yes -o StrictHostKeyChecking=accept-new \
      "$user@$host" "chmod +x ~/migration-tools/*.sh" || return 1
}

# Read a single key out of the live .env without ever printing the value.
# Live .env values are quoted — trim them (see cpanel-deployment notes).
src_env() {
  src_ssh "grep -m1 '^$1=' $SRC_APP/.env | cut -d= -f2- | sed -e \"s/^[\\\"']//\" -e \"s/[\\\"']\$//\""
}

# Human-readable byte count.
human() { numfmt --to=iec-i --suffix=B "$1" 2>/dev/null || echo "$1 bytes"; }

STAMP="$(date +%Y%m%d-%H%M%S)"
