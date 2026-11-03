# Tax Integrations correction to PR #331

Owner scope: Customize POS → Tax Integrations contains PRA, FBR POS IMS, and Digital Invoice. Receipt Display has no integration setup entry. Existing PRA settings, company product type, agent routing, printer options and company credentials must remain unchanged unless explicitly saved.

Compatibility matrix:
- Existing PRA-only owner → existing PRA settings, no new reporting by default.
- Existing DI Sandbox/Production profile → original token/environment/history preserved; official URL defaults shown when new columns are blank.
- PRA + IMS → separate evidence per source/environment, no FBR POS financial transaction or duplicate stock/ledger record.
- Second company/cashier/inactive company/view-only impersonation → no cross-company or unauthorized writes.
- Existing agents → existing PRA/FBR queues and callbacks untouched; alongside IMS uses a separate opt-in agent protocol.
- Frozen accepted/uncertain evidence → never auto replay, including after switching environments.

Verification will use synthetic data and blocked regulator egress. No live tokens or customer fixtures. PHP runtime is currently unavailable in this workspace; hosted CI supplies application runtime checks.

## Recovery and acceptance evidence

An explicit connection refresh is allowed only for drafts, definitive rejections and never-dispatched queued invoices. It clears the federal mapping for a fresh review, records the previous identity/hash in history and retains the frozen financial source snapshot. Dispatched/accepted/uncertain records cannot refresh or replay. Exact submitted payloads remain in encrypted attempt evidence.

Before the final recovery addition, isolated CI passed 55 focused application tests, native MariaDB migration/concurrency/DI checks, workflow/release safety, locked dependency/build guards, and the new real Chrome desktop/390px Tax Integrations journey. The final head adds a 56th recovery regression and exercises its explicit browser action. Final-head results are recorded in PR #335; full CI and live acceptance are separate.

Scope stays manual reporting and dedicated combined receipt previews. No real FBR/PRA call or live customer write was used to develop this correction.
