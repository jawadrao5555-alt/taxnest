<?php

use App\Models\{Branch, Company, HealthAdmission, HealthBed, HealthBill, HealthCharge, HealthDoctor, HealthMedicine, HealthOperation, HealthOperationTheatre, HealthPatient, HealthPrescription, HealthProcedure, HealthVisit, User};
use App\Services\{HealthBillingService, HealthChargeIngestService, HealthIpdBillingService, HealthIpdService, HealthOpdService, HealthOperationService, HealthPatientService, HealthPharmacyCheckoutService};
use App\Support\DevStagingGuard;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;

require __DIR__.'/../vendor/autoload.php';
$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
if (!$app->environment('local','testing') || env('HOSPITAL_DEMO_ALLOW') !== '1' || DevStagingGuard::problems()) {
    throw new RuntimeException('Opted-in local disposable database required.');
}
$hospital = Company::where('ntn','DEMO-344-A')->firstOrFail();
if ($hospital->name !== 'TaxNest Demo General Hospital' || $hospital->fbr_reporting_enabled) {
    throw new RuntimeException('Unexpected demo tenant or reporting enabled');
}
$id = $hospital->id;
$owner = User::where('company_id',$id)->where('health_role','health_owner')->firstOrFail();
$doctor = HealthDoctor::withoutGlobalScopes()->where('company_id',$id)->whereNotNull('user_id')->firstOrFail();
$doctorUser = User::findOrFail($doctor->user_id);
$branch = Branch::withoutGlobalScopes()->where('company_id',$id)->where('is_head_office',true)->firstOrFail();
$name = 'Demo Clinical Journey';
$result = DB::transaction(function () use ($id,$hospital,$owner,$doctor,$doctorUser,$branch,$name,$argv) {
    if (($argv[1] ?? '') === 'prepare') {
        if (HealthPatient::withoutGlobalScopes()->where('company_id',$id)->where('name',$name)->exists()) {
            throw new RuntimeException('Clinical journey already initialized');
        }
        $patient = HealthPatientService::register($id,['name'=>$name,'branch_id'=>$branch->id,'gender'=>'female','age_years'=>30]);
        $appointment = HealthOpdService::book(['company_id'=>$id,'health_patient_id'=>$patient->id,'health_doctor_id'=>$doctor->id,
            'branch_id'=>$branch->id,'kind'=>'walkin','appointment_date'=>today()->toDateString()],$owner);
        $visit = HealthOpdService::checkIn($appointment,$owner,['visit_type'=>'emergency','fee_amount'=>1500]);
        HealthOpdService::startConsultation($visit);
        $stay = HealthIpdService::request(['company_id'=>$id,'branch_id'=>$branch->id,'health_patient_id'=>$patient->id,
            'health_doctor_id'=>$doctor->id,'admission_type'=>'maternity'],$doctorUser);
        $bed = HealthBed::withoutGlobalScopes()->where('company_id',$id)->where('status', HealthBed::STATUS_AVAILABLE)->firstOrFail();
        $stay = HealthIpdService::admit($stay,$bed->id,$owner);
        HealthIpdBillingService::recordPayment($stay,['amount'=>500,'kind'=>'advance','method'=>'cash'],$owner);
        $nurse = User::where('company_id',$id)->where('health_role','health_nurse')->firstOrFail();
        HealthIpdService::recordCare($stay,$nurse,'stable','Fictional ward observation');
        $op = HealthOperationService::schedule(['company_id'=>$id,'branch_id'=>$branch->id,'health_patient_id'=>$patient->id,
            'health_admission_id'=>$stay->id,'primary_surgeon_id'=>$doctor->id,'title'=>'Demo C-section',
            'health_procedure_id'=>HealthProcedure::withoutGlobalScopes()->where('company_id',$id)->where('code','CS-DEMO')->value('id'),
            'health_operation_theatre_id'=>HealthOperationTheatre::withoutGlobalScopes()->where('company_id',$id)->value('id'),
            'scheduled_start'=>now()->toDateTimeString(),'scheduled_end'=>now()->addHour()->toDateTimeString()],$doctorUser);
        HealthOperationService::saveConsumables($op,[['item_name'=>'Demo surgery kit','quantity'=>1,'unit_price'=>200,'is_billable'=>true]]);
        HealthOperationService::complete($op,['outcome'=>'successful'],$doctorUser);
        return ['patient'=>$patient->id,'visit'=>$visit->id,'stay'=>$stay->id,'operation'=>$op->id,
            'tests'=>DB::table('health_diagnostic_tests')->where('company_id',$id)->pluck('id','service'),
            'at'=>now()->toDateTimeString()];
    }
    if (($argv[1] ?? '') !== 'close') {
        throw new RuntimeException('Use prepare or close');
    }
    $patient = HealthPatient::withoutGlobalScopes()->where('company_id',$id)->where('name',$name)->sole();
    $visit = HealthVisit::withoutGlobalScopes()->where('company_id',$id)->where('health_patient_id',$patient->id)->sole();
    $stay = HealthAdmission::withoutGlobalScopes()->where('company_id',$id)->where('health_patient_id',$patient->id)->sole();
    if ($stay->status !== HealthAdmission::STATUS_ADMITTED || DB::table('health_diagnostic_orders')->where('health_patient_id',$patient->id)
        ->where('status','released')->count() !== 2 || !DB::table('health_deliveries')->where('health_admission_id',$stay->id)->exists()
        || !DB::table('health_triage_records')->where('health_visit_id',$visit->id)->exists()) {
        throw new RuntimeException('Signed diagnostics, triage, delivery and an open stay are required');
    }
    $rx = HealthPrescription::withoutGlobalScopes()->where('company_id',$id)->where('health_visit_id',$visit->id)->sole();
    $medicine = HealthMedicine::withoutGlobalScopes()->where('company_id',$id)->where('code','DEMO-MED')->sole();
    $sale = HealthPharmacyCheckoutService::sell($id,['prescription_id'=>$rx->id,'payment_method'=>'cash',
        'lines'=>[['medicine_id'=>$medicine->id,'prescription_item_id'=>$rx->items()->sole()->id,'quantity'=>2]]],$branch->id,$owner->id,$hospital);
    HealthOpdService::complete($visit,$doctorUser);
    $stay = HealthIpdService::requestDischarge($stay,$doctorUser,['discharge_summary'=>'Demo discharge after observation']);
    $balance = (float) HealthIpdBillingService::summary($stay)['outstanding'];
    if ($balance) {
        HealthIpdBillingService::recordPayment($stay,['kind'=>'advance','amount'=>$balance,'method'=>'cash'],$owner);
    }
    $stay = HealthIpdService::discharge($stay,$owner);
    HealthChargeIngestService::syncPatient($id,$patient->id,$owner);
    $charges = HealthCharge::withoutGlobalScopes()->where('company_id',$id)->where('health_patient_id',$patient->id)->get();
    $bill = HealthBillingService::createBill($id,$patient->id,$charges->modelKeys(),['scope'=>'consolidated'],$owner)['bill'];
    if (!HealthBillingService::finalize($bill,$owner)['ok']) {
        throw new RuntimeException('Clinical bill could not be finalized');
    }
    $outstanding = (float) $bill->fresh()->outstanding_amount;
    if ($outstanding !== 2800.0 || (float) $sale->total_amount !== 40.0) {
        throw new RuntimeException('Expected only the consultation (1500) and two investigations (1300) still payable; observed '.$outstanding);
    }
    $payment = HealthBillingService::recordPayment($id,$patient->id,['health_bill_id'=>$bill->id,'method'=>'cash','amount'=>$outstanding],$owner);
    if (!$payment['ok'] || (float) $bill->fresh()->outstanding_amount !== 0.0 || $stay->status !== 'discharged') {
        throw new RuntimeException('Cash or discharge reconciliation failed');
    }
    return ['patient'=>$patient->id,'visit'=>$visit->id,'stay'=>$stay->id,'bill'=>$bill->bill_no,'total'=>$bill->fresh()->total_amount,
        'pharmacy_collected'=>40,'inpatient_balance_paid'=>$balance,'central_cash'=>$outstanding,'outstanding'=>$bill->fresh()->outstanding_amount,
        'discharge'=>$stay->status,'signed_reports'=>2];
});
echo json_encode($result, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR), PHP_EOL;
