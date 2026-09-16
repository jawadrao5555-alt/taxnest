#!/usr/bin/env bash
# Reproducible wrapper for the isolated native DI proof. The lab creates a
# unique disposable database and never reads application .env.
set -Eeuo pipefail
ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
exec "$ROOT/scripts/rc-mariadb-migration-lab.sh" --di "$@"