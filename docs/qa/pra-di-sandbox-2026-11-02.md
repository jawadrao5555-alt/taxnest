# PRA POS + FBR DI sandbox rehearsal

Status: opt-in hosted DI sandbox pilot, plus the original local rehearsal. FBR production reporting remains unavailable. No deployment, live receipt change or regulator approval is implied.

## Problem and resulting behavior

PRA POS bills previously had no separate, reviewable FBR DI rehearsal record. The new owner-only workbench captures an explicitly selected completed POS sale or return as a sandbox copy, freezes its amounts, lets a technical reviewer prepare the exact FBR sandbox payload, and records an explicit sandbox submission independently of PRA.

There is one original POS transaction. This workbench never creates a regular DI `Invoice`, stock movement, customer-ledger entry, second sale, or payment collection. Normal checkout, PRA reporting and customer receipts do not call it. This is a manual rehearsal; automatic checkout reporting remains future work after the legal and technical mapping is confirmed.

## Hosted company pilot (owner-operated after deployment)

1. Sign in as the active owner of an approved, active PRA POS company. Open **Receipt Settings → PRA + FBR Digital Invoice · Sandbox setup**.
2. Every company starts disabled. Select **PRA + FBR Digital Invoice · sandbox pilot**, enter that company's **DI sandbox token**, confirm sandbox-only use and save. The dedicated `pos_di_sandbox_profiles` table stores an encrypted, hidden credential; no existing company credentials or reporting settings are overwritten. Blank token means keep the saved value. Never send tokens through chat or PR comments.
3. Open the workbench, select a completed bill and explicitly authorize a sandbox copy. Capture alone transmits nothing. The buyer defaults are fictional placeholders for sandbox use and must be reviewed with the rest of the JSON payload.
4. Review the source snapshot, classification, buyer fields, federal tax and payload total. Save, then separately submit. The fixed sandbox endpoint is the only network destination. The workbench never sends to PRA itself.
5. Accepted acknowledgement enables the DI reference and QR on the watermarked 58/80mm combined test receipt. Do not issue it as a customer tax invoice. PRA details are the frozen source status at capture time, not a new PRA confirmation.
6. Disable the pilot to block new capture/prepare/submit actions. Previously stored evidence and the saved token remain; an already-started request may finish. Re-enable to review the workbench again.

The server kill switch `PRA_DI_SANDBOX_HOSTED_ENABLED=false` disables hosted setup and submissions. It defaults to true to make owner setup available after deployment, **not** to enable any company: a company's profile remains absent/disabled until the owner opts in. Hosted access is limited to `production`/`staging` application environments. Local/test environments retain the disposable-database gate. The Cloud Agent itself never connects to a live server or uses real company data/tokens.

### Compatibility checks

| Configuration | Expected behavior |
| --- | --- |
| Existing PRA-only company | No profile by default; no submissions, financial or receipt-setting changes. |
| Owner enables DI sandbox | Own encrypted credential; manual copy/review/send only. |
| Cashier or another company | Cannot change/read the first company's profile or submit its copy. |
| Blank token / disabled profile | Existing token preserved on edit; missing token cannot enable hosted pilot; disabled blocks sending. |
| Existing DI/FBR POS credentials | Never overwritten; hosted pilot never falls back to them. |
| Multiple windows/devices | Existing unique source/claim locks prevent duplicate submissions; profile creation serialized on company row. |
| Existing agents and nine receipt layouts | No contract or normal receipt changes; pilot uses dedicated test preview. |

## Enable and review locally

1. Use the disposable MariaDB setup in [Cloud Agent development](../ops/cloud-agent-development.md), with fictional data only. Apply migrations in that local database.
2. Set `APP_ENV=local` and `PRA_DI_SANDBOX_ENABLED=true`. The database must use the `mysql` driver, loopback host, no connection URL, and exactly `taxnest_dev` or `taxnest_staging`. Hosted environments use the separate explicit company opt-in described above. Tests also permit SQLite `:memory:` under `APP_ENV=testing`.
3. Sign in as the active owner of an approved, active POS company. Open **Receipt Settings → PRA + FBR DI sandbox rehearsal**, or `/pos/di-sandbox`.
4. Create/select a completed fictional bill and confirm that the source data is fictional. Capturing the same bill returns its existing frozen record.
5. Review the POS snapshot. Fill the blank scenario, HS classification, UoM, rate, sale type, federal tax and payload total using reviewed sandbox reference data. Save the payload; saving does not transmit it.
6. A deliberate sandbox transmission requires that fictional company's separately configured, encrypted `fbr_sandbox_token`. There is no production-token fallback. The new Sandbox setup page can save a dedicated pilot token; the legacy local credential is used only when no pilot profile exists. Never paste credentials in a PR or QA evidence.
7. Explicitly submit the saved payload, then inspect both 58mm and 80mm test receipt previews. Previewing does not print automatically. These watermarked receipts are not customer tax invoices.

The sender uses only `https://gw.fbr.gov.pk/di_data/v1/di/postinvoicedata_sb`, refuses redirects and ignores company endpoint overrides. Existing DI and IMS POS flows retain their existing behavior.

## Tax mapping still requiring confirmation

