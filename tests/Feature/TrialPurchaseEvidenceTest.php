<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\PricingPlan;
use App\Models\Subscription;
use App\Services\TrialSubscriptionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class TrialPurchaseEvidenceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        // Migrations seed their own trial catalogue. These disposable cases
        // deliberately control which product seed is present or missing.
        PricingPlan::where('is_trial', true)->delete();
    }

    public function test_missing_product_trial_never_borrows_another_products_features(): void
    {
        PricingPlan::create(['name' => 'Other product trial', 'product_type' => 'pos', 'price' => 0, 'is_trial' => true, 'invoice_limit' => -1]);
        $company = Company::create(['name' => 'Fictional DI trial', 'ntn' => 'TRIAL001', 'product_type' => 'di']);
        $sub = TrialSubscriptionService::ensureTrial($company->id, 'di');
        $this->assertNull($sub->pricing_plan_id);
        $this->assertNotNull($sub->trial_ends_at);
        $this->assertSame(0.0, (float) $sub->final_price);
    }

    public function test_wrong_product_argument_cannot_attach_a_foreign_trial(): void
    {
        $company = Company::create(['name' => 'Fictional product boundary', 'ntn' => 'TRIAL004', 'product_type' => 'di']);
        try {
            TrialSubscriptionService::ensureTrial($company->id, 'pos');
            $this->fail('Wrong-product trial was created.');
        } catch (\Illuminate\Validation\ValidationException $exception) {
            $this->assertArrayHasKey('product_type', $exception->errors());
        }
        $this->assertSame(0, Subscription::where('company_id', $company->id)->count());
    }

    public function test_trial_terms_are_frozen_without_paid_documents_and_existing_paid_term_is_preserved(): void
    {
        $plan = PricingPlan::create(['name' => 'Fictional DI trial', 'product_type' => 'di', 'price' => 0, 'is_trial' => true, 'invoice_limit' => 10]);
        $company = Company::create(['name' => 'Fictional trial snapshot', 'ntn' => 'TRIAL002', 'product_type' => 'di']);
        $sub = TrialSubscriptionService::ensureTrial($company->id, 'di');
        $this->assertIsArray($sub->purchase_snapshot);
        $this->assertSame('trial_assignment', $sub->purchase_snapshot['source']);
        $this->assertSame(10, $sub->entitlementPlan()->invoice_limit);
        $snapshot = $sub->purchase_snapshot;
        $plan->update(['invoice_limit' => 1000, 'name' => 'Changed catalogue']);
        $again = TrialSubscriptionService::ensureTrial($company->id, 'di');
        $this->assertSame($sub->id, $again->id);
        $this->assertSame($snapshot, $again->purchase_snapshot);
        $this->assertSame(10, $again->entitlementPlan()->invoice_limit);
        $this->assertDatabaseCount('subscription_invoices', 0);
        $this->assertDatabaseCount('subscription_payments', 0);
        $paidCompany = Company::create(['name' => 'Other fictional paid company', 'ntn' => 'TRIAL003', 'product_type' => 'di']);
        $paid = Subscription::create(['company_id' => $paidCompany->id, 'pricing_plan_id' => $plan->id,
            'active' => true, 'final_price' => 12000, 'start_date' => now(), 'end_date' => now()->addYear()]);
        $before = $paid->fresh()->getAttributes();
        $this->assertSame($paid->id, TrialSubscriptionService::ensureTrial($paidCompany->id, 'di')->id);
        $this->assertSame($before, $paid->fresh()->getAttributes());
    }

    public function test_requesting_a_paid_package_during_signup_only_creates_the_bounded_trial(): void
    {
        Mail::fake();
        Notification::fake();
        foreach (['di' => '/register', 'pos' => '/pos/register', 'fbrpos' => '/fbr-pos/register'] as $product => $path) {
            $paid = PricingPlan::firstOrCreate(['name' => 'Business', 'product_type' => $product, 'is_trial' => false], ['price' => 15000, 'invoice_limit' => 100]);
            $trial = PricingPlan::create(['name' => 'Fictional trial '.$product, 'product_type' => $product, 'price' => 0, 'is_trial' => true, 'invoice_limit' => 10]);
            $input = ['name' => 'Fictional owner', 'email' => $product.'-trial@example.invalid', 'password' => 'Synthetic123!',
                'password_confirmation' => 'Synthetic123!', 'company_name' => 'Fictional signup '.$product,
                'company_ntn' => ['di' => '8123401', 'pos' => '8123402', 'fbrpos' => '8123403'][$product],
                'requested_plan' => $paid->name, 'requested_billing_cycle' => 'annual', 'pricing_plan_id' => $paid->id,
                'pos_type' => $product === 'fbrpos' ? 'general' : 'restaurant'];
            $this->post($path, $input)->assertSessionHasNoErrors()->assertRedirect();
            $company = Company::where('name', $input['company_name'])->sole();
            $this->assertSame($paid->id, (int) $company->requested_plan_id);
            $sub = Subscription::where('company_id', $company->id)->sole();
            $this->assertSame($trial->id, (int) $sub->pricing_plan_id);
            $this->assertSame(0.0, (float) $sub->final_price);
            $this->assertNotNull($sub->trial_ends_at);
            $this->assertNotNull($sub->purchase_snapshot);
            auth($product === 'di' ? 'web' : $product)->logout();
            $this->flushSession();
        }
        $this->assertDatabaseCount('subscription_invoices', 0);
        $this->assertDatabaseCount('subscription_payments', 0);
    }
}
