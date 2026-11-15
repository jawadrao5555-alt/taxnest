# Shared configured periods: #374 with #316/#337 preservation

The canonical resolver accepts monthly, quarterly, semiannual and annual. DI retains its existing configured period behavior. POS/FBR/ERP short periods require explicit `BILLING_SHARED_CONFIGURED_CYCLES=true`; default false preserves the existing annual commercial policy and does not activate historical retired price columns. Prices must be explicitly positive in the cycle column. Missing or unsupported periods are rejected, never converted into an annual purchase. Existing annual arithmetic and branch add-on annual rates are unchanged.

`BILLING_DISABLE_FRESH_MONTHLY=true` is a separate explicit rollout policy, default false. It never rewrites an existing subscription. Persisted validated quotes retain their own frozen period, rate and purchased entitlements after catalogue edits or policy changes; repeat proof approval remains idempotent. No new commercial prices are seeded. Activation requires operator configuration of approved prices and policy, not a code assumption.

Synthetic coverage: three shared product families, four periods, leap-year month ends, unchanged catalogue, missing/zero rate rejection, opt-in gate, frozen monthly approval after price removal and monthly retirement, duplicate approval, existing DI prices and snapshot regressions. Branch renewal verifies configured quarter with existing annual slot rate prorated by 3/12.

FBR Starter/Business discrepancy: `FbrPosPlanComparisonService` already identifies legacy marketing claims of 2/5 team accounts against configured 1/3 `user_limit`. Do not change commercial limits to match marketing. Customer cards must use canonical configured limits. Actual staging catalogue values still require read-only confirmation; no live catalogue inspected or modified in this module.

Not a blanket closure of #316/#337/#374. Customer/signup selector wiring, consolidated browser runs, native database money/approval concurrency and final exact-head CI remain integration evidence. No live money, data or fiscal configuration changed.

## Operator activation, no automatic commercial change

1. Read-only review the current catalogue and existing annual contracts. Enter only owner-approved explicit monthly/quarterly/semiannual prices through the admin plan editor; keep missing prices null. No UI amount is inferred from the annual price.
2. In staging set `BILLING_SHARED_CONFIGURED_CYCLES=true`, rebuild the application's config cache through the approved deployment process, and verify each affected product's visible selector and server-side forged-period rejection.
3. Only when explicitly approved, set `BILLING_DISABLE_FRESH_MONTHLY=true` to stop new monthly quotes. Leave this false otherwise. Check pending frozen monthly proofs still approve with original terms.
4. Production activation requires the owner's deploy approval and configured prices. Reverting the policy flags hides future options but does not rewrite issued subscriptions or frozen quotes. Normal permission checks remain mandatory.
