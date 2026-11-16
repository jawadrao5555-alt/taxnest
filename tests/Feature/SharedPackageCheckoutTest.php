<?php
namespace Tests\Feature;
use App\Models\{Company,PricingPlan,User,PaymentProof};
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\{Mail,Storage};
use Illuminate\Http\UploadedFile;
use Tests\TestCase;

class SharedPackageCheckoutTest extends TestCase
{
    use RefreshDatabase;

    public function test_two_logged_in_guards_keep_quote_and_upload_on_originating_company(): void
    {
        Mail::fake(); Storage::fake('local');
        config(['billing.shared_configured_cycles'=>true]);
        $this->withoutMiddleware();
        view()->share('errors', new \Illuminate\Support\ViewErrorBag);
        foreach (['pos','fbrpos','health'] as $guard) {
            $company=Company::create(['name'=>'Synthetic '.$guard,'ntn'=>'CHECKOUT'.$guard,'product_type'=>$guard === 'health' ? 'erps' : $guard,'pos_integration_mode'=>'pra']);
            $plan=PricingPlan::create(['name'=>'Starter','product_type'=>$guard === 'health' ? 'erps' : $guard,'invoice_limit'=>800,'price'=>14999,'price_quarterly'=>4299,'is_public'=>true]);
            $user=User::create(['name'=>'Owner','email'=>$guard.'-checkout@example.test','password'=>'unused','role'=>'company_admin','company_id'=>$company->id]);
            $term=\App\Services\SubscriptionAssignmentService::assign($company->id,$plan->id,'annual');
            $paidBefore[$company->id]=$term->fresh()->getAttributes();
            $this->actingAs($user,$guard);
            $fixtures[$guard]=[$company,$plan,$user];
        }
        foreach ($fixtures as $guard=>[$company,$plan,$user]) {
            app()->instance('currentCompanyId',$company->id);
            app()->instance('currentBranchGuard',$guard);
            app()->instance('currentBranchId',null);
            $this->post(route($guard.'.package-checkout.select'),['plan_id'=>$plan->id,'billing_cycle'=>'quarterly'])->assertRedirect();
            $this->get(route($guard.'.package-checkout',['plan_id'=>$plan->id]))->assertOk()->assertSee('4,299.00')->assertSee('Submit receipt for review');
            $quote=session(\App\Services\PendingSubscriptionSelection::key($company->id))['quote'];
            $this->assertEquals(4299,$quote['net_quote']);
            $this->post(route($guard.'.payment-proof.store'),['pricing_plan_id'=>$plan->id,'billing_cycle'=>'quarterly','amount'=>4299,
                'payment_method'=>'bank','proof'=>UploadedFile::fake()->create('synthetic.pdf',2,'application/pdf')])->assertSessionHasNoErrors();
            $proof=PaymentProof::where('company_id',$company->id)->sole();
            $this->assertSame('quarterly',$proof->billing_cycle);
            $this->assertSame($plan->id,$proof->pricing_plan_id);
        }
        $this->assertDatabaseCount('subscriptions',3);
        foreach ($paidBefore as $id=>$before) $this->assertSame($before,\App\Models\Subscription::where('company_id',$id)->sole()->getAttributes());
        $this->assertDatabaseCount('subscription_payments',0);
    }

    public function test_checkout_rejects_cashier_wrong_product_and_unconfigured_period(): void
    {
        $this->withoutMiddleware();
        $company=Company::create(['name'=>'Synthetic FBR','ntn'=>'CHECKOUTDENY','product_type'=>'fbrpos']);
        $plan=PricingPlan::create(['name'=>'Starter','product_type'=>'fbrpos','invoice_limit'=>800,'price'=>14999,'is_public'=>true]);
        $user=User::create(['name'=>'Cashier','email'=>'cashier-checkout@example.test','password'=>'unused','role'=>'user','company_id'=>$company->id]);
        $this->actingAs($user,'fbrpos')->post(route('fbrpos.package-checkout.select'),['plan_id'=>$plan->id,'billing_cycle'=>'annual'])->assertForbidden();
        $user->update(['role'=>'company_admin']);
        $this->actingAs($user->fresh(),'fbrpos')->post(route('fbrpos.package-checkout.select'),['plan_id'=>$plan->id,'billing_cycle'=>'quarterly'])->assertSessionHasErrors('billing_cycle');
        $other=PricingPlan::create(['name'=>'Starter','product_type'=>'pos','invoice_limit'=>800,'price'=>14999]);
        $this->post(route('fbrpos.package-checkout.select'),['plan_id'=>$other->id,'billing_cycle'=>'annual'])->assertSessionHasErrors('pricing_plan_id');
        $this->assertDatabaseCount('payment_proofs',0);
        $this->assertDatabaseCount('subscriptions',0);
    }
}
