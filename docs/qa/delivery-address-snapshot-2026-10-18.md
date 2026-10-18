# Delivery address missing from preview and print

Reported: address missing on 3–4 of 10 delivery bills, in both preview and print. Source trace: both receipt widths read the persisted delivery_address snapshot. Client loadCustomerAddresses clears that snapshot while fetching; stale responses are not scoped to the current customer. Modal/lookup/quick-create selections do not initialize delivery selection. Held/provisional recall briefly clears and later re-pins the original snapshot, including from an obsolete response. Payment can serialize during the empty interval. These are reproduced code-level failure paths, not verification of the owner's historical bills.

Before-change compatibility matrix:

| Case | Required behavior |
|---|---|
| Saved customer default + slow/failed address book | Known default immediately available and preserved |
| Alternate address chosen / held bill snapshot | Preserve exact choice; no mutable default substitution |
| Customer A response arrives after B selection | Ignore A response |
| Customer/modal/lookup/inline/quick-create selection | Same address initialization |
| No known address yet + lookup in flight | Await current bounded lookup before serializing payment/hold |
| Explicit empty address / walk-in / takeaway | Preserve existing optional semantics; never invent address |
| Old receipt / other company / another counter | Never rewrite historical snapshots or share state across customers |

Local PHP/Chrome/MariaDB unavailable. Node behavioral tests extract the real sale methods and simulate delayed responses using fictional customers. CI must run those and the actual authenticated sale screen at desktop/390px; no live customer/company access. Owner controls merge/deploy. Preview/print may only be called verified after their relevant checks pass.

Post-change local validation: 14 real-method behavioral Node tests PASS (defaults, late A/B response isolation, historical alternate snapshot, modal/lookup selection, offline default, submission barrier, manual alternate, clear cart and intentional empty choice). CI runs these under egress blocking. Added authenticated desktop/390px browser regression to the mandatory table-order journey: create a synthetic customer, delay address book, attempt an actual provisional bill, assert no premature POST, then verify stored payload/receipt preview and print CSS contain the address. No regulator posting or production access. PHP/full suite and this Chrome proof are pending exact-HEAD CI; no physical printer or historical-company repair claimed.

If the current lookup fails and there is no known/selected address, saving stops with the existing translated network-error feedback instead of silently serializing an unknown empty address. A successful empty address book remains optional, as do walk-in/takeaway flows. Known snapshots do not wait on a slow book. Cancelled deletions do not change selection state.

Offline recovery: a cashier-entered new address becomes a visible one-off bill snapshot immediately. Failure to save the reusable customer address book still gives the existing failure feedback, but does not discard the typed address or block that bill. Confirmed saves replace the temporary option with the server row. Extra behavioral tests cover failed/offline and confirmed saves.
