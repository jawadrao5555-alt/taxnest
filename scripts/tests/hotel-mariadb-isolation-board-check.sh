#!/usr/bin/env bash
# Hotel tenant/branch isolation + dashboard board against disposable local MariaDB.
# Does NOT change phpunit.xml (which remains sqlite :memory:).

set -euo pipefail
ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
cd "$ROOT"

SOCK="${HOTEL_MARIADB_SOCKET:-$ROOT/.local/mariadb/run/mysqld.sock}"
if [[ ! -S "$SOCK" ]]; then
  echo "BLOCKED: MariaDB socket missing at $SOCK" >&2
  exit 2
fi

if ! mysqladmin --socket="$SOCK" ping --silent 2>/dev/null; then
  echo "BLOCKED: MariaDB not responding on $SOCK" >&2
  exit 2
fi

export APP_ENV=local
export DB_CONNECTION=mysql
export DB_HOST=127.0.0.1
export DB_PORT=3307
export DB_DATABASE=taxnest_dev
export DB_USERNAME=taxnest_dev
export DB_PASSWORD=taxnest_local_dev_only
export DB_SOCKET="$SOCK"
export CACHE_STORE=array
export SESSION_DRIVER=array
export QUEUE_CONNECTION=sync

php "$ROOT/scripts/tests/hotel-mariadb-isolation-board-check.php"
exit $?
