<?php

use App\Models\{Company, HealthBill, HealthCharge, HealthDoctor, HealthPatient, User};
use App\Services\{HealthBillingService, HealthChargeIngestService, HealthModuleService, HealthOpdService, HealthPostingService};
use Illuminate\Support\Facades\DB;

$company = require __DIR__.'/lib/hospital-demo.php';

function verify(bool $condition, string $label): void
{
    if (!$condition) {
        throw new RuntimeException($label);
    }
}

$owner = User::where('company_id', $company->id)->where('health_role', 'health_owner')->firstOrFail();
$patient = HealthPatient::withoutGlobalScopes()->where('company_id', $company->id)->firstOrFail();
$doctor = HealthDoctor::withoutGlobalScopes()->where('company_id', $company->id)->firstOrFail();
$evidence = DB::transaction(function () use ($company, $owner, $patient, $doctor) {
    $id = $company->id;
    verify(!HealthBill::withoutGlobalScopes()->where('company_id', $id)->exists(), 'Day already run; initialize another empty disposable database.');
    $appointment = HealthOpdService::book(['company_id' => $id, 'health_patient_id' => $patient->id,
        'branch_id' => $patient->branch_id, 'health_doctor_id' => $doctor->id,
        'kind' => 'walkin', 'appointment_date' => today()->toDateString(), 'reason' => 'Demo consultation'], $owner);
    $visit = HealthOpdService::checkIn($appointment, $owner, ['fee_amount' => 1500]);
    HealthOpdService::startConsultation($visit);
    HealthOpdService::complete($visit->fresh(), $owner);
    $sync = HealthChargeIngestService::syncPatient($id, $patient->id, $owner);
    verify($sync['posted'] === 1 && HealthChargeIngestService::syncPatient($id, $patient->id, $owner)['posted'] === 0,
        'Second charge ingestion duplicated the visit or first charge was missing.');
    $charge = HealthCharge::withoutGlobalScopes()->where('company_id', $id)->where('health_visit_id', $visit->id)->sole();
    $result = HealthBillingService::createBill($id, $patient->id, [$charge->id], [], $owner);
    verify($result['ok'], 'Create bill failed');
    $bill = $result['bill'];
    verify(HealthBillingService::finalize($bill, $owner)['ok'] && (float) $bill->fresh()->total_amount === 1500.0, 'Bill total/finalization mismatch');
    foreach ([500, 1000] as $amount) {
        verify(HealthBillingService::recordPayment($id, $patient->id, ['health_bill_id' => $bill->id,
            'amount' => $amount, 'method' => 'cash'], $owner)['ok'], 'Payment refused');
        verify((float) $bill->fresh()->outstanding_amount === ($amount === 500 ? 1000.0 : 0.0), 'Patient balance mismatch');
    }
    HealthPostingService::postBill($bill->fresh(), $owner);
    verify(HealthBillingService::refund($bill->fresh(), 1600, [], $owner)['reason'] === 'exceeds_refundable', 'Excess refund allowed');
    verify(HealthBillingService::refund($bill->fresh(), 200, [], $owner)['ok'], 'Refund failed');
    $debits = DB::table('health_journal_lines')->where('company_id', $id)->sum('debit');
    $credits = DB::table('health_journal_lines')->where('company_id', $id)->sum('credit');
    verify((float) $debits > 0 && (float) $debits === (float) $credits, 'Journal is missing or unbalanced');
    return ['appointment' => $appointment->id, 'visit' => $visit->id, 'bill' => $bill->bill_no,
        'invoiced' => $bill->fresh()->total_amount, 'collected' => $bill->fresh()->paid_amount,
        'refunded' => $bill->fresh()->refunded_amount, 'debits' => $debits, 'credits' => $credits];
});
$profiles = Company::where('product_type', 'erps')->orderBy('id')->get()->map(fn ($org) => [
    'name' => $org->name, 'modules' => HealthModuleService::enabled($org),
    'branches' => DB::table('branches')->where('company_id', $org->id)->count(),
])->all();
echo json_encode(['profiles' => $profiles, 'day' => $evidence], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR), PHP_EOL;
