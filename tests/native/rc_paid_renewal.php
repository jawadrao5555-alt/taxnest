<?php
declare(strict_types=1);

$database = getenv('DB_DATABASE') ?: '';
$socket = getenv('DB_SOCKET') ?: '';
if (getenv('APP_ENV') !== 'rc-mariadb' || !getenv('LD_PRELOAD')
    || !preg_match('/^taxnest_rc_[a-z0-9_]+_full$/D', $database)
    || !preg_match('#^/tmp/taxnest-rc-mariadb-[a-z0-9_]+/run/mariadb.sock$#D', $socket)) {
    fwrite(STDERR, "FAIL unsafe paid renewal target\n");
    exit(2);
}

require dirname(__DIR__, 2).'/vendor/autoload.php';
$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Http\Controllers\SaasAdmin\AdminPaymentProofController;
use App\Models\{AdminAuditLog, AdminUser, Company, PaymentProof, PricingPlan, Subscription};
use Illuminate\Http\Request;
use Symfony\Component\Process\Process;

function approveRenewal(int $proofId, int $planId, int $adminId): void
{
    auth('admin')->setUser(AdminUser::findOrFail($adminId));
    $request = Request::create('/admin/payment-proofs/'.$proofId.'/approve', 'POST', [
        'pricing_plan_id' => $planId, 'billing_cycle' => 'annual', 'verified_received_amount' => 1000,
    ]);
    $request->setLaravelSession(app('session')->driver());
    app()->instance('request', $request);
    $response = app(AdminPaymentProofController::class)->approve($request, $proofId);
    if ($response->getStatusCode() !== 302) {
        throw new RuntimeException('Paid renewal controller did not return its normal redirect.');
    }
}

if (($argv[1] ?? '') === 'worker') {
    [$proofId, $planId, $adminId, $barrier, $index] = array_slice($argv, 2);
    if (!str_starts_with($barrier, dirname($socket).'/renewal-') || !ctype_digit($index)) {
        throw new RuntimeException('Unsafe renewal barrier.');
    }
    $proof = PaymentProof::with('company')->findOrFail($proofId);
    if ($proof->company->name !== 'Synthetic paid renewal') {
        throw new RuntimeException('Unknown renewal fixture.');
    }
    file_put_contents($barrier.'.ready-'.$index, 'ready');
    $deadline = microtime(true) + 20;
    while (!is_file($barrier)) {
        if (microtime(true) > $deadline) throw new RuntimeException('Renewal barrier timed out.');
        usleep(10000);
    }
    approveRenewal((int) $proofId, (int) $planId, (int) $adminId);
    exit(0);
}

