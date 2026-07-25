---
name: Auth guards & login rules
description: Per-panel guard isolation, cross-login rules, admin auto-detect, login identifiers, login-page styling. Moved from replit.md Jul 2026.
---

# Auth guards & login — full rules

- Guards isolated per panel: DI=`web`, PRA POS=`pos`, FBR POS=`fbrpos`, admin=`admin_users` guard.
- Company users can NEVER cross-login (wrong panel = "Invalid credentials", no redirect hint). Only ADMIN creds auto-detect on any login form → admin guard → /admin/dashboard.
- Rate-limited (5 attempts/key). POS admin on DI /login auto-redirects to /pos/login.
- Login identifiers: Email, Phone, Username, CNIC, NTN (CNIC/NTN resolve to company_admin of the matching company).
- Login pages: premium dark glassmorphism — POS purple, DI modal emerald, Admin indigo-navy, FBR POS deep blue. FBR POS routes: /fbr-pos/login, /fbr-pos/register, /fbr-pos/logout.
- Pending companies can VIEW all features but CANNOT act until admin approves (exception: /onboarding/* POSTs allowed so they don't deadlock).
