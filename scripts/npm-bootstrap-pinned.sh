#!/usr/bin/env bash
# Run bootstrap npm commands with the known-clean npm/Node pair.
#
# This is intentionally a thin launcher. It does not disable audits, ignore
# lockfile integrity, change registries, or retry a failed install with an
# unpinned npm. The caller still owns the normal npm command and its security
# gates.
#
# Required runtime:
#   Node major 22
#   npm 10.9.2 (downloaded by npx from the configured registry when absent)
#
# Examples:
#   NPM_BOOTSTRAP_NODE=/path/to/node \
#     scripts/npm-bootstrap-pinned.sh --version
#   NPM_BOOTSTRAP_NODE=/path/to/node \
#     scripts/npm-bootstrap-pinned.sh ci --ignore-scripts --no-audit --no-fund
#
# NPM_BOOTSTRAP_NODE may point at the tested Node binary. If omitted, `node`
# must itself report major version 22. Keeping the node directory first in PATH
# makes npm@10.9.2's executable use that same Node rather than the runner's
# default runtime. npx's package bin directory remains ahead of the runner's
# npm for lifecycle children, so nested npm invocations stay pinned too.

set -euo pipefail

readonly REQUIRED_NODE_MAJOR="22"
readonly PINNED_NPM_VERSION="10.9.2"

node_bin="${NPM_BOOTSTRAP_NODE:-node}"
if [[ "$node_bin" != */* ]]; then
    node_bin="$(command -v "$node_bin" || true)"
fi
if [[ -z "$node_bin" || ! -x "$node_bin" ]]; then
    echo "npm bootstrap: Node binary not found: ${NPM_BOOTSTRAP_NODE:-node}" >&2
    exit 127
fi

actual_node="$($node_bin --version)"
node_major="$(printf "%s" "$actual_node" | sed -E "s/^v([0-9]+).*/\1/")"
if [[ "$node_major" != "$REQUIRED_NODE_MAJOR" ]]; then
    echo "npm bootstrap: expected Node major 22; found ${actual_node}" >&2
    echo "Set NPM_BOOTSTRAP_NODE to the tested Node 22 binary." >&2
    exit 2
fi

npx_bin="${NPM_BOOTSTRAP_NPX:-npx}"
if [[ "$npx_bin" != */* ]]; then
    npx_bin="$(command -v "$npx_bin" || true)"
fi
if [[ -z "$npx_bin" || ! -x "$npx_bin" ]]; then
    echo "npm bootstrap: npx binary not found: ${NPM_BOOTSTRAP_NPX:-npx}" >&2
    exit 127
fi

node_dir="$(cd "$(dirname "$node_bin")" && pwd)"
export PATH="${node_dir}:$PATH"

# Do not add --registry, --ignore-scripts, --no-audit, or --no-fund here:
# bootstrap callers choose those flags explicitly and package-firewall
# vulnerability decisions must remain effective. npx resolves only the
# pinned npm tool; project packages still follow their lockfile and npm's
# normal integrity/audit behavior.
exec "$npx_bin" --yes --package="npm@${PINNED_NPM_VERSION}" -- npm "$@"
