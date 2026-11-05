# Hospital ERP structure and acceptance boundary

The owner deferred external fiscal integration on 5 November 2026 and asked to finish the hospital structure and testing. This changes the integration acceptance sequence; it does not certify the fiscal adapter or authorize production deployment.

## Product and ownership

TaxNest ERP is the fourth SaaS product. A company owns its subscriptions, entitlements, branches, users, patients, clinical records, inventory and accounts. Central entitlements cap the modules an owner may enable. Disabling a module blocks its operations and retains its stored history. Suspension also blocks an already authenticated hospital session.

| Area | Operational structure |
| --- | --- |
| Reception / OPD | Patient identity, appointment, queue, doctor, consultation, prescription |
| Emergency | Emergency visit, triage priority and clinical observation |
| IPD / nursing | Wards, rooms, beds, admission, transfer, care records, advances, discharge |
| Maternity / theatre | Normal delivery or completed C-section, theatre, procedure, consumables, newborn record |
| Laboratory / imaging | Catalogue, charge, order, accession, signed report, encrypted correction history and PDF |
| Pharmacy | Prescription, batch, expiry, purchase, supplier payment, dispense and return |
| Accounts | Source receipt attribution, patient bill, cash/advance/refund, ledger, doctor shares, settlements and reports |
| HR | Staff profiles, shifts, roster, attendance, corrections, leave, approved monthly payroll input snapshot and export |
| Administration | Product directory, subscription, package/module limits, user/branch limits, activation/suspension, diagnostics, audit and targeted notices |

## Signed diagnostic correction

The initial released result is never overwritten. A correction requires result-signing permission, a reason, the revision the signer reviewed and a request UUID. The hospital/branch/department/confidential-chart gates apply before reading or writing the order. Corrections are serialized on the order; a repeat of the same request is harmless, a changed retry or stale revision is rejected. They do not create another charge.

The PDF identifies the current signed result and shows the original and intermediate results in a separate superseded-history section. Audit events contain identifiers rather than clinical text. Report access is audited. Clinical staff must communicate the correction to the treating team; automated critical-result alerts and acknowledgement tracking are not implemented.

## Six operating configurations

A: all nine modules, owned laboratory/pharmacy. B: independent laboratory/pharmacy with hospital-owned referral records and separate provider companies. C: no internal lab/pharmacy. D: OPD-only. E: maternity/gynecology. F: all services with multiple branches. Independent-provider referrals do not silently create provider revenue, stock movements or transfer confidential medical data.

## Boundaries that remain explicit

- Fiscal submission remains unaccepted for hospital section 237A and provincial reporting. Existing retail POS/DI mocks are engineering tests only. Do not switch the demo to production endpoints.
- The HR module is an approved attendance/payroll-input handoff. It is **not** a statutory salary calculation, filing or bank salary-disbursement engine. Accounts expenses/journals do not turn that handoff into an automated payroll run.
- Laboratory and imaging are manual clinical reporting workflows. No analyser interface, PACS/DICOM acquisition or device certification is claimed.
- PDFs and browser receipts are tested digitally. Physical hospital printers, scanners and offsite encryption-key custody remain installation acceptance steps.
- Existing finalized historical bills are not rewritten. A real facility with manually duplicated historical collections requires a separately reviewed reconciliation before migration; synthetic acceptance is not evidence about its real balances.

This is a concrete hospital-core review boundary, not a declaration that every feature of a nationwide commercial hospital product is finished. The original full-commercial objective remains open for these uncompleted items. A review PR must retain this distinction and must not be labelled deployment-ready.
