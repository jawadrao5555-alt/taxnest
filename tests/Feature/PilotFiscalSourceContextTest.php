<?php
namespace Tests\Feature;

use App\Models\Company;
use App\Services\PilotFiscalSourceContext;
use App\Services\PilotFiscalRouteSnapshot;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class PilotFiscalSourceContextTest extends TestCase
{
    use RefreshDatabase;

    public function test_school_charge_payment_and_saved_balance_are_distinct_without_payload_or_network(): void
    {
        Http::fake();
        $company = Company::create(['name'=>'Synthetic','ntn'=>uniqid(),'product_type'=>'fbrpos','pos_type'=>'education','business_category'=>'education']);
        $id = DB::table('school_fee_receipts')->insertGetId(['company_id'=>$company->id,'collected_by'=>1,
            'request_key'=>(string) \Illuminate\Support\Str::uuid(),'student_reference'=>'SYN','fee_head'=>'tuition',
            'amount_paisa'=>30000,'payable_paisa'=>100000,'paid_paisa'=>50000,'balance_paisa'=>50000,
            'fee_lines'=>json_encode([['fee_head'=>'tuition','amount_paisa'=>100000]]),'collected_at'=>now(),
            'fiscal_route_snapshot'=>PilotFiscalRouteSnapshot::newBill($company,'school','school_receipt')]);
        $before = DB::table('school_fee_receipts')->find($id);
        $context = PilotFiscalSourceContext::inspect($company->id,'school','school_receipt',$id);
        $this->assertSame(['charge_paisa'=>100000,'current_payment_paisa'=>30000,'paid_at_receipt_paisa'=>50000,'balance_at_receipt_paisa'=>50000],$context['amounts']);
        $this->assertSame(100000,$context['local_lines'][0]['amount_paisa']);
        $this->assertNull($context['regulator_payload']);$this->assertNull($context['reportable_event']);
        $this->assertFalse($context['can_dispatch']);$this->assertSame('off',$context['routing']['route']);
        $this->assertEquals($before,DB::table('school_fee_receipts')->find($id));
        Http::assertNothingSent();
        $this->expectException(\Symfony\Component\HttpKernel\Exception\HttpException::class);
        PilotFiscalSourceContext::inspect($company->id+1,'school','school_receipt',$id);
    }

    public function test_hospital_bill_is_not_an_installment_and_later_payment_does_not_rewrite_earlier_balance(): void
    {
        Http::fake();
        $company = Company::create(['name'=>'Synthetic','ntn'=>uniqid(),'product_type'=>'fbrpos','pos_type'=>'hospital','business_category'=>'hospital']);
        $raw = PilotFiscalRouteSnapshot::newBill($company,'hospital','hospital_invoice');
        $id = DB::table('hospital_invoices')->insertGetId(['company_id'=>$company->id,'request_key'=>(string) \Illuminate\Support\Str::uuid(),
            'patient_reference'=>'SYN','service_head'=>'consultation','total_paisa'=>100000,'created_by'=>1,'campus_name'=>'Synthetic','fiscal_route_snapshot'=>$raw]);
        $payments = [];
        foreach ([[30000,70000],[70000,0]] as [$amount,$balance]) {
            $payments[] = DB::table('hospital_payments')->insertGetId(['company_id'=>$company->id,'invoice_id'=>$id,
                'request_key'=>(string) \Illuminate\Support\Str::uuid(),'amount_paisa'=>$amount,'balance_paisa'=>$balance,
                'payment_method'=>'cash','collected_by'=>1,'cashier_name'=>'Synthetic','campus_name'=>'Synthetic','collected_at'=>now(),'fiscal_route_snapshot'=>$raw]);
        }
        $bill = PilotFiscalSourceContext::inspect($company->id,'hospital','hospital_invoice',$id);
        $this->assertNull($bill['amounts']['current_payment_paisa']);
        $first = PilotFiscalSourceContext::inspect($company->id,'hospital','hospital_payment',$payments[0]);
        $this->assertSame(['charge_paisa'=>100000,'current_payment_paisa'=>30000,'paid_at_receipt_paisa'=>30000,'balance_at_receipt_paisa'=>70000],$first['amounts']);
        $last = PilotFiscalSourceContext::inspect($company->id,'hospital','hospital_payment',$payments[1]);
        $this->assertSame(0,$last['amounts']['balance_at_receipt_paisa']);
        foreach ([$bill,$first,$last] as $context) {
            $this->assertFalse($context['can_dispatch']);$this->assertNull($context['regulator_payload']);
            $this->assertContains('category_reportable_event_unverified',$context['blockers']);
        }
        Http::assertNothingSent();
    }
}
