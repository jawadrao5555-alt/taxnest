<?php
namespace Tests\Feature;

use App\Models\Company;
use App\Services\PilotFiscalRouteSnapshot;
use App\Services\SchoolImsPaymentEvent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class SchoolImsPaymentEventTest extends TestCase
{
    use RefreshDatabase;

    private function company(string $category = 'education'): Company
    {
        return Company::create(['name'=>'Synthetic','ntn'=>uniqid(),'product_type'=>'fbrpos','pos_type'=>$category,'business_category'=>$category]);
    }

    private function receipt(Company $company, int $amount, int $paid): int
    {
        return DB::table('school_fee_receipts')->insertGetId(['company_id'=>$company->id,'collected_by'=>1,
            'request_key'=>(string) \Illuminate\Support\Str::uuid(),'student_reference'=>'SYN-PRIVATE-STUDENT',
            'student_name'=>'Synthetic private name','fee_head'=>'tuition','fee_lines'=>json_encode([['amount_paisa'=>1000000]]),
            'amount_paisa'=>$amount,'payable_paisa'=>1000000,'paid_paisa'=>$paid,'balance_paisa'=>1000000-$paid,
            'collected_at'=>now(),'payment_method'=>'cash',
            'fiscal_route_snapshot'=>PilotFiscalRouteSnapshot::newBill($company,'school','school_receipt')]);
    }

    public function test_full_and_split_receipts_prepare_received_amount_only_and_retry_keeps_identity(): void
    {
        Http::fake();$company=$this->company();
        $full=$this->receipt($company,1000000,1000000);
        $first=$this->receipt($company,400000,400000);
        $second=$this->receipt($company,600000,1000000);
        $before=DB::table('school_fee_receipts')->get()->toArray();$events=[];
        foreach ([$full=>1000000,$first=>400000,$second=>600000] as $id=>$amount) {
            $result=SchoolImsPaymentEvent::prepare($company->id,$id,'payment_finalized');
            $events[$id]=$result['product_event'];
            $this->assertSame($amount,$events[$id]['received_amount_paisa']);
            $this->assertSame($result,SchoolImsPaymentEvent::prepare($company->id,$id,'payment_finalized'));
            $this->assertFalse($result['can_dispatch']);$this->assertFalse($result['official_event_verified']);
            $this->assertNull($result['ims_payload']);$this->assertNull($result['fiscal_number']);$this->assertNull($result['fiscal_qr']);
            $this->assertSame('off',$result['routing']['route']);
            foreach (['fee_lines','payable_paisa','paid_paisa','balance_paisa','student_reference','student_name','account_id'] as $private) $this->assertArrayNotHasKey($private,$events[$id]);
            $this->assertStringNotContainsString('SYN-PRIVATE-STUDENT',json_encode($result));
        }
        $this->assertNotSame($events[$first]['local_identity'],$events[$second]['local_identity']);
        $this->assertSame(1000000,$events[$first]['received_amount_paisa']+$events[$second]['received_amount_paisa']);
        $this->assertEquals($before,DB::table('school_fee_receipts')->get()->toArray());Http::assertNothingSent();
    }

    public function test_draft_view_reprint_and_foreign_receipt_never_dispatch_or_create_an_event(): void
    {
        Http::fake();$company=$this->company();$foreign=$this->company();$id=$this->receipt($company,400000,400000);
        foreach (['draft','view','reprint','payment_retry',''] as $trigger) {
            $result=SchoolImsPaymentEvent::prepare($company->id,$id,$trigger);
            $this->assertNull($result['product_event']);$this->assertFalse($result['can_dispatch']);
        }
        foreach ([$foreign->id, $company->id] as $tenant) {
            try { SchoolImsPaymentEvent::prepare($tenant,$tenant===$company->id?PHP_INT_MAX:$id,'payment_finalized');$this->fail('Missing or foreign source accepted'); }
            catch (HttpException $e) { $this->assertSame(404,$e->getStatusCode()); }
        }
        $this->assertSame(1,DB::table('school_fee_receipts')->count());Http::assertNothingSent();
    }

    public function test_original_route_is_not_replaced_by_current_settings_and_unknown_ims_never_retries(): void
    {
        Http::fake();$company=$this->company();$id=$this->receipt($company,400000,400000);
        $snapshot=json_decode(DB::table('school_fee_receipts')->where('id',$id)->value('fiscal_route_snapshot'),true);
        $snapshot['route']='ims';$snapshot['eligible']=true; // synthetic historical uncertainty, not authorization
        DB::table('school_fee_receipts')->where('id',$id)->update(['fiscal_route_snapshot'=>json_encode($snapshot)]);
        DB::table('companies')->where('id',$company->id)->update(['fbr_reporting_enabled'=>false]);
        $result=SchoolImsPaymentEvent::prepare($company->id,$id,'payment_finalized');
        $this->assertSame('ims',$result['routing']['route']);
        $this->assertSame('reconciliation_required',$result['routing']['state']);
        $this->assertFalse($result['routing']['can_retry']);$this->assertFalse($result['can_dispatch']);
        $this->assertSame(400000,$result['product_event']['received_amount_paisa']);
        Http::assertNothingSent();
    }
}
