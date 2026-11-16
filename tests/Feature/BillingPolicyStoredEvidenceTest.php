<?php
namespace Tests\Feature;
use App\Models\{Company,PricingPlan,Subscription};
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\{Blade,DB};
use Tests\TestCase;
class BillingPolicyStoredEvidenceTest extends TestCase
{
    use RefreshDatabase;
    public function test_stored_json_null_is_unavailable_but_sql_null_remains_legacy():void
    {
        $company=Company::create(['name'=>'Synthetic policy UI','ntn'=>'UI-POLICY','product_type'=>'di']);
        $plan=PricingPlan::create(['name'=>'Business','product_type'=>'di','price'=>1000,'invoice_limit'=>2]);
        $subscription=Subscription::create(['company_id'=>$company->id,'pricing_plan_id'=>$plan->id,'active'=>true,'start_date'=>'2028-01-01','end_date'=>'2029-01-01']);
        foreach(['null',null] as $raw){
            DB::table('subscriptions')->where('id',$subscription->id)->update(['lifecycle_snapshot'=>$raw]);
            $policySubscription=$subscription->fresh();
            self::assertNull($policySubscription->lifecycle_snapshot);
            self::assertSame($raw!==null,$policySubscription->hasLifecycleSnapshot());
            foreach(['en','ur','rur']as$locale){
                app()->setLocale($locale);
                $html=Blade::render("@include('billing._policy-status')",compact('policySubscription'));
                self::assertStringContainsString('data-policy-version="'.($raw===null?'legacy':'unavailable').'"',$html);
                self::assertStringNotContainsString('data-policy-version="'.($raw===null?'unavailable':'legacy').'"',$html);
                self::assertStringNotContainsString('<time ',$html);
            }
        }
    }
}
