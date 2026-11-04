# TaxNest Hospital ERP · issue 344

**Commercial release is on hold.** The branch contains the implementation and test evidence. Hosted PR CI and official fiscal certification are pending. Regulatory acceptance, clinical and payroll limitations, and the legacy-finance review below keep the commercial release open.

## Verification results

| Check | Result |
|---|---|
| Original healthcare baseline on main | 370 passed, 2,028 assertions and 25 PHPUnit deprecations |
| Repository-wide SQLite verification with sockets denied at OS level | 5,893 passed, 11 skipped, 0 failed and 0 errored across 5,904 cases with 48,619 assertions |
| Supplemental cases | The same 11 skipped cases passed under their required conditions. Two used the repository's loopback-only DI mock. Seven checked native MariaDB schema and KOT behavior. Two exercised competing POS cash and stock workers on a disposable MariaDB. These 11 are already included in the 5,904 unique cases. |
| Focused healthcare verification after fixes | 384 passed, 0 skipped, 0 failed, 2,219 assertions. The 25 PHPUnit deprecation notices are separate from failures. After the MariaDB IPD correction, 102 affected IPD, patient-journey and accounts tests passed with 776 assertions. |
| Browser profiles | A–F each exercised all nine services, 54 protected access checks. Seven service landing pages per profile were checked for access or rejection. Two write routes used nonexistent IDs to distinguish denied operations from permitted requests reaching record lookup. Real successful clinical writes were exercised separately. |
| Build and dependencies | Vite production asset build passed. Composer platform requirements passed under PHP 8.4.27. Application integration ran on MariaDB 10.11.14. |
| Hosted GitHub PR CI | No PR was opened, so there is no hosted PR check to approve. |

An initial unrestricted full-suite invocation was stopped by automatic safety review when an existing test attempted to reach the PRA IMS host. That run is excluded. The replacement SQLite runner verifies kernel socket denial before launching PHPUnit, including its children. The additional DI tests used a loopback test server with a simulated response. The synthetic hospital had FBR reporting disabled. The POS adapter test replaced the transport method. No production credentials were used.

The [machine-readable test index](test-results.json) includes individual healthcare case names and the supplemental case identities. The [lab runbook](runbook.md) contains the setup and reproduction commands. The [regulatory decision](regulatory.md) distinguishes the hospital EFD requirements from the existing retail adapter.

## Hospital profiles

| Profile | Configuration | Verification |
|---|---|---|
| A · TaxNest Demo General Hospital | Owned lab and pharmacy with OPD, IPD, accounts, HR, imaging, maternity and emergency | Nine services enabled. Real patient, signed results, pharmacy inventory, theatre, clinical records, cashier and discharge exercised. |
| B · Independent services | Hospital retains its other services. Lab and pharmacy operate under separate owners | Internal dispensing and internal lab production denied. Clinical referrals remain in the hospital's records. A linked test verifies retry-safe referral creation, cross-company refusal and zero hospital charges or stock movement. Two separately subscribed provider tenants are provisioned. No patient data is transmitted to them automatically. |
| C · No pharmacy or lab | OPD, IPD, accounts and HR | Four services enabled. Both absent services refused protected operations. |
| D · OPD-only clinic | OPD and core patient registration | Eight optional services denied. |
| E · Maternity home | OPD, IPD, maternity, accounts and HR | Five services enabled. Normal vaginal delivery and C-section are separate regression cases. A C-section record requires a completed operation for the same patient and admission. |
| F · Multispecialty | All nine services across the main and satellite branches | All nine access gates exercised. Branch and tenant isolation have separate negative HTTP tests. |

The two additional providers have their own company boundaries, administrator and package. Test rows are fictional. The demo initializer refuses nonempty, nonlocal and non-opted-in databases. It excludes the legacy data-only migration that otherwise inserts a named customer into fresh installs.

## One patient, registration to discharge

