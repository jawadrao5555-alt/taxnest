<?php
/**
 * POS package gate-matrix check — run BEFORE every live deploy.
 *
 * Asserts the Starter/Business/Pro/Unlimited plan-gate matrix
 * (PosFeatureService::PLAN_GATES) against live code paths, plus the two
 * derived gates that ride on it:
 *   - PosAccessService::customSet()      (Team Custom Access — Business and above)
 *   - PublicProfileController::publicUrlFor() (QR Menu — Business and above)
 *
 * Current package policy (owner-approved Aug 22, 2026):
 *   Business+ includes Delivery Riders and QR Menu; Pro+ includes Staff Hazri.
 *   WhatsApp Bill, Rider Live Tracking and Caller ID remain paid add-ons.
 * and the cross-cutting rules: active trial unlocks everything, expired
 * trial locks the premium gates, admin override unlocks everything,
 * no-subscription locks everything, internal accounts bypass all gates.
 *
 * Runs against the MySQL staging DB inside a transaction that is ALWAYS
 * rolled back — no rows survive, safe to run any time the DB is up.
 *
 * Usage (dev container — note the PG env-strip prefix):
 *   env -u DATABASE_URL -u DB_CONNECTION -u PGHOST -u PGPORT -u PGUSER \
 *       -u PGPASSWORD -u PGDATABASE php scripts/plan-gate-check.php
 *
 * Exit codes: 0 = matrix intact, 1 = regression found, 2 = could not run
 * (DB down / wrong driver / plan rows missing).
 */

require __DIR__ . '/../vendor/autoload.php';
$app = require __DIR__ . '/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Http\Controllers\PublicProfileController;
use App\Models\Branch;
use App\Models\Company;
use App\Models\Subscription;
use App\Models\User;
use App\Services\BranchAddonService;
use App\Services\PlanLimitService;
use App\Services\PosFeatureService;
use App\Services\PosAccessService;
use Illuminate\Support\Facades\DB;

// ── Safety: must be MySQL (never sqlite / Replit Postgres) ─────────────
try {
    $driver = DB::connection()->getDriverName();
    if ($driver !== 'mysql') {
        fwrite(STDERR, "ERROR: expected mysql connection, got '{$driver}' — run with the PG env-strip prefix.\n");
        exit(2);
    }
    DB::connection()->getPdo();
} catch (\Throwable $e) {
    fwrite(STDERR, 'ERROR: cannot reach the staging MySQL DB: ' . $e->getMessage() . "\n");
    fwrite(STDERR, "Start the 'MySQL Staging' workflow and retry.\n");
    exit(2);
}

// ── Expected matrix (owner-approved Aug 2026, see the pro_max migration) ──
// Gate order follows PosFeatureService::PLAN_GATES.
$GATES = PosFeatureService::PLAN_GATES;
$EXPECTED_GATE_ORDER = [
    'deals_enabled', 'riders_enabled', 'hazri_enabled', 'analytics_enabled',
    'reports_enabled', 'rider_tracking_enabled', 'custom_access_enabled',
    'qr_menu_enabled', 'offline_enabled', 'excel_enabled',
    'khata_enabled', 'loyalty_enabled', 'kot_enabled', 'caller_id_enabled',
    'whatsapp_enabled',
];
$MATRIX = [
    // plan name => [deals, riders, hazri, analytics, reports, rider_tracking, custom_access, qr_menu, offline, excel, khata, loyalty, kot, caller_id, whatsapp]
    // 23 Aug 2026 (owner): Pro and Pro Max are RETIRED — Pro was merged into
    // Business, which keeps the name and takes Pro's whole feature set at
    // Rs 27,999/yr. Three sellable packages remain: Starter, Business,
    // Unlimited. WhatsApp Bill is now INCLUDED in Business and Unlimited, and
    // Caller ID is included in Unlimited (still a paid add-on below it) — an
    // add-on gate may ride a package only because both now have a comparison
    // row. Rider Live Tracking stays add-on-only on every package.
    // 22 Aug 2026 (owner): Delivery Riders + QR Menu are included from
    // Business upward. Active trials still unlock everything by rule; admin
    // override and internal accounts too. Custom Access is included from
    // Business upward and is unavailable on Starter.
    // 9 Aug 2026 strict binding: reports_enabled gates CSV/PDF exports only —
    // Starter card promises basic report PAGES (ungated) but NOT exports, so
    // Starter reports=false; excel_enabled (product import/export) = Business+.
    // 10 Aug 2026 FBR infrastructure pass: khata/loyalty/kot columns added
    // TRUE for every plan of every product (behaviour-preserving — nothing
    // was gated on them before). The FBR ladder flip comes later.
    // 13 Aug 2026 (owner, market-capture move): Business gains Kitchen mode
    // (restaurant_enabled — asserted separately below) + Analytics.
    'Starter'   => [false, false, false, false, false, false, false, false, false, false, true, true, true, false, false],
    'Business'  => [true,  true,  true,  true,  true,  false, true,  true,  true,  true,  true, true, true, false, true],
    'Unlimited' => [true,  true,  true,  true,  true,  false, true,  true,  true,  true,  true, true, true, true,  true],
];
// Branch ladder (owner-approved 21 Aug 2026): har package apne card wali
// branches MUFT deta hai; us se ooper har branch Rs 10,000 SAALANA paid add-on
// (BranchAddonService + companies.extra_branch_slots). Unlimited ki hadd -1 se
// 5 hui — warna branch feature live hote hi wo shop bila hisaab branches bana
// leti. Ye qatar aur pricing formula lockstep mein rehni chahiye.
// 23 Aug 2026 (owner): Unlimited 2 branches, baqi 1 — us se ooper paid slots.
$BRANCH_LADDER = ['Starter' => 1, 'Business' => 1, 'Unlimited' => 2];

