#!/usr/bin/env bash
# Run the committed-source dependency/build/guard recertification lane.
#
# This intentionally does not run PHPUnit, browser acceptance, or a database
# lab. Those are source-stability gates owned by the coordinating agent. The
# lane here is safe to run while those workers are editing their harnesses.
set -euo pipefail
umask 077

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
ROOT="$(cd "$SCRIPT_DIR/.." && pwd)"
all_locks=0

usage() {
    echo "usage: $0 [--all-locks]" >&2
    exit 2
}
while (($#)); do
    case "$1" in
        --all-locks) all_locks=1; shift ;;
        -h|--help) usage ;;
        *) usage ;;
    esac
done

run_id="$(date -u +%Y%m%dT%H%M%SZ)-$$"
runtime="$ROOT/.local/recertification/rc-$run_id"
mkdir -p "$runtime/logs"
chmod 700 "$runtime" "$runtime/logs"
results="$runtime/results.tsv"
: >"$results"

bootstrap_log="$runtime/logs/bootstrap.log"
bootstrap_args=(--source "$ROOT" --runtime "$runtime/bootstrap" --build)
((all_locks)) && bootstrap_args+=(--all-locks)
set +e
bash "$ROOT/scripts/rc-bootstrap.sh" "${bootstrap_args[@]}" >"$bootstrap_log" 2>&1
bootstrap_status=$?
set -e
if ((bootstrap_status != 0)); then
    echo "rc-recertify: bootstrap failed (exit $bootstrap_status); raw log is $bootstrap_log" >&2
    exit "$bootstrap_status"
fi
source_dir="$(awk -F= '$1 == "BOOTSTRAP_SOURCE" {print substr($0, index($0, "=") + 1)}' "$bootstrap_log" | tail -1)"
[[ -n "$source_dir" && -d "$source_dir" ]] || {
    echo "rc-recertify: bootstrap did not report a source directory" >&2
    exit 2
}

source_sha="$(git -C "$ROOT" rev-parse HEAD)"
runtime_home="$runtime/bootstrap/home"
audit_env=(
    "HOME=$runtime_home"
    "TMPDIR=$runtime_home/tmp"
    "XDG_CACHE_HOME=$runtime_home/npm-cache"
    "COMPOSER_HOME=$runtime_home/composer"
    "COMPOSER_CACHE_DIR=$runtime_home/composer-cache"
    "NPM_CONFIG_CACHE=$runtime_home/npm-cache"
    "npm_config_cache=$runtime_home/npm-cache"
    "NPM_CONFIG_USERCONFIG=$runtime_home/npmrc"
    "NPM_CONFIG_AUDIT=true"
    "NPM_CONFIG_FUND=false"
    "NPM_CONFIG_PROGRESS=false"
    "ELECTRON_SKIP_BINARY_DOWNLOAD=1"
    "PATH=${PATH:-/usr/bin:/bin}"
    "LANG=C"
    "LC_ALL=C"
    "TZ=UTC"
    "CI=1"
)

run_check() {
    local name="$1"
    local cwd="$2"
    shift 2
    local log="$runtime/logs/$name.log"
    set +e
    (
        cd "$cwd"
        env -i "${audit_env[@]}" "$@"
    ) >"$log" 2>&1
    local status=$?
    set -e
    local result=PASS
    ((status == 0)) || result=FAIL
    printf '%s\t%s\t%s\t%s\t%s\n' "$name" "$result" "$status" "$log" \
        "$(sha256sum "$log" | awk '{print $1}')" >>"$results"
    printf 'RC_CHECK name=%s status=%s exit=%s log_sha256=%s\n' \
        "$name" "$result" "$status" "$(sha256sum "$log" | awk '{print $1}')"
}

