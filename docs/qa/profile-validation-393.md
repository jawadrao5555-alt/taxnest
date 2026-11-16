# Scoped profile validation (#301 / #321)

Generic authenticated `/profile` now validates optional phone syntax and a strict
calendar date of birth (not future). Date of birth is nullable, owner-entered,
not inferred/backfilled, omitted from implicit User JSON, and unrelated to any
messaging opt-in. Omitting the field preserves it; an explicit blank clears it.
The forward-only additive migration preserves values during operational rollback.

Email, phone and username uniqueness uses the existing product identity contract,
not global SaaS uniqueness. Request company/role fields are not writable. The
personal write locks a current user and refuses a changed tenant membership.
Generic business profile validates formatted/13-digit CNIC and bounded phone
numbers; it derives company identity from the current actor, never request IDs.
These are format checks, not verified identity or permission to transmit data.

Other product-specific onboarding/profile forms retain their own rules and
regression suites. This module does not certify every historical profile form,
activate greeting providers, enroll recipients, or send real messages.

Synthetic feature coverage includes valid leap date, invalid/future dates,
malformed phone/CNIC, explicit clear/omission, protected fields, JSON privacy,
and cross-product/same-product identity behavior. Hosted final-head browser and
aggregate verification must be recorded separately from focused test evidence.
