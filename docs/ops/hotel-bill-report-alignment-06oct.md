# Hotel bill/report alignment and credit preview

Hotel Bills lists stays; Tax Reports lists fiscal/local sale and credit documents. A stay can have multiple invoices. Counts therefore need not match. The original POS invoice identifier must match across both screens.

The Bills view now shows every visible linked document, its original number, amount and fiscal status, plus separately labelled stay totals, net guest payments and remaining balance. Tax Reports links the corresponding stay beneath the existing invoice number without changing report money or export calculations. Archived documents retain the scoped receipt path. Hidden billing/cashier documents are not exposed and combined money is withheld if documents are not all visible.

Credit review distinguishes original unrounded line tax from rounded credit header tax. It now mirrors issuance residuals for previously credited lines and the final remaining invoice, including header total/tax caps and exempt inclusive lines. It does not change the original invoice or issue any document.

Compatibility: reported exclusive-tax discounted line with 14.40 item tax / 14 header tax; existing zero-tax review; partial then final residual; inclusive card-save; original tax snapshots; legacy and archived invoices; different tenant, branch and cashier/billing scope. Existing CI exercises the original UI on desktop and 390px mobile.

Local workspace maintenance removed the previous repository. Recovery through unauthenticated git clone was unavailable; source was retrieved through the authorized repository API. PHP/Composer are unavailable in this execution environment. Runtime reproduction and retest use canonical disposable Actions CI; local Node syntax checks alone are not runtime proof.

## Credit-note activation remains unresolved

The default issuance/refund gate is intentionally unchanged. The owner screenshots confirm preview navigation, not Hotel full/partial PRA credit acceptance. Actual sandbox acceptance, merchant RefUSIN matching, retries/duplicates, nonzero-tax reconciliation, supported legacy-agent transport and return-period procedure remain required before write activation. Tests with synthetic accepted status cannot replace that evidence. No production credentials, live invoice experiments, refund or fiscal writes are performed by the agent.
