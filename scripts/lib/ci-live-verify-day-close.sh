#!/usr/bin/env bash
# Sourced by ci-live-verify.sh. CURL carries its existing private QA cookie jar.
# Retry only this read-only GET. Never retry login POSTs, alter QA roles, change
# company settings, seed bills or turn a persistent missing control into PASS.
verify_day_close() {
  local attempt code transport reason
  for attempt in 1 2 3; do
    code=$("${CURL[@]}" -o "$TMPD/page.html" -w '%{http_code}' "${LIVE_URL}/pos/day-close")
    transport=$?
    if [ "$transport" -ne 0 ]; then
      reason="transport_error"
    elif [ "$code" != "200" ]; then
      reason="http_error"
    else
      reason=$(python3 "$ROOT/scripts/lib/ci-live-verify-day-close.py" "$TMPD/page.html") || reason="classifier_error"
      if [ "$reason" = "ok" ]; then
        ok "auto day-close toggle and report document on /pos/day-close (attempt $attempt)"
        return 0
      fi
    fi

    # Reasons are fixed enums. No body, headers, tokens, user names or page URLs
    # from the response enter the logs. Keep auth failures immediate.
    echo "    Day-close probe: attempt=$attempt http=${code:-000} reason=$reason"
    if [ "$reason" = "login_document" ] || [ "$code" = "401" ] || [ "$code" = "403" ] || [ "$code" = "419" ]; then
      break
    fi
    [ "$attempt" -lt 3 ] && sleep 1
  done
  bad "/pos/day-close verification failed after attempt $attempt (http=${code:-000}, reason=$reason)"
  return 1
}
