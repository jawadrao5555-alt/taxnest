#!/bin/bash
# Fixture tests for scripts/live-error-triage.sh (Task 734).
# Verifies every supported CLI-probe signature buckets as CLI PROBE, real app
# errors bucket as APP ERRORS, and other-day entries are ignored.
set -uo pipefail
cd "$(dirname "$0")/../.."

FAIL=0
check() { # desc expected_app expected_probe day fixture
  local desc=$1 exp_app=$2 exp_probe=$3 day=$4 fixture=$5
  local out; out=$(printf '%s\n' "$fixture" | bash scripts/live-error-triage.sh "$day")
  local app probe
  app=$(printf '%s\n' "$out" | grep -oP '^APP ERRORS: \K\d+')
  probe=$(printf '%s\n' "$out" | grep -oP '^CLI PROBE.*: \K\d+')
  if [ "$app" = "$exp_app" ] && [ "$probe" = "$exp_probe" ]; then
    echo "PASS: $desc"
  else
    echo "FAIL: $desc (app=$app expected=$exp_app, probe=$probe expected=$exp_probe)"
    printf '%s\n' "$out"
    FAIL=1
  fi
}

D=2026-08-15
HDR="[$D 10:00:00] production.ERROR: boom {\"exception\":\"[object] (Illuminate\\\\Database\\\\QueryException)"

check "/tmp/*.php frame = probe" 0 1 "$D" "$HDR at /tmp/p.php:3)
[stacktrace]
#0 /tmp/p.php(3): run()
#1 {main}"

check "Command line code frame = probe" 0 1 "$D" "$HDR at Command line code:1)
[stacktrace]
#0 Command line code(1): foo()"

check "eval()'d code frame = probe" 0 1 "$D" "$HDR)
[stacktrace]
#0 /home/x/artisan(1) : eval()'d code(1): foo()"

check "Psy\\Shell (tinker) frame = probe" 0 1 "$D" "$HDR)
[stacktrace]
#0 /home/x/vendor/psy/psysh/src/Shell.php(1): Psy\\Shell->execute()"

check "JSON-escaped Psy\\\\Shell frame = probe" 0 1 "$D" "$HDR)
[stacktrace]
#0 vendor: Psy\\\\Shell->execute()"

check "app stack = app error" 1 0 "$D" "$HDR at /home/taxnestc/public_html/app/Http/Controllers/PosController.php:10)
[stacktrace]
#0 /home/taxnestc/public_html/public/index.php(50): App\\Http\\Kernel->handle()"

check "other-day entries ignored" 0 0 "$D" "[2026-08-14 09:00:00] production.ERROR: yesterday
[stacktrace]
#0 /tmp/p.php(1): x()"

check "mixed app + probe" 1 1 "$D" "$HDR at /tmp/p.php:3)
[stacktrace]
#0 /tmp/p.php(3): run()
$HDR at app/Foo.php:1)
[stacktrace]
#0 /home/taxnestc/public_html/public/index.php(1): handle()"

exit $FAIL
