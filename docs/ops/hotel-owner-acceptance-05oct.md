# Owner Hotel acceptance notes — 5 October 2026 (PKT)

1. Owner supplied a matching original CARD bill and full return document, each for Rs 1, and confirmed both verify in PRA Sahulat. This supports the existing full Hotel return path; it does not prove the new PR #159 partial adjustment or separate actual refund settlement. Customer identifiers and uploaded documents are not committed.
2. Walk-in form screenshot: room, dates, agreed nightly rate and totals displayed before check-in.
3. After Check in, owner screenshot shows the browser native print dialog for a stay statement. Expected: an in-app bill review popup, with printing only on explicit request. Cause: both walk-in and reserved check-in redirected to statement?print=1.
4. Fix: check-in redirects to saved stay details and opens the shared bill dialog once. Owner requires payment, explicit PRA/local confirmation, checkout and receipt/print in that same dialog. Close keeps the recorded stay; amount/method edits require a fresh preview. Durable confirmation identity covers partial payment and checkout even when neither creates a new bill. Review itself does not issue a fiscal bill, collect money or submit PRA. The shared confirmation/receipt/PRA-status dialog from #156 is extended, rather than adding a second print-only popup.

PR #159 credit adjustments/refund settlement and the check-in popup correction are separate changes. Canonical CI is required on each final head; local PHP/Composer are unavailable.

5. Bills page screenshot: Correct / cancel entry remains visible for reported Hotel bills. Requirement: hide that correction/cancellation entry for fiscal-protected stays while retaining valid local correction access; verify direct-request protection too.

6. Transactions → New Invoice opens /pos/invoice/create while Hotel Restaurant Outlet is OFF, leading to the sale recovery screen. Use the Hotel check-in entry for this configuration; preserve the sale entry when Outlet is ON and for other categories.
7. Guests page has no Edit/Delete actions. Implement a guest directory profile separately from stay/fiscal records, so edits and directory deletion preserve original bookings and receipts.

## Final implementation scope (pending final-head CI)

One Hotel bill dialog handles check-in review, explicit full-bill confirmation, remaining collection, checkout and receipt printing. New UI bookings stamp actual folio collections/refunds by business date and drawer; full fiscal invoices do not pretend an unpaid balance was received. Legacy stays keep existing accounting behavior. Already-issued bills are reused at checkout without another fiscal document.

Guest profile edits/removal affect the directory and future-booking selector; original stays and invoices are preserved. Corporate/customer billing is an optional nested section. PRA status separates reporting permission, cloud/device route, recent agent heartbeat and last accepted branch-scoped Hotel fiscal invoice. Historical acceptance is not a claim of current connectivity.

Credit-note navigation is present in the dialog, while PR159 issuance/refund enablement gates remain explicit. No production testing or credentials were used.