record_skip() {
    local name="$1"
    local reason="$2"
    local log="$runtime/logs/$name.log"
    printf 'SKIP: %s\n' "$reason" >"$log"
    printf '%s\tSKIP\t0\t%s\t%s\n' "$name" "$log" \
        "$(sha256sum "$log" | awk '{print $1}')" >>"$results"
    printf 'RC_CHECK name=%s status=SKIP exit=0 log_sha256=%s\n' \
        "$name" "$(sha256sum "$log" | awk '{print $1}')"
}

# These are dependency operations, so they intentionally run outside
# rc-safe-run. The application network guard is applied only below.
run_check composer-audit "$source_dir" composer audit --locked --format=json --no-interaction --no-ansi
run_check root-npm-audit "$source_dir" npm audit --json --package-lock-only --no-fund --no-progress
run_check agent-npm-audit "$source_dir/pra-agent" npm audit --json --package-lock-only --no-fund --no-progress
run_check realtime-npm-audit "$source_dir/agent-realtime-gateway" npm audit --json --package-lock-only --no-fund --no-progress
if ((all_locks)); then
    while IFS= read -r lock; do
        case "$lock" in
            package-lock.json|pra-agent/package-lock.json|agent-realtime-gateway/package-lock.json) continue ;;
        esac
        package_dir="${lock%/*}"
        [[ "$package_dir" == "$lock" ]] && package_dir="$source_dir" || package_dir="$source_dir/$package_dir"
        name="$(printf '%s' "$lock" | tr '/.' '__')-npm-audit"
        run_check "$name" "$package_dir" npm audit --json --package-lock-only --no-fund --no-progress
    done < <(find "$source_dir" -type f -name package-lock.json \
        -not -path '*/node_modules/*' -not -path '*/vendor/*' -printf '%P\n' | sort)
fi

# The build is in the independent checkout and therefore cannot alter this
# worktree's tracked generated assets.
run_check root-web-asset-build "$source_dir" npm run build

node_major="$(node --version | sed -E 's/^v([0-9]+).*/\1/')"
if [[ "$node_major" =~ ^[0-9]+$ ]] && ((node_major >= 22)); then
    record_skip agent-node-engine-guard "Node $node_major is available; Windows packaging remains an owner-controlled external build"
else
    record_skip agent-node-engine-guard "Node >=22.12.0 is required by pra-agent; current runner has Node ${node_major:-unknown}; no incompatible build was attempted"
fi
if command -v cc >/dev/null 2>&1; then
    run_check compiler-available-guard "$ROOT" cc --version
else
    record_skip compiler-available-guard "no C compiler in this runner; network guard cannot be rebuilt"
fi

# The subprocess gets a fresh HOME and an LD_PRELOAD egress guard. This
# verifies TCP, UDP, DNS denial and loopback resolution without contacting an
# application/fiscal endpoint.
run_check network-egress-guard "$ROOT" \
    bash "$ROOT/scripts/rc-safe-run" --root "$source_dir" -- \
    python3 scripts/tests/rc-network-guard-check.py
run_check safe-run-environment "$ROOT" \
    bash "$ROOT/scripts/rc-safe-run" --root "$source_dir" -- \
    sh -c 'test "${HOME#/}" != "$HOME" && test -z "${DATABASE_URL:-}" && test -z "${AWS_ACCESS_KEY_ID:-}" && test "${RC_SAFE_RUN:-}" = 1'

# This guard reads git-tracked paths only and prints paths/rules, never file
# contents. It is deliberately run against the caller checkout, not the
# archive, because that is the identity that will be reviewed.
run_check repository-artifact-guard "$ROOT" bash "$ROOT/scripts/verify-repository-artifacts.sh"

docs_dir="$ROOT/docs/release-candidate/recertification"
mkdir -p "$docs_dir"
report="$docs_dir/rc-$run_id.md"
summary="$docs_dir/rc-$run_id.json"
junit="$docs_dir/rc-$run_id.junit.xml"

