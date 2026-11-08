<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\PricingPlan;
use App\Models\Subscription;
use App\Services\SubscriptionAssignmentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class DiBillingCyclesTest extends TestCase
{
    use RefreshDatabase;

    public function test_configured_di_cycles_use_exact_prices_and_calendar_months_without_touching_another_tenant(): void
    {
        Http::preventStrayRequests();
        Mail::fake();
        $this->travelTo(\Carbon\Carbon::parse('2028-01-31 12:00:00', 'Asia/Karachi'));
        $plan = PricingPlan::create(['name' => 'Starter', 'product_type' => 'di', 'price' => 1000,
            'price_monthly' => 1000, 'price_quarterly' => 2970, 'price_semi_annual' => 5820,
            'price_yearly' => 11280, 'is_trial' => false, 'invoice_limit' => 100]);
        $other = Company::create(['name' => 'Unchanged buyer', 'ntn' => 'DICYCLEOTHER', 'product_type' => 'di']);
        $legacy = Subscription::create(['company_id' => $other->id, 'pricing_plan_id' => $plan->id,
            'billing_cycle' => 'monthly', 'final_price' => 777, 'active' => true,
            'start_date' => '2027-12-31', 'end_date' => '2028-01-31']);
        $before = $legacy->fresh()->getAttributes();
        foreach (['monthly' => [1000, '2028-02-29'], 'quarterly' => [2970, '2028-04-30'],
            'semi_annual' => [5820, '2028-07-31'], 'annual' => [11280, '2029-01-31']] as $cycle => [$price, $end]) {
            $company = Company::create(['name' => 'Cycle buyer '.$cycle, 'ntn' => 'DICYCLE'.$cycle, 'product_type' => 'di']);
            $sub = SubscriptionAssignmentService::assign($company->id, $plan->id, $cycle);
            $this->assertSame($cycle, $sub->billing_cycle);
            $this->assertEquals($price, $sub->final_price);
            $this->assertSame($end, $sub->end_date->toDateString());
            $this->assertSame($cycle, $sub->purchase_snapshot['price']['cycle']);
        }
        $this->assertSame($before, $legacy->fresh()->getAttributes());
        Http::assertNothingSent();
    }

    public function test_unconfigured_short_cycle_is_refused_without_creating_subscription(): void
    {
        $company = Company::create(['name' => 'Annual configured only', 'ntn' => 'DICYCLEONLY', 'product_type' => 'di']);
        $plan = PricingPlan::create(['name' => 'Starter', 'product_type' => 'di', 'price' => 1000,
            'price_yearly' => 11280, 'is_trial' => false, 'invoice_limit' => 100]);
        try {
            SubscriptionAssignmentService::assign($company->id, $plan->id, 'quarterly');
            $this->fail('Unconfigured cycle was sold.');
        } catch (\Illuminate\Validation\ValidationException $e) {
            $this->assertArrayHasKey('billing_cycle', $e->errors());
        }
        $this->assertDatabaseCount('subscriptions', 0);
    }

    public function test_reviewed_short_cycle_keeps_quoted_price_and_terms_after_catalogue_reprice_and_retry(): void
    {
        Mail::fake();
        Http::preventStrayRequests();
        $company = Company::create(['name' => 'Quoted DI buyer', 'ntn' => 'DICYCLEQUOTE', 'product_type' => 'di']);
        $plan = PricingPlan::create(['name' => 'Kaarobar', 'product_type' => 'di', 'price' => 1000,
            'price_quarterly' => 2970, 'invoice_limit' => 100, 'is_trial' => false]);
        $quote = \App\Services\DistributorDiscountService::quote($company, $plan, 'quarterly');
        $proof = \App\Models\PaymentProof::create(['company_id' => $company->id, 'pricing_plan_id' => $plan->id,
            'billing_cycle' => 'quarterly', 'amount' => 2970, 'status' => 'pending', 'proof_path' => 'synthetic-cycle-proof',
            'distributor_quote_snapshot' => $quote]);
        $plan->update(['price_quarterly' => 9999, 'invoice_limit' => 500]);
        $admin = \App\Models\AdminUser::create(['name' => 'Synthetic cycle reviewer', 'email' => 'cycle-review@example.test',
            'password' => bcrypt('SyntheticOnly123!'), 'role' => 'super_admin']);
        $input = ['pricing_plan_id' => $plan->id, 'billing_cycle' => 'quarterly', 'verified_received_amount' => 2970];
        $this->actingAs($admin, 'admin')->post(route('saas.admin.payment-proofs.approve', $proof->id), $input)->assertSessionHas('success');
        $sub = Subscription::findOrFail($proof->fresh()->subscription_id);
        $this->assertEquals(2970, $sub->final_price);
        $this->assertSame(100, $sub->entitlementPlan()->invoice_limit);
        $this->assertSame('quarterly', $sub->billing_cycle);
        $this->assertEquals(2970, $sub->purchase_snapshot['price']['final_price']);
        $invoice = \App\Models\SubscriptionInvoice::where('payment_proof_id', $proof->id)->sole();
        $this->assertEquals(2970, $invoice->amount);
        $this->post(route('saas.admin.payment-proofs.approve', $proof->id), $input)->assertRedirect();
        $this->assertSame(1, Subscription::where('company_id', $company->id)->count());
        $this->assertSame(1, $invoice->payments()->count());
    }
    public function test_current_plan_can_select_a_new_configured_period_without_replacing_paid_access(): void
    {
        Http::preventStrayRequests();
        Mail::fake();
        $company = Company::create(['name' => 'Synthetic renewal buyer', 'ntn' => 'DIRENEWPICK', 'product_type' => 'di',
            'status' => 'approved', 'company_status' => 'active', 'onboarding_completed' => true]);
        $plan = PricingPlan::create(['name' => 'Kaarobar', 'product_type' => 'di', 'price' => 1000,
            'price_quarterly' => 2970, 'price_yearly' => 11280, 'invoice_limit' => 100, 'is_trial' => false, 'is_public' => true]);
        $sub = SubscriptionAssignmentService::assign($company->id, $plan->id, 'annual');
        $before = $sub->fresh()->getAttributes();
        $owner = \App\Models\User::create(['name' => 'Synthetic renewal owner', 'email' => 'cycle-owner@example.test',
            'password' => 'unused', 'role' => 'company_admin', 'company_id' => $company->id, 'is_active' => true]);
        $this->actingAs($owner)->get('/billing/plans')->assertOk()->assertSee('Select period &amp; upload renewal receipt', false);
        $this->post('/billing/subscribe', ['plan_id' => $plan->id, 'billing_cycle' => 'quarterly'])
            ->assertSessionHasNoErrors()->assertRedirect('/billing/plans');
        $this->get('/billing/plans')->assertOk()->assertSee('value="quarterly"', false)->assertSee('Quarterly package total');
        $this->assertSame('quarterly', session(\App\Services\PendingSubscriptionSelection::key($company->id))['quote']['cycle']);
        $this->assertSame($before, $sub->fresh()->getAttributes());
        $this->assertSame(1, Subscription::where('company_id', $company->id)->count());
        Http::assertNothingSent();
    }

}
