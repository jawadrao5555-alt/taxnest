#!/bin/bash
# Shared Elaan freshness gate for CI and manual deploy.
#
# Source after scripts/lib/live-host.sh. Caller must define fail() and step().
# Required globals: ROOT, SSH_OPTS, HOST, LIVE_DIR, LIVE_DEPLOY_MARKER
# Optional: NO_ELAAN, SKIP_ELAAN
#
# Usage:
#   elaan_freshness_check <live_head_before> <target_sha>
#
# NEW SHA: requires a published exact-scope AppUpdate with created_at after the
# last deploy marker, exact TARGET_SHA receipt and committed audience/categories.
#
# SAME SHA rerun (live HEAD == target SHA == marker commit): when the
# time-based count is zero, PASS only if the internal deployment_key matches
# TARGET_SHA. The customer-visible title never contains release provenance.
# Unrelated old announcements (including the same human title from an older
# SHA) do not satisfy the gate. skip_elaan is not used.

elaan_committed_spec_path() {
  if [ -f "$ROOT/deploy/elaan.yml" ]; then
    printf '%s\n' "$ROOT/deploy/elaan.yml"
  elif [ -f "$ROOT/deploy/elaan.yaml" ]; then
    printf '%s\n' "$ROOT/deploy/elaan.yaml"
  fi
}

elaan_committed_title() {
  local SPEC
  SPEC=$(elaan_committed_spec_path)
  [ -n "$SPEC" ] || return 0
  python3 "$ROOT/scripts/lib/elaan-spec-parse.py" "$SPEC" 2>/dev/null \
    | python3 -c 'import json,sys; print(json.load(sys.stdin).get("title",""))' \
    || true
}

elaan_published_title() {
  local RAW
  RAW=$(elaan_committed_title)
  RAW="${RAW%"${RAW##*[![:space:]]}"}"
  printf '%s\n' "$RAW"
}

elaan_freshness_check() {
  local LIVE_HEAD="$1"
  local TARGET_SHA="$2"

  step "Preflight: Elaan freshness check"
  if [ "${NO_ELAAN:-0}" = "1" ] || [ "${SKIP_ELAAN:-}" = "1" ] || [ "${SKIP_ELAAN:-}" = "true" ]; then
    echo "!!! ELAAN SKIPPED (emergency / --no-elaan / skip_elaan) !!!" >&2
    return 0
  fi

  local SPEC SCOPE_SQL SCOPE_B64
  SPEC=$(elaan_committed_spec_path)
  [ -n "$SPEC" ] || fail "elaan check: committed announcement specification missing"
  SCOPE_SQL=$(python3 "$ROOT/scripts/lib/elaan-scope-sql.py" "$SPEC" "$TARGET_SHA") \
    || fail "elaan check: invalid committed announcement scope or SHA"
  SCOPE_B64=$(printf '%s' "$SCOPE_SQL" | base64 | tr -d '\n')

  local ELAAN_OUT ELAAN_RC PAYLOAD
  PAYLOAD=$(base64 < "$ROOT/scripts/lib/elaan-receipt.php" | tr -d '\n')
  ELAAN_OUT=$(timeout 30 ssh "${SSH_OPTS[@]}" "$HOST" \
    "LIVE_DIR='$LIVE_DIR' LIVE_PHP='${LIVE_PHP:-/usr/bin/php}' MARKER_FILE='$LIVE_DEPLOY_MARKER' TARGET_SHA='$TARGET_SHA' SCOPE_B64='$SCOPE_B64' PAYLOAD='$PAYLOAD' bash -s" 2>&1 <<'EOFELAAN'
set -eu
SCRIPT=$(mktemp /tmp/taxnest-elaan-receipt.XXXXXX.php)
trap 'rm -f "$SCRIPT"' EXIT
printf '%s' "$PAYLOAD" | base64 -d > "$SCRIPT"
"$LIVE_PHP" "$SCRIPT"
EOFELAAN
  )
  ELAAN_RC=$?
  [ "$ELAAN_RC" -eq 0 ] && [ -n "$ELAAN_OUT" ] || fail "elaan receipt check failed (rc=$ELAAN_RC): $ELAAN_OUT"
  # Accept exactly one protocol line; PHP warnings/transport noise never count.
  [[ "$ELAAN_OUT" =~ ^ELAAN_COUNT=([0-9]+)\ MARKER_COMMIT=([0-9a-f]{40})\ RECEIPT_COUNT=([0-9]+)$ ]] \
    || fail "elaan receipt check blocked deploy: $ELAAN_OUT"
  local COUNT="${BASH_REMATCH[1]}" MARKER_COMMIT="${BASH_REMATCH[2]}"
  local TITLE_MATCH="${BASH_REMATCH[3]}" TITLE
  TITLE=$(elaan_published_title)

  local EVAL_JSON EVAL_OUT EVAL_RC
  EVAL_JSON=$(python3 -c 'import json,sys; print(json.dumps({
    "live_head": sys.argv[1],
    "target_sha": sys.argv[2],
    "marker_commit": sys.argv[3],
    "time_fresh_count": int(sys.argv[4]),
    "committed_title": sys.argv[5] if sys.argv[5] != "" else None,
    "title_match_count": int(sys.argv[6]),
  }))' "$LIVE_HEAD" "$TARGET_SHA" "${MARKER_COMMIT:-}" "$COUNT" "${TITLE:-}" "${TITLE_MATCH:-0}")
  EVAL_OUT=$(python3 "$ROOT/scripts/lib/elaan-freshness-eval.py" "$EVAL_JSON" 2>/dev/null)
  EVAL_RC=$?

  case "$EVAL_OUT" in
    *time_fresh*)
      echo "Elaan check: PASSED ($COUNT published update(s) since last deploy)."
      return 0
      ;;
    *same_sha_original_title*)
      echo "Elaan check: PASSED (same-SHA rerun — original committed title still published; marker commit matches TARGET_SHA; not re-dated)."
      return 0
      ;;
  esac

  if [ $EVAL_RC -eq 0 ]; then
    echo "Elaan check: PASSED."
    return 0
  fi

  case "$EVAL_OUT" in
    *same_sha_no_committed_title*)
      fail "elaan missing on same-SHA rerun — deploy/elaan.yml title required to prove the original announcement still exists (do not use skip_elaan as the normal fix)"
      ;;
    *same_sha_title_missing*)
      fail "elaan missing on same-SHA rerun — committed title not found as published exact-scope AppUpdate (unrelated old announcements do not count)"
      ;;
    *)
      fail "elaan missing — create announcement then re-run, or skip_elaan/--no-elaan for emergencies"
      ;;
  esac
}
