#!/usr/bin/env bash
# Reconstruct a disposable release-candidate checkout from committed source.
#
# The default is deliberately an independent clone of HEAD, not the caller's
# working tree. This makes an uncommitted vendor/node_modules/.env/cache state
# irrelevant and gives the caller a reproducible source identity without
# relying on parent Git metadata. Dependency downloads are allowed here;
# application commands belong behind rc-safe-run.
set -euo pipefail
umask 077

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
ROOT="$(cd "$SCRIPT_DIR/.." && pwd)"
SOURCE_ROOT="$ROOT"
runtime_root=""
working_tree=0
all_locks=0
build_assets=0
bootstrap_phase=initialization
diagnostics_written=0

emit_failure_diagnostics() {
    local exit_code="$1"
    local diagnostic_root="${runtime_root:-}"
    local helper="$SCRIPT_DIR/lib/rc-failure-diagnostics.py"

    ((diagnostics_written)) && return 0
    diagnostics_written=1

    # A failure can happen before --runtime has been created (for example,
    # while validating --source). Keep a best-effort local runtime so that
    # even argument/setup failures have an uploadable, sanitized record.
    if [[ -z "$diagnostic_root" || ! -d "$diagnostic_root" ]]; then
        mkdir -p "$ROOT/.local/recertification" 2>/dev/null || true
        diagnostic_root="$(mktemp -d "$ROOT/.local/recertification/bootstrap-failure.XXXXXX" 2>/dev/null || true)"
    fi
    if [[ -z "$diagnostic_root" || ! -d "$diagnostic_root" ]]; then
        diagnostic_root="$(mktemp -d "${TMPDIR:-/tmp}/taxnest-rc-bootstrap-failure.XXXXXX" 2>/dev/null || true)"
    fi

    if [[ -n "$diagnostic_root" && -d "$diagnostic_root" && -r "$helper" ]] \
        && command -v python3 >/dev/null 2>&1; then
        python3 "$helper" \
            --runtime "$diagnostic_root" \
            --phase "$bootstrap_phase" \
            --exit "$exit_code" >&2
    fi
    if [[ -n "$diagnostic_root" && -d "$diagnostic_root" ]]; then
        printf 'RC_FAILURE_RUNTIME=%s\n' "$diagnostic_root" >&2
        printf 'RC_FAILURE_ARTIFACT_DIR=%s/failure-diagnostics\n' "$diagnostic_root" >&2
        printf 'RC_FAILURE_SUMMARY=%s/failure-diagnostics/diagnostic.json\n' "$diagnostic_root" >&2
        printf 'RC_FAILURE_LOG=%s/failure-diagnostics/logs.txt\n' "$diagnostic_root" >&2
    else
        printf 'RC_FAILURE_ARTIFACT_DIR=unavailable\n' >&2
    fi
}

on_exit() {
    local exit_code=$?
    if ((exit_code != 0)); then
        set +e
        emit_failure_diagnostics "$exit_code"
    fi
    exit "$exit_code"
}
trap on_exit EXIT

usage() {
    cat >&2 <<'EOF'
usage: rc-bootstrap.sh [options]

Options:
  --source DIR       source checkout (default: this worktree)
  --working-tree     use DIR as-is; requires a clean git checkout
  --runtime DIR      empty directory for the disposable checkout/runtime
  --all-locks        also npm ci every optional package-lock.json
  --build            run the root locked web asset build after npm ci
EOF
    exit 2
}

while (($#)); do
    case "$1" in
        --source)
            [[ $# -ge 2 && -n "$2" ]] || usage
            SOURCE_ROOT="$2"
            shift 2
            ;;
        --working-tree) working_tree=1; shift ;;
        --runtime)
            [[ $# -ge 2 && -n "$2" ]] || usage
            runtime_root="$2"
            shift 2
            ;;
        --all-locks) all_locks=1; shift ;;
        --build) build_assets=1; shift ;;
        -h|--help) usage ;;
        *) usage ;;
    esac
done

SOURCE_ROOT="$(cd "$SOURCE_ROOT" && pwd)"
git_root="$(git -C "$SOURCE_ROOT" rev-parse --show-toplevel)"
source_sha="$(git -C "$SOURCE_ROOT" rev-parse HEAD)"

if ((working_tree)); then
    [[ "$SOURCE_ROOT" == "$git_root" ]] || {
        echo "rc-bootstrap: --working-tree source must be the git root" >&2
        exit 2
    }
    if [[ -n "$(git -C "$git_root" status --porcelain --untracked-files=all)" ]]; then
        echo "rc-bootstrap: --working-tree requires a clean checkout; use the default archive mode" >&2
        exit 2
    fi
