# TaxNest - Heavy Enterprise Product

## Overview
TaxNest is a multi-company SaaS platform designed for comprehensive tax and invoice management in Pakistan, ensuring strict compliance with FBR regulations. It provides smart invoicing, configurable governance, an enterprise API, PDF generation, and a demo mode. The "Heavy Enterprise" version expands these capabilities to include a Company Approval System, Customer Ledger, Multi-Branch support, FBR Token Health Monitor, Advanced Admin View, Immutable Audit Logs, Enterprise Analytics, and enhanced security features. The project targets the Pakistani market with a focus on robust compliance, scalability, and an intuitive user experience, aiming for a significant market share.

## User Preferences
- ZIA CORPORATION is a REAL production account (not demo/internal) - NTN: 3620291786117, Owner: ZIA UR REHMAN (Digital Invoice ONLY, NO POS data)
- NestPOS Enterprise Store (company_id 11) is the dedicated POS company — completely separate from Digital Invoice
- POS Admin: posadmin@taxnest.com / Admin@12345 (company_id 11, NestPOS Enterprise Store)
- Digital Invoice and POS data are FULLY ISOLATED — no cross-contamination
- POS billing is ANNUAL-ONLY (6% discount baked in) — no billing cycle toggle
- DI billing has full cycle toggle: Monthly / Quarterly(-1%) / Semi-Annual(-3%) / Annual(-6%)
- POS admin CANNOT login through Digital Invoice /login — auto-redirected to /pos/login
- **Unified Login (ADMIN-ONLY auto-detect)**: All login forms (POS, DI, FBR POS) auto-detect ONLY admin credentials → admin guard + redirect to /admin/dashboard. Company users CANNOT cross-login between panels — DI creds on /pos/login = "Invalid credentials" (no redirect). Each company guard (`web`/`pos`/`fbrpos`) is isolated. Rate-limited (5 attempts/key).
- Login pages use premium dark glassmorphism design: POS = deep purple gradient, DI modal = deep emerald gradient, Admin = indigo-navy gradient, FBR POS = deep blue gradient
- FBR POS uses isolated `fbrpos` guard — completely separate auth from DI (`web`) and PRA POS (`pos`). Login at `/fbr-pos/login`, register at `/fbr-pos/register`, logout at `/fbr-pos/logout`
- Test Trading Company (company_id 12, test@testtrading.pk / Admin@12345) — for testing admin approval workflow
- Pending companies can VIEW all features but CANNOT perform any actions until admin approves
- Login supports: Email, Phone, Username, CNIC, NTN
- CNIC/NTN login maps to company_admin user of matching company

## System Architecture
TaxNest is built on Laravel 12 with PHP 8.4, utilizing Breeze for authentication. The frontend employs Tailwind CSS, Alpine.js, and Chart.js.

