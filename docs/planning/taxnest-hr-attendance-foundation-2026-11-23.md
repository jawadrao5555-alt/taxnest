# TaxNest HR & Attendance — HR-01 Foundation Contract
Date: 2026-11-23
Status: Draft / standalone-first / no production changes

## Product goal
Cross-platform Android/iOS employee app and web admin for independent multi-company HR SaaS. Future TaxNest integration via explicit, versioned adapters, not implicit company-group access.

## Existing repository findings
- Laravel 12 application; existing product-scoped User identity and Company.product_type.
- CompanyGroupService derives relationships from CNIC/NTN and weaker email/phone; these must never grant authorization.
- SubscriptionAssignmentService replaces active company subscriptions; do not use it to attach HR to an existing POS/DI company.
- Healthcare HR already contains attendance evidence, correction approval, leave, roster and payroll-input patterns; evaluate extraction without coupling new HR records to healthcare tables.
- POS biometric models provide device, PIN map and raw punch structures; integrate via isolated adapter and idempotency.

## Phase 1 domain boundaries
HR tenant (organization) owns branches, locations, departments, policies, memberships, employees, shifts, attendance events, requests, salary data, devices and audit records.
Employee is an employment identity distinct from login credentials; a person may have memberships in more than one company. Never assume matching email/CNIC means access.
No change to existing Company/User/product billing behavior in HR-01.

## Proposed data model (design, not migrations)
hr_organizations, hr_organization_memberships, hr_employees, hr_departments, hr_work_locations, hr_shift_templates, hr_shift_assignments, hr_policy_versions, hr_policy_assignments, hr_attendance_events, hr_attendance_days, hr_location_observations, hr_location_consents, hr_leave_types, hr_leave_balances, hr_leave_requests, hr_exit_requests, hr_incidents, hr_incident_responses, hr_disciplinary_decisions, hr_payroll_periods, hr_payroll_items, hr_device_connections, hr_device_employee_maps, hr_audit_events.
Every tenant-owned table has organization_id; indexes and authorization enforce tenant isolation. Sensitive data encrypted, access restricted, retention configurable.

## Employee app contract
- Employee profile: name, father name, CNIC, DOB, address, email, mobile, photo, designation, branch, salary visibility, QR ID card.
- Login: verified email/mobile/CNIC identifier plus secure secret/OTP; device biometrics for local unlock, not as server-side identity proof.
- GPS selfie check-in/out; evidence includes server receipt time, device time, accuracy, consent, geofence result, selfie/liveness verification and device metadata.
- Company may select app, biometric hardware or hybrid punches.
- Employee sees the same underlying recorded location observations and their statuses as authorized HR; disputes supported.
- Tracking limited to assigned duty sessions; no routine off-duty collection. OS restrictions/disabled GPS represented as unavailable, never fabricated presence or automatic misconduct.
- Mobile OS cannot override disabled master Location Services.

## Default editable policy template (subject to legal review)
- Example shift 09:00–17:00; on-time through 09:05, late after 09:05 through 09:30, after 09:30 absent flag with actual punch retained; check-out from 17:00.
- Two monthly leave days on request; conditional 15 annual days where monthly facility unused (accrual and legal interpretation pending).
- Separate emergency quota: three days per year.
- Proposed perfect-attendance bonus: one monthly salary after full eligibility review.
- Each company may override templates with effective-dated versions, subject to statutory minimum entitlements.
- Incident detected -> employee explanation -> HR/admin review -> warning, waiver, correction or legally permissible fine. No automatic payroll fine.
- Individual, branch, department, rotating, night and split shifts supported; explicit precedence and effective dates.

## Security and acceptance gates
1. Cross-tenant API and IDOR tests; no implicit access from CompanyGroupService.
2. Payroll and location permissions independently enforced; immutable evidence and correction history.
3. Time boundary tests (09:05:00, 09:30:00, 17:00:00), timezone and overnight shifts.
4. Duplicate device events, out-of-order offline sync, late-arriving punches.
5. Consent revocation and duty-end tracking stop; no off-duty location ingestion.
6. Existing POS/DI/Healthcare login and billing regression tests before any integration.
7. Internal fictional test company first; no real CNIC, salary, credentials or biometric templates in public tests.

## Delivery slices
HR-01A: domain schema + tenant-scoped authorization + fictional tests.
HR-01B: company setup, memberships, employee directory, policy versioning.
HR-02: attendance event ingestion and schedule engine.
HR-03: employee mobile APIs and consent-driven location verification.
HR-04: leave, exit, incident reviews.
HR-05: payroll and bonus.
HR-06: biometric adapters.
HR-07: subscriptions and external TaxNest integration adapters.

## Explicit non-goals for this initial branch
No live employee onboarding, no real GPS collection, no existing product billing modifications, no migrations/deployment, no automatic fines. Before implementation, review existing migrations, healthcare access rules, product catalog and CI; keep sensitive audit and credentials private.