fi

if [[ -z "$runtime_root" ]]; then
    mkdir -p "$ROOT/.local/recertification"
    runtime_root="$(mktemp -d "$ROOT/.local/recertification/bootstrap.XXXXXX")"
else
    [[ ! -e "$runtime_root" || -z "$(find "$runtime_root" -mindepth 1 -print -quit 2>/dev/null)" ]] || {
        echo "rc-bootstrap: runtime directory must be empty: $runtime_root" >&2
        exit 2
    }
    mkdir -p "$runtime_root"
fi
runtime_root="$(cd "$runtime_root" && pwd)"
chmod 700 "$runtime_root"
logs="$runtime_root/logs"
mkdir -p "$logs"
chmod 700 "$logs"

source_dir="$runtime_root/source"
if ((working_tree)); then
    source_dir="$SOURCE_ROOT"
else
    # Use an independent local clone, not git archive. A source directory
    # without .git can silently discover this worktree's parent metadata when
    # a later guard calls git; --no-local/--no-hardlinks also prevents shared
    # object storage from making the proof depend on this checkout.
    source_ref="$(git -C "$git_root" symbolic-ref --quiet --short HEAD || true)"
    clone_args=(clone --no-local --no-hardlinks --depth 1 --no-checkout)
    [[ -z "$source_ref" ]] || clone_args+=(--branch "$source_ref")
    git "${clone_args[@]}" "$git_root" "$source_dir" >/dev/null
    git -C "$source_dir" checkout --detach "$source_sha" >/dev/null
    expected_root="$(cd "$source_dir" && pwd)"
    actual_root="$(git -C "$source_dir" rev-parse --show-toplevel)"
    actual_sha="$(git -C "$source_dir" rev-parse HEAD)"
    [[ "$actual_root" == "$expected_root" && "$actual_sha" == "$source_sha" ]] || {
        echo "rc-bootstrap: independent clone identity verification failed" >&2
        exit 2
    }
fi

npm_bootstrap="$source_dir/scripts/npm-bootstrap-pinned.sh"
[[ -x "$npm_bootstrap" ]] || {
    echo "rc-bootstrap: pinned npm helper is missing or not executable: $npm_bootstrap" >&2
    exit 2
}

home="$runtime_root/home"
mkdir -p "$home"/{tmp,composer-cache,npm-cache,composer}
chmod 700 "$home"

base_env=(
    "HOME=$home"
    "TMPDIR=$home/tmp"
    "XDG_CACHE_HOME=$home/npm-cache"
    "COMPOSER_HOME=$home/composer"
    "COMPOSER_CACHE_DIR=$home/composer-cache"
    "COMPOSER_ALLOW_SUPERUSER=1"
    "COMPOSER_NO_INTERACTION=1"
    "NPM_CONFIG_CACHE=$home/npm-cache"
    "npm_config_cache=$home/npm-cache"
    "NPM_CONFIG_AUDIT=false"
    "NPM_CONFIG_FUND=false"
    "NPM_CONFIG_PROGRESS=false"
    "NPM_CONFIG_USERCONFIG=$home/npmrc"
    "ELECTRON_SKIP_BINARY_DOWNLOAD=1"
    "PATH=${PATH:-/usr/bin:/bin}"
    "LANG=C"
    "LC_ALL=C"
    "TZ=UTC"
    "CI=1"
    "APP_ENV=testing"
    "APP_KEY=base64:YWFhYWFhYWFhYWFhYWFhYWFhYWFhYWFhYWFhYWFhYWE="
    "APP_URL=http://127.0.0.1"
    "NO_PROXY=*"
    "no_proxy=*"
)

run_logged() {
    local name="$1"
    shift
    local log="$logs/$name.log"
    bootstrap_phase="$name"
    printf 'BOOTSTRAP command=%s\n' "$name"
    set +e
    (
        cd "$1"
        shift
        env -i "${base_env[@]}" "$@"
    ) >"$log" 2>&1
    local status=$?
    set -e
    if ((status != 0)); then
        echo "rc-bootstrap: $name failed (exit $status); see $log" >&2
        return "$status"
    fi
    printf 'BOOTSTRAP pass=%s log=%s\n' "$name" "$log"
}

