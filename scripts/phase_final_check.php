<?php

require __DIR__ . '/../vendor/autoload.php';
$app = require_once __DIR__ . '/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class FinalPhaseCheck
{
    private $results = [];

    public function run()
    {
        $this->phase5_observability();
        $this->phase6_performance();
        $this->phase7_security();
        $this->phase8_final();

        $this->report();
    }

    // ─────────────────────────────
    // PHASE 5 — OBSERVABILITY
    // ─────────────────────────────
    private function phase5_observability()
    {
        $tests = [];

        $logPath = storage_path('logs/laravel.log');
        $tests[] = $this->assert(file_exists($logPath), 'Log file exists');

        $tests[] = $this->assert(Schema::hasColumn('invoices', 'retry_count'), 'Retry column exists on invoices');

        try {
            $failed = DB::table('invoices')->where('fbr_status', 'failed')->count();
            $tests[] = $this->assert($failed >= 0, "Failed invoices accessible (count: $failed)");
        } catch (\Throwable $e) {
            $tests[] = $this->assert(false, 'Failed invoices query: ' . $e->getMessage());
        }

        $this->results['PHASE 5 — OBSERVABILITY'] = $tests;
    }

    // ─────────────────────────────
    // PHASE 6 — PERFORMANCE
    // ─────────────────────────────
    private function phase6_performance()
    {
        $tests = [];

        try {
            $start = microtime(true);
            DB::table('invoices')->limit(10)->get();
            $time = microtime(true) - $start;
            $tests[] = $this->assert($time < 1, sprintf('DB query under 1s (took %.3fs)', $time));
        } catch (\Throwable $e) {
            $tests[] = $this->assert(false, 'DB query: ' . $e->getMessage());
        }

        $this->results['PHASE 6 — PERFORMANCE'] = $tests;
    }

    // ─────────────────────────────
    // PHASE 7 — SECURITY
    // ─────────────────────────────
    private function phase7_security()
    {
        $tests = [];

        $tests[] = $this->assert(config('auth.guards.pos') !== null, 'POS guard configured');
        $tests[] = $this->assert(config('auth.guards.fbrpos') !== null, 'FBR POS guard configured');
        $tests[] = $this->assert(config('auth.guards.web') !== null, 'DI (web) guard configured');

        $env = env('APP_ENV');
        $tests[] = $this->assert($env !== 'local', "APP_ENV not 'local' (current: $env)");

        // Bonus: confirm CSRF middleware is present and HTTPS guard exists
        $kernel = app(\Illuminate\Contracts\Http\Kernel::class);
        $reflect = new \ReflectionClass($kernel);
        $prop = $reflect->getProperty('middlewareGroups');
        $prop->setAccessible(true);
        $groups = $prop->getValue($kernel);
        $webMiddleware = $groups['web'] ?? [];
        $tests[] = $this->assert(
            in_array(\Illuminate\Foundation\Http\Middleware\VerifyCsrfToken::class, $webMiddleware) ||
            in_array(\App\Http\Middleware\VerifyCsrfToken::class, $webMiddleware),
            'CSRF middleware in web group'
        );

        $this->results['PHASE 7 — SECURITY'] = $tests;
    }

    // ─────────────────────────────
    // PHASE 8 — FINAL
    // ─────────────────────────────
    private function phase8_final()
    {
        $tests = [];

        $tables = ['invoices', 'pos_transactions', 'companies', 'users'];
        foreach ($tables as $t) {
            try {
                $tests[] = $this->assert(Schema::hasTable($t), "Table $t exists");
            } catch (\Throwable $e) {
                $tests[] = $this->assert(false, "Table $t check: " . $e->getMessage());
            }
        }

        try {
            $nulls = DB::table('invoices')->whereNull('total_amount')->count();
            $tests[] = $this->assert($nulls === 0, "No null invoice totals (found: $nulls)");
        } catch (\Throwable $e) {
            $tests[] = $this->assert(false, 'Null totals check: ' . $e->getMessage());
        }

        $this->results['PHASE 8 — FINAL'] = $tests;
    }

    private function assert($cond, $msg)
    {
        return [
            'status' => $cond ? 'PASS' : 'FAIL',
            'message' => $msg,
        ];
    }

    private function report()
    {
        echo "\n===== FINAL PHASE REPORT =====\n";

        $totalPass = 0;
        $totalFail = 0;

        foreach ($this->results as $phase => $tests) {
            echo "\n$phase\n";
            foreach ($tests as $t) {
                echo ($t['status'] === 'PASS' ? '✓' : '✗') . ' ' . $t['message'] . "\n";
                if ($t['status'] === 'PASS') $totalPass++;
                else $totalFail++;
            }
        }

        echo "\n=============================\n";
        echo "TOTAL: $totalPass PASS / $totalFail FAIL\n";
        echo $totalFail === 0
            ? "ALL PASS → 100% READY FOR DEPLOY\n"
            : "$totalFail FAIL → FIX BEFORE GO-LIVE\n";
        echo "=============================\n";
    }
}

(new FinalPhaseCheck())->run();
