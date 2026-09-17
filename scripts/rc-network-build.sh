#!/usr/bin/env bash
set -euo pipefail
ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"; out="${RC_NETWORK_GUARD_BUILD_DIR:-/tmp}/rc-network-guard.so"; mkdir -p "$(dirname "$out")"
ccbin="$(command -v cc || command -v gcc || true)"; [[ -n "$ccbin" ]] || { echo 'rc-network-build: BLOCKED no C compiler for egress guard' >&2; exit 2; }
"$ccbin" -shared -fPIC -O2 -ldl -o "$out" "$ROOT/scripts/rc-network-guard.c"; printf '%s\n' "$out"