python3 - "$results" "$source_sha" "$runtime" "$report" "$summary" "$junit" <<'PY'
import datetime
import hashlib
import json
import os
import sys
from xml.sax.saxutils import escape

results_path, source_sha, runtime, report_path, summary_path, junit_path = sys.argv[1:]
rows = []
for line in open(results_path, encoding="utf-8"):
    line = line.rstrip("\n")
    if not line:
        continue
    name, status, exit_code, log, digest = line.split("\t")
    rows.append({
        "name": name,
        "status": status,
        "exit": int(exit_code),
        "log": log,
        "sha256": digest,
    })

tests = len(rows)
failures = sum(row["status"] == "FAIL" for row in rows)
skipped = sum(row["status"] == "SKIP" for row in rows)
errors = 0
now = datetime.datetime.now(datetime.timezone.utc).isoformat().replace("+00:00", "Z")
runtime_ref = os.path.join(".local", "recertification", os.path.basename(runtime))
sanitized_rows = [
    {**row, "log": os.path.relpath(row["log"], runtime)}
    for row in rows
]

def audit_findings(path):
    """Return terse advisory identities, never copy raw advisory payloads."""
    try:
        payload = json.load(open(path, encoding="utf-8"))
    except (OSError, ValueError):
        return ["audit output was not machine-readable"]
    findings = []
    if isinstance(payload.get("advisories"), dict):
        for package, advisories in sorted(payload["advisories"].items()):
            for item in advisories or []:
                ident = item.get("advisoryId") or item.get("cve") or item.get("title") or "unidentified"
                severity = item.get("severity") or "unknown"
                findings.append(f"{package}: {ident} ({severity})")
    elif isinstance(payload.get("vulnerabilities"), dict):
        for package, item in sorted(payload["vulnerabilities"].items()):
            via = item.get("via") or []
            for advisory in via:
                if isinstance(advisory, str):
                    ident = advisory
                    severity = item.get("severity") or "unknown"
                else:
                    ident = advisory.get("source") or advisory.get("title") or "unidentified"
                    severity = advisory.get("severity") or item.get("severity") or "unknown"
                findings.append(f"{package}: {ident} ({severity})")
    return findings or ["none reported"]

audit_rows = []
for row in rows:
    if row["name"].endswith("-audit"):
        findings = audit_findings(row["log"])
        audit_rows.append({"name": row["name"], "findings": findings})

summary = {
    "schema_version": 1,
    "generated_utc": now,
    "source_sha": source_sha,
    "runtime": runtime_ref,
    "tests": tests,
    "failures": failures,
    "errors": errors,
    "skipped": skipped,
    "checks": sanitized_rows,
    "audits": audit_rows,
}
bootstrap_meta_path = os.path.join(runtime, "bootstrap", "bootstrap.json")
try:
    bootstrap_meta = json.load(open(bootstrap_meta_path, encoding="utf-8"))
except (OSError, ValueError):
    bootstrap_meta = {"source_mode": "unavailable"}
lock_hash_path = bootstrap_meta.get("lock_hashes")
lock_hashes = {}
if lock_hash_path:
    try:
        for line in open(lock_hash_path, encoding="utf-8"):
            lock, digest = line.rstrip("\n").split("\t", 1)
            lock_hashes[lock] = digest
    except (OSError, ValueError):
        lock_hashes = {}
summary["bootstrap"] = {
    "source_mode": bootstrap_meta.get("source_mode"),
    "source_sha": bootstrap_meta.get("source_sha"),
    "node": bootstrap_meta.get("node"),
    "php": bootstrap_meta.get("php"),
    "composer": bootstrap_meta.get("composer"),
    "lock_hashes": lock_hashes,
}
with open(summary_path, "w", encoding="utf-8") as handle:
    json.dump(summary, handle, indent=2, sort_keys=True)
    handle.write("\n")

def junit_attr(value):
    return escape(str(value), {'"': "&quot;"})

