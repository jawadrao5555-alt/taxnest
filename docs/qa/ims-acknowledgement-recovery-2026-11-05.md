# IMS acknowledgement recovery

## Problem and limits of evidence
A completed restaurant bill appears in the central FBR portal but TaxNest has
`verification_required` and no reference. Never resend that bill. The current
Agent catches decode/transport errors without a diagnostic, so the exact
customer failure cannot be identified from the stored callback. No raw response
was supplied. Do not claim the reproduced response variant proves that cause.

## Reproduction and changes
- Local baseline SOAP decoder rejects a JSON-string-wrapped JSON object with
  explicit `Code:100` and an invoice number (`Invalid IMS result`). Updated decoder
  unwraps exactly one additional JSON string; bare numbers/strings, arrays,
  faults, ambiguous results and missing acknowledgements remain unconfirmed.
- Agent bridge preserves an allow-listed diagnostic enum in its durable scoped
  callback. No raw XML, tokens, headers, endpoint details or source payload are
  added to logs/callback storage. Callback retries never resend the invoice.
- New company-admin + explicit-CSRF portal recovery form saves independently
  checked central-portal evidence without making an HTTP/fiscal request. It
  requires exact environment, POS ID, USIN, date, invoice type, quantity, sale,
  tax and total matching the frozen payload, a reference, note and confirmation.
  Only unresolved/local-accepted records qualify. Original sale, payload,
  payment, stock, tax, pairing and current integration configuration are unchanged.
- Company locking prevents two bills claiming one reference concurrently;
  same-company/environment reference reuse and existing-reference conflicts are
  blocked. Reconciliation records actor/time/provenance and retains prior attempts.
  Late callbacks cannot overwrite accepted evidence. Existing receipt readers
  show the recovered reference and QR; no automatic print is triggered.
- Agent source identity 1.13.33; complete parsed lockfile comparison confirms only
  its two root version fields changed. Pinned graph guard updated accordingly,
  with all dependency integrity and source checks retained.

## Compatibility matrix
| Case | Expected / coverage |
|---|---|
| Wrapped SOAP JSON acknowledgement | Decode, one fiscal POST, scoped callback |
| Existing SOAP JSON / local JSON | Existing tests stay green |
| Timeout / malformed / conflicting / no number | Unknown, no automatic resend |
| Missing diagnostic from older Agent | Optional field; existing callback tests |
| Callback outage / restart / second key | Durable callback retry, no second POST, scoped evidence |
| Matching portal evidence | Accepted with owner provenance; reference in receipt |
| Wrong identity / amounts / confirmation | Reject without mutation |
| Draft / already accepted / reference reused | Reject; no replacement or replay |
| Cashier / other tenant / no CSRF | Denied by actual HTTP routes |
| 1366px / 390px browser | Hosted synthetic portal recovery rejects wrong amount,
  saves matching evidence and checks receipt QR; no regulator dispatch |

## Validation
- Baseline/updated decoder reproduction executed locally against the same
  synthetic response: baseline throws; updated returns explicit code/reference.
- Focused Node flow/release tests: 25 passed.
- Npm lock portability guard: passed; graph changes only for root release identity.
- PHPUnit (local PHP 8.3.6): DI/IMS feature file 61 tests / 461 assertions; federal automation 20 / 130.
- PHP syntax and JS syntax / diff whitespace checks passed.
- Local Chrome installation failed because the downloaded archive was truncated;
  no successful local visual QA claimed. Required hosted browser lane now exercises
  the actual new form and receipt with fictional data in its disposable MariaDB.
- Full local suite not run: available PHP 8.3 does not meet unchanged ^8.4.1
  runtime requirement; required hosted PHP 8.4 suite remains authoritative.
- No production settings/data changes, fiscal request, merge, deployment, release
  publication or customer reconciliation performed by this PR preparation.

## Owner acceptance after deployment
Verify the existing FBR portal row against the frozen IMS record; use the recovery
form once, then inspect original receipt for both references/QRs. This is manual
portal evidence, not a recovered network response. Install the released Agent
1.13.33 before testing future automatic acknowledgements. Keep automatic reporting
paused until the intended original-tax policy and actual next sale are reviewed.
Do not create a dummy Production sale or resend an uncertain/accepted invoice.
