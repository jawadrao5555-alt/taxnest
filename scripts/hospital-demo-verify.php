<?php

use App\Models\Company;
use App\Support\NestErps;
use Illuminate\Support\Facades\DB;

$hospital = require __DIR__.'/lib/hospital-demo.php';
$tables = [
    'companies', 'branches', 'users', 'health_patients', 'health_doctors', 'health_visits', 'health_charges',
    'health_bills', 'health_payments', 'health_journals', 'health_journal_lines', 'health_diagnostic_orders',
    'health_audit_events', 'health_cashier_requests', 'health_deliveries', 'health_external_referrals',
    'health_triage_records', 'health_visit_attachments', 'health_medicine_batches', 'health_supplier_payments', 'health_attendance_days',
    'health_staff_profiles', 'purchase_orders', 'health_diagnostic_amendments',
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
if (in_array('--check-journey', $argv, true)) {
    $patient = DB::table('health_patients')->where('company_id',$hospital->id)->where('name','Demo Clinical Journey')->sole();
    $bill = DB::table('health_bills')->where('company_id',$hospital->id)->where('health_patient_id',$patient->id)->sole();
    $stay = DB::table('health_admissions')->where('company_id',$hospital->id)->where('health_patient_id',$patient->id)->sole();
    $diagnostics = DB::table('health_diagnostic_orders')->where('company_id',$hospital->id)
        ->where('health_patient_id',$patient->id)->where('status','released')->count();
    if ($diagnostics !== 2 || $stay->status !== 'discharged' || (float) $bill->outstanding_amount !== 0.0 ||
        !DB::table('health_deliveries')->where('health_admission_id',$stay->id)->where('method','caesarean')->exists()) {
        throw new RuntimeException('Restored clinical journey failed.');
    }
    $attachment = \App\Models\HealthVisitAttachment::withoutGlobalScopes()->where('company_id',$hospital->id)
        ->where('original_name','demo-lab.pdf')->sole();
    $bytes = \Illuminate\Support\Facades\Storage::disk('local')->get($attachment->path);
    if (!is_string($bytes) || !str_starts_with($bytes, '%PDF')) {
        throw new RuntimeException('Clinical report bytes are missing from the private file store.');
    }
    $checks['attachment_sha256'] = hash('sha256', $bytes);
    $orders = \App\Models\HealthDiagnosticOrder::where('company_id', $hospital->id)
        ->where('health_patient_id', $patient->id)->with('amendments')->get();
    foreach ($orders as $order) {
        if ($order->result !== 'Synthetic '.$order->service.' report'
            || $order->amendments->count() !== 1
            || $order->amendments->sole()->result !== 'Corrected synthetic '.$order->service.' report'
            || $order->amendments->sole()->reason !== 'Demo transcription correction') {
            throw new RuntimeException('Signed original or corrected clinical history failed restoration.');
        }
    }
    $checks['clinical'] = ['signed_reports'=>$diagnostics, 'discharge'=>$stay->status, 'bill'=>$bill->bill_no,
        'total'=>$bill->total_amount,'outstanding'=>$bill->outstanding_amount];
}
echo json_encode($checks, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR), PHP_EOL;
