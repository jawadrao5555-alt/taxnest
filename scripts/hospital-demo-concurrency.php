<?php

use App\Models\{Company, HealthBill, HealthPayment, User};
use App\Services\HealthBillingService;
use App\Support\DevStagingGuard;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

require __DIR__.'/../vendor/autoload.php';
$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

if (!$app->environment('local', 'testing') || env('HOSPITAL_DEMO_ALLOW') !== '1' || DevStagingGuard::problems()
    || !function_exists('pcntl_fork')) {
    throw new RuntimeException('Concurrent demo requires an opted-in local MariaDB and pcntl.');
}
$company = Company::where('ntn', 'DEMO-344-A')->firstOrFail();
if ($company->name !== 'TaxNest Demo General Hospital' || $company->fbr_reporting_enabled) {
    throw new RuntimeException('Unexpected hospital or regulator reporting enabled.');
}
$bill = HealthBill::withoutGlobalScopes()->where('company_id', $company->id)->where('bill_no', 'B000001')->firstOrFail();
$actor = User::where('company_id', $company->id)->where('health_role', 'health_owner')->firstOrFail();
if ((float) $bill->refunded_amount !== 200.0 || HealthPayment::withoutGlobalScopes()->where('company_id', $company->id)
    ->where('reference', 'DEMO-RACE')->exists()) {
    throw new RuntimeException('Run this test once after hospital-demo-day.php in a new demo.');
}
function race(callable $work): array
{
    $dir = sys_get_temp_dir().'/hospital-race-'.Str::uuid();
    mkdir($dir, 0700);
    $children = [];
    DB::purge();
    try {
        foreach ([0, 1] as $worker) {
            $pid = pcntl_fork();
            if ($pid === -1) {
                throw new RuntimeException('Unable to fork a worker');
            }
            if ($pid === 0) {
                try {
                    DB::connection()->getPdo();
                    touch($dir.'/ready-'.$worker);
                    $deadline = microtime(true) + 20;
                    while (!file_exists($dir.'/start')) {
                        if (microtime(true) > $deadline) {
                            throw new RuntimeException('Race barrier timed out');
                        }
                        usleep(5000);
                    }
                    file_put_contents($dir.'/result-'.$worker, json_encode($work($worker), JSON_THROW_ON_ERROR));
                    exit(0);
                } catch (Throwable $error) {
                    fwrite(STDERR, $error->getMessage().PHP_EOL);
                    exit(1);
                }
            }
            $children[] = $pid;
        }
        $deadline = microtime(true) + 20;
        while (!file_exists($dir.'/ready-0') || !file_exists($dir.'/ready-1')) {
            if (microtime(true) > $deadline) {
                throw new RuntimeException('Workers failed to reach the barrier');
            }
            usleep(5000);
        }
        touch($dir.'/start');
        foreach ($children as $pid) {
            pcntl_waitpid($pid, $status);
            if (!pcntl_wifexited($status) || pcntl_wexitstatus($status) !== 0) {
                throw new RuntimeException('Concurrent hospital operation failed');
            }
        }
        return array_map(fn ($i) => json_decode(file_get_contents($dir.'/result-'.$i), true, flags: JSON_THROW_ON_ERROR), [0, 1]);
    } finally {
        foreach (glob($dir.'/*') as $file) {
            unlink($file);
        }
        rmdir($dir);
    }
}
$key = (string) Str::uuid();
$deposits = race(function () use ($key, $bill, $actor) {
    $result = HealthBillingService::recordPayment($bill->company_id, $bill->health_patient_id,
        ['kind'=>'deposit', 'amount'=>125, 'method'=>'cash', 'request_key'=>$key, 'reference'=>'DEMO-RACE'], $actor);
    return ['ok'=>$result['ok'], 'receipt'=>$result['payment']->receipt_no ?? null];
});
if (!$deposits[0]['ok'] || $deposits[0] !== $deposits[1] || !isset($deposits[0]['receipt'])
    || (float) HealthPayment::withoutGlobalScopes()->where('company_id', $company->id)->where('reference', 'DEMO-RACE')->sum('amount') !== 125.0) {
    throw new RuntimeException('Duplicate deposit posted or replay receipt did not match');
}
$refunds = race(fn () => HealthBillingService::refund($bill, 1000, ['request_key'=>(string) Str::uuid()], $actor)['ok']);
sort($refunds);
if ($refunds !== [false, true] || (float) $bill->fresh()->refunded_amount !== 1200.0) {
    throw new RuntimeException('Concurrent refunds exceeded the refundable balance');
}
echo json_encode(['deposit_workers'=>$deposits, 'deposit_total'=>125, 'refund_workers'=>$refunds,
    'new_refund'=>1000, 'total_refunded'=>$bill->fresh()->refunded_amount], JSON_PRETTY_PRINT), PHP_EOL;
