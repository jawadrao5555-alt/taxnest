#!/usr/bin/env bash
# OWNER-RUN ONLY. Defaults to dry-run and never discovers broad filesystem paths.
set -euo pipefail
set +x
umask 077

DRY_RUN=1
APP_ROOT=""
OWNER_APPROVED=0

usage() {
    cat <<'USAGE'
Usage: bash scripts/ops/production-permissions-hardening.sh --app-root /absolute/taxnest [--dry-run]
       bash scripts/ops/production-permissions-hardening.sh --app-root /absolute/taxnest \
         --execute --owner-approved-production-execution

Only these resolved, non-symlink targets may be changed:
  <app-root>/storage/backups       directories 0700; regular files 0600
  <app-root>/bootstrap/cache       directory 0750; existing known cache files 0640

The default is dry-run. --execute requires the literal owner-approval flag.
The script never chowns, deletes, creates, follows symlinks, reads .env, or
changes tenant settings.
USAGE
}

while [[ $# -gt 0 ]]; do
    case "$1" in
        --app-root) APP_ROOT="${2:-}"; shift 2 ;;
        --dry-run) DRY_RUN=1; shift ;;
        --execute) DRY_RUN=0; shift ;;
        --owner-approved-production-execution) OWNER_APPROVED=1; shift ;;
        -h|--help) usage; exit 0 ;;
        *) echo "ERROR: unknown argument: $1" >&2; usage >&2; exit 2 ;;
    esac
done

[[ -n "$APP_ROOT" && "$APP_ROOT" = /* ]] || { echo "ERROR: --app-root must be an absolute path." >&2; exit 2; }
[[ -d "$APP_ROOT" && ! -L "$APP_ROOT" ]] || { echo "ERROR: application root must be an existing non-symlink directory." >&2; exit 2; }
ROOT="$(cd "$APP_ROOT" && pwd -P)"
[[ "$ROOT" != "/" && -f "$ROOT/artisan" && -f "$ROOT/bootstrap/app.php" ]] || {
    echo "ERROR: app root is not a resolved Laravel application root." >&2; exit 2;
}
[[ "$DRY_RUN" -eq 1 || "$OWNER_APPROVED" -eq 1 ]] || {
    echo "ERROR: refusing mutation without --owner-approved-production-execution." >&2; exit 2;
}

resolve_target() {
    local relative="$1" target="$ROOT/$1"
    [[ -e "$target" && ! -L "$target" ]] || { echo "ERROR: required target is absent or a symlink: $relative" >&2; exit 2; }
    [[ "$(cd "$target" && pwd -P)" == "$target" ]] || { echo "ERROR: unresolved target: $relative" >&2; exit 2; }
    printf '%s\n' "$target"
}

BACKUPS="$(resolve_target storage/backups)"
CACHE="$(resolve_target bootstrap/cache)"
[[ -d "$BACKUPS" && -d "$CACHE" ]] || { echo "ERROR: exact targets must be directories." >&2; exit 2; }
if find -P "$BACKUPS" -xdev -type l -print -quit | grep -q .; then
    echo "ERROR: refusing backup tree containing a symlink." >&2; exit 2
fi

KNOWN_CACHE=(config.php packages.php services.php events.php routes-v7.php)
echo "Permission-hardening plan (no ownership changes):"
echo "  root: $ROOT"
echo "  backup tree: $BACKUPS (directories 0700, regular files 0600)"
echo "  cache directory: $CACHE (0750)"
for file in "${KNOWN_CACHE[@]}"; do
    [[ ! -L "$CACHE/$file" ]] || { echo "ERROR: refusing cache symlink: $CACHE/$file" >&2; exit 2; }
    [[ -e "$CACHE/$file" ]] && echo "  cache file: $CACHE/$file (0640)"
done

if [[ "$DRY_RUN" -eq 1 ]]; then
    echo "DRY RUN — nothing changed. PROPOSED — NOT EXECUTED — OWNER APPROVAL REQUIRED"
    exit 0
fi

find -P "$BACKUPS" -xdev -type d -exec chmod 0700 -- {} +
find -P "$BACKUPS" -xdev -type f -exec chmod 0600 -- {} +
chmod 0750 -- "$CACHE"
for file in "${KNOWN_CACHE[@]}"; do
    [[ -e "$CACHE/$file" ]] && chmod 0640 -- "$CACHE/$file"
done
echo "DONE: exact paths hardened. Verify the PHP-FPM service account can read bootstrap/cache before closing the change record."