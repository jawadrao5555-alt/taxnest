---
name: Outgoing mail via cPanel noreply mailbox
description: Working SMTP settings for all TaxNest outgoing email (dev + live), and the Replit web-SAPI secret pitfall that silently breaks auth.
---

# Working SMTP settings (verified Jul 2026, dev → real send OK)
- Mailbox: `noreply@taxnest.com.pk` on the same cPanel server (mail.taxnest.com.pk = 66.29.138.229; ports 465 AND 587 open from Replit).
- .env block that works: `MAIL_MAILER=smtp`, `MAIL_HOST=mail.taxnest.com.pk`, `MAIL_PORT=465`, `MAIL_SCHEME=smtps`, `MAIL_USERNAME=noreply@taxnest.com.pk`, `MAIL_PASSWORD=<mailbox pw>`, `MAIL_FROM_ADDRESS=noreply@taxnest.com.pk`, `MAIL_FROM_NAME="TaxNest"`, `MAIL_TIMEOUT=15`.
- Dev secret `NOREPLY_MAIL_PASSWORD` holds the mailbox password. LIVE fix = same block in `/home/taxnestc/public_html/.env` + `config:clear` (+ web OPcache reset per runbook).

# Replit web-SAPI secret pitfall (cost a debugging round)
- **Workflow-served PHP web requests may NOT see Replit secrets added mid-session** — CLI (bash tool) sees them, but `artisan serve` under the workflow returned MISSING for getenv/$_SERVER/$_ENV even after workflow restarts.
- **`MAIL_PASSWORD="${NOREPLY_MAIL_PASSWORD}"` interpolation fails SILENTLY**: phpdotenv leaves the literal `${...}` string (len 24) as the password → SMTP 535 on web requests while CLI sends fine. Classic split symptom: CLI mail OK + web mail 535 = env visibility, not credentials.
- **Fix**: write the REAL password value into the gitignored `.env` (copy programmatically via `php -r` + getenv, never print it). Single-quote it in .env if it contains no `'`.

# What sends mail (all synchronous, all via MailHealth try/catch)
- Forgot-password OTP+link (PasswordResetLinkController), payment-proof admin alert, payment approve/reject emails, trial reminders (needs cron on live).
- Admin Settings has one-click "Send Test Email" (sends to logged-in admin's email) + persistent red MailHealth banner while mail is broken; any successful send clears it.
- Debug recipe: send-to-self (`noreply@` → `noreply@`) proves auth+transport without needing an external inbox; check `storage/logs/laravel.log` for "Mail send failed".
