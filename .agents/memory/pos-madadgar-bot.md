---
name: Madadgar AI support bot (PRA POS)
description: Invariants for the POS floating AI support bubble — escalation state machine, route middleware, OpenAI key resolution, dev-web env gotcha.
---

# Madadgar AI support bot (PRA POS, Jul 2026)

- One floating bubble on ALL POS pages (pos-app layout only; component `madadgar-support`, replaced the plain WhatsApp bubble THERE only — other layouts keep `x-whatsapp-support`). Menu = AI chat + WhatsApp deep link. ALL POS roles may use it, including cashiers (owner explicit) — do NOT add role gates.
- **Escalation is a two-step state machine (architect-mandated):** the model's `escalate_to_admin` tool call only returns a pending card `{title, summary, kind}` to the client; the FeatureSuggestion row (`source='madadgar'`, status pending, `[Masla / Problem]`/`[Feature Request]` prefix in details) is created EXCLUSIVELY by POST `/pos/madadgar/escalate` when the user taps "Haan". Never create the row inside the chat turn.
- **Routes are `pos.auth` ONLY — deliberately NO `company.approval`** (pending companies may chat; their POSTs would be 403'd). Keep it that way when adding endpoints.
- **Why:** support must work precisely when a company is stuck pre-approval.
- OpenAI key resolution order: encrypted SystemSetting `madadgar_openai_key_enc` (admin panel, Crypt) → env `OPENAI_API_KEY` via `config('services.openai.key')`. Kill switch = SystemSetting `madadgar_enabled` ('1' default). Widget hidden entirely when no key AND no WhatsApp number; kill-switch OFF = WhatsApp-only panel. Admin page: `/admin/madadgar-chats` (logs + settings).
- Caps: 30 user msgs/user/day (failed OpenAI turns delete the user row so they don't eat the cap), 5 escalations/user/day, throttle 20/min msg + 10/min escalate. Session = client UUID (localStorage `tn_madadgar_session`), char(36) col; history/message ALWAYS scoped user_id+company_id+session_id.
- Widget root has `@keydown.stop` — sale-screen F-key/Escape document listeners have no input guard; any future support widget on POS pages needs the same.
- **Dev-web env gotcha (root cause found):** Laravel's `artisan serve` ServeCommand deliberately BLANKS almost all env vars for its `php -S` child when a `.env` file exists (whitelist: APP_ENV/PATH/…), so workspace secrets are invisible to dev WEB requests even after workflow restart — CLI sees them fine. Fix: copy the literal value into the gitignored `.env` (`printf ... "$VAR" >> .env` — never echo it). This is the mechanism behind the older "web SAPI misses mid-session secrets" note in `mail-noreply-smtp.md`.
- **ChatGPT Plus ≠ API credit:** owner's OpenAI key returned 429 `insufficient_quota` despite an active ChatGPT subscription — API billing is separate; needs prepaid credit at platform.openai.com → Billing. Explain this before debugging "broken" keys.
- Live deploy note: live `.env` now carries OPENAI_API_KEY (admin-panel encrypted key overrides it if ever set).
