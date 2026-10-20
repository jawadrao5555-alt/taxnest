# WhatsApp setup and delivery evidence

Original gaps: configuredFor() checks only nonempty fields, so a corrupt saved
encrypted token can enable auto-send; the settings page has no checklist or
separate actual-delivery evidence. The existing durable outbox/send semantics
are retained. No real Meta credentials or customer messages were accessed.

Adds local saved-configuration readiness with no provider calls. Enabling
requires a decryptable nonempty token/App Secret; disabling always remains
available. Blank password inputs retain existing secrets; no secrets are
rendered, flashed or logged. Existing company settings are never reset.

Recent delivered/read evidence is scoped to current company, distinct from
sent/pending/unknown. It is evidence for that historical bill, not a global
integration pass. Meta validity, template approval, scheduler operation and
real customer receipt still require owner acceptance. Verify-token absence is
a warning, not a new block for an already configured webhook.

Regression matrix: valid existing credentials remain usable; corrupt token
rejects enable but permits disable; no old-input secret leak; foreign-company
delivery excluded; sent does not imply delivered; cashier denied. Existing
desktop/390px optional-auto-send journey checks the readiness/evidence region.

Local JS syntax and whitespace checks passed. PHP/Chrome unavailable locally;
fresh isolated CI required for targeted/full PHP and real browser re-test.
No real delivery verification is claimed by this change.
