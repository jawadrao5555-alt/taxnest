---
name: Shared marketing footer & panel-aware login
description: The public marketing footer is one shared component across DI/POS/FBR landings; its login link must be panel-aware.
---

# Shared marketing footer / panel-aware login

The public marketing pages (`/`, `/digital-invoice`, `/pos`, `/fbr-pos-landing`, `/contact`) all render ONE shared footer component (`resources/views/components/site-footer.blade.php`). It exposes `loginUrl` / `loginLabel` props (default `/login`).

**Rule:** any shared public chrome (footer, nav) that links to "Log In" must point to the CORRECT panel's login on POS/FBR landings — pass `login-url="/pos/login"` / `login-url="/fbr-pos/login"`. A bare `/login` is the DI (`web`) panel only.

**Why:** auth guards are isolated per panel (DI=`web`, PRA POS=`pos`, FBR POS=`fbrpos`). A POS/FBR company user who lands on `/login` gets "Invalid credentials" with no redirect (only the platform ADMIN auto-detects across forms). So a single hardcoded `/login` in shared chrome silently steers POS/FBR visitors to a dead end.

**How to apply:** when adding a new panel/landing that reuses `site-footer` (or any shared marketing nav), always pass that panel's own login URL. Public contact/legal info in the footer + `/contact` reads admin-editable `SystemSetting` keys: `company_legal_name`, `contact_email`, `contact_phone`, `contact_address`, `support_hours` (+ `support_whatsapp_number` for the widget) — edit via saas-admin settings, never hardcode.