xml = [
    '<?xml version="1.0" encoding="UTF-8"?>',
    f'<testsuite name="taxnest-release-candidate" tests="{tests}" failures="{failures}" errors="{errors}" skipped="{skipped}">',
]
for row in rows:
    xml.append(
        f'  <testcase name="{junit_attr(row["name"])}" classname="rc-recertify">'
    )
    if row["status"] == "FAIL":
        xml.append(
            f'    <failure message="command exited {row["exit"]}">log_sha256={row["sha256"]}</failure>'
        )
    elif row["status"] == "SKIP":
        xml.append('    <skipped message="environment or owner-controlled external gate"/>')
    xml.append("  </testcase>")
xml.append("</testsuite>")
open(junit_path, "w", encoding="utf-8").write("\n".join(xml) + "\n")

with open(report_path, "w", encoding="utf-8") as handle:
    handle.write("# Release-candidate dependency/build/guard recertification\n\n")
    handle.write("This lane is committed-source only and does not run PHPUnit, ")
    handle.write("browser acceptance, database labs, fiscal endpoints, or releases.\n\n")
    handle.write(f"- Generated UTC: `{now}`\n")
    handle.write(f"- Committed source SHA: `{source_sha}`\n")
    handle.write(f"- Runtime evidence (ignored): `{runtime_ref}`\n")
    handle.write(f"- Machine summary: `{os.path.basename(summary_path)}`\n")
    handle.write(f"- JUnit: `{os.path.basename(junit_path)}`\n\n")
    handle.write("## Bootstrap identity\n\n")
    handle.write(f'- Source mode: `{bootstrap_meta.get("source_mode", "unavailable")}`\n')
    handle.write(f'- Bootstrap SHA: `{bootstrap_meta.get("source_sha", "unavailable")}`\n')
    handle.write(f'- Node / PHP / Composer: `{bootstrap_meta.get("node", "unavailable")}` / `{bootstrap_meta.get("php", "unavailable")}` / `{bootstrap_meta.get("composer", "unavailable")}`\n')
    handle.write("- Locked dependency hashes:\n")
    for lock, digest in sorted(lock_hashes.items()):
        handle.write(f"  - `{lock}`: `{digest}`\n")
    handle.write("\n")
    handle.write("## Counts\n\n")
    handle.write(f"`tests={tests}` `failures={failures}` `errors={errors}` `skipped={skipped}`\n\n")
    handle.write("## Checks\n\n| Check | Result | Exit | Log SHA-256 |\n|---|---:|---:|---|\n")
    for row in rows:
        handle.write(f'| `{row["name"]}` | **{row["status"]}** | {row["exit"]} | `{row["sha256"]}` |\n')
    handle.write("\n## Dependency advisory findings\n\n")
    for item in audit_rows:
        handle.write(f'### {item["name"]}\n\n')
        for finding in item["findings"]:
            handle.write(f"- {finding}\n")
        handle.write("\n")
    handle.write("## Boundary\n\n")
    handle.write("- Dependency downloads and advisory lookups ran only during bootstrap/audit steps.\n")
    handle.write("- Application-network evidence used `rc-safe-run`, which permits loopback only.\n")
    handle.write("- Agent Windows packaging is not claimed; the Node engine guard records the available runtime.\n")
    handle.write("- All raw command logs remain under ignored `.local/recertification`; this report stores only sanitized statuses and hashes.\n")
PY

cp "$summary" "$runtime/summary.json"
cp "$junit" "$runtime/recertification.junit.xml"
cp "$report" "$runtime/recertification.md"
printf 'RC_RECERTIFICATION_REPORT=%s\nRC_RECERTIFICATION_SUMMARY=%s\nRC_RECERTIFICATION_JUNIT=%s\n' \
    "$report" "$summary" "$junit"

failures="$(awk -F '\t' '$2 == "FAIL" {count++} END {print count + 0}' "$results")"
if ((failures > 0)); then
    exit 1
fi