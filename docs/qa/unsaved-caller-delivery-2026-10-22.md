# Explicit caller Bill: consistent Delivery type

Owner video `a0fc2d7b-2d8d-43e8-b23a-650f29532ce3.mp4`: a caller without a saved customer remains Takeaway after attaching to the bill. The actual callerBillFrom method changed to Delivery only inside its matched-customer branch.

Before patch, the new regression reproduced `takeaway` instead of `delivery` (1 failed/3 passed). Move the existing feature-gated transition after either customer-attachment branch. A passive incoming ring remains non-mutating; the explicit Bill/attach action follows the same existing policy for both identities. Saved-customer identity/address, existing cart and shops without Delivery are preserved.

Local production-method caller + delivery-address regressions: 18/18 pass after patch. Browser acceptance extends the real sale/delivery journey with a fictional unsaved caller and screenshot at desktop/390px; CI pending. Supported PHP/application browser sockets unavailable locally, so no local full-suite/browser or independent live acceptance is claimed. No real calls, customer data or print jobs used.
