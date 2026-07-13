---
name: SaaS admin flash/validation banners
description: Central flash + validation error rendering convention for the SaaS admin panel layout
---

# SaaS admin flash & validation banners

Rule: `layouts/admin-app.blade.php` renders `session('success')`, `session('error')` AND `$errors->any()` centrally (just above `{{ $slot }}`). Individual saas-admin pages must NOT render their own copies — duplicates were removed from companies/create, companies/edit, and sales views (Jul 2026).

**Why:** Before the `$errors` block existed, any validation failure on admin forms (e.g. subscription override forms on companies/show) silently redirected back with ZERO feedback — the owner concluded "koi override kaam nahi karta" when the feature was actually fine. Silent validation failure reads as a broken feature to a non-technical owner.

**How to apply:** New saas-admin forms need no flash/error markup — the layout covers it. If a form "does nothing" on submit, suspect a validation failure first (check field names match the controller's `validate()` keys, e.g. usage-free needs `free_invoice_limit`, not `limit`).
