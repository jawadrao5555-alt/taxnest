#!/bin/bash
# Full local/Cloud development bootstrap for TaxNest.
#
# Idempotent. Safe defaults. NEVER deploys or uses production credentials.
#
# Steps:
#   1. Verify PHP extensions (pdo_mysql, etc.)
#   2. Ensure MariaDB is up + local taxnest_dev DB/user exist
#   3. Create .env from .env.example if missing (never overwrites)
#   4. Generate APP_KEY if empty
#   5. Run migrations against the LOCAL database
#   6. Optional: --seed-plans runs PricingPlanSeeder only
#
# Usage:
#   bash scripts/cloud-dev-bootstrap.sh
#   bash scripts/cloud-dev-bootstrap.sh --seed-plans
#   bash scripts/cloud-dev-bootstrap.sh --seed-local-qa
#   bash scripts/cloud-dev-bootstrap.sh --skip-migrate

set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$ROOT"

SEED_PLANS=0
SEED_LOCAL_QA=0
SKIP_MIGRATE=0
for arg in "$@"; do
  case "$arg" in
    --seed-plans) SEED_PLANS=1 ;;
    --seed-local-qa) SEED_LOCAL_QA=1 ;;
    --skip-migrate) SKIP_MIGRATE=1 ;;
    -h|--help)
      sed -n '2,22p' "$0"
      exit 0
      ;;
  esac
done

step() { echo ""; echo "==> $*"; }
fail() { echo "BOOTSTRAP FAILED: $*" >&2; exit 1; }

step "Verify PHP extensions"
php -v | head -1
for ext in pdo_mysql mbstring xml curl zip openssl fileinfo tokenizer ctype json bcmath; do
  php -m | grep -qi "^${ext}$" || fail "missing PHP extension: ${ext}"
  echo "  ok ${ext}"
done
php -m | grep -qi '^gd$' && echo "  ok gd" || echo "  note: gd missing (PDF/image features may fail)"
php -m | grep -qi '^intl$' && echo "  ok intl" || echo "  note: intl missing (polyfills usually cover this)"

step "Ensure local MariaDB + database"
bash "$ROOT/scripts/cloud-dev-start.sh"

step "Prepare .env (local only — never committed)"
if [ ! -f .env.example ]; then
  fail ".env.example missing — cannot bootstrap safely"
fi
if [ ! -f .env ]; then
  cp .env.example .env
  echo "Created .env from .env.example"
else
  echo ".env already exists — leaving it untouched"
fi

# Refuse if .env points at a non-local host (safety)
DB_HOST_VAL=$(grep -E '^DB_HOST=' .env | head -1 | cut -d= -f2- | tr -d '"'"'" || true)
case "${DB_HOST_VAL:-127.0.0.1}" in
  127.0.0.1|localhost|"" ) ;;
  *)
    fail "DB_HOST=${DB_HOST_VAL} is not local — refusing to migrate (Cloud bootstrap is localhost-only)"
    ;;
esac

if ! grep -qE '^APP_KEY=base64:' .env; then
  step "Generating APP_KEY"
  php artisan key:generate --force --no-interaction
else
  echo "APP_KEY already set"
fi

if [ ! -L public/storage ] && [ ! -e public/storage ]; then
  step "Linking storage"
  php artisan storage:link --no-interaction || true
fi

if [ "$SKIP_MIGRATE" = "0" ]; then
  step "Running migrations on LOCAL database"
  php artisan migrate --force --no-interaction
else
  echo "Skipping migrations (--skip-migrate)"
fi

if [ "$SEED_PLANS" = "1" ] && [ "$SEED_LOCAL_QA" = "0" ]; then
  step "Seeding PricingPlanSeeder only (no customer/demo credential seeders)"
  php artisan db:seed --class=PricingPlanSeeder --force --no-interaction
elif [ "$SEED_LOCAL_QA" = "1" ]; then
  step "Seeding local NestPOS QA demo shop (fail-closed; see docs/ops/cloud-agent-local-browser-qa.md)"
  bash "$ROOT/scripts/cloud-local-qa-seed.sh" --with-plans
else
  echo "Skipping seeders (pass --seed-plans and/or --seed-local-qa)."
  echo "NOTE: DatabaseSeeder and video/demo seeders are NOT run automatically."
fi

step "Quick health"
php artisan about --only=environment,drivers 2>/dev/null || php artisan --version
php -r '
require "vendor/autoload.php";
$app = require "bootstrap/app.php";
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();
try {
  $v = Illuminate\Support\Facades\DB::select("select version() as v")[0]->v ?? "?";
  echo "DB connection OK: {$v}\n";
} catch (Throwable $e) {
  fwrite(STDERR, "DB connection FAILED: ".$e->getMessage()."\n");
  exit(1);
}
'

echo ""
echo "---------------------------------------------------------------"
echo "CLOUD DEV BOOTSTRAP OK"
echo "  Local DB: taxnest_dev @ 127.0.0.1:3306"
echo "  Serve:    php artisan serve --host=127.0.0.1 --port=8000"
echo "  Tests:    php artisan test"
echo "  UI smoke: bash scripts/cloud-local-qa-seed.sh && node scripts/cloud-local-ui-smoke.mjs"
echo "  Frontend: npm ci && npm run build"
echo "---------------------------------------------------------------"
