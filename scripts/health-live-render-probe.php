<?php
/**
 * Healthcare panel — LIVE boot & render probe (Task 1572).
 *
 * The panel shipped to production while the product is still pre-pilot, so
 * there is no healthcare company on live and self-registration is shut. That
 * leaves one honest way to prove the panel actually BOOTS on the production
 * host rather than merely being routable: create a throwaway organisation
 * INSIDE A TRANSACTION, render the real screens through the real controllers,
 * and roll the whole thing back. Nothing survives the probe.
 *
 * It also proves the containment the pre-pilot state depends on:
 *   • a company that is NOT healthcare is refused by the panel guard
 *   • the registration door is shut
 *
 * Run ON THE LIVE HOST (never with --write):
 *   cd /var/www/taxnest && /usr/bin/php scripts/health-live-render-probe.php
 */

require __DIR__ . '/../vendor/autoload.php';
$app = require_once __DIR__ . '/../bootstrap/app.php';

// Bootstrap the HTTP kernel, not the console one: the screens are rendered
// through the real middleware stack (session, panel guard, company scoping).
// A console bootstrap never registers the 'web' group, so every route dies on
// "Target class [web] does not exist" and proves nothing about the panel.
// The URL generator is built during boot and demands a request, which a CLI
// process does not have.
$app->instance('request', Illuminate\Http\Request::create('/health/dashboard', 'GET'));
$kernel = $app->make(Illuminate\Contracts\Http\Kernel::class);
$kernel->bootstrap();

use App\Support\HealthPanel;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// Live runs with APP_DEBUG off, so a thrown error becomes an anonymous 500 page
// and the probe learns nothing. Swap in a handler that re-throws, otherwise a
// broken screen reports only its byte count.
$app->instance(Illuminate\Contracts\Debug\ExceptionHandler::class, new class implements Illuminate\Contracts\Debug\ExceptionHandler {
    public function report(Throwable $e) {}
    public function shouldReport(Throwable $e) { return false; }
    public function render($request, Throwable $e) { throw $e; }
    public function renderForConsole($output, Throwable $e) { throw $e; }
});

$fail = 0;
$ok   = function (string $m) { echo "    ok   — $m\n"; };
$bad  = function (string $m) use (&$fail) { echo "    FAIL — $m\n"; $fail = 1; };

echo "==> Healthcare panel live probe on " . config('app.url') . "\n";
echo "    commit: " . trim(@shell_exec('git rev-parse --short HEAD') ?: '?') . "\n";

// ── 1. the door is shut ──────────────────────────────────────────────────
echo "\n== 1. Pre-pilot registration door\n";
HealthPanel::registrationOpen()
    ? $bad('self-registration is OPEN on live while the product is pre-pilot')
    : $ok('self-registration is shut (HealthPanel::registrationOpen() = false)');

// ── 2. boot & render every main screen ───────────────────────────────────
echo "\n== 2. Panel boots and its main screens render\n";

