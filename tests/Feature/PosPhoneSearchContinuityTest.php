<?php
namespace Tests\Feature;

use App\Models\{Company, PosCustomer, PosTransaction, User};
use App\Services\PosFeatureService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;
use Illuminate\Support\Facades\DB;

class PosPhoneSearchContinuityTest extends TestCase
{
    use RefreshDatabase;

    private function shop(): array
    {
        $company = Company::create(['name'=>'Synthetic B03 Shop','ntn'=>uniqid(),'email'=>uniqid().'@example.test','product_type'=>'pos','status'=>'approved','company_status'=>'active','is_internal_account'=>true,'pos_integration_mode'=>'pra']);
        $user = User::create(['name'=>'Synthetic Owner','email'=>uniqid().'@example.test','password'=>bcrypt('SyntheticOnly123!'),'company_id'=>$company->id,'role'=>'company_admin','pos_role'=>'pos_admin','is_active'=>true]);
        PosFeatureService::flushGateCaches();
        return [$company,$user];
    }

    public function test_lookup_and_create_reuse_equivalent_phone_identity_without_touching_another_tenant(): void
    {
        [$company,$owner]=$this->shop(); [$other]=$this->shop();
        $customer=PosCustomer::create(['company_id'=>$company->id,'name'=>'Synthetic Contact','phone'=>'0300-1234567','is_active'=>true]);
        $foreign=PosCustomer::create(['company_id'=>$other->id,'name'=>'Other Contact','phone'=>'+92 300 1234567','is_active'=>true]);
        $this->actingAs($owner,'pos')->getJson('/pos/restaurant/api/customer-lookup?'.http_build_query(['phone'=>'+92 300 1234567']))->assertOk()->assertJsonPath('found',true)->assertJsonPath('customer.id',$customer->id);
        $this->actingAs($owner,'pos')->postJson('/pos/restaurant/api/customer-store',['name'=>'Do not duplicate','phone'=>'0092 300 1234567'])->assertOk()->assertJsonPath('customer.id',$customer->id);
        $this->assertSame(1,PosCustomer::where('company_id',$company->id)->count());
        $this->assertSame('Other Contact',$foreign->fresh()->name);
    }

    public function test_search_combines_phone_business_day_order_and_payment_filters_before_the_limit(): void
    {
        [$company,$owner]=$this->shop();
        $day=\App\Services\PosBusinessDay::current($company->id);
        foreach ([['B03-WANTED','0300-1234567','cash','delivery'],['B03-CARD','03001234567','card','delivery'],['B03-OTHER','03111234567','cash','delivery'],['B03-TABLE','03001234567','cash','dine_in']] as [$number,$phone,$method,$type]) {
            PosTransaction::create(['company_id'=>$company->id,'invoice_number'=>$number,'invoice_mode'=>'pra','pra_status'=>null,'customer_name'=>'Synthetic Buyer','customer_phone'=>$phone,'total_amount'=>100,'payment_method'=>$method,'order_type'=>$type,'status'=>'completed','business_date'=>$day,'created_by'=>$owner->id]);
        }
        $response=$this->actingAs($owner,'pos')->getJson('/pos/api/todays-bills?'.http_build_query(['date'=>$day,'search'=>'+92 300 1234567','order_type'=>'delivery','payment_method'=>'cash']))->assertOk();
        $rows=$response->json('bills');
        $this->assertSame(['B03-WANTED'],array_column($rows,'invoice_number'));
        $this->actingAs($owner,'pos')->get('/pos/transactions?'.http_build_query(['tab'=>'local','search'=>'+92 300 1234567','payment_method'=>'cash','order_type'=>'delivery']))->assertOk()->assertSee('B03-WANTED')->assertDontSee('B03-OTHER');
    }
    public function test_cache_lock_failure_uses_database_serialization_instead_of_splitting_identity(): void
    {
        [$company,$owner]=$this->shop();
        $existing=PosCustomer::create(['company_id'=>$company->id,'name'=>'Synthetic Existing','phone'=>'0300-1234567','is_active'=>true]);
        \Illuminate\Support\Facades\Cache::partialMock()->shouldReceive('lock')->andThrow(new \RuntimeException('Synthetic unavailable cache'));
        $this->actingAs($owner,'pos')->postJson('/pos/restaurant/api/customer-store',['phone'=>'+92 300 1234567'])->assertOk()->assertJsonPath('customer.id',$existing->id);
        $this->assertSame(1,PosCustomer::where('company_id',$company->id)->count());
    }

