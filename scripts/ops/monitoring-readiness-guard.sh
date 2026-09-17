#!/usr/bin/env bash
# Local configuration validator only; it never contacts monitored systems.
set -euo pipefail
set +x

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
CONFIG=""
[[ "${1:-}" == --config ]] && CONFIG="${2:-}"
[[ -z "${1:-}" || "${1:-}" == --config ]] || { echo "Usage: $0 [--config /absolute/private.env]" >&2; exit 2; }
required=(QUEUE_LAG_SOURCE SCHEDULER_HEARTBEAT_SOURCE REALTIME_HEARTBEAT_SOURCE DISK_SOURCE MEMORY_SOURCE DB_CONNECTIONS_SOURCE TLS_EXPIRY_SOURCE AGENT_VERSION_SOURCE FISCAL_FAILURE_SOURCE ALERT_ROUTE)
if [[ -z "$CONFIG" ]]; then
    printf 'DRY RUN — no monitoring calls. Required private configuration keys:\n'
    printf '  %s\n' "${required[@]}"
    exit 0
fi
[[ "$CONFIG" = /* && -f "$CONFIG" && ! -L "$CONFIG" ]] || { echo "ERROR: config must be an absolute private regular file." >&2; exit 2; }
[[ "$(stat -c %a "$CONFIG")" =~ ^[0-6][0-0][0-0]$ ]] || { echo "ERROR: monitoring config is group/world readable." >&2; exit 2; }
git -C "$ROOT" ls-files --error-unmatch -- "$CONFIG" >/dev/null 2>&1 && { echo "ERROR: monitoring config is tracked." >&2; exit 2; }
for key in "${required[@]}"; do
    grep -qE "^${key}=.+" "$CONFIG" || { echo "FAIL: missing $key" >&2; exit 1; }
done
grep -Eqi '^(.*PASSWORD|.*TOKEN|.*SECRET|.*PRIVATE_KEY)=' "$CONFIG" && { echo "FAIL: use secret references, not secret values, in monitoring config." >&2; exit 1; }
echo "PASS: monitoring configuration names are complete; values were not displayed and no endpoint was contacted."