lock_hashes="$runtime_root/lock-hashes.tsv"
: >"$lock_hashes"
record_lock() {
    local lock="$1"
    [[ -f "$source_dir/$lock" ]] || {
        echo "rc-bootstrap: missing committed lockfile: $lock" >&2
        exit 2
    }
    printf '%s\t%s\n' "$lock" "$(sha256sum "$source_dir/$lock" | awk '{print $1}')" >>"$lock_hashes"
}
record_lock composer.lock
record_lock package-lock.json
record_lock pra-agent/package-lock.json
record_lock agent-realtime-gateway/package-lock.json
if ((all_locks)); then
    while IFS= read -r lock; do
        case "$lock" in
            package-lock.json|pra-agent/package-lock.json|agent-realtime-gateway/package-lock.json) ;;
            *) record_lock "$lock" ;;
        esac
    done < <(find "$source_dir" -type f -name package-lock.json \
        -not -path '*/node_modules/*' -not -path '*/vendor/*' -printf '%P\n' | sort)
fi

run_logged composer-install "$source_dir" composer install \
    --no-interaction --prefer-dist --no-progress --no-ansi --no-scripts
run_logged root-npm-ci "$source_dir" "$npm_bootstrap" ci --ignore-scripts --no-audit --no-fund
run_logged agent-npm-ci "$source_dir/pra-agent" "$npm_bootstrap" ci --ignore-scripts --no-audit --no-fund
run_logged realtime-npm-ci "$source_dir/agent-realtime-gateway" "$npm_bootstrap" ci --ignore-scripts --no-audit --no-fund

if ((all_locks)); then
    while IFS= read -r lock; do
        case "$lock" in
            package-lock.json|pra-agent/package-lock.json|agent-realtime-gateway/package-lock.json) continue ;;
        esac
        package_dir="${lock%/*}"
        [[ "$package_dir" == "$lock" ]] && package_dir="$source_dir" || package_dir="$source_dir/$package_dir"
        name="$(printf '%s' "$lock" | tr '/.' '__')-npm-ci"
        run_logged "$name" "$package_dir" "$npm_bootstrap" ci --ignore-scripts --no-audit --no-fund
    done < <(find "$source_dir" -type f -name package-lock.json \
        -not -path '*/node_modules/*' -not -path '*/vendor/*' -printf '%P\n' | sort)
fi

if ((build_assets)); then
    run_logged root-web-build "$source_dir" "$npm_bootstrap" run build
fi

# Installation must never turn this into a stateful app checkout.
for forbidden in \
    "$source_dir/.env" \
    "$source_dir/.env.local" \
    "$source_dir/.env.production" \
    "$source_dir/.env.backup"; do
    [[ ! -e "$forbidden" ]] || {
        echo "rc-bootstrap: generated secret environment file: $forbidden" >&2
        exit 1
    }
done
shopt -s nullglob
generated_cache=("$source_dir"/bootstrap/cache/*.php "$source_dir"/storage/framework/cache/data/*)
if ((${#generated_cache[@]})); then
    echo "rc-bootstrap: generated application cache is not allowed in bootstrap" >&2
    printf '  %s\n' "${generated_cache[@]}" >&2
    exit 1
fi
shopt -u nullglob

for lock in composer.lock package-lock.json pra-agent/package-lock.json agent-realtime-gateway/package-lock.json; do
    current="$(sha256sum "$source_dir/$lock" | awk '{print $1}')"
    expected="$(awk -v key="$lock" '$1 == key {print $2}' "$lock_hashes")"
    [[ "$current" == "$expected" ]] || {
        echo "rc-bootstrap: lockfile changed during bootstrap: $lock" >&2
        exit 1
    }
done

metadata="$runtime_root/bootstrap.json"
node_version="$(node --version 2>/dev/null || echo unavailable)"
php_version="$(php -r 'echo PHP_VERSION;' 2>/dev/null || echo unavailable)"
composer_version="$(composer --version 2>/dev/null | awk '$1 == "Composer" && $2 == "version" {print $3; exit}' || echo unavailable)"
cat >"$metadata" <<EOF
{
  "schema_version": 1,
  "source_sha": "$source_sha",
  "source_mode": "$([[ $working_tree -eq 1 ]] && echo working-tree || echo independent-local-clone)",
  "source_dir": "$source_dir",
  "runtime_dir": "$runtime_root",
  "lock_hashes": "$lock_hashes",
  "optional_locks": $([[ $all_locks -eq 1 ]] && echo true || echo false),
  "web_build": $([[ $build_assets -eq 1 ]] && echo true || echo false),
  "composer_scripts": false,
  "node": "$node_version",
  "php": "$php_version",
  "composer": "$composer_version"
}
EOF
chmod 600 "$metadata" "$lock_hashes"
printf 'BOOTSTRAP_ROOT=%s\nBOOTSTRAP_SOURCE=%s\nBOOTSTRAP_METADATA=%s\n' \
    "$runtime_root" "$source_dir" "$metadata"