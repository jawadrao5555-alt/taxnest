<?php
/**
 * POS package gate-matrix check — run BEFORE every live deploy.
 *
 * Asserts the Starter/Business/Pro/Pro Max/Unlimited plan-gate matrix
 * (PosFeatureService::PLAN_GATES) against live code paths, plus the two
 * derived gates that ride on it:
 *   - PosAccessService::customSet()      (Team Custom Access — Unlimited only)
 *   - PublicProfileController::publicUrlFor() (QR Menu — Pro and above)
 *
 * Ladder restructure (owner-approved Aug 2, 2026, see the
 * pro_gains_riders_qr_ladder_restructure migration):
 *   Pro = everything except Hazri + Rider Live Tracking; Pro Max = + Hazri;
 *   Unlimited = everything + no limits.
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
    // 17 Aug 2026 (owner): Caller ID (Android app + sale-screen popup) = Unlimited ONLY.
    // 17 Aug 2026 (owner): WhatsApp Bill (receipt share + auto-open) = Pro and above.
    // 9 Aug 2026 (owner): rider LIVE tracking moved back UP — Unlimited ONLY.
    // 9 Aug 2026 strict binding: reports_enabled gates CSV/PDF exports only —
    // Starter card promises basic report PAGES (ungated) but NOT exports, so
    // Starter reports=false; excel_enabled (product import/export) = Business+.
    // 10 Aug 2026 FBR infrastructure pass: khata/loyalty/kot columns added
    // TRUE for every plan of every product (behaviour-preserving — nothing
    // was gated on them before). The FBR ladder flip comes later.
    // 13 Aug 2026 (owner, market-capture move): Business gains Kitchen mode
    // (restaurant_enabled — asserted separately below) + Analytics.
    'Starter'   => [false, false, false, false, false, false, false, false, false, false, true, true, true, false, false],
    'Business'  => [true,  false, false, true,  true,  false, false, false, true,  true,  true, true, true, false, false],
    'Pro'       => [true,  true,  false, true,  true,  false, false, true,  true,  true,  true, true, true, false, true],
    'Pro Max'   => [true,  true,  true,  true,  true,  false, false, true,  true,  true,  true, true, true, false, true],
    'Unlimited' => [true,  true,  true,  true,  true,  true,  true,  true,  true,  true,  true, true, true, true,  true],
];
// Branch ladder (owner-approved 21 Aug 2026): har package apne card wali
// branches MUFT deta hai; us se ooper har branch Rs 10,000 SAALANA paid add-on
// (BranchAddonService + companies.extra_branch_slots). Unlimited ki hadd -1 se
// 5 hui — warna branch feature live hote hi wo shop bila hisaab branches bana
// leti. Ye qatar aur pricing formula lockstep mein rehni chahiye.
$BRANCH_LADDER = ['Starter' => 1, 'Business' => 1, 'Pro' => 2, 'Pro Max' => 3, 'Unlimited' => 5];

// Derived-surface expectations per plan:
$CUSTOM_SET_PLANS = ['Unlimited'];                   // customSet() honored only here
$QR_URL_PLANS     = ['Pro', 'Pro Max', 'Unlimited']; // publicUrlFor() non-null only here
// Restaurant module (pricing_plans.restaurant_enabled → restaurantAllowed()):
// Business+ since 13 Aug 2026 (Kitchen mode opened up for Business).
$RESTAURANT_PLANS = ['Business', 'Pro', 'Pro Max', 'Unlimited'];

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

    // ── 1. The five paid plans ─────────────────────────────────────────
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

    // ── 4. Admin override unlocks everything (rides on Starter) ────────
    $c = $mkCompany('Override');
    $mkSub($c, $plans['Starter']->id, ['override_type' => 'temporary', 'override_until' => now()->addDays(10)]);
    $assertGates($c, $allTrue, 'override on Starter');

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
    $ebPlan = $plans['Pro'];                    // 2 included branches
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

    // ── 7. FBR POS ladder (owner-approved 9 Aug 2026 — strict binding +
    //       reprice 999/1999/2999). Rows matched by product_type+name; a
    //       drift here means the fbrpos_plan_reprice_and_strict_gating
    //       migration didn't run or someone hand-edited the rows. ────────
    $fbrGateCols = ['inventory_enabled', 'offline_enabled', 'excel_enabled', 'khata_enabled',
                    'reports_enabled', 'deals_enabled', 'loyalty_enabled', 'kot_enabled',
                    'analytics_enabled'];
    $FBR_MATRIX = [
        // name => [price, inventory, offline, excel, khata, reports, deals, loyalty, kot, analytics]
        'Starter'  => [999,  true,  false, false, false, false, false, false, false, false],
        'Business' => [1999, true,  true,  true,  true,  true,  false, false, false, false],
        'Pro'      => [2999, true,  true,  true,  true,  true,  true,  true,  true,  true],
        // Trial gate COLUMNS stay false (PRA convention): active trial unlocks
        // via isTrialActive; true columns would leak features to EXPIRED trials.
        'Trial'    => [0,    true,  false, false, false, false, false, false, false, false],
    ];
    $fbrPlans = DB::table('pricing_plans')->where('product_type', 'fbrpos')->get()->keyBy('name');
    foreach ($FBR_MATRIX as $name => $row) {
        $p = $fbrPlans[$name] ?? null;
        if (!$p) { bad("fbrpos plan row missing: {$name}"); continue; }
        $wantPrice = array_shift($row);
        check((float) $p->price === (float) $wantPrice,
            "fbrpos {$name}: price must be {$wantPrice}, got {$p->price}");
        check((float) ($p->price_monthly ?? -1) === (float) $wantPrice,
            "fbrpos {$name}: price_monthly must be {$wantPrice}, got " . ($p->price_monthly ?? 'NULL'));
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
        foreach (['offline_enabled', 'excel_enabled', 'khata_enabled', 'reports_enabled'] as $g) {
            check(PosFeatureService::planAllows($c, $g) === true, "fbrpos Business sub: {$g} must be open");
        }
        foreach (['deals_enabled', 'loyalty_enabled', 'kot_enabled', 'analytics_enabled'] as $g) {
            check(PosFeatureService::planAllows($c, $g) === false, "fbrpos Business sub: {$g} must be locked");
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
echo "Package gate matrix intact (Starter/Business/Pro/Pro Max/Unlimited + trial/override/internal rules).\n";
exit(0);
