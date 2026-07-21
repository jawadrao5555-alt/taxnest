---
name: Outgoing mail via cPanel noreply mailbox
description: Working SMTP settings for all TaxNest outgoing email (dev + live), and the Replit web-SAPI secret pitfall that silently breaks auth.
---

# Working SMTP settings (verified Jul 2026, dev → real send OK)
- Mailbox: `noreply@taxnest.com.pk` on the same cPanel server (mail.taxnest.com.pk = 66.29.138.229; ports 465 AND 587 open from Replit).
- .env block that works: `MAIL_MAILER=smtp`, `MAIL_HOST=mail.taxnest.com.pk`, `MAIL_PORT=465`, `MAIL_SCHEME=smtps`, `MAIL_USERNAME=noreply@taxnest.com.pk`, `MAIL_PASSWORD=<mailbox pw>`, `MAIL_FROM_ADDRESS=noreply@taxnest.com.pk`, `MAIL_FROM_NAME="TaxNest"`, `MAIL_TIMEOUT=15`.
- Dev secret `NOREPLY_MAIL_PASSWORD` holds the mailbox password. LIVE fix = same block in `/home/taxnestc/public_html/.env` + `config:clear` (+ web OPcache reset per runbook).
- **LIVE was silently broken until 21 Jul 2026**: live `.env` still had the Laravel DEFAULT mail block (`MAIL_MAILER=log`, host 127.0.0.1:2525, from hello@) and NO `smtp_settings` SystemSetting row — every OTP/approval email went to the log file, MailHealth stayed green (log driver "succeeds"). Fixed by writing the real block over SSH (password piped via stdin into a temp PHP script that rewrites .env + makes a timestamped .env backup — never echo it), then config:clear + web OPcache reset; CLI send-to-self test passed. Lesson: `MAIL_MAILER=log` on prod is invisible in the admin UI — health checks must grep the live .env, not trust MailHealth.

# Replit web-SAPI secret pitfall (cost a debugging round)
- **Workflow-served PHP web requests may NOT see Replit secrets added mid-session** — CLI (bash tool) sees them, but `artisan serve` under the workflow returned MISSING for getenv/$_SERVER/$_ENV even after workflow restarts.
- **`MAIL_PASSWORD="${NOREPLY_MAIL_PASSWORD}"` interpolation fails SILENTLY**: phpdotenv leaves the literal `${...}` string (len 24) as the password → SMTP 535 on web requests while CLI sends fine. Classic split symptom: CLI mail OK + web mail 535 = env visibility, not credentials.
- **Fix**: write the REAL password value into the gitignored `.env` (copy programmatically via `php -r` + getenv, never print it). Single-quote it in .env if it contains no `'`.

# Admin-panel SMTP override (Jul 2026)
- SaaS admin → Settings has an "Email (SMTP) Settings" card: ONE SystemSetting JSON key `smtp_settings` (password Crypt-encrypted inside; value col is TEXT so ciphertext fits).
- `SmtpRuntimeConfig::apply()` runs in AppServiceProvider::boot on EVERY request/CLI: when enabled + host+username+password present → overrides `mail.*` config at runtime; otherwise silent no-op and .env stays the fallback. NEVER throws (DB-down/decrypt-fail safe).
- Password field never echoed; blank on save = keep existing (`hasPassword()` guard forces a password only when none saved). encryption 'ssl'→scheme smtps, 'tls'→scheme null (Symfony auto-STARTTLS).
- Verified by sabotage test: bogus MAIL_HOST in .env + DB settings enabled → Send Test Email still delivers. CAVEAT: long-running `queue:work` boots once — SMTP changes need a worker restart to reach queued jobs (current senders are all synchronous, so not urgent).

# What sends mail (all synchronous, all via MailHealth try/catch)
- Forgot-password OTP+link (PasswordResetLinkController), payment-proof admin alert, payment approve/reject emails, trial reminders (needs cron on live).
- Admin Settings has one-click "Send Test Email" (sends to logged-in admin's email) + persistent red MailHealth banner while mail is broken; any successful send clears it.
- Debug recipe: send-to-self (`noreply@` → `noreply@`) proves auth+transport without needing an external inbox; check `storage/logs/laravel.log` for "Mail send failed".
