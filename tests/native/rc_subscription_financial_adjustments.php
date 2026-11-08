<?php
declare(strict_types=1);

$database = getenv('DB_DATABASE') ?: '';
$socket = getenv('DB_SOCKET') ?: '';
if (getenv('APP_ENV') !== 'rc-mariadb' || !getenv('LD_PRELOAD')
    || !preg_match('/^taxnest_rc_[a-z0-9_]+_full$/D', $database)
    || !preg_match('#^/tmp/taxnest-rc-mariadb-[a-z0-9_]+/run/mariadb.sock$#D', $socket)) {
    fwrite(STDERR, "FAIL unsafe subscription adjustment target\n");
    exit(2);
}

require dirname(__DIR__, 2).'/vendor/autoload.php';
$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\{
    AdminAuditLog, AdminUser, Company, PricingPlan, Subscription,
    SubscriptionFinancialAdjustment, SubscriptionInvoice, SubscriptionPayment
};
use App\Services\SubscriptionFinancialAdjustmentService;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;

if (($argv[1] ?? '') === 'worker') {
    [$companyId, $invoiceId, $paymentId, $adminId, $key, $barrier, $index] = array_slice($argv, 2);
    if (!str_starts_with($barrier, dirname($socket).'/adjustment-') || !ctype_digit($index)) {
        throw new RuntimeException('Unsafe subscription-adjustment barrier.');
    }
    file_put_contents($barrier.'.ready-'.$index, 'ready');
    $deadline = microtime(true) + 20;
    while (!is_file($barrier)) {
        if (microtime(true) > $deadline) throw new RuntimeException('Adjustment barrier timed out.');
        usleep(10000);
    }
    try {
        SubscriptionFinancialAdjustmentService::record([
            'company_id' => (int) $companyId,
            'subscription_invoice_id' => (int) $invoiceId,
            'subscription_payment_id' => (int) $paymentId,
            'kind' => SubscriptionFinancialAdjustment::REFUND,
            'amount' => 700,
            'reason' => 'Synthetic concurrent external refund evidence',
            'external_reference' => 'SYNTHETIC-NATIVE-'.$index,
            'idempotency_key' => $key,
        ], (int) $adminId);
        echo "won\n";
    } catch (DomainException) {
        echo "rejected\n";
    }
    exit(0);
}

$suffix = bin2hex(random_bytes(8));
$admin = AdminUser::create([
    'name' => 'Synthetic native adjustment reviewer',
    'email' => $suffix.'@adjustment.invalid',
    'password' => bcrypt('SyntheticOnly123!'),
    'role' => 'super_admin',
]);
$company = Company::create([
    'name' => 'Synthetic native adjustment company',
    'email' => 'shop-'.$suffix.'@adjustment.invalid',
    'ntn' => $suffix,
    'product_type' => 'pos',
    'status' => 'approved',
    'company_status' => 'active',
]);
$other = Company::create([
    'name' => 'Synthetic native adjustment other company',
    'email' => 'other-'.$suffix.'@adjustment.invalid',
    'ntn' => 'x'.$suffix,
    'product_type' => 'pos',
    'status' => 'approved',
    'company_status' => 'active',
]);
$plan = PricingPlan::create([
    'name' => 'Synthetic native adjustment plan',
    'product_type' => 'pos',
    'price' => 1000,
    'invoice_limit' => 100,
    'is_active' => true,
    'is_trial' => false,
]);
$subscription = Subscription::create([
    'company_id' => $company->id,
    'pricing_plan_id' => $plan->id,
    'billing_cycle' => 'annual',
    'start_date' => now(),
    'end_date' => now()->addYear(),
    'final_price' => 1000,
    'active' => true,
]);
$otherSubscription = Subscription::create([
    'company_id' => $other->id,
    'pricing_plan_id' => $plan->id,
    'billing_cycle' => 'annual',
    'start_date' => now(),
    'end_date' => now()->addYear(),
    'final_price' => 1000,
    'active' => true,
]);
$invoice = SubscriptionInvoice::create([
    'company_id' => $company->id,
    'subscription_id' => $subscription->id,
    'document_number' => 'SUB-NATIVE-'.$suffix,
    'document_snapshot' => ['version' => 1, 'fixture' => $suffix],
    'amount' => 1000,
    'status' => 'paid',
    'due_date' => now(),
    'paid_at' => now(),
]);
$payment = SubscriptionPayment::create([
    'subscription_invoice_id' => $invoice->id,
    'amount' => 1000,
    'payment_method' => 'bank',
    'transaction_ref' => 'SYNTHETIC-NATIVE-PAYMENT',
    'paid_at' => now(),
    'receipt_number' => 'RCP-NATIVE-'.$suffix,
    'receipt_snapshot' => ['version' => 1, 'fixture' => $suffix],
]);
$otherInvoice = SubscriptionInvoice::create([
    'company_id' => $other->id,
    'subscription_id' => $otherSubscription->id,
    'document_number' => 'SUB-NATIVE-OTHER-'.$suffix,
    'document_snapshot' => ['version' => 1, 'fixture' => 'other-'.$suffix],
    'amount' => 1000,
    'status' => 'paid',
    'due_date' => now(),
    'paid_at' => now(),
]);
$otherPayment = SubscriptionPayment::create([
    'subscription_invoice_id' => $otherInvoice->id,
    'amount' => 1000,
    'payment_method' => 'bank',
    'transaction_ref' => 'SYNTHETIC-NATIVE-OTHER-PAYMENT',
    'paid_at' => now(),
    'receipt_number' => 'RCP-NATIVE-OTHER-'.$suffix,
    'receipt_snapshot' => ['version' => 1, 'fixture' => 'other-'.$suffix],
]);
$before = [
    'company' => $company->fresh()->getAttributes(),
    'subscription' => $subscription->fresh()->getAttributes(),
    'invoice' => $invoice->fresh()->getAttributes(),
    'payment' => $payment->fresh()->getAttributes(),
    'other_company' => $other->fresh()->getAttributes(),
    'other_subscription' => $otherSubscription->fresh()->getAttributes(),
    'other_invoice' => $otherInvoice->fresh()->getAttributes(),
    'other_payment' => $otherPayment->fresh()->getAttributes(),
];

