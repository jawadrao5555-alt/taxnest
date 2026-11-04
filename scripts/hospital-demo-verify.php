<?php

use App\Models\Company;
use App\Support\{DevStagingGuard, NestErps};
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;

require __DIR__.'/../vendor/autoload.php';
$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
if (!$app->environment('local', 'testing') || env('HOSPITAL_DEMO_ALLOW') !== '1' || DevStagingGuard::problems()) {
    throw new RuntimeException('Refused: local, opted-in disposable MariaDB required.');
}
$hospital = Company::where('ntn', 'DEMO-344-A')->firstOrFail();
if ($hospital->name !== 'TaxNest Demo General Hospital' || $hospital->fbr_reporting_enabled) {
    throw new RuntimeException('Incorrect fixture or regulator reporting enabled.');
}
$tables = [
    'companies', 'branches', 'users', 'health_patients', 'health_doctors', 'health_visits', 'health_charges',
    'health_bills', 'health_payments', 'health_journals', 'health_journal_lines', 'health_diagnostic_orders',
    'health_audit_events', 'health_cashier_requests', 'health_deliveries', 'health_external_referrals',
    'health_triage_records', 'health_medicine_batches', 'health_supplier_payments', 'health_attendance_days',
    'health_staff_profiles', 'purchase_orders',
];
$report = [];
foreach ($tables as $table) {
    $digest = hash_init('sha256');
    $count = 0;
    foreach (DB::table($table)->orderBy('id')->cursor() as $row) {
        hash_update($digest, serialize($row));
        ++$count;
    }
    $report[$table] = [$count, hash_final($digest)];
}
$checks = ['tables' => $report, 'names' => Company::whereIn('product_type', NestErps::PRODUCT_TYPES)
    ->orderBy('id')->get()->pluck('name'), 'amount' => DB::table('health_bills')->sum('total_amount')];
if (in_array('--check-purchase', $argv, true)) {
    $batch = DB::table('health_medicine_batches')->where('company_id', $hospital->id)->where('batch_no', 'DEMO-BATCH-344')->sole();
    $order = DB::table('purchase_orders')->where('id', $batch->purchase_order_id)->where('company_id', $hospital->id)->firstOrFail();
    $paid = DB::table('health_supplier_payments')->where('company_id', $hospital->id)->sum('amount');
    $stockExpected = in_array('--check-journey', $argv, true) ? 8.0 : 10.0;
    if ((float) $batch->quantity !== $stockExpected || (float) $order->total_amount !== 120.0 || (float) $paid !== 70.0) {
        throw new RuntimeException('Supplier or stock reconciliation failed.');
    }
    $checks['purchase'] = ['units' => $batch->quantity, 'received' => $order->total_amount,
        'paid' => $paid, 'payable' => round($order->total_amount - $paid, 2)];
}
echo json_encode($checks, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR), PHP_EOL;
