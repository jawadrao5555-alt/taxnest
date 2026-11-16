# B01 #316 / #301 workstream 3 reconciliation (read-only audit)

## Owner decision: prospective selected-package policy (15 November 2026)

The owner selected a NEW-registration policy, not a rewrite of legacy contracts.
Implementation and acceptance are in progress; this section supersedes the
unresolved-choice wording in the historical audit below.

- New public registration explicitly selects a valid same-product package.
  Freeze 72 hours of trial with unlimited **bill count only**. Other feature,
  branch, user, add-on and fiscal permissions remain the selected package's
  verified limits; School/Hospital fiscal stays blocked.
- Verified full payment during trial confirms the purchase, with paid time
  starting at the frozen trial end. Payment after trial starts paid time at
  verified activation, never backdated. Do not grant future paid features early.
- Seven days of grace only follows paid expiry. After grace, new billing is
  blocked while authorized history, receipts and reprint remain available.
- Renewal preserves remaining paid time; explicitly test dates during and after
  grace. Duplicate approval creates no duplicate receipt or term.
- Existing customers retain legacy terms, caps, grants and entitlements. Preserve
  #362 renewal behavior and #363 immutable snapshots for existing contracts.
- Signup, checkout, customer and admin pages show policy version and frozen
  trial/paid/grace dates. Boundary, retry, renewal and regression tests are required.

No live settings, commercial price changes or data migration have been performed.

Inspected current main after local allocator full run. These are source-to-code/test mappings, not new acceptance executions. Historical source remains planning; passing selected suites is not whole-programme acceptance.

