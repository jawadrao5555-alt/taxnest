# Admin login attempt limits — local regression evidence

## Failure and change

The admin password endpoint had no failed-attempt budget. A correct password still signed in immediately after repeated failures. The controller now enforces 5 failures per normalized email + IP and 20 failures per IP across identities, each with a 60-second decay. Cache keys hash identities and use an admin-specific namespace. Blocked requests do not invoke authentication. Successful login clears the identity budget, regenerates the session as before, and leaves the shared IP failure budget intact.

Only the email is retained in form input. The block message reports retry seconds without confirming whether an account exists. Existing login/logout, remember-me and intended redirects remain in place. No account, password, role, session-store or proxy settings are reset. The limiter uses Laravel's configured cache store; availability and shared-cache configuration remain deployment prerequisites.

## Local evidence

- Before the controller change: **2 of 5 regression cases failed** (45 assertions), confirming no lockout and an email-casing bypass of the expected budget.
- After: **6 tests, 59 assertions passed**. Covers decay, success resetting failures, normalized casing, separate identities, rotating-email/IP budget and a second IP, and no password in flashed form data.
- Related authentication, security and admin page suite: **41 tests, 1,786 assertions passed**, PHP 8.4.27, SQLite in-memory.
- `git diff --check` passed.

This is password-attempt limiting; it does not implement two-factor authentication. Browser/MariaDB and CI results are stated separately in the PR. No production account/login was exercised, and no merge or deployment was performed.