$barrier = dirname($socket).'/adjustment-'.$suffix;
$processes = [];
$keys = [(string) Str::uuid(), (string) Str::uuid()];
try {
    for ($index = 0; $index < 2; $index++) {
        $process = new Process([
            PHP_BINARY, '-c', php_ini_loaded_file(), __FILE__, 'worker',
            (string) $company->id, (string) $invoice->id, (string) $payment->id,
            (string) $admin->id, $keys[$index], $barrier, (string) $index,
        ]);
        $process->setTimeout(40);
        $process->start();
        $processes[] = $process;
    }
    $deadline = microtime(true) + 20;
    while (count(glob($barrier.'.ready-*')) !== 2) {
        if (microtime(true) > $deadline) throw new RuntimeException('Adjustment workers did not reach barrier.');
        usleep(10000);
    }
    file_put_contents($barrier, 'go');
    $outcomes = [];
    foreach ($processes as $process) {
        $process->wait();
        if (!$process->isSuccessful()) throw new RuntimeException('Adjustment worker failed: '.$process->getErrorOutput());
        $outcomes[] = trim($process->getOutput());
    }
    sort($outcomes);
    if ($outcomes !== ['rejected', 'won']) {
        throw new RuntimeException('Concurrent refund evidence did not retain exactly one bounded record.');
    }
    $refund = SubscriptionFinancialAdjustment::where('kind', SubscriptionFinancialAdjustment::REFUND)->sole();
    if ((float) $refund->amount !== 700.0
        || SubscriptionFinancialAdjustment::count() !== 1
        || AdminAuditLog::where('target_type', 'SubscriptionFinancialAdjustment')->count() !== 1) {
        throw new RuntimeException('Concurrent refund evidence or audit count is not exact.');
    }

    SubscriptionFinancialAdjustmentService::record([
        'company_id' => $company->id,
        'subscription_invoice_id' => $invoice->id,
        'subscription_payment_id' => $payment->id,
        'kind' => SubscriptionFinancialAdjustment::REFUND,
        'amount' => 700,
        'reason' => 'Synthetic concurrent external refund evidence',
        'external_reference' => $refund->external_reference,
        'idempotency_key' => $refund->idempotency_key,
    ], $admin->id);
    if (SubscriptionFinancialAdjustment::count() !== 1
        || AdminAuditLog::where('target_type', 'SubscriptionFinancialAdjustment')->count() !== 1) {
        throw new RuntimeException('Idempotent refund retry duplicated evidence or audit.');
    }

    SubscriptionFinancialAdjustmentService::record([
        'company_id' => $company->id,
        'subscription_invoice_id' => $invoice->id,
        'subscription_payment_id' => $payment->id,
        'kind' => SubscriptionFinancialAdjustment::REVERSAL,
        'reverses_adjustment_id' => $refund->id,
        'amount' => 700,
        'reason' => 'Synthetic native evidence correction',
        'idempotency_key' => (string) Str::uuid(),
    ], $admin->id);
    SubscriptionFinancialAdjustmentService::record([
        'company_id' => $company->id,
        'subscription_invoice_id' => $invoice->id,
        'subscription_payment_id' => $payment->id,
        'kind' => SubscriptionFinancialAdjustment::REFUND,
        'amount' => 1000,
        'reason' => 'Synthetic native replacement external refund evidence',
        'external_reference' => 'SYNTHETIC-NATIVE-REPLACEMENT',
        'idempotency_key' => (string) Str::uuid(),
    ], $admin->id);
    if (SubscriptionFinancialAdjustment::count() !== 3
        || SubscriptionFinancialAdjustment::where('reverses_adjustment_id', $refund->id)->count() !== 1) {
        throw new RuntimeException('Reversal did not retain both original and replacement evidence exactly once.');
    }
    foreach ($before as $name => $attributes) {
        $model = match ($name) {
            'company' => $company, 'subscription' => $subscription, 'invoice' => $invoice, 'payment' => $payment,
            'other_company' => $other, 'other_subscription' => $otherSubscription,
            'other_invoice' => $otherInvoice, 'other_payment' => $otherPayment,
        };
        if ($model->fresh()->getAttributes() !== $attributes) {
            throw new RuntimeException($name.' changed while retaining financial-adjustment evidence.');
        }
    }
    echo "PASS: native concurrent refund evidence stays bounded, idempotent and append-only; reversal, billing records and second tenant preserved\n";
} finally {
    foreach ($processes as $process) if ($process->isRunning()) $process->stop(1);
    if (is_file($barrier)) unlink($barrier);
    foreach (glob($barrier.'.ready-*') as $ready) unlink($ready);
}
