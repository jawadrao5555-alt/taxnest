#!/bin/bash
# Elaan (What's New) insert helper — Task 999
# Creates a published AppUpdate row so POS/FBR users see the bell badge + popup
# after a deploy. Run this BEFORE (or after) each deploy.
#
# Usage (live — default):
#   bash scripts/elaan-insert.sh \
#     --title "Naya update — August 2026" \
#     --point "Pehla kaam theek hua" \
#     --point "Doosra feature add kiya" \
#     [--audience pos|fbr_pos|all]   (default: pos) \
#     [--category restaurant] [--category pharmacy] ...   (repeatable; none = all shops)
#
# Usage (dev local DB):
#   bash scripts/elaan-insert.sh --dev \
#     --title "Test elaan" \
#     --point "Test point 1"
#
# Rules (from .agents/memory/pos-whats-new-updates.md):
#   - points MUST be a PHP array on the way in (never a pre-encoded JSON string).
#   - AppUpdate model's setPointsAttribute handles encoding — just pass the array.
#   - audience 'pos' = PRA POS, 'fbr_pos' = FBR POS, 'all' = both panels.
#   - --category narrows the elaan to shops on those business categories
#     (Task 1585). No --category = every shop of that audience. Unknown keys
#     are rejected by the model normalizer, so the row would silently widen —
#     the PHP side below fails loudly instead.
#   - Editing an existing row does NOT reset seen rows — always create a NEW row.
#
# After running, verify the row appeared: check /admin/app-updates on live.

set -uo pipefail
cd "$(dirname "$0")/.."

# shellcheck source=scripts/lib/live-host.sh
source "$(dirname "$0")/lib/live-host.sh"
live_host_assert_not_retired || exit 1
KEY="$LIVE_SSH_KEY"
HOST="$LIVE_SSH_HOST"
SSH_OPTS=("${LIVE_SSH_OPTS[@]}")

fail() { echo ""; echo "ELAAN INSERT FAILED: $*" >&2; exit 1; }

# ---------------------------------------------------------- Parse args
TITLE=""
AUDIENCE="pos"
DEV=0
declare -a POINTS=()
declare -a CATEGORIES=()

while [ $# -gt 0 ]; do
  case "$1" in
    --title)    shift; TITLE="$1" ;;
    --point)    shift; POINTS+=("$1") ;;
    --audience) shift; AUDIENCE="$1" ;;
    --category) shift; CATEGORIES+=("$1") ;;
    --dev)      DEV=1 ;;
    *) echo "Unknown arg: $1" >&2; exit 1 ;;
  esac
  shift
done

[ -n "$TITLE" ] || fail "--title is required"
[ ${#POINTS[@]} -gt 0 ] || fail "at least one --point is required"
case "$AUDIENCE" in pos|fbr_pos|all) ;; *) fail "--audience must be pos, fbr_pos, or all" ;; esac

