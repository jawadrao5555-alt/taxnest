# Online transfer confirmation authorization

Owner report: a customer pays cash while staff record the bill as paid online.
The report is not independently verified against production or individual staff.

## Inspected gap and proposed correction

The existing web payment gates require `online_payment_confirmed=true` but do
not require the person posting that flag to be an owner/admin/manager. A client
boolean proves neither authority nor bank receipt. Direct `qr_payment` creation
and editing a cash bill to QR also need the same role boundary.

Use the existing `User::isPosAdmin()` policy (owner/company admin, POS admin or
POS manager) together with explicit company equality. Apply it to restaurant
pay, counter invoice creation, changing a bill into QR, and the incoming-order
fallback confirmation. The atomic waiter-order claim also refuses an
unauthorized confirmation. Staff can still mark a transfer as awaited; that
does not confirm receipt. Cash/card calculations and immutable submitted fiscal
bills are unchanged. Existing authorized online-payment tests remain in place.
Errors tell staff in plain English/Urdu to ask the owner/manager to check receipt.

This deliberately changes cashier permission: a cashier cannot self-confirm a
transfer or create/reclassify a bill as QR. An authorized manager must complete
that action in their own session. No shared password or role elevation is added.
Existing stored payments and settings are not rewritten. Denials log company and
actor IDs only, without customer/bank data. Existing successful payment records
retain their normal actor and accounting paths.

## Tests and acceptance

Added real-route Feature regressions for forged confirmation through both pay
endpoints, direct QR/online bypass, continued cashier cash payment, and policy
denial for another company's owner and a missing actor. Existing authorized
online settlement, quote/tax-mode and fallback regressions must also pass.

`git diff --check` and PHP syntax checks passed. This host has only PHP 8.3;
syntax checking is not supported-platform application acceptance. Full Laravel
HTTP/PHPUnit reproduction, supported PHP 8.4+, MariaDB concurrency and browser
checks have NOT run locally. Draft pending authentic exact-HEAD CI and review.
Do not describe this PR as a verified fraud fix before those checks pass.

## Limits

No bank/acquirer integration is added: an authorized human still has to check
actual receipt. This cannot establish past misconduct or prevent an authorized
manager from entering false information. Card terminal reconciliation and the
Agent/Local Core event projection path need independent acceptance; this is a
web QR/online-confirmation boundary, not a claim of end-to-end offline payment
fraud prevention. No production access, credentials, historical bill changes,
live prints, merge or deployment were performed. Main's shared Electron audit
failure may independently block mandatory CI; it must not be bypassed.
