# SEO Strategy — TaxNest

## Project summary
TaxNest is a multi-product SaaS platform for Pakistan tax and invoice compliance:
- **Digital Invoice (DI)** – FBR-compliant invoicing at `/digital-invoice` (redirects also at `/di`)
- **NestPOS (PRA POS)** – PRA-integrated point-of-sale at `/pos`
- **FBR POS** – FBR-integrated POS (owner says currently frozen; do not scan separately)

Tech stack: Laravel 12 / PHP 8.4, Blade templates (SSR), Tailwind CSS, Alpine.js.
All public marketing pages are server-rendered — full HTML is returned to crawlers.

## In scope
- Public marketing pages: `/`, `/pos`, `/digital-invoice`, `/tutorials`, `/contact`, `/download`
- `robots.txt`, sitemap, structured data, meta tags, OG/Twitter, canonical, favicon

## Out of scope
- Authenticated app pages (`/dashboard`, `/invoice/**`, `/pos/dashboard/**`, `/admin/**`, etc.)
- FBR POS (owner has frozen work there — do not flag FBR-POS-specific authenticated pages)
- Public invoice share URLs (`/share/invoice/{uuid}`) — intentionally noindex is expected for single-invoice public share links; the `public/company-profile.blade.php` already has `noindex, nofollow` which appears intentional

## Target audience
Pakistani businesses and tax consultants needing FBR/PRA-compliant invoicing and POS solutions.
Primary market: Pakistan (Urdu/Roman Urdu users; English business language preferred per owner rules).

## Primary keywords (inferred)
- "FBR invoicing Pakistan", "PRA POS Pakistan", "NestPOS", "TaxNest"
- "PRA compliant POS", "FBR digital invoice software Pakistan"

## Dismissed categories
- (None yet)
