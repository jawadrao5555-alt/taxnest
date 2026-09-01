#!/usr/bin/env bash
# Measure what actually decides the hosting choice: how long this machine takes
# to reach the Pakistani regulators, and how often it fails to reach them.
#
#   bash 07-latency-probe.sh [attempts]
#
# Run it on the CURRENT live server for a baseline, then on any candidate
# (a Dubai VPS, a Karachi VPS, a free trial box) and compare. It needs nothing
# but bash and curl, so it can be pasted onto a trial server with no setup.
#
# Read-only: it opens TLS connections and reads nothing but timings. It sends
# no credentials and submits no data to the regulators.
#
# Why not ping: ICMP is often deprioritised or dropped entirely, and it cannot
# see TLS handshake cost — which is where a distant server really loses. These
# numbers are full HTTPS connect times, the same work a real submission does.

set -uo pipefail

ATTEMPTS="${1:-8}"
TIMEOUT=8

# The endpoints our money actually depends on, plus one control.
TARGETS=(
  "PRA IMS (POS)|https://ims.pral.com.pk"
  "FBR gateway (Digital Invoice)|https://gw.fbr.gov.pk"
  "our own site (control)|https://taxnest.pk"
)

command -v curl >/dev/null 2>&1 || { echo "curl missing — install curl and re-run" >&2; exit 2; }

echo "Latency probe — $(date -u '+%Y-%m-%d %H:%M:%S') UTC"
echo "host: $(hostname 2>/dev/null || echo '?')   attempts per target: $ATTEMPTS   timeout: ${TIMEOUT}s"
echo

# Median of a whitespace-separated list of milliseconds.
median() {
  local n vals
  vals=$(printf '%s\n' "$@" | LC_ALL=C sort -n)
  n=$#
  [ "$n" -eq 0 ] && { echo "-"; return; }
  printf '%s\n' "$vals" | awk -v n="$n" 'NR==int((n+1)/2){print; exit}'
}

printf '%-32s %8s %8s %8s %8s %10s\n' "target" "ok" "fail" "median" "worst" "handshake"
printf '%-32s %8s %8s %8s %8s %10s\n' "--------------------------------" "-----" "-----" "------" "-----" "---------"

OVERALL_FAIL=0
for entry in "${TARGETS[@]}"; do
  LABEL="${entry%%|*}"
  URL="${entry##*|}"
  ok=0; fail=0; totals=(); handshakes=()

  for _ in $(seq 1 "$ATTEMPTS"); do
    # %{time_connect}   = TCP handshake done
    # %{time_appconnect}= TLS handshake done (0 on plain http)
    # %{time_total}     = everything, including the response
    out=$(curl -s -o /dev/null --max-time "$TIMEOUT" \
            -w '%{time_connect} %{time_appconnect} %{time_total}' \
            "$URL" 2>/dev/null)
    rc=$?
    if [ $rc -ne 0 ] || [ -z "$out" ]; then
      fail=$((fail + 1))
      continue
    fi
    ok=$((ok + 1))
    # Seconds with decimals -> whole milliseconds, without needing bc.
    tot=$(awk '{printf "%d", $3 * 1000}' <<<"$out")
    tls=$(awk '{printf "%d", ($2 > 0 ? $2 - $1 : 0) * 1000}' <<<"$out")
    totals+=("$tot"); handshakes+=("$tls")
  done

  OVERALL_FAIL=$((OVERALL_FAIL + fail))
  if [ "$ok" -gt 0 ]; then
    med=$(median "${totals[@]}")
    worst=$(printf '%s\n' "${totals[@]}" | LC_ALL=C sort -n | tail -1)
    hmed=$(median "${handshakes[@]}")
    printf '%-32s %8s %8s %7sms %7sms %9sms\n' "$LABEL" "$ok" "$fail" "$med" "$worst" "$hmed"
  else
    printf '%-32s %8s %8s %8s %8s %10s\n' "$LABEL" "$ok" "$fail" "—" "—" "—"
  fi
done

echo
echo "How to read this:"
echo "  median    — the normal round trip. Under ~150ms is good, over ~800ms is the current problem."
echo "  handshake — TLS setup alone. This is pure distance; no amount of CPU or RAM reduces it."
echo "  fail      — timeouts and refused connections. This is the one that loses a shop's bill."
echo
[ "$OVERALL_FAIL" -gt 0 ] \
  && echo "RESULT: $OVERALL_FAIL failed connection(s) out of $((ATTEMPTS * ${#TARGETS[@]}))." \
  || echo "RESULT: no failed connections."