$suffix = bin2hex(random_bytes(8));
$admin = AdminUser::create([
    'name' => 'Synthetic renewal reviewer', 'email' => $suffix.'@renewal.invalid',
    'password' => bcrypt('SyntheticOnly123!'), 'role' => 'super_admin',
]);
$company = Company::create([
    'name' => 'Synthetic paid renewal', 'email' => 'shop-'.$suffix.'@renewal.invalid',
    'ntn' => $suffix, 'product_type' => 'pos', 'status' => 'approved', 'company_status' => 'active',
]);
$other = Company::create([
    'name' => 'Synthetic other renewal account', 'email' => 'other-'.$suffix.'@renewal.invalid',
    'ntn' => 'x'.$suffix, 'product_type' => 'pos', 'status' => 'approved', 'company_status' => 'active',
]);
$plan = PricingPlan::create([
    'name' => 'Starter', 'product_type' => 'pos', 'price' => 1000,
    'is_trial' => false, 'invoice_limit' => 100,
]);
$previousEnd = now()->addMonthsNoOverflow(2)->startOfDay();
$old = Subscription::create([
    'company_id' => $company->id, 'pricing_plan_id' => $plan->id, 'billing_cycle' => 'annual',
    'active' => true, 'final_price' => 1000, 'start_date' => now()->subMonths(10), 'end_date' => $previousEnd,
]);
$foreign = Subscription::create([
    'company_id' => $other->id, 'pricing_plan_id' => $plan->id, 'billing_cycle' => 'annual',
    'active' => true, 'final_price' => 1000, 'start_date' => now(), 'end_date' => $previousEnd,
]);
$foreignBefore = $foreign->fresh()->getAttributes();
$proof = PaymentProof::create([
    'company_id' => $company->id, 'pricing_plan_id' => $plan->id, 'billing_cycle' => 'annual',
    'amount' => 1000, 'proof_path' => 'synthetic-renewal', 'status' => 'pending',
]);
$barrier = dirname($socket).'/renewal-'.$suffix;
$processes = [];
try {
    for ($index = 0; $index < 2; $index++) {
        $process = new Process([
            PHP_BINARY, '-c', php_ini_loaded_file(), __FILE__, 'worker', (string) $proof->id,
            (string) $plan->id, (string) $admin->id, $barrier, (string) $index,
        ]);
        $process->setTimeout(40);
        $process->start();
        $processes[] = $process;
    }
    $deadline = microtime(true) + 20;
    while (count(glob($barrier.'.ready-*')) !== 2) {
        if (microtime(true) > $deadline) throw new RuntimeException('Renewal workers did not reach barrier.');
        usleep(10000);
    }
    file_put_contents($barrier, 'go');
    foreach ($processes as $process) {
        $process->wait();
        if (!$process->isSuccessful()) throw new RuntimeException('Renewal worker failed: '.$process->getErrorOutput());
    }
    $new = Subscription::where('company_id', $company->id)->where('active', true)->sole();
    if (Subscription::where('company_id', $company->id)->count() !== 2
        || $new->end_date->toDateString() !== $previousEnd->copy()->addMonthsNoOverflow(12)->toDateString()
        || $old->fresh()->end_date->toDateString() !== $previousEnd->toDateString()
        || (bool) $old->fresh()->active || $proof->fresh()->status !== 'verified'
        || (int) $proof->fresh()->subscription_id !== $new->id
        || $foreign->fresh()->getAttributes() !== $foreignBefore) {
        throw new RuntimeException('Concurrent renewal changed or duplicated a paid term.');
    }
    $purchaseSnapshot = $new->purchase_snapshot;
    if (!is_array($purchaseSnapshot) || ($purchaseSnapshot['version'] ?? null) !== 1
        || ($purchaseSnapshot['company_id'] ?? null) !== $company->id
        || ($purchaseSnapshot['plan']['invoice_limit'] ?? null) !== 100
        || ($purchaseSnapshot['price']['final_price'] ?? null) !== (string) $new->final_price
        || ($purchaseSnapshot['period']['end'] ?? null) !== $new->end_date->toDateString()) {
        throw new RuntimeException('Concurrent approval did not retain the purchased plan and period.');
    }
    $plan->update(['invoice_limit' => 5, 'price' => 8000]);
    if ($new->fresh()->purchase_snapshot !== $purchaseSnapshot) {
        throw new RuntimeException('Catalogue editing changed retained purchase evidence.');
    }
    approveRenewal($proof->id, $plan->id, $admin->id);
    if (Subscription::where('company_id', $company->id)->count() !== 2
        || AdminAuditLog::where('target_type', 'PaymentProof')->where('target_id', $proof->id)
            ->where('action', 'Payment proof approved')->count() !== 1) {
        throw new RuntimeException('Renewal retry duplicated a subscription or approval audit.');
    }
    if ($new->fresh()->purchase_snapshot !== $purchaseSnapshot || $old->fresh()->purchase_snapshot !== null) {
        throw new RuntimeException('Retry changed purchase evidence or backfilled unknown history.');
    }
    echo "PASS: native purchase snapshot survives catalogue edit and concurrent approval retry\n";
    echo "PASS: native concurrent paid renewal preserves remaining term once; retry and other company preserved\n";
} finally {
    foreach ($processes as $process) if ($process->isRunning()) $process->stop(1);
    if (is_file($barrier)) unlink($barrier);
    foreach (glob($barrier.'.ready-*') as $ready) unlink($ready);
}
