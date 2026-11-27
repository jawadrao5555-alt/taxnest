<?php

namespace Tests\Feature;

use App\Models\{AdminUser, Company, PaymentProof, PricingPlan, SignupPackageRequest, Subscription, SubscriptionInvoice, SystemSetting, User};
use App\Support\NestErps;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\{Mail, Notification, Storage};
use Illuminate\Support\ViewErrorBag;
use Tests\TestCase;

final class ErpPublicPricingTest extends TestCase
{
    use RefreshDatabase;

    public function test_erp_hub_has_working_product_sections_and_assisted_move_enquiry(): void
    {
        $this->withoutVite();
        config(['health.registration_open' => false]);
        SystemSetting::set('contact_email', 'erp-team@example.test');
        SystemSetting::set('support_whatsapp_number', '03001234567');

        $this->get('/')->assertOk()->assertSee(NestErps::LANDING_PATH);
        foreach (['/nest-erps', '/healthcare'] as $route) {
            $this->get($route)->assertOk()
                ->assertSee('id="hospital-erp"', false)->assertSee('id="lab-erp"', false)->assertSee('id="school-erp"', false)
                ->assertSee('id="pricing"', false)->assertSee('id="erp-upgrade"', false)->assertSee(route('health.login'))
                ->assertSee(route('contact', ['topic' => 'erp-upgrade']))->assertDontSee('/health/register');
        }

        $this->get('/contact?topic=erp-upgrade')->assertOk()->assertSee('Plan your ERP move')
            ->assertSee('mailto:erp-team@example.test?subject=TaxNest%20ERP%20upgrade%20enquiry', false)
            ->assertSee(urlencode('Hello, I would like to plan a move from my current TaxNest product to TaxNest ERP.'), false);
        $this->get('/contact?topic=unrecognised-enquiry')->assertOk()->assertSee('Contact & Legal')
            ->assertDontSee('Plan your ERP move')->assertDontSee('unrecognised-enquiry');
    }

    public function test_billing_admin_can_publish_and_hide_exact_erp_prices_without_opening_pre_pilot_signup(): void
    {
        $this->withoutVite();
        config(['billing.shared_configured_cycles' => true, 'health.registration_open' => false]);
        $admin = $this->admin();
        $fields = $this->planFields();
        $this->actingAs($admin, 'admin')->post(route('saas.admin.plans.store'), $fields)
            ->assertSessionHasNoErrors();
        $plan = PricingPlan::where('name', $fields['name'])->sole();
        $this->assertSame(['opd'], $plan->health_modules);
        $this->assertSame(3, $plan->branch_limit);
        $this->get('/nest-erps')->assertOk()->assertDontSee($plan->name);

        $published = array_replace($fields, ['erp_website_price_visible' => 1]);
        $this->put(route('saas.admin.plans.update', $plan->id), $published)
            ->assertSessionHasNoErrors();
        $draft = PricingPlan::create($published + ['is_trial' => false]);
        $draft->forceFill(['name' => 'Unreleased ERP offer', 'erps_vertical' => 'unreleased', 'price' => 49999])->save();
        $this->get('/nest-erps')->assertOk()->assertSee($plan->name)->assertSee('Branches: 3')
            ->assertSee('36,000.75')->assertSee('9,999.50')->assertDontSee('Unreleased ERP offer')
            ->assertDontSee('/health/register');

        $this->put(route('saas.admin.plans.update', $plan->id), array_replace($published, ['price' => 0]))
            ->assertSessionHasErrors('erp_website_price_visible');
        $this->put(route('saas.admin.plans.update', $plan->id), $fields)->assertSessionHasNoErrors();
        $this->get('/nest-erps')->assertOk()->assertDontSee($plan->name)->assertSee('Request a quote');
        $this->assertSame('36000.75', $plan->fresh()->price);
        $this->assertTrue($plan->fresh()->is_public);
    }

