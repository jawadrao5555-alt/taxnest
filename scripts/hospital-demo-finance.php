<?php

use App\Services\HealthChartOfAccountsService as Chart;
use App\Services\HealthPostingService;
use Illuminate\Support\Facades\DB;

$hospital = require __DIR__.'/lib/hospital-demo.php';
$owner = \App\Models\User::where('company_id', $hospital->id)->where('health_role', 'health_owner')->firstOrFail();
$patient = DB::table('health_patients')->where('company_id', $hospital->id)->where('name', 'Demo Clinical Journey')->sole();
$clinicalTotal = (float) DB::table('health_bills')->where('company_id', $hospital->id)->where('health_patient_id', $patient->id)->value('total_amount');
if ($clinicalTotal < 10040) {
    throw new RuntimeException('Complete the named clinical journey before finance acceptance.');
}
$sweep = HealthPostingService::sweep($hospital->id, null, null, $owner);
if ($sweep['failed']) {
    throw new RuntimeException('Source posting sweep failed.');
}
$before = DB::table('health_journals')->where('company_id', $hospital->id)->count();
$repeat = HealthPostingService::sweep($hospital->id, null, null, $owner);
if ($repeat['failed'] || $before !== DB::table('health_journals')->where('company_id', $hospital->id)->count()) {
    throw new RuntimeException('Repeating source posting created additional journals.');
}
$balance = static function (string $key) use ($hospital): float {
    return round((float) DB::table('health_journal_lines')
        ->where('company_id', $hospital->id)->where('health_account_id', Chart::id($hospital->id, $key))
        ->selectRaw('COALESCE(SUM(debit-credit), 0) AS balance')->value('balance'), 2);
};
$expected = [Chart::CASH => $clinicalTotal + 355.0, Chart::PHARMACY_INVENTORY => 96.0,
    Chart::SUPPLIER_PAYABLE => -50.0, Chart::PATIENT_RECEIVABLE => 1200.0,
    Chart::PATIENT_ADVANCE => -125.0, Chart::COGS_PHARMACY => 24.0];
$observed = [];
foreach ($expected as $account => $amount) {
    $observed[$account] = $balance($account);
    if ($observed[$account] !== $amount) {
        throw new RuntimeException($account.' balance: expected '.$amount.', observed '.$observed[$account]);
    }
}
$sum = DB::table('health_journal_lines')->where('company_id', $hospital->id)
    ->selectRaw('SUM(debit) AS debit, SUM(credit) AS credit')->first();
if (round((float) $sum->debit - (float) $sum->credit, 2) !== 0.0) {
    throw new RuntimeException('Hospital trial balance does not balance.');
}
echo json_encode(['accounts' => $observed, 'debit' => $sum->debit, 'credit' => $sum->credit,
    'journals' => $before, 'repeat_sweep_added_journals' => 0], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR), PHP_EOL;
