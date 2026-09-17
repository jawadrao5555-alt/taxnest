#!/usr/bin/env bash
# Prevent common public-repository leaks and unreconciled release artifacts.
set -euo pipefail
set +x

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
MODE=staged
[[ "${1:-}" == --tracked ]] && MODE=tracked
[[ -z "${1:-}" || "${1:-}" == --tracked ]] || { echo "Usage: $0 [--tracked]" >&2; exit 2; }
cd "$ROOT"

if [[ "$MODE" == staged ]]; then
    mapfile -d '' FILES < <(git diff --cached --name-only --diff-filter=ACMR -z)
else
    mapfile -d '' FILES < <(git ls-files -z)
fi
fail=0
for path in "${FILES[@]}"; do
    [[ -n "$path" ]] || continue
    case "$path" in
        .env.example|*/.env.example)
            ;; # documented example keys contain no secret values
        .env|.env.*|*/.env|*/.env.*|*google-services.json|*GoogleService-Info.plist|*service-account*.json|*service_account*.json)
            echo "FAIL: secret-bearing configuration must not be committed: $path" >&2; fail=1 ;;
        cookies.txt|*/cookies.txt|*cookie*.txt|*cookie*.json)
            echo "FAIL: cookies/session exports must not be committed: $path" >&2; fail=1 ;;
        *retry*log*|*fbr_retry_output*|*pra_retry_output*)
            echo "FAIL: ad-hoc fiscal retry output must not be committed: $path" >&2; fail=1 ;;
        *.sql.gz|*.dump|*.dump.gz|*.7z|backup.sql|*/backup.sql|*production*data*.sql|*production*export*.sql|*complete*data*.sql)
            echo "FAIL: database dumps/backups must not be committed: $path" >&2; fail=1 ;;
        *.apk|*.exe|*.dll|*.zip|*.tar.gz)
            if ! grep -Fq "\"path\": \"$path\"" docs/release-manifests/artifact-inventory.json 2>/dev/null; then
                echo "FAIL: binary has no reviewed artifact inventory entry: $path" >&2; fail=1
            fi ;;
    esac
done

if [[ "$MODE" == tracked ]]; then
    python3 - docs/release-manifests/artifact-inventory.json <<'PY' || fail=1
import hashlib, json, os, sys
inventory = json.load(open(sys.argv[1], encoding="utf-8"))
for item in inventory["artifacts"]:
    path = item["path"]
    if item["kind"] == "symlink":
        if (not os.path.islink(path)
                or os.readlink(path) != item["target"]
                or not os.path.isfile(path)):
            print("FAIL: expected artifact symlink is absent, changed, or unresolved: " + path)
            raise SystemExit(1)
        continue
    if not os.path.isfile(path):
        print("FAIL: inventoried artifact missing: " + path)
        raise SystemExit(1)
    digest = hashlib.sha256(open(path, "rb").read()).hexdigest()
    if digest != item["sha256"]:
        print("FAIL: artifact checksum drift: " + path)
        raise SystemExit(1)
    if item["provenance_status"] != "build-attested":
        print("BLOCKED: artifact lacks independently attested source/build SHA: " + path)
        raise SystemExit(1)
PY
fi

[[ "$fail" -eq 0 ]] && echo "PASS: repository artifact guard ($MODE)" || exit 1