    public function test_public_package_selection_stores_the_exact_plan_and_period_in_the_signup_quote(): void
    {
        $this->withoutVite();
        Mail::fake();
        Notification::fake();
        config(['billing.shared_configured_cycles' => true, 'health.registration_open' => true]);
        $plan = PricingPlan::create(array_replace($this->planFields(), ['erp_website_price_visible' => true]));
        $this->get('/nest-erps')->assertOk()->assertSee('/health/register?plan_id='.$plan->id.'&billing_cycle=quarterly');
        $this->get('/health/register?plan_id='.$plan->id.'&billing_cycle=quarterly')->assertOk()
            ->assertSee('9,999.50')->assertSee('name="pricing_plan_id"', false);

        $email = 'erp-selected-owner@example.test';
        $this->post('/health/register', [
            'company_name' => 'Synthetic ERP customer', 'company_ntn' => '8790021', 'company_cnic' => '3520212345621',
            'name' => 'Synthetic owner', 'email' => $email, 'phone' => '03001112221', 'org_type' => 'clinic',
            'password' => 'Synthetic123!', 'password_confirmation' => 'Synthetic123!',
            'pricing_plan_id' => $plan->id, 'billing_cycle' => 'quarterly',
        ])->assertSessionHasNoErrors()->assertRedirect();

        $company = Company::where('email', $email)->sole();
        $quote = SignupPackageRequest::where('company_id', $company->id)->sole();
        $this->assertSame($plan->id, $quote->pricing_plan_id);
        $this->assertSame('quarterly', $quote->billing_cycle);
        $this->assertSame('9999.50', $quote->quoted_amount);
        $this->assertDatabaseCount('subscription_invoices', 0);
    }

    public function test_erp_receipt_approval_uses_the_selected_quote_after_an_admin_reprices_the_package(): void
    {
        Mail::fake();
        Storage::fake('local');
        $this->withoutMiddleware();
        view()->share('errors', new ViewErrorBag);
        config(['billing.shared_configured_cycles' => true]);
        $plan = PricingPlan::create(array_replace($this->planFields(), ['erp_website_price_visible' => true]));
        $company = Company::create([
            'name' => 'Synthetic ERP company', 'ntn' => 'ERP-WEB-RATE', 'product_type' => NestErps::PRODUCT_TYPE,
        ]);
        $user = User::create([
            'name' => 'Company owner', 'email' => 'erp-receipt-owner@example.test', 'password' => 'unused',
            'role' => 'company_admin', 'company_id' => $company->id,
        ]);
        $this->actingAs($user, 'health');
        $this->post(route('health.package-checkout.select'), [
            'plan_id' => $plan->id, 'billing_cycle' => 'annual',
        ])->assertRedirect()->assertSessionHasNoErrors();
        $plan->update(['erp_website_price_visible' => false, 'price' => '48000.25', 'branch_limit' => 8, 'health_modules' => ['hr']]);
        $this->get(route('health.package-checkout', ['plan_id' => $plan->id]))->assertOk()
            ->assertSee('Payable total: PKR 36,000.75')->assertDontSee('48,000.25');
        $this->post(route('health.payment-proof.store'), [
            'pricing_plan_id' => $plan->id, 'billing_cycle' => 'annual', 'amount' => '36000.75',
            'payment_method' => 'bank', 'proof' => UploadedFile::fake()->create('synthetic.pdf', 2, 'application/pdf'),
        ])->assertSessionHasNoErrors();
        $proof = PaymentProof::where('company_id', $company->id)->sole();
        $this->assertEquals(36000.75, $proof->distributor_quote_snapshot['net_quote']);

        $otherPlan = PricingPlan::create(array_replace($this->planFields(), ['name' => 'Other ERP package']));
        $approval = route('saas.admin.payment-proofs.approve', $proof->id);
        $this->actingAs($this->admin(), 'admin')->post($approval, [
            'pricing_plan_id' => $otherPlan->id, 'billing_cycle' => 'annual', 'verified_received_amount' => '36000.75',
        ])->assertSessionHas('error');
        $this->assertSame('pending', $proof->fresh()->status);
        $this->post($approval, [
            'pricing_plan_id' => $plan->id, 'billing_cycle' => 'annual', 'verified_received_amount' => '36000.75',
        ])->assertSessionHas('success');
        $subscription = Subscription::findOrFail($proof->fresh()->subscription_id);
        $this->assertEquals(36000.75, SubscriptionInvoice::where('payment_proof_id', $proof->id)->sole()->amount);
        $this->assertSame($plan->id, $subscription->pricing_plan_id);
        $this->assertEquals(36000.75, $subscription->final_price);
        $this->assertSame(3, $subscription->entitlementPlan()->branch_limit);
        $this->assertSame(['opd'], $subscription->entitlementPlan()->health_modules);
    }

