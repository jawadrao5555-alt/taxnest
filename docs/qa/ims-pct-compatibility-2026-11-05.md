# PRA alongside IMS: optional catalogue PCT

## Reproduction and root cause
On main ecb019ad33325686fd65d9f5704879afe3d6106d, the automatic reporting
controller requires a dotted HS code for both DI and IMS. Reproduced through
the real Laravel POST route: a reviewed IMS rule with an empty catalogue code
redirected back with `The hs code field is required.`

Standalone FBR POS already emits the eight-character transport value
`00000000` when its catalogue has no HS code (FbrService::buildFbrPosPayload).
The alongside IMS payload and manual preparation template did not match it.
This patch reuses that existing compatibility behavior; it does NOT prove
that every installed IMS accepts that value. No statute is cited as evidence
that the protocol field itself is optional. PCTCode stays present and its
eight-digit backend validation remains enforced. DI still requires its HS code.

## Compatibility matrix
| Configuration | Evidence / behavior |
| --- | --- |
| IMS, no catalogue PCT | Reviewed save succeeds; one queued_agent record, PCTCode string 00000000 |
| IMS, existing reviewed PCT | Existing mapping continues to produce the original 8-digit code |
| IMS, malformed supplied code | Rejected; saved rule version remains unchanged |
| DI, missing/invalid HS | Rejected; previously saved mapping preserved |
| Manual IMS preparation | Same compatibility default; malformed payload still rejected |
| Legacy Agent JSON and new SOAP | No contract changes; string leading zeros retained in SOAP |
| Repeated enqueue/dispatch | One outbox/submission; original sale untouched |
| Another tenant/device or uncertain response | Existing isolation, claim and unknown-outcome regressions retained |

No migrations, automatic enablement, legacy bill backfill, tax assumptions,
changes to saved rules or Agent release are included. The separately reviewed
tax rate remains mandatory. Existing catalogue codes are not replaced.

## Local validation
- Pre-fix: new IMS missing-code regression failed on the real save route;
  DI missing-code rejection passed.
- PHP focused: PraDiSandboxTest, PosFederalAutomationTest,
  FbrPayloadPrecisionTest and FbrPosReturnCreditNotePayloadTest:
  **79 tests, 534 assertions passed** (PHP 8.3.6).
- Agent transport, claims, callbacks, unknown outcomes and SOAP: **18 passed**.
- Chrome: real Laravel-rendered DI and IMS forms at 1280px and 390px;
  IMS blank-code form interaction and DI-specific fields checked; no pageerrors.
  This is rendered-form browser coverage, NOT a complete browser save journey;
  actual HTTP save and service dispatch are covered by the feature tests.
  Screenshots: .local/browser-evidence/ims-pct-optional-{1280,390}.png and
  di-hs-required-{1280,390}.png (untracked).
- Full PHPUnit attempted locally. ComposerPlatformContractTest rejects local
  PHP 8.3.6 because the repository requires PHP ^8.4.1. No platform requirement
  or test was weakened; exact-head hosted PHP 8.4 CI remains required.
  Automatic approval review subsequently blocked continuation of that run
  because an external PRAL IMS HTTPS request was detected. No workaround or
  retry of that external access was attempted. The local full suite is NOT a
  pass; focused tests prevent stray Laravel HTTP requests and ran successfully.
- Native MariaDB server unavailable locally. No schema change is introduced;
  hosted MariaDB checks remain required.

## Acceptance boundary
No production credentials, company settings or fiscal submissions used.
Punjab Plus's installed Windows IMS acceptance and central FBR acknowledgement
remain unverified. Synthetic response tests cannot establish regulator
acceptance. Owner review and deployment are separate from PR preparation.
