# PRA POS + FBR DI: Sandbox and Production

The same PRA POS source bill can have separate DI evidence in each environment. This implementation reuses `FbrService` acknowledgement handling and the DI endpoints already used by the standalone DI product. It does not create a regular DI `Invoice`, another sale, stock movement, revenue or customer-ledger entry.

## Owner workflow

1. Receipt Settings → **PRA + FBR Digital Invoice · Connection setup**.
2. Save the company's **Sandbox token**, select **Sandbox**, enable and confirm test use.
3. Open the workbench, select a completed bill, capture its test copy, review the exact JSON mapping and submit. Required scenario IDs apply only in Sandbox. A complete accepted FBR response enables its test reference/QR. Capturing/saving alone transmits nothing.
4. Check the company's applicable scenarios in FBR/IRIS. Local accepted scenario IDs are displayed as evidence, not as a certification that all required scenarios have passed. FBR issues the production token after the applicable scenario requirements succeed; TaxNest does not issue tokens or automatically activate Production.
5. Save the separate **Production token**, choose **Production**, and explicitly confirm completed FBR onboarding and reviewed tax mapping. Existing tokens are preserved when their input is blank; both are encrypted and never echoed/flashed.
6. Select a real completed bill, explicitly confirm Production capture, review live buyer/classification/tax/total values, save and separately confirm **Submit to FBR PRODUCTION**. The Production template has no fictional buyer defaults or `scenarioId`.
7. Inspect the accepted Production reference and compact combined receipt. Sandbox receipts remain watermarked. Pending/unknown Production records are labelled preview, without an FBR logo/QR claiming acceptance.

This is manual reporting from the workbench, not an automatic checkout hook. FBR POS (IMS) choice and the nine normal receipt layouts remain separate work. PRA continues through its existing cloud/agent connection; this workbench never resends to PRA.

## Environment invariants

| Case | Required behavior |
| --- | --- |
| Existing company or old settings | DI remains off unless previously explicitly enabled; existing profiles/records default to Sandbox. |
| Switch Sandbox ↔ Production | No automatic sends, payload promotions, reference reuse or alteration of history. |
| Same source bill in both environments | Unique `(company_id, source_transaction_id, environment)`; separate immutable snapshots, payloads, attempts and references. Production requires explicit new capture/review. |
| Missing selected token | Refuse submission; never fall back to the other environment or to company production credentials. Legacy local-only Sandbox may use its old local sandbox credential if no profile exists. |
| Stale browser form | Production capture requires its explicit environment and consent. Prepare/submit refuse a record whose environment is not currently selected. |
| Return | Original sale must have an accepted DI reference in the same company and environment. A PRA or Sandbox reference cannot adjust Production. |
| Timeout/partial response/interrupted claim | Block retry/edit; reconcile externally. Never claim success or auto-reset an uncertain request. |
| Accepted/rejected | Accepted requires a header reference and valid unique acknowledgement for every line; explicit rejection can be reviewed and prepared again. |
| Disable reporting | Stop new work; preserve history and receipt reads. An already-started network request may finish. |
| Multiple windows/devices | Company/source locks plus the unique environment constraint and exclusive submission claim prevent duplicate sends. |
| Another tenant, staff, read-only impersonation | No cross-company access or owner-only setting mutation; existing impersonation middleware remains in force. |
| Offline browser | `/pos/di-sandbox*` remains network-only; no stale offline fiscal evidence. |

The physical tables and route names retain `sandbox` for compatibility. Records now carry an immutable reporting environment; `sandbox_invoice_number` and `production_invoice_number` remain distinct. Setup stores `production_confirmed_at/by` as the owner's onboarding attestation, not an FBR certification result. Existing successful scenarios from another integrator are not fabricated or inferred.

## Endpoints and controls

- Sandbox: `https://gw.fbr.gov.pk/di_data/v1/di/postinvoicedata_sb`
- Production: `https://gw.fbr.gov.pk/di_data/v1/di/postinvoicedata`
- Endpoint choice is fixed by the captured environment. Company URL overrides, redirects and automatic POST retries are refused.
- `PRA_DI_SANDBOX_HOSTED_ENABLED=false`: hosted workbench kill switch (legacy name retained).
- `PRA_DI_PRODUCTION_ENABLED=false`: block production activation/submission without disabling Sandbox.
- Local rehearsal: `PRA_DI_SANDBOX_ENABLED=true`, local disposable MariaDB guard or testing SQLite `:memory:`. No real tokens/data are used in Cloud Agent verification.
- New migration defaults old rows to Sandbox and preserves their references. Its downgrade refuses to discard production records or stored production credentials. Roll back application code while preserving fiscal evidence instead.

## Mapping and receipt limits

PRA tax does not imply an FBR exemption or zero rate. Classification, sale type, rate/SRO, buyer fields, federal tax and the treatment of provincial tax in DI totals require review for the company. No automatic tax mapping is introduced. Source quantities/net bases remain fixed and discounts are not deducted twice. Production receipt shows the original customer total and separately labels FBR reported tax/total; those figures do not create a second payment demand.

References reviewed: [FBR DI user manual v1.4](https://download1.fbr.gov.pk/Docs/20256251162757102DIUserManualV1.4.pdf), [DI API v1.12](https://download1.fbr.gov.pk/Docs/20257301172130815TechnicalDocumentationforDIAPIV1.12.pdf). Actual FBR acceptance proves API acceptance, not legal mapping approval.

## Verification

The standard CI lane runs the focused suite before the broad suite:

```bash
bash scripts/rc-safe-run -- vendor/bin/phpunit tests/Feature/PraDiSandboxTest.php --testdox
bash scripts/rc-safe-run -- node scripts/sw-smoke-check.mjs
bash scripts/verify-repository-artifacts.sh
git diff --check
```

Coverage includes separate tokens/endpoints, encrypted/hidden credentials, CSRF and owner confirmation, old-input secrecy, tenant isolation, same-source dual-environment records without duplicate financial entries, stale-mode refusal, Production scenario rejection, same-environment returns, unknown/replay blocking, disabled history access and environment-specific receipts.

All regulator responses and credentials in tests are synthetic. Even tests labelled Production execute only against an in-memory test database and mocked HTTP with process egress blocked. No Cloud Agent production access, actual FBR submission or deployment has occurred. Local PHP/Composer is unavailable in the refreshed workspace; JavaScript/PHP syntax checks run locally and PHP behavior runs on the disposable CI runner. Local Chrome/physical printer and real regulator acceptance are not claimed. Current exact-head outcomes are kept in the PR description.