The named MariaDB fixture registered a second fictional patient, opened an emergency consultation, recorded triage and issued a prescription. It admitted the patient to a bed, took a PKR 500 advance, recorded a nursing observation, completed a C-section with a consumable, and linked the delivery record to the completed operation. The clinical desk signed and opened both laboratory and radiology PDFs. The pharmacy dispensed the prescription from the purchased batch. The patient went home with the bed released and a consolidated bill of **PKR 10,040**.

| Collection source | PKR |
|---|---:|
| Inpatient advance | 500 |
| Inpatient balance at discharge | 6,700 |
| Pharmacy counter | 40 |
| Central cashier for the consultation and two investigations | 2,800 |
| **Total collected** | **10,040** |
| **Outstanding** | **0** |

The second MariaDB instance restored this clinical case from the full dump. It contained both signed results, the delivery linked to the operation, the discharged stay, two patient invoices and nine healthcare payment rows. The verification compared 23 tables, decrypted tenant names, the clinical balance, stock results and the SHA-256 of a private lab-report attachment restored to a separate storage root.

## Feature and evidence matrix

| Feature | Exercised workflow | Evidence or boundary |
|---|---|---|
| Reception, doctors and OPD | Register, duplicate detection, appointments, queue tokens, consultation, vitals, prescription and fees | OPD core, pilot security and patient journey tests. Named general hospital includes medicine, obstetrics and surgery specialties. |
| Emergency | Clinical triage, nurse permissions, encrypted presenting problem, retry and conflicting update | Active emergency visit in the named hospital plus capability and OFF tests. |
| IPD, rooms and beds | Admission, atomic bed assignment, ward transfer, nursing note, room charges, advances, financial clearance, discharge and bed release | IPD suite and actual MariaDB discharge. The MariaDB test exposed an invalid SQL alias that SQLite accepted. |
| Maternity and surgery | Normal delivery, C-section, completed theatre record, charge, consumables, newborn outcomes and birth weight | Normal and surgical routes tested. Named C-section linked to the same patient's stay. No medical treatment recommendations were generated. |
| Laboratory | Catalogue, order, sample accession, signed result, encrypted storage, PDF, retry and cross-tenant protection | Real lab desk plus controller tests. Released results reject overwrites. A clinical amendment/reissued-report workflow and analyser interfaces have not been validated. |
| Radiology | Catalogue, order, study accession, signed report, protected PDF | Named imaging order and PDF plus access and isolation tests. Image acquisition devices and PACS/DICOM exchange were not exercised. |
| Pharmacy | Prescription dispensing, batch movement, purchase cost, stock balance, expiry handling and returns | Pharmacy checkout/stock suites plus a two-unit dispense in the named patient journey. |
| Procurement and suppliers | Receive ten units at PKR 12, pay PKR 30 at receipt and another PKR 40 | Browser submitted stock and payment forms. Purchase PKR 120, paid PKR 70, balance PKR 50. Eight units remained after dispensing two at PKR 20. |
| Patient billing and day close | Finalization, advance, partial payments, cancellation, excessive-refund rejection, split surplus, cashier shift and day close | Billing and accounting suites. Named first bill PKR 1,500 paid in two instalments with a PKR 200 refund. Posted debits and credits both PKR 3,200 at that checkpoint. |
| Cross-module cash | Already-paid OPD and pharmacy lines, a single inpatient advance allocated across bills, net of an earlier refund | A reproduced PKR 1,700 overstatement now settles to zero without another payment. A PKR 1,000 advance less PKR 200 refund funds two separate bills only once. |
| Doctor commissions and settlements | Accrual from posted bills, repeat accrual, approval, payout, reversals and reconciliation | Accounting and pilot journey suites. A 40% share of PKR 2,500 accrued PKR 1,000 once. |
| HR and payroll | Staff profiles, roster, attendance, corrections, leave approval, payroll monthly locking and permissioned export | Attendance and readiness suites plus a worked day in the named hospital. The existing module produces payroll inputs and an indicative amount. Statutory deduction filing and salary disbursement are outside the verified workflow. |
| Privacy, RBAC and audit | Confidential clinical chart, treating-doctor access, branch and department scope, denied foreign IDs, audited sensitive access and exports | Security, pharmacy-branch, audit, IPD and diagnostic tests. No production patient records were used. |
| Concurrency and failure recovery | Competing deposits, refund caps, stale fiscal mirrors, duplicate requests, safe retries, sample phase ordering | Independent MariaDB workers returned the same receipt for one PKR 125 deposit. Two competing PKR 1,000 refunds produced exactly one acceptance. |
| SaaS Admin | Fourth product navigation, current and legacy ERP directory, new maternity company, package/module limits, subscription assignment, suspension/reactivation, support and diagnostics | Central policy masks tenant choice, including an explicitly empty package. Audit captures entitlement changes. PHP and browser tests verified the screens, permissions and isolation from other products. |
| Product notices | ERP-specific audience, legacy ERP discriminator and retail exclusions | Announcement audience tests verify preview and query results agree. |
| Receipts, export and printing | Application-generated PDF and receipt payload, QR rendering and decoding, locked payroll/export and audit-pack permissions | Mock fiscal receipt QR decoded to the acknowledged synthetic number. No physical printer or scanner was attached. |
| Backup and restoration | Complete disposable SQL dump restored to an independent MariaDB instance with the matching test key | The [acceptance evidence](acceptance.json) contains the dump digest and matched fingerprints. Private attachment bytes also matched after restoring to a separate storage root. Offsite key recovery and physical printer output remain unverified. |

