# TaxNest ERP ecosystem scope registers

Development scope approved on 28 November 2026 after the architecture review. The baseline is [#477](https://github.com/jawadrao5555-alt/taxnest/pull/477) at `0ffb5b0362c422942b4abc036c291d5775177335`, with the [owner-approved deployment and live verification](https://github.com/jawadrao5555-alt/taxnest/actions/runs/46126759570). That release is separate from the development below.

| Track | Register | Associated work |
| --- | --- | --- |
| A | [Shared platform foundations](shared-platform.md) | Focused additive foundation PRs |
| B | [HR & Attendance](hr-attendance.md) | #478 and #434–440 |
| C | [Laboratory ERP](laboratory-erp.md) | #444–453 and #474 |
| D | [Distribution ERP](distribution-erp.md) | Separate product PRs |
| E | [TaxNest Switch](taxnest-switch.md) | Separate product and connector PRs |
| F | [Enterprise 360](enterprise-360.md) | Separate Monitor, Manage and Full Enterprise PRs |
| G | [Upcoming Products](upcoming-products.md) | Early independent website PR |

## Tracking requirements and evidence

Requirement IDs in each register identify complete product scope. Link focused PRs to the IDs they advance and record the branch, head SHA, tests, real UI evidence when applicable, dependencies and open items. A phase can be Planned, Partial (baseline functionality exists), In development, Verified in staging, Blocked, or Released and verified. Testing and release statuses are distinct. Release evidence includes the owner approval, exact deployed commit, protected workflow and relevant live checks. A pilot passes only the requirements it demonstrates.

The 28 November approval brief is the controlling scope. The A–Z architecture review and original HR master brief supply project detail. Approval authorizes development. Commercial decisions, real customer operations, clinical acceptance, legally sensitive policies and production release use their own applicable approval paths. Preserve exact dates, receipts and history. Use isolated fictional data and Sandbox integration for development.

Parallel tracks may work with stable synthetic contracts. Shared scopes and access checks gate real cross-product reads and activation. Exact ledger and stock formats gate final posting and migration adapters. An unavailable vendor, device or credential blocks that integration only. Implementation work stays on focused branches and each product keeps its own integrated release candidate. Preserve source PRs until their full requirements, integration tests and applicable deployment evidence are accounted for.

## Common commercial and acceptance requirements

Every product must account for functional UI and workflows, independent registration and packages where applicable, product page, a permanent isolated fictional demo, Android/iOS where applicable, authorized import/export, Product Guide, tutorials, help, Madadgar, Super Admin and Live Ops. No demo issues live fiscal documents, accepts real payments, or contains real employee or patient information. Pricing, limits and commercial terms need owner approval. Unsupported capabilities must not be presented as live.

Candidate checks include exact-head CI, full PHPUnit, native MariaDB, relevant Node/browser checks, migrations and rollback, tenant/legal-entity/product isolation, IDOR, subscription preservation, concurrent operations and offline idempotency. Cover financial/stock reconciliation, medical integrity and employee privacy where applicable. Exercise backup/restore, performance and existing product regressions. Report functional UI evidence, fixture/simulator evidence and actual device/vendor acceptance separately. Retain and verify demo access after testing. Production release and actual live checks follow the existing protected workflow.
