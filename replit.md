# TaxNest - Heavy Enterprise Product

## Overview
TaxNest is a multi-company SaaS platform for comprehensive tax and invoice management in Pakistan, ensuring strict compliance with the Federal Board of Revenue (FBR). It offers smart invoicing, configurable governance, an enterprise API, and robust PDF generation. The "Heavy Enterprise" version adds features like a Company Approval System, Customer Ledger, Multi-Branch support, FBR Token Health Monitor, Advanced Admin View, Immutable Audit Logs, and Enterprise Analytics. The project aims to capture a significant market share in Pakistan by prioritizing compliance, scalability, and an exceptional user experience.

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
- **Dynamic FBR Compliance:** Features FBR Excel template alignment, PRAL API integration, per-item FBR fields, pre-submission payload validation with sandbox mode, and submission idempotency.
- **Enterprise UX Simplification:** Streamlined invoice lifecycle with four states and a simplified FBR submission flow.
- **DI Invoice PDF Default:** Pure 100% B&W PDF is the standard output for invoices.
- **PRA POS Cart Per-Item Tax Toggle:** Prominent "NO TAX / TAX" toggle button next to Notes input for on-the-fly tax exemption marking on individual cart lines.
- **Market-Launch Receipt Hardening:** Thermal receipt templates are optimized for cheap thermal printers.
- **Global HS Intelligence Control System:** Centralized `global_hs_master` table, HS resolution, dynamic validation for tax schedules, and weighted suggestions.
- **HS Code Mapping Engine:** Admin-managed mappings with real-time suggestions during invoice creation.
- **Admin Announcement System:** Allows administrators to create targeted, dismissible announcements.
- **SaaS Management Layer:** Separated admin and franchise management with distinct authentication, layouts, subscription plan builders, company approval workflows, and usage monitoring.
- **Product-Type Plan Separation:** `pricing_plans` table distinguishes between `di` and `pos` product types.
- **Subscription Override + Usage Limit System:** Admin-only override functionality on `subscriptions` table for various access types.
- **NestPOS Module:** Isolated POS system with its own authentication, layouts, and data models, supporting PRA integration with offline billing and auto-sync. Includes a full Restaurant POS Module with table management, KDS, KOT, inventory, and CRM. Features an Enterprise Cart UI, seamless keyboard flow, and a unified top navigation layout.
- **FBR POS Module:** Isolated FBR-integrated POS with direct FBR API submission, product tax configuration, FBR reporting toggle, and a confidential PIN system. Features a refined professional UI palette, enhanced cashier UX, provisional-bill/mandatory payment-confirm flow, Unified Smart Input for barcode/scan, and a full keyboard-only cart flow.
- **TaxNest PRA Sync Agent (Desktop Companion App):** Electron-based desktop application for polling the server, submitting invoices to PRA from a local IP, and reporting results.
- **UI/UX Design:** Responsive sidebar layout with consistent dark/light modes, emerald-600 primary color. Features a unified SaaS-grade design system, enhanced mobile responsiveness, and an Enterprise UX Engine for notifications, spinners, and transitions. DI Dashboard has premium upgrades with gradient banners and KPI cards.
- **PWA / Mall-Grade "exe-look" Suite:** Three independent PWAs (Tax DI, Nest Pra Pos, Nest FBR Pos) with branded icons, Service Workers for offline capabilities, push notifications, and Blade components. Includes an iOS Install Modal, POS offline pre-caching, and PWA diagnostics page, with auto-update and refresh control.
- **FBR POS Edit & Retry (Failed Bills):** Allows cashiers to fix FBR-rejected bills and resubmit without regenerating the invoice.
- **PDF A4 Margin Hardening:** All A4 PDF templates use safe `15mm 15mm 18mm 15mm` page margins.
- **Universal Mobile / PWA Polish:** Global CSS applied across all layouts and standalone views to enhance mobile experience, including iOS tuning, safe-area insets, touch target sizing, responsive tables, mobile-first typography, and horizontal overflow prevention.
- **POS Cart Keying Fixes:** Implemented stable `_uid` fields for cart items to resolve DOM-reuse bugs in Alpine.js across all cart-bearing POS views.
- **PRA POS Universal v2 (Phase 1 — Foundation Polish):** Premium customization layer over the existing universal POS (`/pos/v2/invoice/create` + `pos.universal.blade.php`). Built on the existing `companies.feature_flags` JSON column and `PosFeatureService`. Phase 1 deliverables:
  1. **`PosFeatureService` expanded:** 9 industry presets (Restaurant, Cafe, Quick Service, Retail, Pharmacy, Salon, Grocery, Wholesale, Hybrid Cafe+Retail), 14 module flags grouped into 5 categories (Restaurant & Kitchen, Inventory, Sales, Customer & CRM, Specialty), rich metadata (label/description/icon/color) per flag and preset, dependency resolver.
  2. **Premium `/pos/features` page:** visual preset cards (one-click apply), categorized module groups with descriptions and dependency badges, UI density picker (Simple/Standard/Premium), sticky save bar, "Open Universal POS" CTA.
  3. **Admin override `/admin/company/{id}/pos-features`:** admin can browse to any company → switch industry preset → toggle individual modules → save. All overrides audit-logged via `AuditLogService` + `SecurityLogService`. CTA card on the company show page (Settings tab) → "Open Override Panel".
  4. **Dashboard CTA banner (dismissible):** Promotes Universal POS customization on the PRA POS dashboard for admins; localStorage-dismissible so cashiers/admins are not nagged.
  5. **Manual Item (inventory-OFF only):** Cashier can add an ad-hoc product (Name + Price) directly to cart without it being in the registered product list. Optional "Save permanently" checkbox persists via existing `apiQuickCreate` route under category "Quick" — opt-in, default OFF. Toolbar "+ Manual" emerald button is wrapped in `<template x-if="!isInventoryEnabled()">` so inventory-ON companies never see it; backend `apiQuickCreate` also returns 422 when inventory is on (defence in depth). One-time manual lines (`item_id=null`, `item_type='manual'`) bill cleanly through the standard Pay flow (lax `storeInvoice` validation), but the restaurant Hold/Send-to-Kitchen endpoints require a real `item_id` so those buttons are disabled (with tooltip) and the Alpine `holdOrder()` early-exits while a manual line is in cart.
  - Phases 2-6 pending (module extraction into shared Blade components, full v2 cart rewrite, onboarding wizard, beta migration of legacy POS users).

## External Dependencies
- **MySQL:** Primary production database.
- **PostgreSQL:** Used for Replit development.
- **FBR (Federal Board of Revenue) Pakistan:** Core integration for tax compliance.
- **Laravel Breeze:** Authentication scaffolding.
- **Tailwind CSS:** Frontend styling.
- **Alpine.js:** Interactive frontend components.
- **Chart.js:** Data visualization.
- **PRA (Punjab Revenue Authority):** POS fiscal device integration via PRAL IMS API v1.2.
- **Unsplash / Picsum:** (Fallback) for `ProductImageService`.