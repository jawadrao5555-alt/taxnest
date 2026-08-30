#!/usr/bin/env bash
# Dump the app's database to stdout. Runs ON whichever server owns the .env.
#
#   dumpwrap.sh <app-root> [<php-binary>]
#
# A wrapper rather than an inline ssh one-liner on purpose: building this
# command through two layers of shell quoting is how the password got mangled
# into an "access denied" the first time. Here the escaping happens once, in a
# real file, and can be tested.
#
# Read-only against the database: --single-transaction takes a consistent
# snapshot of InnoDB tables without locking, so shops can keep billing.

set -uo pipefail

ROOT="${1:?usage: dumpwrap.sh <app-root> [php-binary]}"
PHP="${2:-php}"
HERE="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"

[ -d "$ROOT" ] || { echo "dumpwrap: no such app root: $ROOT" >&2; exit 2; }

CNF="$(mktemp "${TMPDIR:-/tmp}/.taxnest-mycnf.XXXXXX")" || exit 2
chmod 600 "$CNF"
trap 'rm -f "$CNF"' EXIT INT TERM

"$PHP" -d display_errors=1 "$HERE/mycnf.php" "$ROOT" "$CNF" || exit 3
[ -s "$CNF" ] || { echo "dumpwrap: credentials file is empty" >&2; exit 3; }

# The database name is not a secret and contains no shell-special characters.
DB="$(grep -m1 '^DB_DATABASE=' "$ROOT/.env" | cut -d= -f2- | tr -d '"'"'"' \r')"
[ -n "$DB" ] || { echo "dumpwrap: DB_DATABASE not found" >&2; exit 3; }

# --defaults-file, NOT --defaults-extra-file. The live host ships a ~/.my.cnf
# containing "database=taxnestc_db"; mysqldump reads that as the --databases
# option, rejects the value, and STOPS processing option files at that point —
# so an extra file listed afterwards is silently never read and the dump dies
# with "access denied". Reading only our own file sidesteps it entirely, which
# is why mycnf.php has to write host and port as well as the credentials.
OPTS=(
  --defaults-file="$CNF"
  --single-transaction        # consistent snapshot, no locks (all 180 tables are InnoDB)
  --quick                     # stream row by row instead of buffering a 91 MB result
  --routines --triggers --events
  --default-character-set=utf8mb4
  --no-tablespaces            # avoids needing the PROCESS privilege
  --hex-blob
)

# MariaDB's mysqldump rejects --set-gtid-purged outright; MySQL's needs it.
if mysqldump --help 2>/dev/null | grep -q -- '--set-gtid-purged'; then
  OPTS+=(--set-gtid-purged=OFF)
fi

mysqldump "${OPTS[@]}" "$DB"
rc=$?

[ $rc -eq 0 ] || echo "dumpwrap: mysqldump exited $rc" >&2
exit $rc
