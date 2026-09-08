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
# NEW SHA: requires a published pos/all AppUpdate with created_at after the
# last deploy marker (unchanged).
#
# SAME SHA rerun (live HEAD == target SHA == marker commit): when the
# time-based count is zero, PASS only if the SHA-qualified published title
# (deploy/elaan.yml title + " [deploy <TARGET_SHA>]") still exists as a
# published pos/all AppUpdate. Does not re-date or duplicate.
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

# Published title used on live for this TARGET_SHA (CI --deploy-sha).
# Fail closed (return 1) if the SHA cannot be qualified — never fall back to
# the unqualified human title (that is Deploy Production #15).
elaan_published_title() {
  local RAW SHA QUALIFIED
  RAW=$(elaan_committed_title)
  SHA="${1:-}"
  RAW="${RAW%"${RAW##*[![:space:]]}"}"
  [ -n "$RAW" ] || return 0
  if [ -z "$SHA" ]; then
    printf '%s\n' "$RAW"
    return 0
  fi
  QUALIFIED=$(python3 "$ROOT/scripts/lib/elaan-deploy-title.py" qualify --title "$RAW" --sha "$SHA") \
    || return 1
  printf '%s\n' "$QUALIFIED"
}

elaan_freshness_check() {
  local LIVE_HEAD="$1"
  local TARGET_SHA="$2"

  step "Preflight: Elaan freshness check"
  if [ "${NO_ELAAN:-0}" = "1" ] || [ "${SKIP_ELAAN:-}" = "1" ] || [ "${SKIP_ELAAN:-}" = "true" ]; then
    echo "!!! ELAAN SKIPPED (emergency / --no-elaan / skip_elaan) !!!" >&2
    return 0
  fi

  local ELAAN_OUT ELAAN_RC
  ELAAN_OUT=$(timeout 30 ssh "${SSH_OPTS[@]}" "$HOST" \
    "LIVE_DIR='$LIVE_DIR' MARKER_FILE='$LIVE_DEPLOY_MARKER' bash -s" 2>&1 <<'EOFELAAN'
if [ ! -f "$MARKER_FILE" ]; then
  echo "ELAAN_NO_MARKER"
  exit 0
fi
MARKER=$(head -1 "$MARKER_FILE" 2>/dev/null || echo "")
MARKER_TS=$(echo "$MARKER" | cut -d'|' -f1)
MARKER_COMMIT=$(echo "$MARKER" | cut -d'|' -f2)
if ! echo "$MARKER_TS" | grep -qE '^[0-9]+$'; then
  echo "ELAAN_MARKER_PARSE_ERROR"
  exit 0
fi
SINCE=$(date -d "@$MARKER_TS" '+%Y-%m-%d %H:%M:%S' 2>/dev/null \
  || date -r "$MARKER_TS" '+%Y-%m-%d %H:%M:%S' 2>/dev/null \
  || echo "")
if [ -z "$SINCE" ]; then
  echo "ELAAN_MARKER_PARSE_ERROR"
  exit 0
fi
cd "$LIVE_DIR"
DB_HOST=$(grep '^DB_HOST=' .env | head -1 | sed 's/^DB_HOST=//' | tr -d "\"'")
[ -z "$DB_HOST" ] && DB_HOST=127.0.0.1
DB_USER=$(grep '^DB_USERNAME=' .env | head -1 | sed 's/^DB_USERNAME=//' | tr -d "\"'")
DB_PASS=$(grep '^DB_PASSWORD=' .env | head -1 | sed 's/^DB_PASSWORD=//' | tr -d "\"'")
DB_NAME=$(grep '^DB_DATABASE=' .env | head -1 | sed 's/^DB_DATABASE=//' | tr -d "\"'")
if [ -z "$DB_HOST" ] || [ -z "$DB_USER" ] || [ -z "$DB_NAME" ]; then
  echo "ELAAN_DB_CREDS_MISSING"
  exit 0
fi
COUNT=$(mysql -h"$DB_HOST" -u"$DB_USER" -p"$DB_PASS" "$DB_NAME" -sN \
  -e "SELECT COUNT(*) FROM app_updates WHERE audience IN ('pos','all') AND is_published=1 AND created_at > '$SINCE'" 2>&1)
MYSQL_RC=$?
if [ $MYSQL_RC -ne 0 ] || ! echo "$COUNT" | grep -qE '^[0-9]+$'; then
  echo "ELAAN_DB_ERROR"
  exit 0
fi
echo "ELAAN_COUNT=$COUNT MARKER_COMMIT=$MARKER_COMMIT SINCE=$SINCE"
EOFELAAN
  )
  ELAAN_RC=$?
  if [ $ELAAN_RC -ne 0 ] || [ -z "$ELAAN_OUT" ]; then
    fail "elaan check: SSH failed (rc=$ELAAN_RC) — create announcement or use skip_elaan/--no-elaan for emergencies only"
  fi

  case "$ELAAN_OUT" in
    ELAAN_NO_MARKER*|ELAAN_MARKER_PARSE_ERROR*|ELAAN_DB_CREDS_MISSING*|ELAAN_DB_ERROR*)
      fail "elaan check blocked deploy ($ELAAN_OUT) — create elaan or use skip_elaan/--no-elaan for emergencies"
      ;;
    *ELAAN_COUNT=*) ;;
    *)
      fail "elaan check: unexpected remote output: $ELAAN_OUT"
      ;;
  esac

  local COUNT MARKER_COMMIT
  COUNT=$(echo "$ELAAN_OUT" | grep -oE 'ELAAN_COUNT=[0-9]+' | cut -d= -f2)
  MARKER_COMMIT=$(echo "$ELAAN_OUT" | grep -oE 'MARKER_COMMIT=[^ ]+' | cut -d= -f2)
  COUNT="${COUNT:-0}"

  local TITLE="" TITLE_MATCH=0
  # Same-SHA evidence path only when time-fresh count is zero.
  if [ "$COUNT" -lt 1 ] 2>/dev/null \
    && [ -n "$LIVE_HEAD" ] && [ -n "$TARGET_SHA" ] \
    && [ "$LIVE_HEAD" = "$TARGET_SHA" ] && [ "$MARKER_COMMIT" = "$TARGET_SHA" ]; then
    TITLE=$(elaan_published_title "$TARGET_SHA") \
      || fail "elaan same-SHA title check: could not qualify deploy/elaan.yml title with TARGET_SHA"
    TITLE="${TITLE%"${TITLE##*[![:space:]]}"}"
    if [ -n "$TITLE" ]; then
      local TITLE_B64 TITLE_OUT TITLE_RC
      TITLE_B64=$(printf '%s' "$TITLE" | base64 -w0 2>/dev/null || printf '%s' "$TITLE" | base64)
      TITLE_OUT=$(timeout 30 ssh "${SSH_OPTS[@]}" "$HOST" \
        "LIVE_DIR='$LIVE_DIR' TITLE_B64='$TITLE_B64' bash -s" 2>&1 <<'EOFTITLE'
cd "$LIVE_DIR" || { echo "ELAAN_TITLE_DB_ERROR"; exit 0; }
DB_HOST=$(grep '^DB_HOST=' .env | head -1 | sed 's/^DB_HOST=//' | tr -d "\"'")
[ -z "$DB_HOST" ] && DB_HOST=127.0.0.1
DB_USER=$(grep '^DB_USERNAME=' .env | head -1 | sed 's/^DB_USERNAME=//' | tr -d "\"'")
DB_PASS=$(grep '^DB_PASSWORD=' .env | head -1 | sed 's/^DB_PASSWORD=//' | tr -d "\"'")
DB_NAME=$(grep '^DB_DATABASE=' .env | head -1 | sed 's/^DB_DATABASE=//' | tr -d "\"'")
if [ -z "$DB_HOST" ] || [ -z "$DB_USER" ] || [ -z "$DB_NAME" ]; then
  echo "ELAAN_TITLE_DB_ERROR"
  exit 0
fi
TITLE=$(printf '%s' "$TITLE_B64" | base64 -d 2>/dev/null || printf '%s' "$TITLE_B64" | base64 --decode 2>/dev/null || true)
if [ -z "$TITLE" ]; then
  echo "ELAAN_TITLE_DB_ERROR"
  exit 0
fi
export TITLE
# Escape for a MySQL single-quoted string literal (title only; never prints secrets).
TITLE_SQL=$(python3 -c "import os; s=os.environ['TITLE']; print(\"'\" + s.replace('\\\\', '\\\\\\\\').replace(\"'\", \"''\") + \"'\")")
COUNT=$(mysql -h"$DB_HOST" -u"$DB_USER" -p"$DB_PASS" "$DB_NAME" -sN \
  -e "SELECT COUNT(*) FROM app_updates WHERE audience IN ('pos','all') AND is_published=1 AND title = ${TITLE_SQL}" 2>&1)
MYSQL_RC=$?
if [ $MYSQL_RC -ne 0 ] || ! echo "$COUNT" | grep -qE '^[0-9]+$'; then
  echo "ELAAN_TITLE_DB_ERROR"
  exit 0
fi
echo "ELAAN_TITLE_COUNT=$COUNT"
EOFTITLE
      )
      TITLE_RC=$?
      if [ $TITLE_RC -ne 0 ] || [ -z "$TITLE_OUT" ]; then
        fail "elaan same-SHA title check: SSH failed (rc=$TITLE_RC)"
      fi
      case "$TITLE_OUT" in
        ELAAN_TITLE_DB_ERROR*)
          fail "elaan same-SHA title check blocked deploy ($TITLE_OUT)"
          ;;
        *ELAAN_TITLE_COUNT=*)
          TITLE_MATCH=$(echo "$TITLE_OUT" | grep -oE 'ELAAN_TITLE_COUNT=[0-9]+' | cut -d= -f2)
          TITLE_MATCH="${TITLE_MATCH:-0}"
          ;;
        *)
          fail "elaan same-SHA title check: unexpected remote output: $TITLE_OUT"
          ;;
      esac
    fi
  fi

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
      fail "elaan missing on same-SHA rerun — committed title not found as published pos/all AppUpdate (unrelated old announcements do not count)"
      ;;
    *)
      fail "elaan missing — create announcement then re-run, or skip_elaan/--no-elaan for emergencies"
      ;;
  esac
}
