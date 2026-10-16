# Admin invoice preview blocked by production CSP

Owner screenshot shows the dialog open with Chrome's blocked-content page.
The UI fetched/validated a PDF, then navigated its iframe to a blob URL.
Production SecurityHeaders default-src allows self/https but not blob frames.
The earlier browser fixture did not enforce that production response policy.

Reproduction commit 9e28d4d2859df890fe829208db58975da360e3d2 in PR #212:
Actions run 38905857887 fails both desktop and mobile early authenticated
preview journeys with actual browser securitypolicyviolation evidence:
Production CSP blocked invoice preview: directive=frame-src, uri=blob.
The disposable fixture obtains the real production CSP from SecurityHeaders,
restores its testing environment immediately, and browser response interception
enforces that same header on actual local admin invoice screens. All business
records/credentials are synthetic; no production browser/DB was accessed.

Correction keeps PDF byte/signature validation and then navigates to the
same authenticated document route. It requires same origin and clears stale
load handlers on close/reopen. No CSP/header relaxation, blob allowance,
public document exposure, tax changes or tenant configuration writes.
Existing admin-only typed-product/company checks, PDF download, close/focus
and print controls remain. All three document types run at both widths with
production CSP enforced, PDF bytes/download identity and foreign-product
access checks. No physical printer proof is claimed.

Local browser installation was attempted; CDN archives were invalid/truncated.
Local PHP/native Chrome application QA is unavailable. Node script syntax and
whitespace pass. Actual before/after browser/full/native results must come
from fresh exact-HEAD CI; the reproduction run intentionally fails. Customer
screenshots/data are not committed. No merge or deployment performed.
