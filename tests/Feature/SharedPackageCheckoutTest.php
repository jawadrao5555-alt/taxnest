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

    public function test_erp_proof_upload_respects_the_same_vertical_rules_as_checkout(): void
    {
        Mail::fake();
        Storage::fake('local');
        $this->withoutMiddleware();
        $company = Company::create(['name' => 'Fictional ERP shop', 'ntn' => 'ERPQA001', 'product_type' => 'erps']);
        $owner = User::create([
            'name' => 'Fictional owner', 'email' => 'erp-proof-owner@example.test',
            'password' => 'unused', 'role' => 'company_admin', 'company_id' => $company->id,
        ]);
        $plan = PricingPlan::create([
            'name' => 'Starter', 'product_type' => 'erps', 'invoice_limit' => 100, 'price' => 14999, 'is_public' => true,
        ]);
        $plan->forceFill(['erps_vertical' => 'unregistered-example'])->save();
        $this->actingAs($owner, 'health');
        $submit = fn () => $this->post(route('health.payment-proof.store'), [
            'pricing_plan_id' => $plan->id, 'billing_cycle' => 'annual', 'amount' => 14999,
            'payment_method' => 'bank', 'proof' => UploadedFile::fake()->create('fictional.pdf', 2, 'application/pdf'),
        ]);

        $submit()->assertSessionHasErrors('pricing_plan_id');
        $this->assertDatabaseCount('payment_proofs', 0);
        $this->assertSame([], Storage::disk('local')->allFiles());

        // The route test disables session middleware, so clear the previous response's flash data.
        session()->forget('errors');
        $plan->forceFill(['erps_vertical' => null])->save();
        $submit()->assertSessionHasNoErrors();
        $this->assertDatabaseHas('payment_proofs', [
            'company_id' => $company->id, 'pricing_plan_id' => $plan->id, 'status' => 'pending',
        ]);
        $this->assertDatabaseCount('subscription_payments', 0);
    }

    public function test_checkout_displays_and_submits_the_same_saved_monthly_price_after_catalogue_changes(): void
    {
        Mail::fake();
        Storage::fake('local');
        $this->withoutMiddleware();
        view()->share('errors', new \Illuminate\Support\ViewErrorBag);
        config(['billing.shared_configured_cycles' => true]);
        $company = Company::create(['name' => 'Fictional monthly shop', 'ntn' => 'MONTHLYQA', 'product_type' => 'pos']);
        $plan = PricingPlan::create([
            'name' => 'Business', 'product_type' => 'pos', 'price' => 24000,
            'price_monthly' => 2200, 'invoice_limit' => 1000, 'is_public' => true,
        ]);
        $admin = User::create([
            'name' => 'Fictional owner', 'email' => 'monthly-owner@example.test',
            'password' => 'unused', 'role' => 'company_admin', 'company_id' => $company->id,
        ]);
        $this->actingAs($admin, 'pos');
        app()->instance('currentCompanyId', $company->id);
        app()->instance('currentBranchGuard', 'pos');
        app()->instance('currentBranchId', null);
        $this->post(route('pos.package-checkout.select'), [
            'plan_id' => $plan->id, 'billing_cycle' => 'monthly',
        ])->assertRedirect();

        $plan->update(['price_monthly' => 2600]);
        $page = route('pos.package-checkout', ['plan_id' => $plan->id]);
        $this->get($page)->assertOk()->assertSee('Monthly · PKR 2,200.00')->assertDontSee('2,600.00');
        config(['billing.disable_fresh_monthly' => true]);
        $this->get($page)->assertOk()->assertSee('Monthly · PKR 2,200.00');
        $this->post(route('pos.payment-proof.store'), [
            'pricing_plan_id' => $plan->id, 'billing_cycle' => 'monthly', 'amount' => 2200,
            'payment_method' => 'bank', 'proof' => UploadedFile::fake()->create('fictional.pdf', 2, 'application/pdf'),
        ])->assertSessionHasNoErrors();
        $proof = PaymentProof::where('company_id', $company->id)->sole();
        $this->assertSame('monthly', $proof->billing_cycle);
        $this->assertEquals(2200, $proof->distributor_quote_snapshot['net_quote']);
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
