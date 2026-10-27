# Feature Suggestions route review

The short browser review ambiguously associated this page with Medicine
Catalogue. Source tracing finds distinct route names, controllers, views and
sidebar links; no redirect/root cause was established. Do not describe this
as a confirmed routing fix.

This test-only PR exercises authenticated GET with populated suggestions and a
planned-status filter, asserts the suggestions view/data and distinct sidebar
links, excludes catalogue sync content, and checks records remain untouched.
Existing two-company tally tests remain unchanged.

Local PHP syntax lint and diff checks pass. PHP >=8.4.1/Composer dependencies
are unavailable here; exact-HEAD full PHPUnit in PR CI must pass. No production
route/controller, tenant configuration or permissions were changed. Independent
live navigation acceptance remains unverified.
