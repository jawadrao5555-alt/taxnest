# Deployment approval read recovery — iteration 1

Owner reports Admin PR verification HTTP 403; subsequent relay claim returned HTTP 409. These are different failure classes. A prior GitHub response carried a rate-reset hint; the exact current production 403 cause and current approval row are not established locally.

All GitHub eligibility and claim validation reads now use one reader, optional server-only read credential, bounded shared 403/429 cooldown, and safe retry guidance. Public reads remain the default. No successful eligibility decision is cached for authorization. Stale claim state is rejected before network reads, then rechecked under lock after fresh GitHub verification. Exact SHA, current checks, owner decision, lease and provenance gates remain.

Optional `TAXNEST_DEPLOYMENT_GITHUB_READ_TOKEN` must be configured only by a trusted operator, with least-privilege repository read permissions, through the existing secure server configuration process. Never place the credential in browser code, PRs or logs. This setting is not a dispatch/merge credential. This PR does not configure production and cannot guarantee GitHub service availability or repair an already-expired approval automatically.

Regression evidence: configured synthetic token and shared cooldown tests fail on the previous reader and pass with the change. Expiring claim must return 409 without sending HTTP; valid claim regression remains covered. Existing owner approval/settings/customer data are not migrated or reset.

Backend-only change: no browser rendering claim. Full required CI (including desktop/mobile browser and MariaDB) must pass at the published head before release readiness. No merge/deploy/live verification performed by the agent. Faint error colours are a separate existing theme PR scope.

## Follow-up verification

Expanded local approval run found one obsolete expected message in SmartDeploymentApprovalTest. Updated the HTTP feature assertion to require the new stage/403 text and 120-second retry guidance, and reject private upstream response text. Re-test: 6 tests / 29 assertions pass. Prior reader/eligibility/relay run: 51 / 223 pass. Unit suite: 273 tests / 10901 assertions, one existing PHPUnit deprecation. Relay dispatch and owner merge safety static checks plus 3 claim-error Python tests pass.

The initial full local attempt was incomplete and used a runtime without ZIP/GD and a PHP child-command wrapper; it is not a full-suite acceptance result. ZIP regression re-test: 3 / 7 pass; GD invoice-reader re-test: 21 / 100 pass after loading local extensions. No tracked runtime or dependencies changed. Fresh final-head CI still governs full PHP/browser/MariaDB acceptance; no historical green promoted to final proof.
