#!/bin/bash
# Deploy-race proof test (Task 711): while a deploy is running, hit key pages
# every 2s and REQUIRE HTTP 200 on every single request. The auto-deploy
# window serves a 200 "Updating..." page, so anything other than 200
# (5xx, 4xx, 3xx, timeout) = FAIL.
#
# Usage:
#   bash scripts/deploy-race-check.sh                 # live, 60s
#   DURATION=120 bash scripts/deploy-race-check.sh    # live, 120s
#   BASE_URL=http://127.0.0.1:5000 bash scripts/deploy-race-check.sh
#
# Run it in one shell, trigger the deploy (push to origin main, or
# bash scripts/deploy-live.sh) in another, and read the verdict.
set -u

BASE_URL="${BASE_URL:-https://taxnest.pk}"
DURATION="${DURATION:-60}"
PATHS=("/pos/login" "/fbr-pos/login" "/")

END=$(( $(date +%s) + DURATION ))
FAIL=0; N=0
echo "Polling ${PATHS[*]} on $BASE_URL every 2s for ${DURATION}s ..."
while [ "$(date +%s)" -lt "$END" ]; do
  for P in "${PATHS[@]}"; do
    CODE=$(curl -s -o /dev/null -w '%{http_code}' --max-time 10 "$BASE_URL$P" || echo 000)
    N=$((N+1))
    if [ "$CODE" = "200" ]; then
      echo "$(date +%T) $P -> $CODE"
    else
      echo "$(date +%T) $P -> $CODE   <-- FAIL (only 200 allowed)"; FAIL=1
    fi
  done
  sleep 2
done
echo ""
if [ "$FAIL" -eq 1 ]; then
  echo "RACE CHECK FAILED: at least one non-200 response during the window ($N requests)."
  exit 1
fi
echo "RACE CHECK OK: all $N requests returned 200."
exit 0
