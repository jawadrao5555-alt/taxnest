# Public PRA buying clarity

Original: public add-on picker showed only selected add-on charges; the buyer had to add the package price mentally and could miss a cheaper included package. Registration separately displayed plan and add-on subtotals.

Show package price, add-on subtotal and combined annual total. Read package totals from SubscriptionAssignmentService::computePrice, the same service used by registration. Recommendation candidates must preserve the current package gates and limits and include every selected add-on at a lower total. The recommendation is optional and does not replace the chosen plan. Its link selects that plan without charging included add-ons again.

Compatibility: manual quote only; no tenant, billing, payment or entitlement writes. No add-on price changes or plan migrations. Existing URL/session server validation remains authoritative. No selection, single selection, both selections, an included-feature alternative and registration handoff are relevant cases. Existing sale discounts are read on each request; server recalculates later charges.

Verification: local JS/shell syntax and whitespace checks pass. PHP/Chrome are unavailable locally. Added real-browser desktop/390px flow selects both actual checkboxes, checks combined total and cheaper included package, clicks registration and verifies carried total, then verifies the included package is not charged for add-ons. Isolated CI also runs the real PHP package-compatibility regression and full existing suite. No deployed/real-payment claim until owner approval, relay and live verification.

WhatsApp manual bill sharing is explicitly distinguished from optional automatic sending; availability requires confirmation until that separate implementation is released. Compatible app/permissions/connectivity dependencies are stated without promising new APK availability.
