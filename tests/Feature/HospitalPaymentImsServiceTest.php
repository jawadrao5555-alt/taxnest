<?php
namespace Tests\Feature;

use App\Models\Company;
use App\Services\HospitalPaymentImsService;
use App\Services\PilotFiscalRouteSnapshot;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class HospitalPaymentImsServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_finalized_payment_uses_received_amount_and_copies_accepted_identity_to_same_receipt(): void
    {
        $company=Company::create(['name'=>'Synthetic Hospital','ntn'=>uniqid(),'product_type'=>'fbrpos','pos_type'=>'hospital',
            'business_category'=>'hospital','fbr_reporting_enabled'=>true,'fbr_pos_enabled'=>true,'fbr_connection_mode'=>'fiscal_device',
            'fbr_pos_id'=>'203480','agent_enabled'=>true,'fbr_pos_environment'=>'sandbox']);
        $invoice=DB::table('hospital_invoices')->insertGetId(['company_id'=>$company->id,'request_key'=>(string)\Illuminate\Support\Str::uuid(),
            'patient_reference'=>'SYN-PATIENT','service_head'=>'consultation','total_paisa'=>2000000,'created_by'=>1,'campus_name'=>'Synthetic',
            'created_at'=>now(),'updated_at'=>now()]);
        $payment=DB::table('hospital_payments')->insertGetId(['company_id'=>$company->id,'invoice_id'=>$invoice,
            'request_key'=>(string)\Illuminate\Support\Str::uuid(),'amount_paisa'=>800000,'balance_paisa'=>1200000,'payment_method'=>'cash',
            'collected_by'=>1,'cashier_name'=>'Synthetic','campus_name'=>'Synthetic','collected_at'=>now(),
            'fiscal_route_snapshot'=>PilotFiscalRouteSnapshot::newHospitalPayment($company->fresh(),$invoice),'created_at'=>now(),'updated_at'=>now()]);
        $result=HospitalPaymentImsService::queueFinalizedReceipt($company->id,$payment);
        $this->assertTrue($result['queued']);$this->assertFalse($result['existing']);
        $mirror=DB::table('fbr_pos_transactions')->find($result['transaction_id']);
        $this->assertSame('8000.00',(string)$mirror->total_amount);
        $this->assertSame(1,DB::table('fbr_pos_transaction_items')->where('transaction_id',$mirror->id)->count());
        DB::table('fbr_pos_transactions')->where('id',$mirror->id)->update(['fbr_status'=>'submitted','fbr_invoice_number'=>'203480SYN000001','fbr_response_code'=>'100']);
        HospitalPaymentImsService::reconcileReceipt($company->id,$payment);
        $saved=DB::table('hospital_payments')->find($payment);
        $this->assertSame('submitted',$saved->fbr_status);$this->assertSame('203480SYN000001',$saved->fbr_invoice_number);
        $this->assertSame('203480SYN000001',$saved->fbr_qr_payload);
        $again=HospitalPaymentImsService::queueFinalizedReceipt($company->id,$payment);
        $this->assertTrue($again['existing']);$this->assertSame($result['transaction_id'],$again['transaction_id']);
        $this->assertSame(1,DB::table('fbr_pos_transactions')->count());
    }
}
