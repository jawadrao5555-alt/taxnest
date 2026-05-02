# TaxNest - Heavy Enterprise Product

## Overview
TaxNest is a multi-company SaaS platform for tax and invoice management in Pakistan, ensuring FBR compliance. It offers smart invoicing, configurable governance, an enterprise API, and robust PDF generation. The "Heavy Enterprise" version includes advanced features like a Company Approval System, Customer Ledger, Multi-Branch support, FBR Token Health Monitor, Advanced Admin View, Immutable Audit Logs, and Enterprise Analytics. The project aims for market leadership in Pakistan through compliance, scalability, and an exceptional user experience.

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
TaxNest is built on Laravel 12 with PHP 8.4, leveraging Breeze for authentication. The frontend uses Tailwind CSS, Alpine.js, and Chart.js.

**Core Architectural Patterns and Decisions:**
-   **Multi-tenancy:** Implemented with `company_id` and `CompanyIsolation` middleware.
-   **Role-Based Access Control (RBAC):** Permissions managed via `RoleMiddleware`.
-   **Dual Invoice Numbering:** Supports separate internal and regulatory invoice numbers.
-   **Dynamic Validation Engine:** `ScheduleEngine` for FBR compliance rules.
-   **Immutable Audit Logging:** Critical events logged with SHA256 hashes.
-   **Queue-based Processing:** Background tasks use a database queue.
-   **Company Approval Workflow:** Manages company lifecycle.
-   **Customer Ledger System:** Automates debit entries and allows manual adjustments.
-   **Multi-Branch System:** Supports multiple operational branches per company.
-   **FBR Token Health Monitoring:** Tracks FBR token status.
-   **Enterprise Analytics:** Provides KPIs and compliance metrics via dashboards.
-   **Security Hardening:** Includes `ForceHttps` and subscription-based access controls.
-   **Dynamic FBR Compliance:** Features FBR Excel template alignment, PRAL API integration, per-item FBR fields, pre-submission payload validation with sandbox, and submission idempotency.
-   **Enterprise UX Simplification:** Streamlined invoice lifecycle with four states and a simplified FBR submission flow.
-   **DI Invoice PDF Default:** Pure 100% B&W PDF output for invoices.
-   **PRA POS Cart Per-Item Tax Toggle:** Prominent "NO TAX / TAX" toggle for on-the-fly tax exemption.
-   **Market-Launch Receipt Hardening:** Thermal receipt templates optimized for cheap thermal printers.
-   **Global HS Intelligence Control System:** Centralized `global_hs_master` table, HS resolution, dynamic validation for tax schedules, and weighted suggestions.
-   **HS Code Mapping Engine:** Admin-managed mappings with real-time suggestions during invoice creation.
-   **Admin Announcement System:** Allows administrators to create targeted, dismissible announcements.
-   **SaaS Management Layer:** Separated admin and franchise management with distinct authentication, layouts, subscription plan builders, company approval workflows, and usage monitoring.
-   **Product-Type Plan Separation:** `pricing_plans` table distinguishes between `di` and `pos` product types.
-   **Subscription Override + Usage Limit System:** Admin-only override functionality on `subscriptions` table.
-   **NestPOS Module:** Isolated POS system with its own authentication, layouts, and data models, supporting PRA integration with offline billing and auto-sync. Includes a full Restaurant POS Module, an Enterprise Cart UI, seamless keyboard flow, and a unified top navigation layout.
-   **FBR POS Module:** Isolated FBR-integrated POS with direct FBR API submission, product tax configuration, FBR reporting toggle, and a confidential PIN system. Features a refined professional UI palette, enhanced cashier UX, provisional-bill/mandatory payment-confirm flow, Unified Smart Input for barcode/scan, and a full keyboard-only cart flow.
-   **TaxNest PRA Sync Agent (Desktop Companion App):** Electron-based desktop application for server polling and submitting invoices to PRA.
-   **UI/UX Design:** Responsive sidebar layout with consistent dark/light modes, emerald-600 primary color. Features a unified SaaS-grade design system, enhanced mobile responsiveness, and an Enterprise UX Engine. DI Dashboard has premium upgrades with gradient banners and KPI cards.
-   **PWA / Mall-Grade "exe-look" Suite:** Three independent PWAs (Tax DI, Nest Pra Pos, Nest FBR Pos) with branded icons, Service Workers for offline capabilities, push notifications, and Blade components. Includes an iOS Install Modal, POS offline pre-caching, and PWA diagnostics page, with auto-update and refresh control.
-   **FBR POS Edit & Retry (Failed Bills):** Allows cashiers to fix FBR-rejected bills and resubmit.
-   **PDF A4 Margin Hardening:** All A4 PDF templates use safe `15mm 15mm 18mm 15mm` page margins.
-   **Universal Mobile / PWA Polish:** Global CSS applied across all layouts and standalone views to enhance mobile experience, including iOS tuning, safe-area insets, touch target sizing, responsive tables, mobile-first typography, and horizontal overflow prevention.
-   **POS Cart Keying Fixes:** Implemented stable `_uid` fields for cart items to resolve DOM-reuse bugs in Alpine.js across all cart-bearing POS views.
-   **PRA POS Quick Type — Manual Entry Inline (Inventory-OFF only):** Extends the Quick Type modal for manual product entry in inventory-off mode, allowing cashiers to input prices for unmatched lines directly.
-   **PRA POS Manual-Cart Billing Bypass:** Universal POS `processPayment()` detects `hasManualItems()` and routes through a dedicated path (`processPaymentManual`) that POSTs directly to `pos.invoice.store`. A `_manual: true` payload flag suppresses auto-creation of master products.
-   **PRA POS Universal v2 (Phase 1 — Foundation Polish):** Premium customization layer for the universal POS. Includes an expanded `PosFeatureService` with 9 industry presets and 14 module flags, a dedicated `/pos/features` page for customization, and admin override capabilities.
-   **POS Product Card — Text-Only Row When No Image:** Product grids in Restaurant POS and Universal POS now render a compact text-only row when no product image is available.
-   **PRA POS Responsive Polish (Phase 1.5 — POS-Scoped Audit & Fixes):** Targeted responsive fixes for core POS pages verified across mobile and desktop viewports, ensuring no body-level horizontal overflow.
-   **Receipt Tax-Exempt Visibility:** Both 80mm and 58mm thermal receipt templates plus the on-screen `transaction-show` view now mark tax-exempt cart lines with a bordered `NT` badge next to the item name, plus a "NT = NO TAX" footnote when any exempt item exists. Totals tables show explicit "Tax-Exempt Items" and "Taxable Amount" rows whenever `exempt_amount > 0`.
-   **Restaurant POS Compact Success Popup + Persistent + Live Print Status:** Replaced the full-page "receipt module" success modal (which embedded an iframe receipt preview taking ~45vh of screen) with a clean compact popup card (`max-w-sm`). **Persistent design**: Esc and backdrop-click do NOT dismiss the popup (Esc is reserved for the browser print dialog only) — popup closes ONLY via X (top-right cross), Close button, or "New Sale" button (Enter key). Print/KOT buttons (and `P`/`K` shortcuts) fire prints but the popup STAYS OPEN so cashier can verify, reprint, or take other actions. **KOT button is ALWAYS visible** in a fixed 4-column grid (Print | KOT | New | Close), regardless of `kitchenSettings.print_on_hold` — cashier can reprint Kitchen Order Ticket anytime even when auto-KOT is ON (paper jam, kitchen lost it). **Live print-status pills** (`receiptPrintStatus` and `kotPrintStatus` Alpine state, `'idle'|'printing'|'done'`) wired into `printReceipt()` and `printKitchenTicket()`: shows spinner + "Printing receipt..." then green-check + "Receipt printed" (same for KOT). Status set to `'printing'` BEFORE iframe load for instant feedback, flipped to `'done'` in the postMessage callback; dedup early-return path also sets `'done'` so pills stay consistent. Status resets to `'idle'` before popup opens (`payHeldOrderDirect`) and on `startNewAfterPayment()` so next sale's popup starts clean. Auto-print chain still fires (`wantsAutoReceipt && wantsAutoKot` → receipt → KOT) but pills show progress instead of auto-closing. The floating "Last Sale" reprint widget remains visible after popup closes for any subsequent reprint needs. Also added `x-cloak` to ALL major modals (Pay/Table/Held/NewCustomer/CustomerPicker/ManagerPin/CustomerHistory/Shortcuts/LowStock/LastSaleWidget/SuccessPopup) to fix FOUC flash on initial page load.
-   **Restaurant POS Inventory Master-Switch Hardening:** When `company.inventory_enabled = false`, `RestaurantPosController::index` now suppresses all stock UI.
-   **Restaurant POS Print Idempotency + Last-Sale Reprint Widget:** `printReceipt()` and `printKitchenTicket()` now carry an idempotency lock. A floating "Last Sale" reprint widget is available (fixed bottom-right).
-   **PRA POS Universal Print Strategy Hardening:** Universal POS print chain ported to a postMessage-chained engine. Receipt always prints first, KOT chains after receipt dialog is dismissed. Manual-cart bills now also auto-print receipt on success. Receipt-modal close fires `cancelPendingPrints()`.
-   **Universal POS Persistent Receipt Popup:** Receipt success modal in `resources/views/pos/universal.blade.php` is now persistent (mirrors Restaurant POS pattern). Removed 4-second auto-dismiss (`scheduleReceiptAutoClose()` is now a no-op kept for legacy call sites at `processPayment` L2878 and `payHeldOrderDirect` L3062). Removed `@click.self` backdrop-close and the global Esc keydown close from the modal wrapper — Esc is now reserved exclusively for the browser print dialog. Popup closes ONLY via three explicit actions: (1) top-right X cross (newly added, fixed `top-3 right-3`), (2) "Close" button in the footer grid, or (3) "New Sale" button (which also clears the cart). Footer reorganized into a 4-column grid: **Print (P) | KOT (K, only when `lastOrderId` exists) | New Sale (Enter) | Close**. Print and KOT buttons fire prints but the popup STAYS OPEN so the cashier can verify the receipt iframe and reprint if needed. Keyboard handler updated: `P`=Print, `K`=KOT, `Enter`/Space=New Sale, Esc no longer bound to popup. Removed the misleading "Auto-dismissing in a few seconds" footer text.

## External Dependencies
-   **MySQL:** Primary production database.
-   **PostgreSQL:** Used for Replit development.
-   **FBR (Federal Board of Revenue) Pakistan:** Core integration for tax compliance.
-   **Laravel Breeze:** Authentication scaffolding.
-   **Tailwind CSS:** Frontend styling.
-   **Alpine.js:** Interactive frontend components.
-   **Chart.js:** Data visualization.
-   **PRA (Punjab Revenue Authority):** POS fiscal device integration via PRAL IMS API v1.2.
-   **Unsplash / Picsum:** (Fallback) for `ProductImageService`.