The implementation deliberately does not select a zero rate or exemption for a PRA service. FBR sandbox acceptance proves transport/validation acceptance, not the correct legal tax treatment.

Before designing production reporting, obtain the applicable written requirement and confirm:

- Which PRA businesses and supplies must report through FBR DI.
- The current service/HS classification, `saleType`, rate and any SRO/item requirement.
- The treatment of provincial tax in `totalValues`, and the correct federal `salesTaxApplicable` value.
- Which buyer fields are required for each applicable sale and whether reporting timing differs by supply type.
- Return/debit-note references and reconciliation rules for uncertain submissions.

Reference: [FBR DI API technical documentation v1.12](https://download1.fbr.gov.pk/Docs/20257301172130815TechnicalDocumentationforDIAPIV1.12.pdf). `SN019` is a sandbox scenario, not an exemption decision. Older API sale-type codes must not be assumed to establish a current PRA mapping. The zero-tax values used in mocked tests are hypothetical transport fixtures, not accounting advice or a production mapping.

## Record and receipt rules

| Case | Behavior |
| --- | --- |
| Cash, card, credit, split payment | Same completed-sale capture and explicit submission path; payment recovery is not a second sale. |
| PRA pending, failed or absent | Frozen PRA state remains separate; FBR acceptance cannot mark PRA accepted. |
| Missing historical line tax snapshots | Capture refuses to guess a base or tax amount. |
| Duplicate capture or submit | One record per company/source; a transaction lock claims one submission before HTTP. |
| Explicit rejection without any acknowledgement | Prepare a reviewed payload again before another attempt; encrypted attempt history survives. |
| Timeout, transport error, malformed/partial response | `verification_required`; no automatic retry, edit, acknowledgement QR or replay. |
| Interrupted process after submission claim | `submitting` stays blocked for operator reconciliation. No reset button claims it was rejected. |
| Accepted response | Requires a header reference and a valid, unique status for every line. |
| Return | Requires the original sale's accepted FBR sandbox reference in the same company. A PRA reference is refused. |
| Receipt | Original POS total and PRA tax stay intact. Separate, labelled PRA source and FBR sandbox blocks; FBR QR appears only after acceptance. |
| Access | Active owner only, company-scoped reads/writes, explicit CSRF protection and submission rate limit. |

`pos_di_sandbox_submissions` is separate evidence storage. Snapshot, payload and attempts are encrypted with the application key. Source-bill cleanup does not cascade into this table. Do not discard the local application key while evidence is required. No raw exception text, bearer token or arbitrary regulator response body is persisted.

Existing receipt themes, paper widths, show-tax settings, printer routing, numbering, PRA token/mode, DI settings and FBR POS settings are not rewritten. The rehearsal's dedicated test receipt is separate from normal saved receipt preferences.

## Verification evidence

Commands must run through the repository's isolated runner, which clears inherited credentials and blocks non-loopback network access:

```bash
bash scripts/rc-safe-run -- php vendor/bin/phpunit \
  tests/Feature/PraDiSandboxTest.php \
  tests/Feature/DiAcknowledgementClassificationTest.php \
  tests/Feature/PosReceiptThemeTest.php --testdox
bash scripts/rc-safe-run -- php artisan test --compact
git diff --check
```

- Focused HTTP/service tests cover source preservation, payment methods, encrypted evidence, independent PRA failure, missing sandbox token, route submission, tenant isolation, CSRF, owner access, production/default-off gates, return references, duplicate claims, incomplete acknowledgements, rejection/unknown handling and receipt QR conditions.
- Regulator responses in these tests are mocked. No real sandbox acknowledgement, tax mapping approval or physical printer result has been established.
- Local Chrome launch was blocked by the execution environment's Unix-socket restriction (`ProcessSingleton`: `Operation not permitted`). HTML route rendering was tested, but visual desktop/mobile and physical thermal-printer verification remain open.
- Continuing an earlier unrestricted full-suite attempt was rejected by automatic approval review because a legacy test could contact PRAL. That run is not accepted as validation evidence. The subsequent isolated command above is the permitted replacement.
- A separate, disposable loopback MariaDB 10.11 check passed: migration up/down, encrypted snapshot round-trip, the unique company/source constraint and the local database gate. It ran inside the same isolated process namespace as the database server; no shared database was used.

Final command outcomes are recorded in the PR description. Keep the PR in draft until the outstanding execution evidence has been reviewed. Hosted pilot setup is announced only to the PRA POS audience. FBR IMS selection, automatic checkout reporting, live DI, and the nine normal receipt formats remain outside this pilot.


## Hosted extension verification (3 November 2026)

Added regression cases for owner setup, encrypted/hidden credential storage, CSRF and consent, no token in flashed old input, existing settings preservation, tenant isolation, disable/re-enable boundaries, hosted transport company context and the global kill switch. All fixtures are fictional; HTTP responses are mocked. A production environment label in a test does not change its SQLite in-memory database or mocked network.

The refreshed Cloud workspace has no PHP/Composer runtime. The standard bootstrap could not start; package installation failed on unavailable container user/group operations. New runtime results must come from disposable GitHub checks. No local PHPUnit, browser or real FBR acknowledgement pass is claimed for this extension.
