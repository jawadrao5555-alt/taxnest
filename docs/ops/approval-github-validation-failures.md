# GitHub validation failures during approval claim

The 4 October PR153 owner workflows failed before merge with HTTP500. A trusted
read-only ERROR_SUMMARY report at 17:06:12 Asia/Karachi identified the two
16:22:34 / 16:24:48 exceptions as `GitHub pull request could not be validated`.
The old code threw a generic InvalidArgumentException for any non-success PR
response. The report did not capture GitHub's response status: rate limiting,
permissions and upstream outage are not established as the incident cause.

This change retries only read-only GitHub GETs on connection errors or 5xx,
at most three attempts. It does not retry 403/429 or any claim, merge or dispatch.
Persistent validation unavailability returns503 with numeric upstream status,
fixed stage and bounded Retry-After. Ineligible exact PR/SHA/check state returns409.
The Actions caller displays only these allow-listed fields, never raw response
bodies, headers, credentials or exception details. Existing successful claim,
OIDC, receipt, main-tip, validate and exact-SHA gates are preserved.

| Configuration | Required behavior |
|---|---|
| GitHub healthy and exact approved PR/check state | Existing claim and receipt behavior |
| Temporary connection/5xx error then success | Bounded GET retry, same validation gates |
| Persistent connection/5xx error |503, no claim/receipt/merge mutation |
|403/429 with retry hint | No immediate GET retry;503 with safe numeric status/delay |
| Draft, stale HEAD, foreign repository, failed validate | Remain ineligible; never authorized |
| Invalid OIDC | Existing403 before validation or state changes |
| Old server with generic error body | Caller logs HTTP status only |

This is transport recovery and error handling, not proof that a persistent
external GitHub failure has recovered. Do not weaken validation to deploy it.
If server access to GitHub remains unavailable, the normal owner release path
will remain blocked until that access recovers. Fresh approval is required
after expiry or when a PR HEAD changes.

Tests: DeploymentGitHubReaderTest covers recovery, exhausted retry,403/429,
bounded hints and redaction. OwnerDeploymentApprovalRelayTest exercises the
actual claim HTTP route and unchanged approval state. Python renderer tests
cover sanitized reporting; existing owner/dispatch guard checks remain required.
No UI, tenant settings, fiscal, printer or Agent contract changes.
