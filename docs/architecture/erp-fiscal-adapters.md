# ERP fiscal adapters

ERP owns patient billing, payment, stock and journal entries. Fiscal transport is a separate opt-in side effect of one final ERP bill. Keep the existing HealthBillFbrService and HealthBillFbrTest as the baseline for the healthcare FBR service flow. Do not route every hospital charge into standalone retail FBR POS.

| Flow | Candidate use | Existing component to evaluate |
| --- | --- | --- |
| ERP to FBR POS | Applicable pharmacy or retail counter sales after approval of the fiscal classification and device setup | FBR POS IMS transport and claim/reconciliation. Keep ERP's sale, stock and accounting entries single |
| ERP to PRA POS | Service bills only where the approved registration and applicable classification require PRA | Existing PRA reporting transport. Store an independent PRA result and account for the approved mapping |
| ERP to DI | Applicable DI goods or services and documented registration/mapping | DI payload validation, invoice/credit-note snapshots and acknowledgement recovery |

1. Persist one immutable outbox intent per tenant, branch, source bill, environment and approved channel, atomically with final ERP billing. No draft, test patient payload, unrelated tenant, or unconfigured channel is dispatched.
2. Freeze source lines, classification, amounts, buyer fields permitted by the fiscal interface, original fiscal reference for returns, environment, credentials identity, and terminal or device binding. Keep clinical details out of the regulator payload and diagnostic logs.
3. Do not duplicate the same federal reporting obligation through FBR POS and DI. Gate a change of channel behind checked reconciliation. Separate local acceptance and central evidence. Unknown outcomes stay on hold for verified recovery, preserving the source identity.
4. Return durable status, fiscal number and QR to the ERP receipt, while retaining the ERP invoice reference. Read models and audit logs must be scoped and redact clinical data.
5. Gate each adapter with mock and sandbox tests for opt-out, wrong tenant/branch, wrong environment, duplicate and concurrent workers, timeout, accepted-late, original credit note and rollback. Verify actual regulatory applicability, fiscal device and sandbox eligibility before enabling a real connector.
