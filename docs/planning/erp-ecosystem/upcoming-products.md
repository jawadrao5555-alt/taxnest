# Upcoming Products scope register

**Status** Early website track in development. Dedicated focused PR lands separately from product functionality and #477. Development approval §14 and original ecosystem brief §14 authorize the early registry and interest workflows. Actual publication follows protected owner release.

## Existing and scope

`resources/views/landing.blade.php`, current public product/navigation views and `MadadgarService` provide reusable website and product information layers. #477 shipped public Our Clients and Product Guide changes, separate from this registry. Preserve existing layouts/links/cards and eligibility. Purchase eligibility and native workflow availability are independent of the Upcoming entry.

| ID | Approved requirement and acceptance |
| --- | --- |
| WEB-01 | Public `/upcoming-products`, homepage and mobile/desktop navigation. Include cards for HR & Attendance, Distribution ERP, TaxNest Switch, Enterprise 360 and Lab expansion. Lab card clearly links to currently available Lab and defines planned commercial expansion. Publish exact supported descriptions, target industries, benefits, proposed features and accessible clearly labeled concept visuals. No unsupported claims or launch dates. |
| WEB-02 | Super Admin CRUD, product/section ordering where applicable, published status and lifecycle Upcoming/In Development/Beta/Released/Temporarily Unavailable. Future approved entries configurable through records. Anonymous query and Madadgar surface allowed published states only. Explicit coming-soon/early availability copy is independent of pricing, checkout and production enrollment. Verify drafts, hide/show, edits, direct routes and unauthorized access. |
| WEB-03 | Register Your Interest, Request Early Access, Notify Me When Available. Distinct purpose, explicit consent, deduplication and rate limiting. Verify email opt-in/expiry and self-serve withdrawal with auditable consent and safe redelivery. Include Super Admin interest review and related management, scoped access and sanitized exports if needed. Public users cannot discover other interest records. |
| WEB-04 | Contextual Madadgar reads verified published entries and correct product states, avoids canned unsupported claims and exposing private interest data. Exercise product intent and existing plans/workflows remaining unchanged. Maintain site catalogue, Product Guide and links. |
| WEB-05 | Real preview of five cards in light/dark, responsive accessibility/contrast, keyboard and Urdu/English as applicable, successful/invalid/duplicate subscription and no unwanted messages. Exercise homepage, current Lab, admin, lifecycle and opt-out in feature/browser tests, and PHP/MariaDB migrations and rollback. After separate owner deploy verify exact release and public routes. |

Track the first PR's code, register IDs, exact head and CI when opened. Mail delivery and production owner approval may gate final confirmation flow publication. Each product has its own commercial release requirements.