// Derived-surface expectations per plan:
$CUSTOM_SET_PLANS = ['Business', 'Unlimited']; // included Business+
$QR_URL_PLANS     = ['Business', 'Unlimited'];
// Restaurant module (pricing_plans.restaurant_enabled → restaurantAllowed()):
// Business+ since 13 Aug 2026 (Kitchen mode opened up for Business).
$RESTAURANT_PLANS = ['Business', 'Unlimited'];

$fail = 0;
$pass = 0;
function ok(string $msg): void { global $pass; $pass++; }
function bad(string $msg): void { global $fail; $fail++; fwrite(STDERR, "    FAIL: {$msg}\n"); }
function check(bool $cond, string $msg): void { $cond ? ok($msg) : bad($msg); }

// If PLAN_GATES itself changes shape, this script must be updated in lockstep.
sort($GATES);
$sortedExpected = $EXPECTED_GATE_ORDER;
sort($sortedExpected);
if ($GATES !== $sortedExpected) {
    bad('PosFeatureService::PLAN_GATES changed (' . implode(',', PosFeatureService::PLAN_GATES)
        . ') — update the expected matrix in scripts/plan-gate-check.php.');
    exit(1);
}

DB::beginTransaction();
try {
    $plans = DB::table('pricing_plans')->where('product_type', 'pos')
        ->whereIn('name', array_keys($MATRIX))->get()->keyBy('name');
    foreach (array_keys($MATRIX) as $name) {
        if (!isset($plans[$name])) {
            fwrite(STDERR, "ERROR: pricing_plans row missing for POS plan '{$name}' — run migrations.\n");
            DB::rollBack();
            exit(2);
        }
    }
    $trialPlan = DB::table('pricing_plans')->where('product_type', 'pos')->where('name', 'Trial')->first();

    $mkCompany = function (string $suffix): Company {
        $c = new Company();
        $c->name = 'GateCheck ' . $suffix . ' ' . uniqid();
        $c->product_type = 'pos'; // this whole script is the PRA POS matrix
        $c->save();
        return $c->fresh();
    };
    $mkSub = function (Company $c, int $planId, array $extra = []): Subscription {
        return Subscription::create(array_merge([
            'company_id' => $c->id,
            'pricing_plan_id' => $planId,
            'active' => true,
            'start_date' => now()->subDay()->toDateString(),
            'end_date' => now()->addYear()->toDateString(),
            'trial_ends_at' => null,
        ], $extra));
    };
    // In-memory user is enough: customSet() only reads attributes + company_id.
    $mkUser = function (Company $c): User {
        $u = new User();
        $u->forceFill([
            'id' => 0,
            'company_id' => $c->id,
            'role' => 'user',
            'pos_role' => 'pos_cashier',
            'pos_custom_access' => json_encode(['dashboard', 'orders']),
        ]);
        return $u;
    };
    $assertGates = function (Company $c, array $expected, string $label) use ($EXPECTED_GATE_ORDER) {
        PosFeatureService::flushGateCaches();
        foreach ($EXPECTED_GATE_ORDER as $i => $gate) {
            $got = PosFeatureService::planAllows($c, $gate);
            check($got === $expected[$i], "{$label}: {$gate} expected " . var_export($expected[$i], true) . ', got ' . var_export($got, true));
        }
    };

    // ── 1. The four paid plans ─────────────────────────────────────────
    foreach ($MATRIX as $planName => $expected) {
        $c = $mkCompany($planName);
        $mkSub($c, $plans[$planName]->id);
        $assertGates($c, $expected, "plan {$planName}");

        // Restaurant & Kitchen module (restaurant_enabled column, not a
        // PLAN_GATES entry — it rides through restaurantAllowed()).
        $wantResto = in_array($planName, $RESTAURANT_PLANS, true);
        $gotResto = PosFeatureService::restaurantAllowed($c);
        check($gotResto === $wantResto,
            "plan {$planName}: restaurantAllowed() expected " . var_export($wantResto, true) . ', got ' . var_export($gotResto, true));

        // Team Custom Access: stored set goes inert unless plan allows it.
        $set = PosAccessService::customSet($mkUser($c));
        $wantSet = in_array($planName, $CUSTOM_SET_PLANS, true);
        check(($set !== null) === $wantSet,
            "plan {$planName}: customSet() should be " . ($wantSet ? 'HONORED' : 'inert (null)') . ', got ' . ($set === null ? 'null' : 'set'));

        // Branch ladder: the plan row must match the owner-approved count.
        $wantBranches = $BRANCH_LADDER[$planName] ?? null;
        check((int) $plans[$planName]->branch_limit === $wantBranches,
            "plan {$planName}: branch_limit must be {$wantBranches}, got " . var_export($plans[$planName]->branch_limit, true));

        // QR Menu public URL: enabled page + slug, gate decides.
        $c->public_profile_slug = strtolower(str_pad(dechex($c->id), 16, 'a'));
        $c->public_profile_settings = ['enabled' => true];
        $c->save();
        PosFeatureService::flushGateCaches();
        $url = PublicProfileController::publicUrlFor($c->fresh());
        $wantUrl = in_array($planName, $QR_URL_PLANS, true);
        check(($url !== null) === $wantUrl,
            "plan {$planName}: publicUrlFor() should be " . ($wantUrl ? 'a URL' : 'null') . ', got ' . var_export($url, true));
    }

    $allTrue  = array_fill(0, count($EXPECTED_GATE_ORDER), true);
    $allFalse = array_fill(0, count($EXPECTED_GATE_ORDER), false);

    // ── 2. Active trial unlocks everything ─────────────────────────────
    if ($trialPlan) {
        $c = $mkCompany('TrialActive');
        $mkSub($c, $trialPlan->id, ['trial_ends_at' => now()->addDays(7)]);
        $assertGates($c, $allTrue, 'active trial');
        check(PosAccessService::customSet($mkUser($c)) !== null, 'active trial: customSet() should be honored');

        // ── 3. Expired trial locks the premium gates ────────────────────
        $c2 = $mkCompany('TrialExpired');
        $mkSub($c2, $trialPlan->id, ['trial_ends_at' => now()->subDay()]);
        PosFeatureService::flushGateCaches();
        foreach (['riders_enabled', 'hazri_enabled', 'analytics_enabled', 'qr_menu_enabled', 'custom_access_enabled'] as $gate) {
            check(PosFeatureService::planAllows($c2, $gate) === false, "expired trial: {$gate} must be false");
        }
        check(PosAccessService::customSet($mkUser($c2)) === null, 'expired trial: customSet() must be inert');
    } else {
        bad("Trial plan row missing (product_type=pos, name=Trial)");
    }

    // ── 4. Admin override = free access ON A PACKAGE ───────────────────
    // Owner rule (Aug 2026): a grant waives the PAYMENT, not the package. Sat
    // on a real package the shop gets exactly that package's features; the
    // admin picks which one while granting. Only a grant with no real package
    // behind it (Trial row, or no plan at all) still opens everything, so
    // legacy partner/internal grants never lose access overnight.
    // Add-on-only gates (Caller ID / WhatsApp Bill / Rider Tracking) sit in NO
    // package, so free access keeps them open — same as a trial.
    $addonGates = array_column(\App\Services\PosAddonPricingService::ADDONS, 'gate');
    $withAddons = function (array $expected) use ($EXPECTED_GATE_ORDER, $addonGates): array {
        foreach ($EXPECTED_GATE_ORDER as $i => $gate) {
            if (in_array($gate, $addonGates, true)) {
                $expected[$i] = true;
            }
        }
        return $expected;
    };

    $ovrExtra = ['override_type' => 'temporary', 'override_until' => now()->addDays(10)];
    $c = $mkCompany('OverrideStarter');
    $mkSub($c, $plans['Starter']->id, $ovrExtra);
    $assertGates($c, $withAddons($MATRIX['Starter']), 'override on Starter');
    check(PosFeatureService::restaurantAllowed($c) === false,
        'override on Starter: Restaurant module must stay closed (Starter has none)');

    $c = $mkCompany('OverrideUnlimited');
    $mkSub($c, $plans['Unlimited']->id, $ovrExtra);
    $assertGates($c, $withAddons($MATRIX['Unlimited']), 'override on Unlimited');
    check(PosFeatureService::restaurantAllowed($c) === true,
        'override on Unlimited: Restaurant module must be open');

    if ($trialPlan) {
        $c = $mkCompany('OverrideTrialPlan');
        $mkSub($c, $trialPlan->id, array_merge($ovrExtra, ['trial_ends_at' => now()->subMonth()]));
        $assertGates($c, $allTrue, 'override on a Trial row (no real package)');
    }

    $c = $mkCompany('OverrideNoPlan');
    Subscription::create([
        'company_id' => $c->id, 'pricing_plan_id' => null, 'active' => true,
        'start_date' => now()->subDay()->toDateString(), 'end_date' => null,
        'override_type' => 'lifetime', 'override_until' => null,
    ]);
    $assertGates($c, $allTrue, 'override with no package at all');

    // ── 5. No active subscription = everything locked ───────────────────
    $c = $mkCompany('NoSub');
    $assertGates($c, $allFalse, 'no subscription');

    // ── 6. Internal account bypasses all gates ──────────────────────────
    $c = $mkCompany('Internal');
    $c->is_internal_account = true;
    $c->save();
    $assertGates($c->fresh(), $allTrue, 'internal account');

    // ── 6b. Paid extra-branch add-on (Rs 10,000/branch/year) ────────────
    //   included branches free → hadd par ruke → khareede hue slots se aage
    //   khule → renewal ka total base + slots×10,000 bane. Admin override aur
    //   trial ke rules waise ke waise.
    $ebPlan = $plans['Unlimited'];              // 2 included branches
    $c = $mkCompany('BranchAddon');
    $mkSub($c, $ebPlan->id);
    $mkBranch = function (Company $co, string $nm) {
        Branch::create(['company_id' => $co->id, 'name' => $nm, 'is_active' => true, 'is_head_office' => false]);
    };
    check(PlanLimitService::canAddBranch($c->id)['allowed'] === true, 'add-on: included branches must be free (0/2)');
    $mkBranch($c, 'B1');
    $mkBranch($c, 'B2');
    check(PlanLimitService::canAddBranch($c->id)['allowed'] === false, 'add-on: must stop at the package limit (2/2)');
    $c->extra_branch_slots = 1;
    $c->save();
    check(PlanLimitService::canAddBranch($c->fresh()->id)['allowed'] === true, 'add-on: a paid slot must open the next branch (2/3)');
    $mkBranch($c, 'B3');
    check(PlanLimitService::canAddBranch($c->id)['allowed'] === false, 'add-on: must stop again once the paid slot is used (3/3)');

    // Pricing formula — one source of truth for every surface.
    check(BranchAddonService::priceForMonths(1, 12) === 10000.0, 'add-on: 1 slot / 12 months must be Rs 10,000');
    check(BranchAddonService::priceForMonths(3, 12) === 30000.0, 'add-on: 3 slots / 12 months must be Rs 30,000');
    check(BranchAddonService::priceForMonths(1, 6) === 5000.0, 'add-on: 1 slot / 6 months pro-rata must be Rs 5,000');
    $ebPriced = \App\Services\SubscriptionAssignmentService::computePrice(
        \App\Models\PricingPlan::find($ebPlan->id), 'annual', $c->fresh()
    );
    check((float) $ebPriced['extra_branch_price'] === 10000.0,
        'add-on: renewal total must include 1 slot × Rs 10,000, got ' . $ebPriced['extra_branch_price']);
    check((float) $ebPriced['final_price'] === (float) $ebPriced['base_price'] + 10000.0,
        'add-on: renewal total must be base package + slots');

    // Trial wali company add-on nahi khareed sakti.
    if ($trialPlan) {
        $ct = $mkCompany('BranchAddonTrial');
        $mkSub($ct, $trialPlan->id, ['trial_ends_at' => now()->addDays(7)]);
        check(BranchAddonService::purchaseEligibility($ct->fresh())['allowed'] === false,
            'add-on: trial company must not be able to buy extra branches');
    }
    // Admin branch override still wins outright (aur kharidari band).
    $co = $mkCompany('BranchAddonOverride');
    $mkSub($co, $ebPlan->id);
    $co->branch_limit_override = 9;
    $co->save();
    check(BranchAddonService::purchaseEligibility($co->fresh())['allowed'] === false,
        'add-on: admin branch override must disable the purchase path');
    check(PlanLimitService::canAddBranch($co->id)['allowed'] === true,
        'add-on: admin branch override must still win over plan+slots');

    // ── 6c. Paid FEATURE add-ons: catalogue-only gates open through an active
    //   pos_addons row, die with its expiry, and stay OFF on every package.
    // Annual-only since 23 Aug 2026 (owner): add-ons are sold for a year, same
    // as the packages they ride on. A second cycle reappearing here means the
    // retirement was half-undone.
    check(\App\Services\PosAddonPricingService::CYCLES === ['annual'],
        'feature add-on: annual must be the ONLY add-on cycle, got '
        . implode('/', \App\Services\PosAddonPricingService::CYCLES));
    foreach (array_keys(\App\Services\PosAddonPricingService::ADDONS) as $adCode) {
        $adRates = [];
        foreach (\App\Services\PosAddonPricingService::CYCLES as $adCycle) {
            $adRates[$adCycle] = \App\Services\PosAddonPricingService::price($adCode, $adCycle);
            check($adRates[$adCycle] > 0,
                "feature add-on: {$adCode} must carry a non-zero {$adCycle} price");
        }

        // An add-on rides on a package and dies with it — it may never cost a
        // meaningful slice of the cheapest package it can be bought against.
        check($adRates['annual'] * 2 <= (int) $plans['Business']->price,
            "feature add-on: {$adCode} annual ({$adRates['annual']}) is too close to the Business package ("
            . (int) $plans['Business']->price . ') — an add-on must stay a small fraction of its package');
    }
    $c = $mkCompany('FeatureAddon');
    $mkSub($c, $plans['Business']->id);
    \App\Services\PosAddonService::flushCache();
    PosFeatureService::flushGateCaches();
    check(PosFeatureService::planAllows($c, 'caller_id_enabled') === false,
        'feature add-on: Business alone must not grant Caller ID');
    DB::table('pos_addons')->insert([
        'company_id' => $c->id, 'addon_code' => 'caller_id', 'active' => 1,
        'billing_cycle' => 'annual', 'amount' => 12000,
        'starts_at' => now()->subDay()->toDateString(), 'ends_at' => now()->addYear()->toDateString(),
        'created_at' => now(), 'updated_at' => now(),
    ]);
    \App\Services\PosAddonService::flushCache();
    PosFeatureService::flushGateCaches();
    check(PosFeatureService::planAllows($c, 'caller_id_enabled') === true,
        'feature add-on: an active pos_addons row must open the gate');
    check(PosFeatureService::planAllows($c, 'rider_tracking_enabled') === false,
        'feature add-on: one add-on must not open a different gate');
    DB::table('pos_addons')->where('company_id', $c->id)->update(['ends_at' => now()->subDay()->toDateString()]);
    \App\Services\PosAddonService::flushCache();
    PosFeatureService::flushGateCaches();
    check(PosFeatureService::planAllows($c, 'caller_id_enabled') === false,
        'feature add-on: an expired pos_addons row must close the gate again');

    // ── 6b. Package comparison table (Task 1350) ───────────────────────
    // The landing + billing comparison table is GENERATED from the plan
    // columns below. These ladders are written here independently of the
    // service, so a hand-edited plan row, a renamed column or a new
    // unnamed gate blocks the deploy instead of quietly publishing a
    // promise the gates do not keep.
    // 23 Aug 2026 (owner): Business took Pro's capacity — unlimited bills and
    // counters, 7 team accounts; Unlimited 12.
    $BILL_LADDER    = ['Starter' => 2000, 'Business' => 'Unlimited', 'Unlimited' => 'Unlimited'];
    $TEAM_LADDER    = ['Starter' => 2, 'Business' => 7, 'Unlimited' => 12];
    $COUNTER_LADDER = ['Starter' => 1, 'Business' => 'Unlimited', 'Unlimited' => 'Unlimited'];

    $expectedLimits = [];
    $expectedFeatures = [];
    foreach (array_keys($MATRIX) as $name) {
        $expectedLimits[$name] = [
            'bills'    => $BILL_LADDER[$name],
            'team'     => $TEAM_LADDER[$name],
            'branches' => $BRANCH_LADDER[$name],
            'counters' => $COUNTER_LADDER[$name],
        ];
        $row = ['restaurant' => in_array($name, $RESTAURANT_PLANS, true)];
        foreach ($EXPECTED_GATE_ORDER as $i => $gateCol) {
            // gate column → comparison row key (kot/khata/loyalty have no
            // tick/cross row — they are "included in every package").
            $rowKey = array_search($gateCol, array_column(
                \App\Services\PosPlanComparisonService::FEATURE_ROWS, 'column'
            ), true);
            if ($rowKey === false) { continue; }
            $keys = array_keys(\App\Services\PosPlanComparisonService::FEATURE_ROWS);
            $row[$keys[$rowKey]] = $MATRIX[$name][$i];
        }
        $expectedFeatures[$name] = $row;
    }

    // Products are unlimited on EVERY paid POS package (owner, Aug 2026) —
    // the old silent Starter 300 / Business 1000 caps are gone. FBR POS keeps
    // its own max_products ladder (shared column, asserted separately).
    $comparisonPlans = \App\Services\PosPlanComparisonService::plans();
    check($comparisonPlans->count() === count($MATRIX),
        'comparison: expected ' . count($MATRIX) . ' paid POS packages, got ' . $comparisonPlans->count());

    // Price ladder + the three billing cycles (owner-set, Aug 2026). Written
    // here independently of the migration so a hand-edited plan row, a missed
    // migration or a half-applied reprice blocks the deploy.
    // Annual-only since 23 Aug 2026: price_quarterly / price_monthly still sit
    // in the table for legacy subscriptions but are NOT sellable, so only the
    // annual rate is asserted — and every requested cycle must charge it.
    $PRICE_LADDER = [
        'Starter'   => ['annual' => 17999],
        'Business'  => ['annual' => 27999],
        'Unlimited' => ['annual' => 34999],
    ];
    check(\App\Services\SubscriptionAssignmentService::SELLABLE_CYCLES === ['annual'],
        'price: annual must be the ONLY sellable cycle, got '
        . implode('/', \App\Services\SubscriptionAssignmentService::SELLABLE_CYCLES));
    $posSaleLive = \App\Models\SaleCampaign::activeFor('pos') !== null;
    foreach ($PRICE_LADDER as $planName => $wantPrices) {
        $planRow = $plans[$planName] ?? null;
        if (!$planRow) { bad("price: {$planName} row missing"); continue; }

        check((int) $planRow->price === $wantPrices['annual'],
            "price: {$planName} annual must be {$wantPrices['annual']}, got " . (int) $planRow->price);

        // A retired column must never MIRROR the annual rate — that is how a
        // year gets sold for a month's fee if a cycle is ever revived.
        check((int) $planRow->price_monthly !== (int) $planRow->price,
            "price: {$planName} price_monthly must not mirror the annual price");

        // ...and every requested cycle must check out as ANNUAL at that same
        // number. (Skipped while a sale campaign is discounting it.)
        $planModel = \App\Models\PricingPlan::find($planRow->id);
        if (!$posSaleLive && $planModel) {
            foreach (['annual', 'quarterly', 'monthly', 'semi_annual'] as $cycle) {
                $priced = \App\Services\SubscriptionAssignmentService::computePrice($planModel, $cycle);
                check($priced['cycle'] === 'annual' && (int) $priced['final_price'] === $wantPrices['annual'],
                    "price: computePrice({$planName}, {$cycle}) must charge annual/{$wantPrices['annual']}, got "
                    . $priced['cycle'] . '/' . (int) $priced['final_price']);
            }
        }
    }
    foreach ($comparisonPlans as $p) {
        check(((int) ($p->max_products ?? -1)) < 0,
            "comparison: {$p->name} must have unlimited products, got " . var_export($p->max_products, true));
    }

    foreach (\App\Services\PosPlanComparisonService::audit($comparisonPlans, $expectedLimits, $expectedFeatures) as $problem) {
        bad('comparison: ' . $problem);
    }
    if (!$fail) { ok('comparison table matches the plan gates'); }

    // The gate the panel enforces must return the number the table prints.
    foreach ($comparisonPlans as $p) {
        $planModel = \App\Models\PricingPlan::find($p->id);
        $want = $TEAM_LADDER[$p->name] ?? null;
        $got = PlanLimitService::teamAccountLimit($planModel);
        check(($want === 'Unlimited' ? $got === null : $got === $want),
            "comparison: PlanLimitService::teamAccountLimit({$p->name}) expected " . var_export($want, true)
            . ', got ' . var_export($got, true));
    }

    // ONE team-account number for POS: user_limit, read through the resolver
    // above. pricing_plans.max_users is a DIFFERENT thing — the DI panel's
    // plan.limit:users middleware counts every user (owner + inactive
    // included) and ignores the company override, so it may never be wired to
    // a POS surface or fed a POS seat count. Any POS/FBR POS route picking up
    // that middleware = a second, stricter team limit nobody advertised.
    $usersLimitRoutes = [];
    foreach (app('router')->getRoutes() as $route) {
        foreach ($route->gatherMiddleware() as $mw) {
            if (is_string($mw) && (str_contains($mw, 'plan.limit:users') || str_contains($mw, 'CheckPlanLimit:users'))) {
                $usersLimitRoutes[] = $route->uri();
            }
        }
    }
    foreach ($usersLimitRoutes as $uri) {
        check(!preg_match('#^(pos|fbr-pos)/#', $uri),
            "comparison: route '{$uri}' carries plan.limit:users — POS team accounts must go through "
            . 'PlanLimitService::canAddPosUser() (user_limit), never the DI max_users cap.');
    }
    check(count($usersLimitRoutes) <= 1,
        'comparison: plan.limit:users is expected on the single DI route only, found: ' . implode(', ', $usersLimitRoutes));

    // ...and the DI column must never be tightened to a POS seat count either:
    // on a POS row max_users stays unlimited or at least as generous as the
    // advertised team-account number, so nothing can refuse a seat the table
    // offers. (Pro / Unlimited are unlimited here by design.)
    foreach ($comparisonPlans as $p) {
        $seats = PlanLimitService::teamAccountLimit(\App\Models\PricingPlan::find($p->id));
        $diCap = ($p->max_users === null || (int) $p->max_users < 0) ? null : (int) $p->max_users;
        check($seats === null ? true : ($diCap === null || $diCap >= $seats),
            "comparison: {$p->name} max_users ({$p->max_users}) is stricter than the advertised "
            . "team-account number ({$seats}) — never mirror POS seats into the DI users cap.");
    }

    // ── 7. FBR POS ladder (owner-approved 23 Aug 2026 — Pro merged INTO
    //       Business, two sellable packages, and the PRA price convention:
    //       pricing_plans.price is the ANNUAL rate with hand-set quarterly /
    //       monthly columns, NOT a monthly rate charged ×12. Rows matched by
    //       product_type+name; a drift here means the two_package_ladder
    //       migration didn't run or someone hand-edited the rows. ────────
    $fbrGateCols = ['inventory_enabled', 'offline_enabled', 'excel_enabled', 'khata_enabled',
                    'reports_enabled', 'deals_enabled', 'loyalty_enabled', 'kot_enabled',
                    'analytics_enabled'];
    $FBR_MATRIX = [
        // name => [annual price, inventory, offline, excel, khata, reports, deals, loyalty, kot, analytics]
        'Starter'  => [17999, true,  false, false, false, false, false, false, false, false],
        'Business' => [27999, true,  true,  true,  true,  true,  true,  true,  true,  true],
        // Trial gate COLUMNS stay false (PRA convention): active trial unlocks
        // via isTrialActive; true columns would leak features to EXPIRED trials.
        'Trial'    => [0,     true,  false, false, false, false, false, false, false, false],
    ];
    // Annual-only since 23 Aug 2026: the hand-set shorter-cycle columns are
    // frozen legacy data, so they are no longer asserted — what matters is that
    // NO cycle can be charged as anything but the annual rate. Trial isn't sold.
    $FBR_SELLABLE = ['Starter', 'Business'];
    $fbrPlans = DB::table('pricing_plans')->where('product_type', 'fbrpos')->get()->keyBy('name');
    foreach ($FBR_MATRIX as $name => $row) {
        $p = $fbrPlans[$name] ?? null;
        if (!$p) { bad("fbrpos plan row missing: {$name}"); continue; }
        $wantPrice = array_shift($row);
        check((float) $p->price === (float) $wantPrice,
            "fbrpos {$name}: annual price must be {$wantPrice}, got {$p->price}");
        // price_monthly must NEVER mirror price — that was the old monthly
        // convention, and a mirror would sell a year for a month's fee.
        if (in_array($name, $FBR_SELLABLE, true)) {
            check((int) ($p->price_monthly ?? -1) !== (int) $p->price,
                "fbrpos {$name}: price_monthly must not mirror the annual price");
        }
        // ...and every requested cycle must check out as ANNUAL at that price.
        $fbrPlanModel = \App\Models\PricingPlan::find($p->id);
        if ($fbrPlanModel && in_array($name, $FBR_SELLABLE, true) && \App\Models\SaleCampaign::activeFor('fbrpos') === null) {
            foreach (['annual', 'quarterly', 'monthly', 'semi_annual'] as $cycle) {
                $priced = \App\Services\SubscriptionAssignmentService::computePrice($fbrPlanModel, $cycle);
                check($priced['cycle'] === 'annual' && (int) $priced['final_price'] === (int) $wantPrice,
                    "fbrpos {$name}: computePrice({$cycle}) must charge annual/{$wantPrice}, got "
                    . $priced['cycle'] . '/' . (int) $priced['final_price']);
            }
        }
        foreach ($fbrGateCols as $i => $col) {
            check((bool) ($p->{$col} ?? false) === $row[$i],
                "fbrpos {$name}: {$col} expected " . var_export($row[$i], true));
        }
    }

    // Functional spot-checks through planAllows (fbrpos companies): the
    // cross-cutting rules must behave exactly like PRA — Starter locked out
    // of premium gates, Business partial, active trial all-open.
    $mkFbrCompany = function (string $suffix) use ($mkCompany): Company {
        $c = $mkCompany('FBR ' . $suffix);
        $c->product_type = 'fbrpos';
        $c->save();
        return $c->fresh();
    };
    if (isset($fbrPlans['Starter'], $fbrPlans['Business'], $fbrPlans['Trial'])) {
        $c = $mkFbrCompany('Starter');
        $mkSub($c, $fbrPlans['Starter']->id);
        PosFeatureService::flushGateCaches();
        foreach (['offline_enabled', 'excel_enabled', 'khata_enabled', 'reports_enabled',
                  'deals_enabled', 'loyalty_enabled', 'kot_enabled', 'analytics_enabled'] as $g) {
            check(PosFeatureService::planAllows($c, $g) === false, "fbrpos Starter sub: {$g} must be locked");
        }
        check(PosFeatureService::planAllows($c, 'inventory_enabled') === true, 'fbrpos Starter sub: inventory_enabled must be open');

        $c = $mkFbrCompany('Business');
        $mkSub($c, $fbrPlans['Business']->id);
        PosFeatureService::flushGateCaches();
        // 23 Aug 2026: Business absorbed Pro, so every gate Pro held is open here.
        foreach (['offline_enabled', 'excel_enabled', 'khata_enabled', 'reports_enabled',
                  'deals_enabled', 'loyalty_enabled', 'kot_enabled', 'analytics_enabled'] as $g) {
            check(PosFeatureService::planAllows($c, $g) === true, "fbrpos Business sub: {$g} must be open");
        }

        $c = $mkFbrCompany('TrialActive');
        $mkSub($c, $fbrPlans['Trial']->id, ['trial_ends_at' => now()->addDays(3)]);
        PosFeatureService::flushGateCaches();
        foreach (['offline_enabled', 'deals_enabled', 'analytics_enabled', 'khata_enabled'] as $g) {
            check(PosFeatureService::planAllows($c, $g) === true, "fbrpos active trial: {$g} must be open");
        }

        $c = $mkFbrCompany('TrialExpired');
        $mkSub($c, $fbrPlans['Trial']->id, ['trial_ends_at' => now()->subDay()]);
        PosFeatureService::flushGateCaches();
        foreach (['offline_enabled', 'deals_enabled', 'analytics_enabled', 'khata_enabled'] as $g) {
            check(PosFeatureService::planAllows($c, $g) === false, "fbrpos expired trial: {$g} must be locked");
        }
    } else {
        bad('fbrpos Starter/Business/Trial rows missing — cannot run functional fbrpos checks');
    }

    // ── 8. FBR POS comparison table (Task 1383) ─────────────────────────
    //       Same contract as the PRA table in section 6: the ladders below are
    //       written here independently of FbrPosPlanComparisonService, so a
    //       hand-edited plan row, a renamed column or a new unnamed FBR gate
    //       blocks the deploy instead of quietly publishing a promise the FBR
    //       gates do not keep.
    // 23 Aug 2026 (owner): Starter 2,000 bills; Business takes Pro's uncapped
    // capacity EXCEPT branches, which stop at 2 (extra branches are the paid
    // Rs 10,000/year add-on, same as PRA POS). Products are now unlimited on
    // both packages — the old 100 / 500 caps are gone.
    $FBR_BILL_LADDER    = ['Starter' => 2000, 'Business' => 'Unlimited'];
    $FBR_TEAM_LADDER    = ['Starter' => 1,    'Business' => 'Unlimited'];
    $FBR_BRANCH_LADDER  = ['Starter' => 1,    'Business' => 2];
    $FBR_COUNTER_LADDER = ['Starter' => 1,    'Business' => 'Unlimited'];
    $FBR_PRODUCT_LADDER = ['Starter' => 'Unlimited', 'Business' => 'Unlimited'];

    $fbrFeatureColumns = array_column(\App\Services\FbrPosPlanComparisonService::FEATURE_ROWS, 'column');
    $fbrFeatureKeys    = array_keys(\App\Services\FbrPosPlanComparisonService::FEATURE_ROWS);

    // The strict-gating matrix above must stay inside the table's own gate
    // list, or the table would quietly stop covering a column this file pins.
    foreach ($fbrGateCols as $col) {
        check(in_array($col, \App\Services\FbrPosPlanComparisonService::GATE_COLUMNS, true),
            "fbr comparison: gate column '{$col}' is asserted above but missing from "
            . 'FbrPosPlanComparisonService::GATE_COLUMNS.');
    }

    $fbrExpectedLimits = [];
    $fbrExpectedFeatures = [];
    foreach ($FBR_MATRIX as $name => $row) {
        if ($name === 'Trial') {
            continue; // the table shows PAID packages only
        }
        $fbrExpectedLimits[$name] = [
            'bills'    => $FBR_BILL_LADDER[$name],
            'team'     => $FBR_TEAM_LADDER[$name],
            'branches' => $FBR_BRANCH_LADDER[$name],
            'counters' => $FBR_COUNTER_LADDER[$name],
            'products' => $FBR_PRODUCT_LADDER[$name],
        ];
        $expected = [];
        foreach ($fbrGateCols as $i => $gateCol) {
            // gate column → comparison row key (inventory has no tick/cross
            // row — it is "included in every package").
            $rowIndex = array_search($gateCol, $fbrFeatureColumns, true);
            if ($rowIndex === false) { continue; }
            $expected[$fbrFeatureKeys[$rowIndex]] = $row[$i + 1]; // $row[0] is the price
        }
        $fbrExpectedFeatures[$name] = $expected;
    }

    $fbrComparisonPlans = \App\Services\FbrPosPlanComparisonService::plans();
    check($fbrComparisonPlans->count() === count($fbrExpectedLimits),
        'fbr comparison: expected ' . count($fbrExpectedLimits) . ' paid FBR POS packages, got '
        . $fbrComparisonPlans->count());

    // The product cap is a SELLING POINT of the upgrade, so it has to stay a
    // real cap — an accidental -1 on Starter/Business would make the table
    // print "Unlimited" and quietly hand away the ladder.
    foreach ($fbrComparisonPlans as $p) {
        if (($FBR_PRODUCT_LADDER[$p->name] ?? null) === 'Unlimited') { continue; }
        check(!\App\Services\FbrPosPlanComparisonService::isUnlimited($p->max_products),
            "fbr comparison: {$p->name} must keep a product cap, got " . var_export($p->max_products, true));
    }

    foreach (\App\Services\FbrPosPlanComparisonService::audit($fbrComparisonPlans, $fbrExpectedLimits, $fbrExpectedFeatures) as $problem) {
        bad('fbr comparison: ' . $problem);
    }

    // The gate the FBR panel enforces must return the number the table prints.
    foreach ($fbrComparisonPlans as $p) {
        $want = $FBR_TEAM_LADDER[$p->name] ?? null;
        $got = PlanLimitService::teamAccountLimit(\App\Models\PricingPlan::find($p->id));
        check(($want === 'Unlimited' ? $got === null : $got === $want),
            "fbr comparison: PlanLimitService::teamAccountLimit({$p->name}) expected " . var_export($want, true)
            . ', got ' . var_export($got, true));
    }

    // ── 9. Digital Invoice comparison table (Task 1383) ─────────────────
    //       DI has no boolean gate COLUMNS: its premium tier is the name-keyed
    //       DiFeatureService::PLAN_FEATURES matrix, so the ticks are pinned by
    //       gate key here and the limits by column, both written independently
    //       of DiPlanComparisonService.
    //       Sep 2026 restructure: only three packages are sold. The retired
    //       rows still exist for the companies sitting on them, so they are
    //       pinned separately below instead of being deleted.
    $DI_INVOICE_LADDER = ['Asaan' => 700, 'Kaarobar' => 2500, 'Unlimited' => 'Unlimited'];
    $DI_AI_PAGE_LADDER = ['Asaan' => 200, 'Kaarobar' => 400, 'Unlimited' => 700];
    // Seats: the TIGHTER of user_limit (canAddUser) and max_users
    // (plan.limit:users) — both guard POST /company/users.
    $DI_USER_LADDER    = ['Asaan' => 2, 'Kaarobar' => 4, 'Unlimited' => 'Unlimited'];
    $DI_BRANCH_LADDER  = ['Asaan' => 1, 'Kaarobar' => 3, 'Unlimited' => 'Unlimited'];
    $DI_PRODUCT_LADDER = ['Asaan' => 'Unlimited', 'Kaarobar' => 'Unlimited', 'Unlimited' => 'Unlimited'];
    $DI_GATE_MATRIX = [
        'Asaan'     => ['ai_reader'],
        'Kaarobar'  => ['ai_reader', 'white_label', 'public_api'],
        'Unlimited' => ['ai_reader', 'white_label', 'public_api'],
    ];
    // Retired-from-sale packages. They no longer appear on any buying surface,
    // so they are NOT audited against the comparison table — they are only here
    // so a gate that survives on a legacy row still counts as pinned.
    $DI_RETIRED_GATE_MATRIX = [
        'Retail'     => [],
        'Business'   => ['recurring_invoices'],
        'Industrial' => ['recurring_invoices'],
        'Enterprise' => ['recurring_invoices'],
        'Premium'    => ['recurring_invoices', 'white_label', 'ai_reader', 'public_api'],
    ];

    // A brand-new DI gate must be pinned here too, not just named in the table.
    foreach (\App\Services\DiFeatureService::GATES as $gate) {
        $pinned = false;
        foreach (array_merge($DI_GATE_MATRIX, $DI_RETIRED_GATE_MATRIX) as $granted) {
            if (in_array($gate, $granted, true)) { $pinned = true; break; }
        }
        check($pinned, "di comparison: gate '{$gate}' is not pinned by any package in this file's matrix.");
    }

    // Every pin above must still match DiFeatureService, retired rows included —
    // a legacy company's feature must not change silently.
    foreach (array_merge($DI_GATE_MATRIX, $DI_RETIRED_GATE_MATRIX) as $name => $granted) {
        $matrixRow = \App\Services\DiFeatureService::PLAN_FEATURES[$name] ?? null;
        check($matrixRow !== null, "di comparison: plan '{$name}' is pinned here but missing from DiFeatureService::PLAN_FEATURES.");
        if ($matrixRow !== null) {
            sort($granted);
            sort($matrixRow);
            check($granted === $matrixRow,
                "di comparison: '{$name}' gates drifted — this file pins [" . implode(', ', $granted)
                . '] but DiFeatureService grants [' . implode(', ', $matrixRow) . '].');
        }
    }

    $diExpectedLimits = [];
    $diExpectedFeatures = [];
    foreach ($DI_GATE_MATRIX as $name => $granted) {
        $diExpectedLimits[$name] = [
            'invoices' => $DI_INVOICE_LADDER[$name],
            'ai_pages' => $DI_AI_PAGE_LADDER[$name],
            'users'    => $DI_USER_LADDER[$name],
            'branches' => $DI_BRANCH_LADDER[$name],
            'products' => $DI_PRODUCT_LADDER[$name],
        ];
        $expected = [];
        foreach (\App\Services\DiPlanComparisonService::FEATURE_ROWS as $rowKey => $spec) {
            $expected[$rowKey] = in_array($spec['gate'], $granted, true);
        }
        $diExpectedFeatures[$name] = $expected;
    }

    $diComparisonPlans = \App\Services\DiPlanComparisonService::plans();
    check($diComparisonPlans->count() === count($diExpectedLimits),
        'di comparison: expected ' . count($diExpectedLimits) . ' paid DI packages, got '
        . $diComparisonPlans->count());

    foreach (\App\Services\DiPlanComparisonService::audit($diComparisonPlans, $diExpectedLimits, $diExpectedFeatures) as $problem) {
        bad('di comparison: ' . $problem);
    }
} catch (\Throwable $e) {
    DB::rollBack();
    fwrite(STDERR, 'ERROR: check crashed: ' . $e->getMessage() . "\n" . $e->getTraceAsString() . "\n");
    exit(2);
} finally {
    if (DB::transactionLevel() > 0) {
        DB::rollBack();
    }
}

echo "plan-gate-check: {$pass} assertions passed" . ($fail ? ", {$fail} FAILED" : '') . "\n";
if ($fail) {
    fwrite(STDERR, "PLAN GATE MATRIX REGRESSION — fix before deploying.\n");
    exit(1);
}
echo "Package gate matrix intact (Starter/Business/Pro/Unlimited + trial/override/internal rules).\n";
exit(0);
