#!/usr/bin/env bash
#
# Fail closed when tracked repository content includes local authentication
# material, raw fiscal retry diagnostics, customer-data exports, or ad-hoc
# generated invoice helpers. This command deliberately prints paths and rule
# names only; it never emits matched file contents.
set -euo pipefail

failures=0

reject_path() {
    printf 'REJECTED_ARTIFACT rule=%s path=%s\n' "$1" "$2" >&2
    failures=1
}

while IFS= read -r -d '' path; do
    # `git ls-files` includes files scheduled for deletion until they are staged.
    # Do not reject a removal merely because a developer runs this guard before
    # `git add -u`; committed CI will still inspect every extant tracked path.
    [[ -e "$path" || -L "$path" ]] || continue

    case "$path" in
        .env.example|.env.testing)
            # Deliberately non-secret, committed example/test bootstrap only.
            ;;
        .env|*/.env|.env.production|.env.backup|.env.local|.env.*)
            reject_path 'secret-environment-file' "$path"
            ;;
        cookies*.txt|*/cookies*.txt)
            reject_path 'browser-cookie-export' "$path"
            ;;
        fbr_retry*|*/fbr_retry*)
            reject_path 'ad-hoc-fiscal-retry-output-or-runner' "$path"
            ;;
        generate-annex-invoices.php|generate-full-demo-invoice.php|generate-nisar-invoices.php|generate-sample-modern-di.php)
            reject_path 'generated-invoice-helper' "$path"
            ;;
        database/production_data_export.sql|backup.sql|*.sql.gz)
            reject_path 'production-dump-or-backup' "$path"
            ;;
        database/deploy/*-production-sync/*)
            reject_path 'production-data-sync-bundle' "$path"
            ;;
        routes-update.zip|caller-app/TaxNest-PRA-Agent-Windows.zip|public/downloads/TaxNest-PRA-Agent-Windows.zip)
            reject_path 'unmanifested-archive' "$path"
            ;;
    esac
done < <(git ls-files -z)

# Report only file paths for likely credentials. The one explicitly fake RSA
# fixture is excluded by exact path; it is required to exercise the credential
# parser and is reviewed as non-functional test data.
secret_paths="$(
    git grep -I -l -E \
        '(BEGIN (RSA |EC |OPENSSH )?PRIVATE KEY|AKIA[0-9A-Z]{16}|gh[pousr]_[A-Za-z0-9_]{20,}|AIza[0-9A-Za-z_-]{20,}|xox[baprs]-[A-Za-z0-9-]{20,})' \
        -- . ':!tests/Feature/FcmKeyPresentLogDedupeTest.php' || true
)"
if [[ -n "$secret_paths" ]]; then
    while IFS= read -r path; do
        [[ -n "$path" ]] && reject_path 'credential-pattern' "$path"
    done <<< "$secret_paths"
fi

if [[ "$failures" -ne 0 ]]; then
    printf 'Repository artifact verification failed. Remove the artifact or add a reviewed, non-sensitive fixture outside production paths.\n' >&2
    exit 1
fi

printf 'Repository artifact verification passed: no prohibited tracked artifacts or credential patterns found.\n'