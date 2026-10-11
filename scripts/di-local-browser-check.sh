#!/usr/bin/env bash
set -euo pipefail
[[ "${RC_SAFE_RUN:-}" == 1 ]] || { echo 'Run through scripts/rc-safe-run'; exit 2; }
mode="${1:-}"
[[ "$mode" == financial || "$mode" == withholding ]] || exit 2
fixture_dir="$(mktemp -d /tmp/taxnest-di-ui-XXXXXX)"
export DB_CONNECTION=sqlite DB_DATABASE="$fixture_dir/di.sqlite" SESSION_DRIVER=file APP_URL=http://127.0.0.1:5919
touch "$DB_DATABASE"
php artisan migrate --force --no-interaction > "$fixture_dir/migrate.log" 2>&1
php scripts/di-local-browser-fixture.php seed "$fixture_dir/fixture.json"
(cd public && exec php -S 127.0.0.1:5919 ../vendor/laravel/framework/src/Illuminate/Foundation/resources/server.php) > "$fixture_dir/server.log" 2>&1 &
di_server_pid=$!
trap 'kill "$di_server_pid" 2>/dev/null || true; rm -rf "$fixture_dir"' EXIT
node scripts/di-local-browser-smoke.mjs "$mode" "$fixture_dir/fixture.json"
php scripts/di-local-browser-fixture.php verify "$fixture_dir/fixture.json" "$mode"
