#!/usr/bin/env bash
# One exact WSL verification command for Hotel / Guest House final delivery.
# Loopback + disposable DB only. Does not merge, deploy, or call FBR/DI.
set -euo pipefail
ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$ROOT"

php artisan test --filter=HotelGuestHouse
VIDEO_PIPELINE_ALLOW=1 php scripts/cloud-local-hotel-qa-seed.php
php artisan serve --host=127.0.0.1 --port=8000 >/tmp/hotel-final-serve.log 2>&1 &
SERVE_PID=$!
cleanup() { kill "$SERVE_PID" 2>/dev/null || true; }
trap cleanup EXIT
for i in 1 2 3 4 5 6 7 8 9 10; do
  curl -sf -o /dev/null http://127.0.0.1:8000/pos/login && break
  sleep 1
done
BASE_URL=http://127.0.0.1:8000 node scripts/cloud-local-hotel-smoke.mjs
php scripts/cloud-local-hotel-http-smoke.php
if command -v mysql >/dev/null && mysql -h127.0.0.1 -utaxnest_dev -ptaxnest_local_dev_only -e 'SELECT 1' >/dev/null 2>&1; then
  bash scripts/tests/hotel-mariadb-concurrency-check.sh
  bash scripts/tests/hotel-mariadb-isolation-board-check.sh
else
  echo "SKIP: local MariaDB hotel probes (taxnest_dev not reachable)"
fi
php artisan test
