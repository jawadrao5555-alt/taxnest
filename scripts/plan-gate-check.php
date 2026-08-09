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
use App\Models\Company;
use App\Models\Subscription;
use App\Models\User;
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
    'qr_menu_enabled', 'offline_enabled',
];
$MATRIX = [
    // plan name => [deals, riders, hazri, analytics, reports, rider_tracking, custom_access, qr_menu, offline]
    // 9 Aug 2026 (owner): rider LIVE tracking moved back UP — Unlimited ONLY.
    'Starter'   => [false, false, false, false, true,  false, false, false, false],
    'Business'  => [true,  false, false, false, true,  false, false, false, true ],
    'Pro'       => [true,  true,  false, true,  true,  false, false, true,  true ],
    'Pro Max'   => [true,  true,  true,  true,  true,  false, false, true,  true ],
    'Unlimited' => [true,  true,  true,  true,  true,  true,  true,  true,  true ],
];
// Derived-surface expectations per plan:
$CUSTOM_SET_PLANS = ['Unlimited'];                   // customSet() honored only here
$QR_URL_PLANS     = ['Pro', 'Pro Max', 'Unlimited']; // publicUrlFor() non-null only here

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

        // Team Custom Access: stored set goes inert unless plan allows it.
        $set = PosAccessService::customSet($mkUser($c));
        $wantSet = in_array($planName, $CUSTOM_SET_PLANS, true);
        check(($set !== null) === $wantSet,
            "plan {$planName}: customSet() should be " . ($wantSet ? 'HONORED' : 'inert (null)') . ', got ' . ($set === null ? 'null' : 'set'));

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