## Bugs reproduced and corrected

- A suspended hospital session still returned HTTP 200 for the patient register. Healthcare rechecks central suspension, logs out the session and enforces reactivation before access resumes.
- Creating a non-FBR-POS hospital through central Admin explicitly inserted NULL into the non-nullable FBR POS environment column. The create path now leaves that unrelated value to the schema default. The new ERP organisation was created, subscribed and suspended/reactivated through normal routes.
- Duplicate submission of a PKR 600 payment against a PKR 1,000 bill overstated both the payment and the patient's surplus. Cashier forms now carry replay keys. Deposit and refund writes lock patient/bill state, reject changed reuse and commit the receipt and its replay record together.
- An already-paid OPD visit and pharmacy sale remained outstanding on the consolidated bill. New finalizations recognize eligible source collections, preserve the original source and receipt time, and cap allocations at the remaining amounts. Inpatient advances are shared once across related bills. Edits to an OPD fee on a finalized bill are refused.
- A retry from an old bill instance attempted to insert a second FBR mirror and hit a unique constraint. The adapter now locks and reloads the bill, reuses the tenant-scoped mirror and preserves the transport hash. Read-only reconciliation no longer inflates the retry count. Attempt records are serialized per bill, and a reported success without an invoice number cannot mark the hospital bill submitted.
- MariaDB rejected the reserved alias `lines` in the IPD summary and report. Both queries now use `line_count` and retain the existing report field names. Acceptance command exceptions now produce a failing exit status.
- Two unrelated POS regression tests used a calendar date for data governed by the active shop business day. Corrected fixture/report dates made the existing cashier isolation and PDF assertions deterministic after midnight. Retail product behavior was unchanged by those test fixes.

## Release gates and owner action

1. Obtain and validate the hospital-specific EFD/SDC contract, licensing, sandbox onboarding and regulator acceptance. The retail POS mock does not certify section 237A.
2. Verify the applicable provincial reporting route, service classification, payload, acknowledgement, correction and reconciliation. No hospital-specific PRA submission has passed in this branch.
3. Validate the facility's clinical amendment and sign-off workflow, statutory payroll requirements, physical printing and offsite key recovery. Existing finalized legacy accounts that may contain manually entered receipts for already-collected charges need a migration reconciliation trial. This branch does not rewrite historic finalized bills.
4. After those gates close, rerun the suite and create the PR with migrations, release notes and rollback evidence. Wait for **PR checks / validate** on the exact final head before owner approval. Follow the [owner procedure](../../ops/owner-merge-and-deploy.md). Until then, this branch is for review and further development only.
