<?php
namespace Tests\Feature;

use App\Models\{Company,PricingPlan,Subscription};
use App\Services\{SubscriptionAssignmentService,DistributorDiscountService};
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/** Existing annual contracts stay unchanged; configured short periods require policy activation. */
class PosAnnualOnlyPricingTest extends TestCase
{
    use RefreshDatabase;

    public function test_shared_cycles_require_opt_in_and_never_silently_fall_back(): void
    {
        foreach (['pos','fbrpos','health'] as $type) {
            $plan = new PricingPlan(['name'=>'Starter','product_type'=>$type,'price'=>14999,'price_quarterly'=>4299]);
            $this->assertSame(['annual'], SubscriptionAssignmentService::cyclesForPlan($plan));
            $this->assertEquals(14999,SubscriptionAssignmentService::computePrice($plan,'annual')['final_price']);
            try { SubscriptionAssignmentService::computePrice($plan,'quarterly'); $this->fail('Unactivated cycle sold'); }
            catch (ValidationException $e) { $this->assertArrayHasKey('billing_cycle',$e->errors()); }
        }
    }

    public function test_configured_cycles_use_exact_rates_and_calendar_months_and_leave_other_purchases_untouched(): void
    {
        config(['billing.shared_configured_cycles'=>true]);
        $this->travelTo(\Carbon\Carbon::parse('2028-01-31 12:00:00'));
        foreach (['pos','fbrpos','health'] as $type) {
            $plan=PricingPlan::create(['name'=>'Starter','product_type'=>$type,'price'=>14999,
                'price_monthly'=>1549,'price_quarterly'=>4299,'price_semi_annual'=>8199,'invoice_limit'=>800]);
            $before=$plan->fresh()->getAttributes();
            foreach (['monthly'=>[1549,'2028-02-29'],'quarterly'=>[4299,'2028-04-30'],
                'semi_annual'=>[8199,'2028-07-31'],'annual'=>[14999,'2029-01-31']] as $cycle=>[$amount,$end]) {
                $company=Company::create(['name'=>'Synthetic buyer','ntn'=>'CYCLE'.$type.$cycle,'product_type'=>$type]);
                $sub=SubscriptionAssignmentService::assign($company->id,$plan->id,$cycle);
                $this->assertSame($cycle,$sub->billing_cycle);
                $this->assertEquals($amount,$sub->final_price);
                $this->assertSame($end,$sub->end_date->toDateString());
                $this->assertSame(800,$sub->entitlementPlan()->invoice_limit);
            }
            $this->assertSame($before,$plan->fresh()->getAttributes());
        }
    }

    public function test_missing_or_zero_short_rate_is_hidden_and_rejected(): void
    {
        config(['billing.shared_configured_cycles'=>true]);
        $plan=new PricingPlan(['product_type'=>'fbrpos','price'=>14999,'price_monthly'=>0]);
        $this->assertSame(['annual'],SubscriptionAssignmentService::cyclesForPlan($plan));
        foreach (['monthly','quarterly','semi_annual','garbage'] as $cycle) {
            try { SubscriptionAssignmentService::computePrice($plan,$cycle); $this->fail('Unpriced cycle sold'); }
            catch (ValidationException $e) { $this->assertArrayHasKey('billing_cycle',$e->errors()); }
        }
    }

    public function test_monthly_frozen_quote_survives_policy_and_catalogue_changes(): void
    {
        config(['billing.shared_configured_cycles'=>true]);
        $company=Company::create(['name'=>'Frozen buyer','ntn'=>'FROZENMONTH','product_type'=>'fbrpos']);
        $plan=PricingPlan::create(['name'=>'Starter','product_type'=>'fbrpos','price'=>14999,'price_monthly'=>1549,'invoice_limit'=>800]);
        $quote=DistributorDiscountService::quote($company,$plan,'monthly');
        $plan->update(['price_monthly'=>null,'invoice_limit'=>900]);
        config(['billing.disable_fresh_monthly'=>true,'billing.shared_configured_cycles'=>false]);
        $this->assertNotContains('monthly',SubscriptionAssignmentService::cyclesForPlan($plan));
        $proof=\App\Models\PaymentProof::create(['company_id'=>$company->id,'pricing_plan_id'=>$plan->id,
            'billing_cycle'=>'monthly','amount'=>1549,'status'=>'pending','proof_path'=>'synthetic-only',
            'distributor_quote_snapshot'=>$quote]);
        $admin=\App\Models\AdminUser::create(['name'=>'Synthetic reviewer','email'=>'frozen@example.test','password'=>'unused','role'=>'super_admin']);
        $input=['pricing_plan_id'=>$plan->id,'billing_cycle'=>'monthly','verified_received_amount'=>1549];
        $this->actingAs($admin,'admin')->post(route('saas.admin.payment-proofs.approve',$proof->id),$input)->assertSessionHas('success');
        $sub=Subscription::findOrFail($proof->fresh()->subscription_id);
        $this->post(route('saas.admin.payment-proofs.approve',$proof->id),$input)->assertRedirect();
        $this->assertSame(1,Subscription::where('company_id',$company->id)->count());
        $this->assertEquals(1549,$sub->final_price);
        $this->assertSame('monthly',$sub->billing_cycle);
        $this->assertSame(800,$sub->entitlementPlan()->invoice_limit);
    }
}