    public function test_quote_only_plan_cannot_be_requested_or_bought_online_and_remains_assignable_by_admin(): void
    {
        $this->withoutVite();
        Mail::fake();
        Storage::fake('local');
        config(['health.registration_open' => true]);
        PricingPlan::whereIn('product_type', NestErps::PRODUCT_TYPES)->update(['erp_website_price_visible' => false]);
        $plan = PricingPlan::create($this->planFields());

        $this->get('/nest-erps')->assertOk()->assertDontSee($plan->name)->assertDontSee('36,000.75')
            ->assertSee('Request a quote')->assertDontSee('/health/register');
        $this->get('/health/register?plan_id='.$plan->id)->assertOk()->assertDontSee($plan->name)
            ->assertDontSee('36,000.75')->assertSee('Request a quote');
        $signup = [
            'company_name' => 'Synthetic quote-only signup', 'company_ntn' => '8790071', 'company_cnic' => '3520212345673',
            'name' => 'Synthetic owner', 'email' => 'erp-quote-only-signup@example.test', 'org_type' => 'clinic',
            'password' => 'Synthetic123!', 'password_confirmation' => 'Synthetic123!', 'billing_cycle' => 'annual',
        ];
        $this->post('/health/register', $signup + ['pricing_plan_id' => $plan->id])->assertSessionHasErrors('pricing_plan_id');
        $this->post('/health/register', $signup + ['requested_plan' => $plan->name])->assertSessionHasErrors('requested_plan');
        $this->assertDatabaseMissing('companies', ['email' => $signup['email']]);
        $this->assertDatabaseCount('signup_package_requests', 0);

        $this->withoutMiddleware();
        view()->share('errors', new ViewErrorBag);
        $company = Company::create(['name' => 'Synthetic assigned ERP', 'ntn' => 'ERP-QUOTE-ONLY', 'product_type' => NestErps::PRODUCT_TYPE]);
        $user = User::create([
            'name' => 'Synthetic owner', 'email' => 'erp-quote-only-owner@example.test', 'password' => 'unused',
            'role' => 'company_admin', 'company_id' => $company->id,
        ]);
        $this->actingAs($user, 'health')->get(route('health.package-checkout', ['plan_id' => $plan->id]))
            ->assertOk()->assertDontSee($plan->name)->assertDontSee('36,000.75')->assertSee('Request a quote');
        $this->post(route('health.package-checkout.select'), ['plan_id' => $plan->id, 'billing_cycle' => 'annual'])
            ->assertUnprocessable();
        $this->post(route('health.payment-proof.store'), [
            'pricing_plan_id' => $plan->id, 'billing_cycle' => 'annual', 'amount' => '36000.75',
            'payment_method' => 'bank', 'proof' => UploadedFile::fake()->create('synthetic.pdf', 2, 'application/pdf'),
        ])->assertSessionHasErrors('pricing_plan_id');
        $this->assertDatabaseCount('payment_proofs', 0);
        $this->assertSame([], Storage::disk('local')->allFiles());

        $this->actingAs($this->admin(), 'admin')->post(route('saas.admin.subscriptions.assign'), [
            'company_id' => $company->id, 'pricing_plan_id' => $plan->id, 'billing_cycle' => 'annual',
            'assignment_mode' => 'unpaid',
        ])->assertSessionHas('success');
        $this->assertDatabaseHas('subscriptions', ['company_id' => $company->id, 'pricing_plan_id' => $plan->id, 'active' => true]);
    }

    private function planFields(): array
    {
        return [
            'name' => 'Synthetic ERP rate', 'product_type' => NestErps::PRODUCT_TYPE, 'erps_vertical' => NestErps::HEALTH,
            'price' => '36000.75', 'invoice_limit' => -1, 'is_public' => 1, 'erp_website_price_visible' => 0,
            'price_quarterly' => '9999.50', 'configure_shared_cycle_rates' => 1, 'branch_limit' => 3,
            'configure_health_modules' => 1, 'health_modules' => ['opd'],
        ];
    }

    private function admin(): AdminUser
    {
        return AdminUser::create([
            'name' => 'Billing administrator', 'email' => 'erp-rate-admin@example.test',
            'password' => bcrypt('synthetic-password'), 'role' => 'super_admin',
        ]);
    }
}
