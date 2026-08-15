#!/bin/bash
# Live laravel.log error triage with CLI-probe detection (Task 734).
#
# Reads laravel.log content on STDIN, extracts production.ERROR entries for a
# given day (default: today) INCLUDING their multi-line stack traces, and
# classifies each entry:
#
#   CLI PROBE  — stack/message references /tmp/*.php, "Command line code",
#                eval()'d code, artisan tinker, or php -r frames. These are
#                manual probe scripts, NOT app errors. Twice (Jul 2026
#                pos_print_jobs.printer_name, Aug 2026
#                pos_user_sessions.last_seen_at) such probes were mistaken for
#                schema drift and spawned phantom fix-tasks (Task 729).
#   APP ERROR  — everything else (stack goes through app code / HTTP kernel).
#
# Usage:
#   ... | bash scripts/live-error-triage.sh [YYYY-MM-DD]
#   e.g. ssh live "tail -c 2000000 storage/logs/laravel.log" | bash scripts/live-error-triage.sh
#
# Exit codes: 0 = no app errors (probe-only or clean), 1 = app errors present.
# (Callers decide whether app errors are blocking; deploy-live.sh treats them
# as a loud NOTE, same as before, but now clearly separated from probe noise.)

set -uo pipefail

DAY="${1:-$(date +%Y-%m-%d)}"

awk -v day="$DAY" '
function classify() {
    if (buf == "") return
    if (buf ~ /\/tmp\/[A-Za-z0-9._\/-]*\.php/ \
        || buf ~ /Command line code/ \
        || buf ~ /eval\(\)'"'"'d code/ \
        || buf ~ /artisan tinker/ \
        || buf ~ /Psy\\+Shell/) {
        nprobe++
        probes = probes head "\n"
    } else {
        napp++
        apps = apps head "\n"
    }
    buf = ""; head = ""
}
# A new log entry starts with a [YYYY-MM-DD timestamp — NOT any "[" line
# ("[stacktrace]" and "[previous exception]" lines must stay in the block).
/^\[20[0-9][0-9]-[01][0-9]-[0-3][0-9] / {
    classify()
    if (index($0, "[" day) == 1 && $0 ~ /production\.ERROR/) {
        head = $0
        buf = $0
        next
    }
}
buf != "" { buf = buf "\n" $0 }
END {
    classify()
    printf "== Live error triage for %s ==\n", day
    printf "APP ERRORS: %d\n", napp + 0
    if (napp > 0) printf "%s", apps
    printf "CLI PROBE (not app errors — /tmp|Command line code|eval frames): %d\n", nprobe + 0
    if (nprobe > 0) printf "%s", probes
    exit (napp > 0 ? 1 : 0)
}
'
