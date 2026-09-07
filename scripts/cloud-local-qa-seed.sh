#!/bin/bash
# Seed the fictional NestPOS video-demo shop into the LOCAL disposable DB only.
#
# Fail-closed:
#   - Requires MariaDB taxnest_dev or taxnest_staging on 127.0.0.1/localhost
#   - Sets VIDEO_PIPELINE_ALLOW=1 only for this process
#   - Writes credentials to untracked .local/qa-creds.env (never committed)
#   - Never touches production / never uses live QA accounts
#
# Usage:
#   bash scripts/cloud-local-qa-seed.sh
#   bash scripts/cloud-local-qa-seed.sh --with-plans   # also seed PricingPlanSeeder first

set -euo pipefail
ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$ROOT"

WITH_PLANS=0
for arg in "$@"; do
  case "$arg" in
    --with-plans) WITH_PLANS=1 ;;
    -h|--help)
      sed -n '2,16p' "$0"
      exit 0
      ;;
  esac
done

fail() { echo "cloud-local-qa-seed FAILED: $*" >&2; exit 1; }
step() { echo ""; echo "==> $*"; }

# Strip Replit/Postgres overrides so Laravel uses .env MariaDB.
unset DATABASE_URL DB_CONNECTION PGHOST PGPORT PGUSER PGPASSWORD PGDATABASE || true

[ -f .env ] || fail ".env missing — run bash scripts/cloud-dev-bootstrap.sh first"

DB_HOST_VAL=$(grep -E '^DB_HOST=' .env | head -1 | cut -d= -f2- | tr -d '"'"'" || true)
case "${DB_HOST_VAL:-127.0.0.1}" in
  127.0.0.1|localhost|"" ) ;;
  *) fail "DB_HOST=${DB_HOST_VAL} is not local — refusing to seed" ;;
esac

DB_NAME_VAL=$(grep -E '^DB_DATABASE=' .env | head -1 | cut -d= -f2- | tr -d '"'"'" || true)
case "${DB_NAME_VAL}" in
  taxnest_dev|taxnest_staging) ;;
  *) fail "DB_DATABASE=${DB_NAME_VAL} is not taxnest_dev|taxnest_staging — refusing to seed" ;;
esac

mkdir -p .local
CREDS=".local/qa-creds.env"
chmod 700 .local 2>/dev/null || true

# Reuse existing local demo password when present; otherwise generate one.
EXISTING_PASS=""
if [ -f "$CREDS" ]; then
  # shellcheck disable=SC1090
  set -a; . "$CREDS"; set +a
  EXISTING_PASS="${VIDEO_DEMO_PASS:-${CLOUD_LOCAL_QA_PASSWORD:-${DEV_POS_PASS:-}}}"
fi
if [ -z "${VIDEO_DEMO_PASS:-}" ] && [ -n "$EXISTING_PASS" ]; then
  VIDEO_DEMO_PASS="$EXISTING_PASS"
fi
if [ -z "${VIDEO_DEMO_PASS:-}" ]; then
  VIDEO_DEMO_PASS="$(openssl rand -base64 18 | tr -d '/+=' | cut -c1-20)"
  echo "Generated new local VIDEO_DEMO_PASS (stored only in $CREDS)"
else
  echo "Reusing existing local VIDEO_DEMO_PASS from env/.local"
fi

LOGIN_EMAIL="videodemo@nestpos.pk"
export VIDEO_DEMO_PASS
export VIDEO_PIPELINE_ALLOW=1

umask 077
cat > "$CREDS" <<EOF
# Local Cloud Agent / NestPOS demo credentials — NEVER commit this file.
# Fictional shop only (VideoDemoShopSeeder). Not a real customer.
VIDEO_DEMO_LOGIN=${LOGIN_EMAIL}
VIDEO_DEMO_PASS=${VIDEO_DEMO_PASS}
CLOUD_LOCAL_QA_LOGIN=${LOGIN_EMAIL}
CLOUD_LOCAL_QA_PASSWORD=${VIDEO_DEMO_PASS}
DEV_POS_LOGIN=${LOGIN_EMAIL}
DEV_POS_PASS=${VIDEO_DEMO_PASS}
EOF
chmod 600 "$CREDS"

# Keep .env VIDEO_DEMO_PASS in sync for artisan seeders (do not print it).
php -r '
$env = file_get_contents(".env");
$pass = getenv("VIDEO_DEMO_PASS");
if ($pass === false || $pass === "") { fwrite(STDERR, "missing VIDEO_DEMO_PASS\n"); exit(1); }
if (preg_match("/^VIDEO_DEMO_PASS=/m", $env)) {
  $env = preg_replace("/^VIDEO_DEMO_PASS=.*$/m", "VIDEO_DEMO_PASS=".$pass, $env, 1);
} else {
  $env = rtrim($env)."\nVIDEO_DEMO_PASS=".$pass."\n";
}
file_put_contents(".env", $env);
'

if [ "$WITH_PLANS" = "1" ]; then
  step "Seeding PricingPlanSeeder (Unlimited plan required by demo shop)"
  php artisan db:seed --class=PricingPlanSeeder --force --no-interaction
fi

# Ensure Unlimited plan exists even without --with-plans (idempotent small seed).
PLAN_COUNT=$(php -r '
require "vendor/autoload.php";
$app = require "bootstrap/app.php";
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
echo (int) Illuminate\Support\Facades\DB::table("pricing_plans")->where("product_type","pos")->where("name","Unlimited")->count();
' 2>/dev/null || echo 0)
if [ "${PLAN_COUNT}" = "0" ]; then
  step "Unlimited plan missing — seeding PricingPlanSeeder"
  php artisan db:seed --class=PricingPlanSeeder --force --no-interaction
fi

step "Seeding VideoDemoShopSeeder (local disposable DB only)"
php artisan db:seed --class=VideoDemoShopSeeder --force --no-interaction

step "Verify demo user exists"
php -r '
require "vendor/autoload.php";
$app = require "bootstrap/app.php";
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$email = "videodemo@nestpos.pk";
$u = Illuminate\Support\Facades\DB::table("users")->where("email", $email)->first();
if (!$u) { fwrite(STDERR, "demo user missing\n"); exit(1); }
echo "OK user_id={$u->id} company_id={$u->company_id} email={$email}\n";
'

echo ""
echo "---------------------------------------------------------------"
echo "LOCAL QA SEED OK"
echo "  Login:  ${LOGIN_EMAIL}"
echo "  Creds:  ${CREDS} (gitignored)"
echo "  Smoke:  BASE_URL=http://127.0.0.1:8000 node scripts/cloud-local-ui-smoke.mjs"
echo "---------------------------------------------------------------"
