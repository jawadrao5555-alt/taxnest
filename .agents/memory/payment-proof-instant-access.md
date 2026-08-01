---
name: Payment-proof instant access
description: Auto 10-day temporary override on payment-proof upload; how it's identified, revoked, and its safeguards
---

# Payment-proof instant 10-day access (owner approved, Aug 2026)

Uploading a payment proof while LOCKED auto-grants a 10-day `override_type='temporary'` on the subscription row — same mechanics as an admin grant, so the existing expired-grant reconciler auto-expires + demotes it if the admin never acts.

**Rules:**
- Auto-grant fires ONLY when: company not internal/suspended/rejected, NO prior rejected proof (owner safeguard — after any rejection, re-uploads get no bridge access), no other active override (never stomp admin grants), and hasAccess() is currently false.
- The auto grant is identified by `override_by IS NULL` + `override_reason` containing `payment proof #{id}` (+ `payment_proofs.auto_access_until`). Admin-granted overrides must NEVER match this signature check.
- Reject revokes exactly that grant and demotes to pending/pending only if the company then truly lacks access (mirrors reconciler safety). Approve = SubscriptionAssignmentService::assign + unlock BOTH status columns (never un-suspend).

**Why:** owner wants zero-wait renewal UX but no way for a rejected payer to keep farming free access by re-uploading.

**How to apply:** any new proof-upload or admin-decision path must preserve the grant signature and the ever-rejected check; don't add auto-access to other upload surfaces without these safeguards.