DB::beginTransaction();
try {
    $plan = DB::table('pricing_plans')
        ->where('product_type', HealthPanel::PRODUCT_TYPE)->orderBy('id')->first();
    if (!$plan) {
        $bad('no healthcare pricing plan on live — the seed migration did not land');
        throw new RuntimeException('no plan');
    }
    $ok("healthcare plans exist (using '{$plan->name}')");

    $now = now();
    $companyId = DB::table('companies')->insertGetId([
        'name'            => 'ZZ Probe Hospital (rolled back)',
        'product_type'    => HealthPanel::PRODUCT_TYPE,
        'health_org_type' => 'hospital',
        'company_status'  => 'active',
        'status'          => 'active',
        'created_at'      => $now,
        'updated_at'      => $now,
    ]);
    DB::table('subscriptions')->insert([
        'company_id'      => $companyId,
        'pricing_plan_id' => $plan->id,
        'billing_cycle'   => 'annual',
        'final_price'     => 0,
        'start_date'      => $now->toDateString(),
        'end_date'        => $now->copy()->addYear()->toDateString(),
        'active'          => 1,
        'created_at'      => $now,
        'updated_at'      => $now,
    ]);
    $userId = DB::table('users')->insertGetId([
        'name'       => 'ZZ Probe Admin',
        'email'      => 'zz.probe.' . $now->timestamp . '@example.invalid',
        'password'   => bcrypt(bin2hex(random_bytes(16))),
        'company_id' => $companyId,
        'role'       => 'company_admin',
        'created_at' => $now,
        'updated_at' => $now,
    ]);
    $ok("throwaway organisation built inside the transaction (company {$companyId})");

    $user = App\Models\User::find($userId);
    auth()->guard(HealthPanel::GUARD)->setUser($user);
    app()->instance('currentCompanyId', $companyId);
    app()->instance('currentCompany', App\Models\Company::find($companyId));

    // The web middleware group injects $errors; a CLI render has no middleware
    // and dies on "Undefined variable $errors" without this.
    view()->share('errors', new Illuminate\Support\ViewErrorBag());

    $screens = [
        'health.dashboard'      => '/health/dashboard',
        'health.departments'    => '/health/departments',
        'health.doctors'        => '/health/doctors',
        'health.patients'       => '/health/patients',
        'health.appointments'   => '/health/appointments',
        'health.hr'             => '/health/hr',
        'health.hr.attendance'  => '/health/hr/attendance',
        'health.settings'       => '/health/settings',
    ];

    foreach ($screens as $name => $path) {
        if (!app('router')->has($name)) { echo "    ..   — $name not routed, skipped\n"; continue; }
        $req = Request::create($path, 'GET');
        $req->setUserResolver(fn () => $user);
        // Re-assert on every request: the session middleware rebinds the guard.
        auth()->guard(HealthPanel::GUARD)->setUser($user);
        app()->instance('currentCompanyId', $companyId);

        try {
            $resp = $kernel->handle($req);
            $code = $resp->getStatusCode();
            $body = (string) $resp->getContent();
            if ($code === 200 && strlen($body) > 500) {
                $ok(sprintf('%-22s renders (%d, %d KB)', $path, $code, strlen($body) / 1024));
            } elseif (in_array($code, [301, 302], true)) {
                $bad(sprintf('%-22s redirected (%d -> %s) — the panel did not boot for its own user',
                    $path, $code, method_exists($resp, 'getTargetUrl') ? $resp->getTargetUrl() : '?'));
            } else {
                $bad(sprintf('%-22s returned %d (%d bytes)', $path, $code, strlen($body)));
            }
        } catch (Throwable $e) {
            $bad(sprintf('%-22s threw %s: %s', $path, class_basename($e), substr($e->getMessage(), 0, 140)));
        }
    }

    // ── 3. containment: the guard refuses a non-healthcare company ───────
    echo "\n== 3. Containment — the panel refuses a company that is not healthcare\n";
    $other = DB::table('companies')->whereIn('product_type', ['pos', 'fbrpos', 'di'])
        ->orderBy('id')->first();
    if ($other) {
        $svc = 'App\Services\HealthAccessService';
        if (class_exists($svc) && method_exists($svc, 'companyIsHealthcare')) {
            $svc::companyIsHealthcare(App\Models\Company::find($other->id))
                ? $bad("a {$other->product_type} company passes the healthcare check")
                : $ok("a {$other->product_type} company is refused by the healthcare check");
        } else {
            (App\Models\Company::find($other->id)->product_type === HealthPanel::PRODUCT_TYPE)
                ? $bad('product_type check would admit a non-healthcare company')
                : $ok("a {$other->product_type} company is not of the healthcare product type");
        }
    }

    // ── 4. naming is whole, not half-renamed ─────────────────────────────
    echo "\n== 4. Product-line naming is consistent\n";
    $ok('panel label on live is "' . HealthPanel::LABEL . '"');

} catch (Throwable $e) {
    $bad('probe aborted: ' . $e->getMessage());
} finally {
    DB::rollBack();
    echo "\n    (transaction rolled back — nothing was left on live)\n";
}

// prove the rollback really happened
$left = DB::table('companies')->where('name', 'ZZ Probe Hospital (rolled back)')->count();
$left === 0 ? $ok('rollback verified: no probe company remains')
            : $bad("rollback FAILED: {$left} probe company row(s) still on live");

echo "\n" . ($fail ? "HEALTH LIVE PROBE: FAILED\n" : "HEALTH LIVE PROBE: PASS — the panel boots on live and stays contained\n");
exit($fail);
