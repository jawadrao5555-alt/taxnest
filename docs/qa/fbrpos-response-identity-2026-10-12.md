# FBR POS fiscal response identity

A Code-100 response message must not become a fiscal number. The server also requires the callback number to agree with the number returned in a supported response field. Mismatches are held for reconciliation without changing historic fiscal identities.

Compatibility: InvoiceNumber, FBRInvoiceNumber and invoiceNumber; numeric/string Code 100; bounded legacy Response identifiers; PRA fiscal-device and cloud clients; older FBR agents remain accepted when their callback carries matching genuine response evidence. No saved tenant setting or existing number is rewritten.

Reproduction: the actual agent submitToPra flow, loaded with a fake loopback transport, failed the prose-number and alternate-field cases before correction while its existing InvoiceNumber case passed. The unchanged PHP controller also failed the new quarantine regression. After correction the agent flow passes all three cases; FBR callback/evidence feature tests pass 19 tests / 103 assertions. No regulator endpoint was contacted.

Full suite is being evaluated separately. Browser is N/A: this change has no UI. Agent packaging/release remains a separate owner process; server checks protect against unsafe legacy callbacks without waiting for every agent to upgrade.

USIN remains the retailer's local invoice number, separate from the returned fiscal identity. Screenshots of another system repeat a portal USIN value while displaying different retailer numbers; they do not establish the source payload or prove duplicate submission. Do not imitate that constant or rewrite existing invoice numbers.

13 October release update: merged deployed main through PR 187 without conflicts.
Current guarded PHP evidence suite: 12 tests / 67 assertions PASS; actual-agent
VM flow: 3 tests PASS. Broader full PHPUnit and native/browser CI must run on
this new PR's exact SHA before approval. No local UI change and no regulator
submission. Remaining audit corrections and their dependencies are tracked in
GitHub issue #194; this PR implements only response/callback identity parsing.
