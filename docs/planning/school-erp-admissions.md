# School ERP admissions foundation

This is the initial data slice for the full School ERP workstream referenced by #314. The School Fees POS workspace continues to own its balances, receipts and fiscal snapshots. No names, amounts or payment history are copied from it. A fee student reference alone does not identify an ERP student and must not be used for an automatic link.

The foundation gives each school its own student reference and academic term codes. A student may have one enrollment per term and the database rejects enrollment against another school's student or term. The draft stores only the minimum student identity and dates needed to establish this boundary.

The admissions service validates the school, student, term and admission date. A repeated admission returns the original record when the date matches. A different date requires an explicit correction flow.

Before exposing data, add the dedicated School vertical and authorized campus membership, verify the package gate and admissions permissions, then add the admissions and term workspace. Cover campus transfers, authorized reads, duplicate requests and incorrect identifiers at the HTTP boundary before enabling onboarding. Billing integration can follow with an explicit School ERP to fees account mapping and approval.

SQLite schema tests exercise tenant isolation and duplicate enrollment. MariaDB migration and rollback, role/campus isolation in the eventual UI, and workflow acceptance remain pending.
