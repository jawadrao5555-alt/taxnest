# TaxNest - Heavy Enterprise Product

## Overview
TaxNest is a multi-company SaaS platform for comprehensive tax and invoice management in Pakistan, ensuring strict FBR compliance. It offers smart invoicing, configurable governance, an enterprise API, PDF generation, and a demo mode. The "Heavy Enterprise" version adds a Company Approval System, Customer Ledger, Multi-Branch support, FBR Token Health Monitor, Advanced Admin View, Immutable Audit Logs, Enterprise Analytics, and enhanced security. The project aims for a significant market share in Pakistan by focusing on compliance, scalability, and user experience.

## User Preferences
- ZIA CORPORATION is a REAL production account (not demo/internal) - NTN: 3620291786117, Owner: ZIA UR REHMAN (Digital Invoice ONLY, NO POS data)
- NestPOS Enterprise Store (company_id 11) is the dedicated POS company — completely separate from Digital Invoice
- POS Admin: posadmin@taxnest.com / Admin@12345 (company_id 11, NestPOS Enterprise Store)
- Digital Invoice and POS data are FULLY ISOLATED — no cross-contamination
- POS billing is ANNUAL-ONLY (6% discount baked in) — no billing cycle toggle
- DI billing has full cycle toggle: Monthly / Quarterly(-1%) / Semi-Annual(-3%) / Annual(-6%)
- POS admin CANNOT login through Digital Invoice /login — auto-redirected to /pos/login
- Unified Login (ADMIN-ONLY auto-detect): All login forms (POS, DI, FBR POS) auto-detect ONLY admin credentials → admin guard + redirect to /admin/dashboard. Company users CANNOT cross-login between panels — DI creds on /pos/login = "Invalid credentials" (no redirect). Each company guard (`web`/`pos`/`fbrpos`) is isolated. Rate-limited (5 attempts/key).
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
- **Company Approval Workflow:** Manages the lifecycle and status of companies.
- **Customer Ledger System:** Automates debit entries and allows manual adjustments.
- **Multi-Branch System:** Supports multiple operational branches per company.
- **FBR Token Health Monitoring:** Tracks FBR token status and connectivity.
- **Enterprise Analytics:** Provides KPIs and compliance metrics through dashboards.
- **Security Hardening:** Includes `ForceHttps` and subscription-based access controls.
- **Dynamic FBR Compliance:** Features FBR Excel template alignment, PRAL API integration, per-item FBR fields, and pre-submission payload validation with sandbox mode, ensuring FBR submission idempotency.
- **Enterprise UX Simplification:** Invoice lifecycle managed through 4 states (`draft`, `failed`, `locked`, `pending_verification`) with a streamlined FBR submission flow.
- **DI Invoice PDF Default:** Pure 100% B&W PDF (`resources/views/invoice/pdf-bw.blade.php`) is the standard output for invoices.
- **PRA POS Cart Per-Item Tax Toggle:** Both `pos/universal.blade.php` (standard PRA POS cart row) and `pos/restaurant/pos.blade.php` (Restaurant POS cart row) expose a prominent **NO TAX / TAX** toggle button next to the Notes input — `text-[11px] font-extrabold` with green-500 active fill + ring, white inactive with hover ring, so cashiers can clearly mark any individual cart line tax-exempt on the fly. Drives `item.is_tax_exempt`, which feeds `exemptAmount` and skips that line from `taxableSubtotal` in totals computation. Restaurant POS cart previously only showed a "NO TAX" badge if the product was pre-configured exempt — there was no per-line toggle; that gap is now closed.
- **Market-Launch Receipt Hardening:** Thermal receipt templates for PRA, FBR, and Restaurant POS (`pos/restaurant/receipt.blade.php`, `pos/receipts/receipt_80mm.blade.php`, `pos/receipts/receipt_58mm.blade.php`, `fbr-pos/receipt.blade.php`) are optimized for cheap thermal printers with specific styling adjustments for legibility. Item table column order is ITEM-first then QTY.
- **Global HS Intelligence Control System:** Centralized `global_hs_master` table, HS resolution, and dynamic validation for tax schedules, including weighted suggestions and rejection learning.
- **HS Code Mapping Engine:** Admin-managed mappings with real-time suggestions during invoice creation.
- **Admin Announcement System:** Allows administrators to create targeted, dismissable announcements.
- **SaaS Management Layer:** Separated admin and franchise management with distinct authentication, layouts, subscription plan builders, company approval workflows, and usage monitoring.
- **Product-Type Plan Separation:** `pricing_plans` table distinguishes between `di` and `pos` product types for plan display.
- **Subscription Override + Usage Limit System:** Admin-only override functionality on `subscriptions` table, allowing for `lifetime`, `temporary`, `grace`, or `usage_free` access, with a defined enforcement order.
- **NestPOS Module:** Isolated POS system with its own authentication, layouts, and data models, supporting PRA integration with offline billing and auto-sync. Includes a full Restaurant POS Module with table management, KDS, KOT, inventory, and CRM. Features an Enterprise Cart UI, seamless keyboard flow, and a unified top navigation layout. Supports 10 universal business categories and offers 6 dashboard styles and themes. Enhanced cashier UX for payment flow, discount system, and per-item tax toggle. Provisional-Bill Lifecycle Clarity implemented for improved cashier understanding.
- **FBR POS Module:** Isolated FBR-integrated POS accessible at `/fbr-pos` with direct FBR API submission, product tax configuration, FBR reporting toggle, and a confidential PIN system. Features a refined professional UI palette (clean indigo-600 primary + emerald accents + slate base, gradients only on hero buttons). Enhanced cashier UX for quantity calculation. Implemented a provisional-bill / mandatory payment-confirm flow, where the cart is treated as provisional until explicit payment confirmation. **Unified Smart Input** — single barcode/scan input merges name + SKU + barcode + HS code search with debounced autocomplete dropdown (NAME/SKU/BARCODE/HS match-type chips), arrow-key navigation, scanner fast-burst still triggers exact-match lookup. F7/Ctrl+K just focuses this single input (no separate search modal). **Cash Guard** — both client (`finalizeAndSubmit`) and server (`FbrPosController::store`) block sale if `payment_method === 'cash'` and `cash_received < total_amount`, with shortfall amount in error toast. **Full Keyboard-Only Cart Flow** — `<n>*` typed in scan input sets a quantity multiplier for next add (with amber × N NEXT badge, requires >150ms idle gap so scanner mid-burst can't trigger); `*` alone jumps to last-added row's qty (auto-switches VAL→QTY mode); `+`/`-` in scan input bump last row qty ±1; standalone `*`/`+`/`-` are deferred 100ms — if more chars arrive (hardware scanner burst) the symbol is injected back into the buffer, so barcodes starting with `+/-/*` are never lost; Arrow ↑/↓ inside qty input ±1; Arrow ↑/↓ inside VAL input ±1 unit-price worth; `Ctrl+↑/↓` anywhere bumps last row qty; `Alt+Q` anywhere focuses last qty; `Enter`/`Esc` in qty/value field returns focus to scan input (for scanned items) so cashier never needs the mouse during high-volume scanning. `lastAddedIndex` is maintained across `addItem`/`duplicateItem`/`removeItem`/`recallSale`/`resetCart` so keyboard shortcuts always target the correct row. **Faint-text Polish** — all secondary labels (Tax Exempt, Item Discount, kbd hints, payment-modal hints, footer notes) bumped from `text-gray-500` → `text-slate-600/700 font-semibold/bold` for legibility on cheap LCD displays.
- **TaxNest PRA Sync Agent (Desktop Companion App):** Electron-based desktop application for polling the server, submitting invoices to PRA from a local IP, and reporting results. Server provides API endpoints and a UI for key management.
- **UI/UX Design:** Responsive sidebar layout with consistent dark/light modes, emerald-600 primary color. Features a unified SaaS-grade design system with consistent components and enhanced mobile responsiveness. Includes an Enterprise UX Engine for notifications, spinners, and transitions. DI Dashboard has premium upgrades with gradient banners and KPI cards.
- **PWA / Mall-Grade "exe-look" Suite:** Three independent PWAs (Tax DI, Nest Pra Pos, Nest FBR Pos) with branded icons, a Service Worker for offline capabilities and push notifications, and Blade components for PWA features. Includes an iOS Install Modal, POS offline pre-caching, and a PWA diagnostics page. PWA auto-update and refresh control mechanisms are implemented for seamless updates.
- **Hardening Batch — Phase 1:** Feature-flagged implementations for Failed Invoice Recovery System, HS Code Mapping Manager, and Reduced Rate Support.
- **FBR POS Edit & Retry (Failed Bills):** Cashier can fix a FBR-rejected bill (HS code, qty, unit price, tax %, UoM, exempt flag, item discount) and resubmit WITHOUT regenerating the invoice. Routes: `GET /fbr-pos/transactions/{id}/edit-failed` (`fbrpos.editFailed`), `POST /fbr-pos/transactions/{id}/update-and-retry` (`fbrpos.updateAndRetry`). Only allowed when `fbr_status` is `failed`/`pending_verification`/`pending` and `invoice_mode != local`; never on `submitted`. Original line-items are snapshotted to `fbr_pos_logs` (`status='edit_snapshot'`) for audit BEFORE mutation. Subtotal/tax/total recomputed; transaction-level discount reapplied; `fbr_submission_hash` reset so FBR accepts the new payload; immediately re-submits via `FbrService->submitFbrPosTransaction`. UI: amber "✏️ Edit & Retry" button on `fbr-pos/show` and `fbr-pos/fail-queue` (next to "Retry as-is"); edit page (`resources/views/fbr-pos/edit-failed.blade.php`) shows red error banner with last FBR error + Alpine.js live-recompute totals.
- **PDF A4 Margin Hardening (Print Corner-Cut Fix):** All 3 A4 PDF templates use safe `15mm 15mm 18mm 15mm` page margins (top/right/bottom/left) — covers consumer inkjet/laser unprintable zones (most cannot reach within 12mm of paper edge, and feed-roller bottom needs ~18mm). Affected: `resources/views/invoice/pdf-bw.blade.php` (DI invoice), `resources/views/fbr-pos/invoice-pdf.blade.php` (FBR POS A4 invoice), `resources/views/fbr-pos/receipt.blade.php` (A4 mode only — thermal 80mm mode untouched, still `margin: 0`).

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