    public function test_server_search_finds_a_phone_older_than_the_first_300_rows_and_labels_a_bounded_result(): void
    {
        [$company,$owner]=$this->shop();
        $day=\App\Services\PosBusinessDay::current($company->id);
        $wanted=PosTransaction::create(['company_id'=>$company->id,'invoice_number'=>'B03-OLDER-MATCH','invoice_mode'=>'pra','pra_status'=>null,'customer_name'=>'Synthetic Older Buyer','customer_phone'=>'0300-1234567','total_amount'=>11100,'payment_method'=>'cash','order_type'=>'delivery','status'=>'completed','business_date'=>$day,'created_by'=>$owner->id]);
        $wanted->forceFill(['bill_token'=>21100])->saveQuietly();
        $attrs=$wanted->getAttributes();unset($attrs['id']);$rows=[];
        for($i=0;$i<305;$i++)$rows[]=array_replace($attrs,['invoice_number'=>'B03-NEWER-'.$i,'customer_phone'=>'03111234567','total_amount'=>200,'bill_token'=>$i+1]);
        DB::table('pos_transactions')->insert($rows);
        $this->actingAs($owner,'pos')->getJson('/pos/api/todays-bills?'.http_build_query(['date'=>$day,'search'=>'+92 300 1234567']))->assertOk()->assertJsonCount(1,'bills')->assertJsonPath('bills.0.id',$wanted->id)->assertJsonPath('bills.0.customer_phone','0300-1234567')->assertJsonPath('has_more',false);
        $this->getJson('/pos/api/todays-bills?'.http_build_query(['date'=>$day,'search'=>'1100']))->assertOk()->assertJsonCount(1,'bills')->assertJsonPath('bills.0.id',$wanted->id);
        $this->getJson('/pos/api/todays-bills?'.http_build_query(['date'=>$day,'search'=>'2110']))->assertOk()->assertJsonCount(1,'bills')->assertJsonPath('bills.0.id',$wanted->id);
        $this->getJson('/pos/api/todays-bills?date='.$day)->assertOk()->assertJsonCount(300,'bills')->assertJsonPath('has_more',true);
    }

    public function test_foreign_country_suffix_and_malformed_search_do_not_select_another_identity(): void
    {
        [$company,$owner]=$this->shop();
        PosCustomer::create(['company_id'=>$company->id,'name'=>'Synthetic Existing','phone'=>'0300-1234567','is_active'=>true]);
        $this->actingAs($owner,'pos')->getJson('/pos/restaurant/api/customer-lookup?'.http_build_query(['phone'=>'+44 3001234567']))->assertOk()->assertJsonPath('found',false);
        $this->getJson('/pos/restaurant/api/customer-lookup?phone[]=bad')->assertUnprocessable();
        $this->getJson('/pos/restaurant/api/customer-search?q[]=bad')->assertUnprocessable();
    }

    public function test_legacy_duplicate_profiles_are_not_merged_or_reassigned_when_an_exact_phone_is_supplied(): void
    {
        [$company,$owner]=$this->shop();
        $first=PosCustomer::create(['company_id'=>$company->id,'name'=>'Synthetic First','phone'=>'0300-1234567','is_active'=>true]);
        $exact=PosCustomer::create(['company_id'=>$company->id,'name'=>'Synthetic Exact','phone'=>'+92 300 1234567','is_active'=>true]);
        $this->actingAs($owner,'pos')->getJson('/pos/restaurant/api/customer-lookup?'.http_build_query(['phone'=>'+92 300 1234567']))->assertOk()->assertJsonPath('customer.id',$exact->id);
        $this->postJson('/pos/restaurant/api/customer-store',['phone'=>'+92 300 1234567'])->assertOk()->assertJsonPath('customer.id',$exact->id);
        $this->assertSame(2,PosCustomer::where('company_id',$company->id)->count());
        $this->assertSame('Synthetic First',$first->fresh()->name);
    }

}
