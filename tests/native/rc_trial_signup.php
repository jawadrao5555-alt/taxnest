<?php

declare(strict_types=1);

$database = getenv('DB_DATABASE') ?: '';
$socket = getenv('DB_SOCKET') ?: '';
if (getenv('APP_ENV') !== 'rc-mariadb' || ! getenv('LD_PRELOAD')
    || ! preg_match('/^taxnest_rc_[a-z0-9_]+_full$/D', $database)
    || ! preg_match('#^/tmp/taxnest-rc-mariadb-[a-z0-9_]+/run/mariadb.sock$#D', $socket)) {
    fwrite(STDERR, "FAIL unsafe trial target\n");
    exit(2);
}
require dirname(__DIR__, 2).'/vendor/autoload.php';
$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\Company;
use App\Models\PricingPlan;
use App\Models\Subscription;
use App\Services\TrialSubscriptionService;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Process\Process;

if (($argv[1] ?? '') === 'worker') {
    [$id,$barrier,$index] = array_slice($argv, 2);
    if (! ctype_digit($id) || ! ctype_digit($index) || ! str_starts_with($barrier, dirname($socket).'/trial-signup-')) {
        throw new RuntimeException('Unsafe trial barrier');
    }
    file_put_contents($barrier.'.ready-'.$index, 'ready');
    $deadline = microtime(true) + 20;
    while (! is_file($barrier)) {
        if (microtime(true) > $deadline) {
            throw new RuntimeException('Trial barrier timeout');
        }usleep(10000);
    }
    echo 'TRIAL_ID='.TrialSubscriptionService::ensureTrial((int) $id, 'di')->id."\n";
    exit(0);
}
$suffix = bin2hex(random_bytes(8));
PricingPlan::create(['name' => 'Synthetic trial '.$suffix, 'product_type' => 'di', 'is_trial' => true, 'price' => 0, 'invoice_limit' => 10]);
$company = Company::create(['name' => 'Synthetic concurrent signup', 'ntn' => $suffix, 'product_type' => 'di']);
$other = Company::create(['name' => 'Other synthetic paid company', 'ntn' => 'x'.$suffix, 'product_type' => 'di']);
$paid = Subscription::create(['company_id' => $other->id, 'active' => true, 'final_price' => 12000, 'start_date' => now(), 'end_date' => now()->addYear()]);
$before = $paid->fresh()->getAttributes();
$invoiceCount = DB::table('subscription_invoices')->count();
$receiptCount = DB::table('subscription_payments')->count();
$barrier = dirname($socket).'/trial-signup-'.$suffix;
$processes = [];
try {
    foreach ([1, 2] as $index) {
        $process = new Process([PHP_BINARY, '-c', php_ini_loaded_file(), __FILE__, 'worker', (string) $company->id, $barrier, (string) $index]);
        $process->setTimeout(40);
        $process->start();
        $processes[] = $process;
    }
    $deadline = microtime(true) + 20;
    while (count(glob($barrier.'.ready-*')) !== 2) {
        if (microtime(true) > $deadline) {
            throw new RuntimeException('Trial workers not ready');
        }usleep(10000);
    }
    file_put_contents($barrier, 'go');
    $ids = [];
    foreach ($processes as $process) {
        $process->wait();
        if (! $process->isSuccessful()) {
            throw new RuntimeException('Trial worker failed: '.$process->getErrorOutput());
        }
        if (! preg_match('/TRIAL_ID=(\d+)/', $process->getOutput(), $match)) {
            throw new RuntimeException('Trial worker missing result');
        }$ids[] = $match[1];
    }
    $sub = Subscription::where('company_id', $company->id)->sole();
    if (count(array_unique($ids)) !== 1 || (int) $ids[0] !== $sub->id || ($sub->purchase_snapshot['source'] ?? null) !== 'trial_assignment'
        || (float) $sub->final_price !== 0.0 || ! $sub->trial_ends_at || $before !== $paid->fresh()->getAttributes()
        || $invoiceCount !== DB::table('subscription_invoices')->count() || $receiptCount !== DB::table('subscription_payments')->count()) {
        throw new RuntimeException('Trial uniqueness/preservation evidence failed');
    }
    echo "PASS: native concurrent trial creation returns one frozen zero-price term; paid company and documents unchanged\n";
} finally {
    foreach ($processes as $process) {
        if ($process->isRunning()) {
            $process->stop(1);
        }
    }
    if (is_file($barrier)) {
        unlink($barrier);
    }
    foreach (glob($barrier.'.ready-*') as $ready) {
        unlink($ready);
    }
}
