# Reporting connection acceptance

Original gap: an Active grant shows authorisation only, with no successful
read evidence or known-billing acceptance steps. A deployed connector does not
install or verify ChatGPT automatically.

The existing owner-only connection screen now explains exact company/date and
filter matching, sale/credit note/tax/payment comparisons and revocation. It
shows per-grant successful read count and latest timestamp from existing audit
metadata, filtered to the current actor and grant. No report contents, tokens,
buyer details or other owner's audits are exposed. No OAuth scope/lifetime,
client callback policy, fiscal settings or business tables change.

Coverage: consent without reads remains unverified; real tool read increments
evidence; failed foreign-company request does not; inconsistent audit actor
excluded; secrets absent; owner demotion denied; business invoice snapshot
unchanged. Real desktop/mobile OAuth journey checks evidence and revocation.

Local JS syntax and whitespace passed. PHP/Chrome unavailable locally; isolated
exact-HEAD CI must pass. Actual ChatGPT OAuth handshake, account availability,
known live bill comparison and owner acceptance remain unverified. This PR
adds the review workflow, not a fabricated completion record.

Refresh on 21 October: incorporates deployed main
`314432faa905dd963d7746d2c207755c22431008` after PR #225's exact-SHA
production verification passed. The release-announcement conflict keeps this
PR's reporting announcement; the previous WhatsApp release is already recorded
by its deployment. Android beta release checks and the WhatsApp readiness
browser journey remain present. Fresh full CI on the refreshed PR HEAD is
required; refer to the PR for the latest run and results. This refresh does not
claim a live ChatGPT OAuth handshake or known-customer billing reconciliation.
