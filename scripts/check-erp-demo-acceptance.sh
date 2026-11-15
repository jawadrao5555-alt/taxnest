#!/usr/bin/env bash
# Synthetic HTTP/service acceptance only. This is not a production or browser probe.
set -euo pipefail
ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$ROOT"
exec bash scripts/rc-safe-run -- php vendor/bin/phpunit \
  tests/Feature/ErpDemoHospitalSeederTest.php \
  tests/Feature/HealthPilotJourneyTest.php \
  tests/Feature/HealthAuditWorkspaceTest.php \
  tests/Feature/HealthOpdCoreTest.php \
  tests/Feature/HealthPharmacyModuleTest.php \
  tests/Feature/HealthPharmacyBranchAccessTest.php \
  tests/Feature/HealthIpdOperationsTest.php \
  tests/Feature/HealthAccountsSettlementsTest.php \
  tests/Feature/HealthcareAttendanceTest.php \
  tests/Feature/HealthPilotSecurityTest.php \
  tests/Feature/HealthRegistrationPrePilotTest.php \
  tests/Feature/HealthBillingFbrTest.php "$@"
