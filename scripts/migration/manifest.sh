#!/usr/bin/env bash
# Build a content manifest for one app root. Runs on EITHER server — this is
# the single source of truth both sides are measured against.
#
#   manifest.sh <app-root> <dir> [<dir> ...]
#
# Emits to stdout, one line per file, TAB separated, sorted by path:
#   <sha256>\t<bytes>\t<relative/path>
#
# Tabs, not spaces: a filename may legitimately contain spaces, and a
# space-delimited manifest silently truncates such a path to its first word,
# which makes the comparison in 05-verify.sh miss real differences.
#
# Exits non-zero if any filename cannot be represented on one line (a tab or a
# newline in the name). Such a file could never be compared, and a verifier
# that quietly ignores part of the payload is worse than one that stops.
#
# Read-only. Never writes anything inside the app root.

set -uo pipefail

ROOT="${1:?usage: manifest.sh <app-root> <dir> [<dir> ...]}"
shift
[ $# -gt 0 ] || { echo "manifest.sh: no directories given" >&2; exit 2; }

cd "$ROOT" 2>/dev/null || { echo "manifest.sh: no such app root: $ROOT" >&2; exit 2; }

command -v sha256sum >/dev/null 2>&1 || { echo "manifest.sh: sha256sum missing" >&2; exit 2; }

# The hashing loop runs in a subshell, so unrepresentable names are recorded in
# a file rather than a variable.
BADLIST="$(mktemp "${TMPDIR:-/tmp}/.manifest-bad.XXXXXX")"
trap 'rm -f "$BADLIST"' EXIT INT TERM

for d in "$@"; do
  [ -d "$d" ] || continue
  # -print0 keeps odd filenames intact; parallel hashing keeps this under a
  # minute even for the ~6,300 small PDFs under storage/app/private.
  find "$d" -type f ! -name '.ftpquota' ! -name 'error_log' -print0 2>/dev/null \
    | xargs -0 -r -P 4 -n 64 sha256sum 2>/dev/null
done | while IFS= read -r line; do
  # sha256sum prints "<64 hex><two spaces><path>", but prefixes the line with a
  # backslash when it had to escape a backslash or newline in the name.
  case "$line" in
    '\'*) printf '%s\n' "$line" >> "$BADLIST"; continue ;;
  esac
  sum=${line%%  *}
  path=${line#*  }
  case "$path" in
    *"$(printf '\t')"*) printf '%s\n' "$path" >> "$BADLIST"; continue ;;
  esac
  sz=$(stat -c %s "$path" 2>/dev/null || stat -f %z "$path" 2>/dev/null || echo 0)
  printf '%s\t%s\t%s\n' "$sum" "$sz" "$path"
done | LC_ALL=C sort -t "$(printf '\t')" -k3,3

if [ -s "$BADLIST" ]; then
  {
    echo "manifest.sh: $(wc -l < "$BADLIST") filename(s) cannot be represented in a manifest"
    echo "manifest.sh: they contain a tab or a newline, so they can be neither compared nor certified:"
    sed 's/^/  /' "$BADLIST"
    echo "manifest.sh: rename them on the source, then re-run."
  } >&2
  exit 3
fi
