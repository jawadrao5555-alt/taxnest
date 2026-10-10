# Hotel payment recovery after reload

A lost confirmation response previously retained its UUID only while the page
remained loaded. A fresh page generated another UUID. This was reproduced with
the shipped JavaScript controller in a fresh Node VM; the monetary duplicate in that
reproduction used an HTTP/ledger double, not production data.

Before confirmation, the browser now durably records the semantic payload and
original UUID under a company/user/stay key. It does not store CSRF, guest
details, credentials or arbitrary request fields. Failure to read/write that
record prevents a new confirmation.

On stay or checkout page startup, the original operation is reconciled using
authenticated GET bill-recovery. The endpoint locks the stay to wait for a
confirmation already holding that lock, checks the original user's key and
operation fingerprint, and reads the existing result without posting payment,
issuing an invoice, printing or dispatching PRA work.

A completed operation shows its original result/receipt. A missing record
offers only an explicit retry of the original payload and UUID with current
CSRF. Session/access errors retain pending identity. A definite business or
validation refusal (409/422) permits explicit refreshed review. Successful
reconciliation clears only the matching pending record; failed storage removal
leaves a safely reconcilable record rather than silently forgetting it.

## Compatibility and tests

- Hotel and Guest House use the same recovery code; tenant/user/stay keys differ.
- Existing same-page input retention, paid checkout, original invoice reuse,
  explicit checkout Close-to-Dashboard, booking availability, tax/pricing,
  fiscal snapshots and saved printer/company settings remain covered.
- Node tests execute shipped JavaScript with DOM/transport doubles. Added
  tests cover reload after commit, reload before commit with renewed CSRF,
  another tenant/user, blocked storage and expired authentication. The two
  reload tests fail against the unchanged base controller and pass with this
  correction; the full local Node set passes 15/15.
- Laravel HTTP tests cover a real nonzero partial payment, read-only recovery,
  unchanged row counts/payment sum, expired-token replay, changed-payload
  refusal, second user/tenant, original submitted invoice and paid checkout.
- Desktop/mobile Chrome acceptance now loses a successful real nonzero
  confirmation response, reloads, reconciles the original invoice using GET,
  checks the actual remaining balance and rejects another monetary POST.

PHP/Composer/Chrome are unavailable in the agent runtime; required disposable
CI supplies server/browser execution. Do not claim those lanes passed until
their exact-head results are available. No production payments or PRA
submissions are used for testing, and no merge/deployment is performed by the
agent.

Browser storage deletion, a different device/browser or loss of its profile
cannot recover a local pending record. Existing folio/receipt reconciliation is
still necessary before a new collection in those circumstances. The code does
not treat equal amounts as duplicates of legitimate subsequent installments.
