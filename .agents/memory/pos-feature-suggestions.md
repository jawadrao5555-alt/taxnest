---
name: POS Feature Suggestion box
description: Customer suggestion flow, admin review, Zyada Demand grouping and the owner's "3 customer rule". Moved from replit.md Jul 2026.
---

# POS Feature Suggestion box — full rules

- Top-nav bulb icon → `/pos/suggestions` (company-scoped list + status badges, 10/user/day cap).
- Admin reviews at `/admin/feature-suggestions` (status: pending/planned/completed/rejected + note shown to customer).
- "Zyada Demand" panel auto-groups similar open requests by title keywords — 2+ distinct companies = Trending, 3+ = build-now badge per owner's "3 customer rule".
- Suggestion box AND What's New (popup + bell) are ADMIN/MANAGER-ONLY (owner, 20 Jul 2026) — cashier + confined roles never see them (nav hidden + /pos/suggestions server-side gated via isPosAdmin).
- Madadgar escalations also land on /admin/feature-suggestions with source=madadgar badge → `pos-madadgar-bot.md`.
