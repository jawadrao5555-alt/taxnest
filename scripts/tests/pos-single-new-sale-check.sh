#!/usr/bin/env bash
# Static UI contract: the sale screen owns one contextual New Sale action per
# viewport. The shared drawer must not add a second link on that same route.
set -euo pipefail
ROOT=$(cd "$(dirname "$0")/../.." && pwd)
NAV="$ROOT/resources/views/layouts/pos-navigation.blade.php"
SALE="$ROOT/resources/views/pos/universal.blade.php"

grep -q "@unless(request()->routeIs('pos.invoice.create'))" "$NAV"
grep -q 'New Sale — MOBILE ONLY' "$SALE"
grep -q 'replaces the static nav link on this page' "$SALE"

echo "pos-single-new-sale-check: ALL PASS"
