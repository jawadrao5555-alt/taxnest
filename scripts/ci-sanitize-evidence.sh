#!/usr/bin/env bash
# Publish only bounded, allowlisted, sanitized CI text evidence.
#
# The helper stages every file and atomically publishes the final directory
# with SANITIZED_SUCCESS as its last write.  A caller must upload only that
# marker-bearing directory; a pre-created target is not evidence by itself.
set -Eeuo pipefail
umask 077

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
ROOT="$(cd "$SCRIPT_DIR/.." && pwd)"
TARGET="${1:-}"

if [[ -z "$TARGET" ]]; then
    printf 'ci-sanitize-evidence: evidence target is required\n' >&2
    exit 2
fi
command -v python3 >/dev/null 2>&1 || {
    printf 'ci-sanitize-evidence: python3 is required\n' >&2
    exit 2
}
[[ -r "$ROOT/scripts/lib/rc-evidence-sanitizer.py" ]] || {
    printf 'ci-sanitize-evidence: sanitizer helper is missing\n' >&2
    exit 2
}
[[ -r "$ROOT/scripts/lib/rc_redactor.py" ]] || {
    printf 'ci-sanitize-evidence: redactor helper is missing\n' >&2
    exit 2
}

exec python3 "$ROOT/scripts/lib/rc-evidence-sanitizer.py" \
    "$TARGET" --workspace "${GITHUB_WORKSPACE:-$ROOT}"