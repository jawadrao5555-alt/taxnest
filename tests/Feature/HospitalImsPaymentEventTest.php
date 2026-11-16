<?php
namespace Tests\Feature;

use App\Models\Company;
use App\Services\PilotFiscalRouteSnapshot;
use App\Services\HospitalImsPaymentEvent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class HospitalImsPaymentEventTest extends TestCase
{
    use RefreshDatabase;

    private function company(): Company
    {
        return Company::create(['name'=>'Synthetic','ntn'=>uniqid(),'product_type'=>'fbrpos','pos_type'=>'hospital','business_category'=>'hospital']);
    }

    private function invoice(Company $company): int
    {
        return DB::table('hospital_invoices')->insertGetId(['company_id'=>$company->id,'request_key'=>(string) \Illuminate\Support\Str::uuid(),
            'patient_reference'=>'SYN-PRIVATE-PATIENT','patient_name'=>'Synthetic private name','service_head'=>'consultation',
            'service_lines'=>json_encode([['amount_paisa'=>2000000]]),'total_paisa'=>2000000,'created_by'=>1,'campus_name'=>'Synthetic',
            'fiscal_route_snapshot'=>PilotFiscalRouteSnapshot::newBill($company,'hospital','hospital_invoice')]);
    }

    private function payment(Company $company, int $invoiceId, int $amount, int $balance): int
    {
        return DB::table('hospital_payments')->insertGetId(['company_id'=>$company->id,'invoice_id'=>$invoiceId,'collected_by'=>1,
            'request_key'=>(string) \Illuminate\Support\Str::uuid(),'amount_paisa'=>$amount,'balance_paisa'=>$balance,
            'cashier_name'=>'Synthetic','campus_name'=>'Synthetic','collected_at'=>now(),'payment_method'=>'cash',
            'fiscal_route_snapshot'=>DB::table('hospital_invoices')->where('id',$invoiceId)->value('fiscal_route_snapshot')]);
    }

    public function test_full_and_split_payment_receipts_keep_received_amount_and_private_ledger_separate(): void
    {
        Http::fake();$company=$this->company();$fullInvoice=$this->invoice($company);$splitInvoice=$this->invoice($company);
        $full=$this->payment($company,$fullInvoice,2000000,0);
        $first=$this->payment($company,$splitInvoice,800000,1200000);
        $second=$this->payment($company,$splitInvoice,1200000,0);
        $before=DB::table('hospital_payments')->get()->toArray();$invoices=DB::table('hospital_invoices')->get()->toArray();$events=[];
        foreach ([$full=>2000000,$first=>800000,$second=>1200000] as $id=>$amount) {
            $result=HospitalImsPaymentEvent::prepare($company->id,$id,'payment_finalized');$events[$id]=$result['product_event'];
            $this->assertSame($amount,$events[$id]['received_amount_paisa']);
            $this->assertSame($result,HospitalImsPaymentEvent::prepare($company->id,$id,'payment_finalized'));
            $this->assertFalse($result['can_dispatch']);$this->assertFalse($result['official_event_verified']);
            $this->assertNull($result['ims_payload']);$this->assertNull($result['fiscal_number']);$this->assertNull($result['fiscal_qr']);
            $this->assertSame('off',$result['routing']['route']);
            foreach (['service_lines','total_paisa','paid_paisa','balance_paisa','patient_reference','patient_name','invoice_id'] as $private) $this->assertArrayNotHasKey($private,$events[$id]);
            $this->assertStringNotContainsString('SYN-PRIVATE-PATIENT',json_encode($result));
        }
        $this->assertNotSame($events[$first]['local_identity'],$events[$second]['local_identity']);
        $this->assertSame(2000000,$events[$first]['received_amount_paisa']+$events[$second]['received_amount_paisa']);
        $this->assertSame(0,(int) DB::table('hospital_payments')->where('id',$second)->value('balance_paisa'));
        $this->assertEquals($before,DB::table('hospital_payments')->get()->toArray());
        $this->assertEquals($invoices,DB::table('hospital_invoices')->get()->toArray());Http::assertNothingSent();
    }

    public function test_draft_view_reprint_and_foreign_payment_are_not_dispatch_triggers(): void
    {
        Http::fake();$company=$this->company();$foreign=$this->company();$invoice=$this->invoice($company);$id=$this->payment($company,$invoice,800000,1200000);
        foreach (['draft','view','reprint','payment_retry',''] as $trigger) {
            $result=HospitalImsPaymentEvent::prepare($company->id,$id,$trigger);
            $this->assertNull($result['product_event']);$this->assertFalse($result['can_dispatch']);
        }
        foreach ([$foreign->id,$company->id] as $tenant) {
            try { HospitalImsPaymentEvent::prepare($tenant,$tenant===$company->id?PHP_INT_MAX:$id,'payment_finalized');$this->fail('Missing/foreign payment accepted'); }
            catch (HttpException $e) { $this->assertSame(404,$e->getStatusCode()); }
        }
        Http::assertNothingSent();
    }

    public function test_unknown_ims_keeps_original_parent_route_without_blind_retry(): void
    {
        Http::fake();$company=$this->company();$invoice=$this->invoice($company);
        $snapshot=json_decode(DB::table('hospital_invoices')->where('id',$invoice)->value('fiscal_route_snapshot'),true);
        $snapshot['route']='ims';$snapshot['eligible']=true; // synthetic uncertainty, never real authorization
        DB::table('hospital_invoices')->where('id',$invoice)->update(['fiscal_route_snapshot'=>json_encode($snapshot)]);
        $id=$this->payment($company,$invoice,800000,1200000);
        DB::table('companies')->where('id',$company->id)->update(['fbr_reporting_enabled'=>false]);
        $result=HospitalImsPaymentEvent::prepare($company->id,$id,'payment_finalized');
        $this->assertSame('ims',$result['routing']['route']);$this->assertSame('reconciliation_required',$result['routing']['state']);
        $this->assertFalse($result['routing']['can_retry']);$this->assertFalse($result['can_dispatch']);
        $this->assertSame(800000,$result['product_event']['received_amount_paisa']);Http::assertNothingSent();
    }
}