**Core Architectural Patterns and Decisions:**
- **Multi-tenancy:** Implemented with `company_id` and `CompanyIsolation` middleware for data segregation.
- **Role-Based Access Control (RBAC):** Permissions are managed via `RoleMiddleware`.
- **Dual Invoice Numbering:** Supports separate internal and regulatory (FBR/PRA) invoice numbers.
- **Dynamic Validation Engine:** `ScheduleEngine` for FBR compliance rules.
- **Immutable Audit Logging:** Critical events are logged with SHA256 hashes for data integrity.
- **Queue-based Processing:** Background tasks utilize a database queue for asynchronous operations.
- **Company Approval Workflow:** Manages the lifecycle and status of companies within the platform.
- **Customer Ledger System:** Automates debit entries and allows for manual adjustments.
- **Multi-Branch System:** Supports multiple operational branches for each company.
- **FBR Token Health Monitoring:** Tracks FBR token status and connectivity to ensure uninterrupted service.
- **Enterprise Analytics:** Provides key performance indicators and compliance metrics through dashboards.
- **Security Hardening:** Includes `ForceHttps` and subscription-based access controls.
- **Dynamic FBR Compliance:** Features FBR Excel template alignment, PRAL API integration, per-item FBR fields, and pre-submission payload validation with a sandbox mode, ensuring FBR submission idempotency through a multi-layer locking mechanism.
- **Enterprise UX Simplification:** Invoice lifecycle managed through 4 states (`draft`, `failed`, `locked`, `pending_verification`) with a streamlined FBR submission flow.
- **DI Invoice PDF Default:** Pure 100% B&W PDF (`resources/views/invoice/pdf-bw.blade.php`) is the standard output for invoices, designed for clarity and compliance.
- **Market-Launch Receipt Hardening (PRA + FBR + Restaurant thermal):** All three thermal receipt templates (`pos/restaurant/receipt.blade.php`, `pos/receipts/receipt_80mm.blade.php`, `fbr-pos/receipt.blade.php`) hardened for cheap thermal printers — every faint gray (#444/#555/#666/#888/#aaa/#bbb/#ccc/#ddd) converted to `#000`, body text bumped to `font-weight: 500-600` minimum, item-row separators changed from `1px dotted #ccc/#ddd` to `1px dashed #000`, all box borders to `1.5-2px solid #000`, color emphasis (orange discount, green exempt, red item-discount, purple provisional) flattened to `#000` with bold weight, items table column widths normalized (Restaurant + 80mm → qty 12% / item 46% / rate 20% / amt 22%; FBR → item 38% / uom 10% / qty 10% / price 20% / total 22%) with `font-variant-numeric: tabular-nums` for column-aligned digits, grand-total row converted to **inverted #000 background with #fff text** for max contrast on cheap thermal heads. No more faint print on low-quality 58/80mm rolls.
- **Global HS Intelligence Control System:** Centralized `global_hs_master` table, HS resolution, and dynamic validation for tax schedules, including weighted suggestions and rejection learning.
- **HS Code Mapping Engine:** Admin-managed mappings with real-time suggestions during invoice creation.
- **Admin Announcement System:** Allows administrators to create targeted, dismissable announcements.
- **SaaS Management Layer:** Separated admin and franchise management with distinct authentication, layouts, subscription plan builders, company approval workflows, and usage monitoring.
- **Product-Type Plan Separation:** `pricing_plans` table distinguishes between `di` and `pos` product types for plan display.
- **Subscription Override + Usage Limit System:** Admin-only override functionality on `subscriptions` table, allowing for `lifetime`, `temporary`, `grace`, or `usage_free` access, with an enforcement order: lifetime → usage_free → temporary/grace → normal subscription check.
- **NestPOS Module:** A completely isolated POS system with its own authentication, layouts, and data models, supporting PRA integration with offline billing and auto-sync. Includes a full Restaurant POS Module with table management, KDS, KOT, inventory, and CRM. Features an Enterprise Cart UI, seamless keyboard flow, and a unified top navigation layout. Supports 10 universal business categories and offers 6 dashboard styles and themes.
- **FBR POS Module:** An isolated FBR-integrated POS accessible at `/fbr-pos` with direct FBR API submission, product tax configuration, FBR reporting toggle, and a confidential PIN system for local data access.
- **TaxNest PRA Sync Agent (Desktop Companion App):** An Electron-based desktop application (separate repository) that runs locally on the client's PC, polling the server for pending invoices, submitting to PRA from a local IP, and reporting results back. The server provides API endpoints (`/api/agent/heartbeat`, `/api/agent/pending-invoices`, `/api/agent/submit-result`) and a UI at `/company/agent` for key management.
- **UI/UX Design:** Responsive sidebar layout with consistent dark/light modes, emerald-600 primary color. Features a unified SaaS-grade design system with consistent components and enhanced mobile responsiveness. Includes an Enterprise UX Engine for notifications, spinners, and transitions. DI Dashboard has premium upgrades with gradient banners and KPI cards.
- **PWA / Mall-Grade "exe-look" Suite:** Three independent PWAs (Tax DI, Nest Pra Pos, Nest FBR Pos) with branded icons, a Service Worker (`public/sw.js`, `CACHE_VERSION = taxnest-v23`) for offline capabilities and push notifications, and Blade components for PWA features. Includes an iOS Install Modal, POS offline pre-caching, and a PWA diagnostics page. Login pages feature branding and install buttons.
- **PWA Auto-Update + Refresh Control (v23+):** SW registered with `updateViaCache: 'none'` in all 3 layouts so the SW file itself is never browser-cached → instant update detection. `<x-pwa-update>` toast auto-checks every 5 min + on tab focus + on `visibilitychange` + on `online` event, with a 30-second auto-apply countdown (dismissable for 5 min). New `<x-pwa-refresh-btn>` always-visible header icon button (placed in all 3 layouts: Tax DI emerald, NestPOS purple, FBR POS blue) — pulses with red/amber `!` badge when an update is waiting; click instantly applies the queued SW or triggers a fresh `reg.update()` and reloads if a new version is found. `<x-pwa-install>` upgraded to premium gradient pill with glow-shadow + 3-pulse attention animation on first detection. `<x-pwa-banner>` upgraded to premium glassmorphism card with floating animated app-icon, "PRO" + "EXE LOOK" badges, and decorative blur orbs.
- **Hardening Batch — Phase 1:** Feature-flagged implementations for Failed Invoice Recovery System, HS Code Mapping Manager, and Reduced Rate Support.

## External Dependencies
- **MySQL:** Primary database for production deployment.
- **PostgreSQL:** Used for Replit development and baseline reference.
- **FBR (Federal Board of Revenue) Pakistan:** Core integration for tax compliance.
- **Laravel Breeze:** Authentication scaffolding.
- **Tailwind CSS:** Frontend styling.
- **Alpine.js:** Interactive frontend components.
- **Chart.js:** Data visualization.
- **PRA (Punjab Revenue Authority):** POS fiscal device integration via PRAL IMS API v1.2.
- **Unsplash / Picsum:** (Fallback) for `ProductImageService` to fetch product images.