echo ""
echo "==> Elaan insert: \"$TITLE\" (audience=$AUDIENCE, ${#POINTS[@]} point(s))"
if [ ${#CATEGORIES[@]} -gt 0 ]; then echo "    categories: ${CATEGORIES[*]}"; else echo "    categories: (all shops)"; fi
for P in "${POINTS[@]}"; do echo "    • $P"; done
echo ""

# ---------------------------------------------------------- Build PHP points array literal
# Each point is single-quoted for PHP. Single quotes inside the text are escaped.
PHP_POINTS_ARRAY="array("
FIRST=1
for P in "${POINTS[@]}"; do
  ESCAPED=$(printf '%s' "$P" | sed "s/'/\\\\'/g")
  if [ $FIRST -eq 1 ]; then
    PHP_POINTS_ARRAY="${PHP_POINTS_ARRAY}'${ESCAPED}'"
    FIRST=0
  else
    PHP_POINTS_ARRAY="${PHP_POINTS_ARRAY},'${ESCAPED}'"
  fi
done
PHP_POINTS_ARRAY="${PHP_POINTS_ARRAY})"

PHP_CATS_ARRAY="array("
FIRST=1
for C in ${CATEGORIES[@]+"${CATEGORIES[@]}"}; do
  ESCAPED=$(printf '%s' "$C" | sed "s/'/\\\\'/g")
  if [ $FIRST -eq 1 ]; then PHP_CATS_ARRAY="${PHP_CATS_ARRAY}'${ESCAPED}'"; FIRST=0
  else PHP_CATS_ARRAY="${PHP_CATS_ARRAY},'${ESCAPED}'"; fi
done
PHP_CATS_ARRAY="${PHP_CATS_ARRAY})"

TITLE_ESCAPED=$(printf '%s' "$TITLE" | sed "s/'/\\\\'/g")
AUDIENCE_ESCAPED=$(printf '%s' "$AUDIENCE" | sed "s/'/\\\\'/g")

# ---------------------------------------------------------- PHP bootstrap script
# Runs identically on live (hardcoded LIVE_DIR paths) or dev (relative paths from CWD).
# GOTCHA: on live the script runs from /tmp so __DIR__ is wrong — we hardcode the path.
# In dev mode the workspace root is passed as $argv[1] so the script finds vendor/.
read -r -d '' PHP_SCRIPT <<PHPEOF || true
<?php
// Elaan insert helper — Task 999
// Base path: argv[1] (dev, passed by shell) > hardcoded live path > __DIR__ fallback.
\$base = (!empty(\$argv[1]) && is_dir(\$argv[1].'/vendor'))
    ? \$argv[1]
    : (is_dir('$LIVE_DIR/vendor')
        ? '$LIVE_DIR'
        : realpath(__DIR__));

require \$base . '/vendor/autoload.php';
\$app = require \$base . '/bootstrap/app.php';
// Use the console kernel for CLI bootstrapping — the HTTP kernel calls
// URL::forceScheme() in AppServiceProvider which requires a bound HTTP
// request and throws a TypeError in CLI context.
\$kernel = \$app->make(Illuminate\Contracts\Console\Kernel::class);
\$kernel->bootstrap();

\$points = $PHP_POINTS_ARRAY;
// Validate: must be a non-empty array of non-blank strings (model rule).
\$points = array_values(array_filter(array_map('trim', \$points), fn(\$p) => \$p !== ''));
if (empty(\$points)) {
    fwrite(STDERR, "ERROR: all points are blank after trim.\n");
    exit(1);
}

// Task 1585: category targeting. Every key must resolve to a real preset —
// a typo would otherwise be dropped and the elaan would go to EVERY shop.
\$cats = $PHP_CATS_ARRAY;
\$cats = array_values(array_filter(array_map('trim', \$cats), fn(\$c) => \$c !== ''));
foreach (\$cats as \$c) {
    if (! App\Services\PosFeatureService::isKnownCategory(\$c)) {
        fwrite(STDERR, "ERROR: unknown business category '" . \$c . "' — elaan NOT created.\n");
        exit(1);
    }
}
\$extra = (\$cats && Illuminate\Support\Facades\Schema::hasColumn('app_updates', 'target_categories'))
    ? ['target_categories' => \$cats] : [];

\$row = App\Models\AppUpdate::create([
    'title'        => '$TITLE_ESCAPED',
    'points'       => \$points,   // PHP array — model setter handles JSON encode
    'audience'     => '$AUDIENCE_ESCAPED',
    'is_published' => true,
    'created_by'   => null,
] + \$extra);

echo "ELAAN_INSERTED id=" . \$row->id . " title=" . json_encode(\$row->title) . "\n";
exit(0);
PHPEOF

# ---------------------------------------------------------- Run
if [ "$DEV" = "1" ]; then
  # Dev: run directly with the dev PHP (strips PG env vars to hit MySQL Staging).
  # Pass the workspace root as argv[1] so the PHP script finds vendor/autoload.php
  # even though the temp file lives in /tmp (where __DIR__ would resolve to /tmp).
  echo "Running on dev DB (local artisan bootstrap)..."
  TMP_SCRIPT=$(mktemp /tmp/elaan_insert_XXXXXX.php)
  printf '%s' "$PHP_SCRIPT" > "$TMP_SCRIPT"
  WORKSPACE_ROOT=$(pwd)
  DEV_OUT=$(env -u DATABASE_URL -u DB_CONNECTION -u PGHOST -u PGPORT -u PGUSER -u PGPASSWORD -u PGDATABASE \
    php "$TMP_SCRIPT" "$WORKSPACE_ROOT" 2>&1)
  DEV_RC=$?
  rm -f "$TMP_SCRIPT"
  echo "$DEV_OUT"
  [ $DEV_RC -eq 0 ] || fail "PHP bootstrap failed on dev (exit $DEV_RC)"
  echo "$DEV_OUT" | grep -q "ELAAN_INSERTED" \
    || fail "PHP ran but ELAAN_INSERTED marker missing — row was NOT created; check output above"
  CREATED_ID=$(echo "$DEV_OUT" | grep -oE 'id=[0-9]+' | head -1 | cut -d= -f2)
  echo ""
  echo "---------------------------------------------------------------"
  echo "ELAAN OK (dev): AppUpdate row #${CREATED_ID} created in dev DB."
  echo "                Audience: $AUDIENCE  |  Points: ${#POINTS[@]}"
  echo "                Verify: /admin/app-updates on the dev server."
  echo "---------------------------------------------------------------"
else
  # Live: stream the PHP script to live via SSH and run it there.
  [ -f "$KEY" ] || fail "SSH key not found at $KEY — can only run on live from the workspace"
  echo "Streaming bootstrap script to live server..."
  LIVE_OUT=$(printf '%s' "$PHP_SCRIPT" \
    | timeout 60 ssh "${SSH_OPTS[@]}" "$HOST" \
        "cat > /tmp/elaan_insert_$$.php && $LIVE_PHP /tmp/elaan_insert_$$.php; RC=\$?; rm -f /tmp/elaan_insert_$$.php; exit \$RC" \
    2>&1) || { echo "$LIVE_OUT" >&2; fail "PHP bootstrap failed on live"; }
  echo "$LIVE_OUT"
  echo "$LIVE_OUT" | grep -q "ELAAN_INSERTED" \
    || fail "PHP ran but ELAAN_INSERTED marker missing — check output above"
  CREATED_ID=$(echo "$LIVE_OUT" | grep -oE 'id=[0-9]+' | head -1 | cut -d= -f2)
  echo ""
  echo "---------------------------------------------------------------"
  echo "ELAAN OK: AppUpdate row #${CREATED_ID} created on live."
  echo "          Audience: $AUDIENCE  |  Points: ${#POINTS[@]}"
  echo "          POS bell badge + popup will appear on next page load."
  echo "          Verify: https://taxnest.pk/admin/app-updates"
  echo "---------------------------------------------------------------"
fi
