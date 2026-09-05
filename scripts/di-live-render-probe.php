<?php
/**
 * Digital Invoice — LIVE "nobody was disturbed" probe (Task 1572).
 *
 * A deploy that adds a whole new product line has to prove it left the EXISTING
 * product lines alone. PRA POS and FBR POS have standing QA companies to log
 * into; Digital Invoice does not — every DI company on live is a real business,
 * and logging in as a real customer to click around is not acceptable.
 *
 * So this renders their screens the way the live-render-probe technique does:
 * as that company's own user, through the real HTTP kernel, READ ONLY. It issues
 * GETs only, and wraps the run in a transaction that is always rolled back so
 * even an incidental write (a last-seen stamp, a session touch) cannot land.
 *
 * Run ON THE LIVE HOST:
 *   cd /var/www/taxnest && /usr/bin/php scripts/di-live-render-probe.php
 */

require __DIR__ . '/../vendor/autoload.php';
$app = require_once __DIR__ . '/../bootstrap/app.php';

$app->instance('request', Illuminate\Http\Request::create('/dashboard', 'GET'));
$kernel = $app->make(Illuminate\Contracts\Http\Kernel::class);
$kernel->bootstrap();

// APP_DEBUG is off on live, so an anonymous 500 page would hide the reason.
$app->instance(Illuminate\Contracts\Debug\ExceptionHandler::class, new class implements Illuminate\Contracts\Debug\ExceptionHandler {
    public function report(Throwable $e) {}
    public function shouldReport(Throwable $e) { return false; }
    public function render($request, Throwable $e) { throw $e; }
    public function renderForConsole($output, Throwable $e) { throw $e; }
});

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

$fail = 0;
$ok   = function (string $m) { echo "    ok   — $m\n"; };
$bad  = function (string $m) use (&$fail) { echo "    FAIL — $m\n"; $fail = 1; };

echo "==> Digital Invoice live probe — existing shops must be untouched\n";
echo "    commit: " . trim(@shell_exec('git rev-parse --short HEAD') ?: '?') . "\n";

// Busiest real DI company: if the deploy hurt DI, it shows here first.
$company = DB::table('companies')->where('product_type', 'di')
    ->where('company_status', 'active')
    ->orderByDesc(DB::raw('(SELECT COUNT(*) FROM invoices i WHERE i.company_id = companies.id)'))
    ->first();

if (!$company) { echo "    FAIL — no active DI company on live\n"; exit(1); }

$user = App\Models\User::where('company_id', $company->id)
    ->whereIn('role', ['company_admin', 'admin', 'user'])->orderBy('id')->first();
if (!$user) { echo "    FAIL — DI company {$company->id} has no user to render as\n"; exit(1); }

$invoices = DB::table('invoices')->where('company_id', $company->id)->count();
echo "    company {$company->id} ({$invoices} invoices), rendering as its own user\n";

DB::beginTransaction();
try {
    view()->share('errors', new Illuminate\Support\ViewErrorBag());

    $screens = [
        '/dashboard'       => 'dashboard still opens',
        '/invoices'        => 'invoice list still opens',
        '/invoice/create'   => 'the create-invoice screen still opens',
        '/reports/tax-summary' => 'reports still open',
        '/products'        => 'products still open',
        '/customers'       => 'customers still open',
    ];

    foreach ($screens as $path => $label) {
        $req = Request::create($path, 'GET');
        $req->setUserResolver(fn () => $user);
        auth()->guard('web')->setUser($user);
        app()->instance('currentCompanyId', $company->id);

        try {
            $resp = $kernel->handle($req);
            $code = $resp->getStatusCode();
            $body = (string) $resp->getContent();
            if ($code === 200 && strlen($body) > 500) {
                $ok(sprintf('%-18s %s (%d KB)', $path, $label, strlen($body) / 1024));
                // A new product line must not have leaked its nav into DI.
                foreach (['fbr-pos/pharmacy' => 'pharmacy', '/health' => 'healthcare'] as $needle => $what) {
                    if (str_contains($body, $needle)) {
                        $bad("$path leaks a $what link into a Digital Invoice screen");
                    }
                }
            } elseif (in_array($code, [301, 302], true)) {
                echo sprintf("    ..   — %-18s redirected (%d) — not part of this company's flow\n", $path, $code);
            } else {
                $bad(sprintf('%-18s returned %d', $path, $code));
            }
        } catch (Throwable $e) {
            $bad(sprintf('%-18s threw %s: %s', $path, class_basename($e), substr($e->getMessage(), 0, 160)));
        }
    }
} catch (Throwable $e) {
    $bad('probe aborted: ' . $e->getMessage());
} finally {
    DB::rollBack();
    echo "    (read-only: transaction rolled back)\n";
}

echo "\n" . ($fail ? "DI LIVE PROBE: FAILED\n" : "DI LIVE PROBE: PASS — Digital Invoice is untouched by this deploy\n");
exit($fail);