| Original scenarios | Concrete implemented evidence | Remaining gap |
|---|---|---|
| T01 same customer/new product | CompanyGroupingTest::test_same_cnic_across_products_forms_one_group; per-account identity retained; signup product checks | Full cloning/product migration old-access matrix not established by this inspection |
| T02 selected-package trial | TrialPurchaseEvidenceTest verifies frozen product trial and no paid documents; SignupTrialSubscriptionTest verifies creation/idempotence | ensureTrial chooses product trial plan, not chosen paid package. DI/FBR trial invoice cap is still enforced from trial plan. Source selected-package/unlimited requirement not implemented universally |
| T03 trial boundary/session | ExpiryReminderBoundaryTest::test_trial_status_boundaries and expired/no-reminder tests; SubscriptionAccessService server checks | Need exact 72-hour timestamp + expired pre-existing browser/API/session paths across all products under intended policy |
| T04 concurrent approval | tests/native/rc_paid_renewal.php: one immutable invoice/receipt, frozen snapshot, renewal exactly once; FranchiseApprovalCommissionTest and AgentCommissionDecisionAtomicityTest | Native financial/grant/commission combinations must not be inferred from standalone marker |
| T05 rollback | VerifiedSubscriptionDocumentsTest::test_late_document_failure_rolls_back_payment_approval_and_activation; shared cycles agent commission insert failure rollback | Implemented scoped cases; not all lifecycle transitions |
| T06 early renewal | PaymentProofInstantAccessTest::test_same_package_paid_renewal_preserves_time_and_retry_does_not_extend_twice; native renewal | Existing paid term preserved. Trial-to-paid policy conflicts with requested start-after-trial |
| T07 late renewal | SubscriptionAssignmentService::assign defaults start now, adds cycle with no-overflow; current paid term carry-forward only nontrial | Explicit late payment policy/current behavior should be separately documented/tested with PKT timestamp |
| T08 leap/timezone/grace | PaidRenewalSchemaTest leap day; configured cycle tests month-end; ExpiryReminderBoundaryTest reminders | No automatic paid-expiry 7-day grace: hasAccess denies as soon as end_date is past. Manual grace override is not automatic 7-day policy |
| T09 suspend paid | AdminCompanyAccountOverviewTest::test_suspension_records_reason_and_restore_condition_atomically_with_company_state + stale cycle/rollback guards | Full existing-session/API/device policy matrix not established |
| T10 payment while suspended | PaymentProofInstantAccessTest::test_approve_never_unsuspends_a_suspended_company | Mapped existing test |
| T11 reactivate expired | Overview tests preserve terms; access service still expiry-checks | Need explicit real-route reactivation + expired-action denial test |
| T12 grant expiry/revocation | PaymentProofInstantAccessTest auto-grant rejection leaves admin override untouched; ExpiryReminderBoundaryTest; overview separate grant/suspension | Lifetime removal / full baseline preservation + audited grant mutation matrix not established |
| T13 suspension/grant/global | Overview separate grant/suspension and unknown legacy reason; access precedence existing | No single proven UI/API/jobs matrix covering all simultaneous states/global maintenance |
| T14 upgrade/downgrade | SubscriptionPurchaseSnapshotTest::test_reviewed_package_change_keeps_original_terms_and_issues_one_new_purchase; PosRenewalBranchSlotReviewTest prevents stranding branches | Generic all-product midterm proration/limits effective-date acceptance not established |
| T15/MT11 counted roles/limits | PosTeamAccountLimitTest, branch quota suites, configured canonical limits | Every plan / branch role-change and concurrent topology matrix not established by billing62-test slice |
| T16 add-on near renewal | PosPaidAddonFlowTest::test_mid_package_addon_is_prorated_to_the_remaining_whole_months_everywhere; calendar-month proration, same proof once, frozen submitted snapshot, expiry rides package | Existing bounded implementation mapped, not all commercial products |
| T17 discounts/frozen terms | SubscriptionPurchaseSnapshotTest and persisted server quote; catalogue changes do not rewrite approval | Complete date/stacking/boundary matrix not established |
| T18 partial/credit/refund/reversal | VerifiedSubscriptionDocumentsTest::test_reviewed_amount_is_distinct_from_claim_and_balance_is_not_silently_forgiven; SubscriptionFinancialAdjustmentTest; native concurrent refund evidence | Adjustments explicitly evidence_only: no applied credit allocation, no balance forgiveness, no entitlement or commission changes. This is not complete accounts receivable/credit application outcome |
| T19 payout after refund | FranchiseApprovalCommissionTest::test_admin_payout_nets_refund_adjustments_and_is_idempotent | Generic subscription adjustment does not call commission service; separate franchise adjustment path is not automatic canonical refund->clawback linkage |
| B01-R07 TaxNest accounts | Immutable subscription invoices/payments plus CompanyAccountOverview/SubscriptionBillingHistory; tenant sales separate; franchise payable | No identified SaaS expense category/reference entry workflow or complete platform receivables/partner-payable source adapter; HealthExpense is tenant hospital data, not TaxNest expense accounting. Do not reuse shop sales or health expense as platform income/expense |

## Exact policy contradiction to resolve with a versioned implementation

`PaymentProofInstantAccessTest::test_trial_time_is_not_carried_into_a_paid_renewal` sets trial_ends_at three days ahead but expects paid end = now + 12 months. `SubscriptionAssignmentService::assign` sets start_date to now, and carries previous end only when previous.trial_ends_at is absent. This preserves existing behavior, but does not fulfill #301 workstream3 paid period starts after remaining trial. Do not delete the old preservation assertion or migrate existing contracts silently. A new policy version for new signup/quotes, with frozen trial/paid/grace boundaries and legacy fallback, is the safe implementation shape.

No runtime changes, live inspection, commercial setting changes, customer data or money movement performed for this audit.

## Newer source qualification (important)

The later #362 document `docs/qa/admin-billing-renewal-2026-11-07.md` explicitly preserves existing trial conversion, expired terms and grant period rules. #363 `docs/qa/admin-billing-bulk-2026-11-07.md` states existing-policy billing workstream, no new automatic-refund/upgrade-credit/commission-clawback policy authorized; product trial selection and partial/excess evidence-only behavior are deliberate. #374 source explicitly reuses #362/#363, requires trial conversion coverage and expiry at recorded term, but does not introduce a replacement 72-hour/grace contract. Therefore the gaps above are unresolved original programme outcomes / policy reconciliation, NOT demonstrated regressions against the later existing-policy release. No implementation should silently replace existing contracts based on the older checklist alone. Root must resolve prospective opt-in interpretation before implementation. All earlier contracts/tests remain intact.
