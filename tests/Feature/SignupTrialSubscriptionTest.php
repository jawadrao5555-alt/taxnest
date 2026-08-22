<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\PricingPlan;
use App\Models\Subscription;
use App\Services\SubscriptionAccessService;
use App\Services\TrialSubscriptionService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * EVERY NEW SIGNUP MUST START WITH A PROPER TRIAL SUBSCRIPTION
 *
 * The payment-proof free-access hole existed only because a company could
 * end up with NO subscription row. hasAccess() fails closed on bare rows,
 * but the healthier guarantee (tested here) is that no registration path —
 * DI self-signup, PRA POS signup, FBR POS signup, admin-created — can leave
 * a company without a subscription row carrying a plan or trial_ends_at.
 */
class SignupTrialSubscriptionTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Schema::dropAllTables();

        Schema::create('companies', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('owner_name')->nullable();
            $table->string('ntn')->nullable();
            $table->string('cnic')->nullable();
            $table->string('email')->nullable();
            $table->string('phone')->nullable();
            $table->string('mobile')->nullable();
            $table->string('address')->nullable();
            $table->string('city')->nullable();
            $table->string('province')->nullable();
            $table->string('business_activity')->nullable();
            $table->string('website')->nullable();
            $table->string('status')->default('pending');
            $table->string('company_status')->default('active');
            $table->string('product_type')->nullable();
            $table->string('pos_type')->nullable();
            $table->boolean('restaurant_mode')->default(false);
            $table->boolean('pra_reporting_enabled')->default(false);
            $table->string('pra_environment')->nullable();
            $table->string('pos_integration_mode')->nullable();
            $table->unsignedBigInteger('requested_plan_id')->nullable();
            $table->boolean('fbr_pos_enabled')->default(false);
            $table->string('fbr_pos_environment')->nullable();
            $table->boolean('fbr_reporting_enabled')->default(false);
            $table->decimal('standard_tax_rate', 8, 2)->nullable();
            $table->unsignedBigInteger('franchise_id')->nullable();
            $table->boolean('is_internal_account')->default(false);
            $table->softDeletes();
            $table->timestamps();
        });

        Schema::create('users', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('email')->unique();
            $table->string('phone')->nullable();
            $table->string('username')->nullable();
            $table->string('password');
            $table->unsignedBigInteger('company_id')->nullable();
            $table->string('role')->nullable();
            $table->string('pos_role')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamp('email_verified_at')->nullable();
            $table->rememberToken();
            $table->timestamps();
        });

        Schema::create('pricing_plans', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('product_type')->nullable();
            $table->decimal('price', 12, 2)->default(0);
            $table->boolean('is_trial')->default(false);
            $table->integer('invoice_limit')->nullable();
            $table->timestamps();
        });

        Schema::create('subscriptions', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('company_id');
            $table->unsignedBigInteger('pricing_plan_id')->nullable();
            $table->string('billing_cycle')->nullable();
            $table->decimal('discount_percent', 8, 2)->default(0);
            $table->decimal('final_price', 12, 2)->default(0);
            $table->date('start_date')->nullable();
            $table->date('end_date')->nullable();
            $table->timestamp('trial_ends_at')->nullable();
            $table->boolean('active')->default(true);
            $table->string('override_type')->nullable();
            $table->timestamp('override_until')->nullable();
            $table->timestamp('override_granted_at')->nullable();
            $table->integer('free_invoice_limit')->nullable();
            $table->string('override_reason')->nullable();
            $table->unsignedBigInteger('override_by')->nullable();
            $table->timestamps();
        });

        Schema::create('registered_credentials', function (Blueprint $table) {
            $table->id();
            $table->string('credential_type');
            $table->string('credential_value', 191);
            $table->string('product_type')->nullable();
            $table->unsignedBigInteger('company_id')->nullable();
            $table->timestamp('created_at')->nullable();
            $table->unique(['credential_type', 'credential_value']);
        });

        // Empty billing-document tables so billableCount() works per product type.
        Schema::create('invoices', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('company_id')->nullable();
            $table->timestamps();
        });
        Schema::create('pos_transactions', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('company_id')->nullable();
            $table->boolean('archived')->default(false);
            $table->timestamps();
        });
        Schema::create('fbr_pos_transactions', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('company_id')->nullable();
            $table->timestamps();
        });

        Schema::create('security_logs', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id')->nullable();
            $table->string('action');
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->text('metadata')->nullable();
            $table->timestamp('created_at')->nullable();
        });

        Mail::fake();
        Notification::fake();
    }

    private function seedTrialPlans(): void
    {
        foreach (['di', 'pos', 'fbrpos'] as $type) {
            PricingPlan::create([
                'name' => strtoupper($type) . ' Trial',
                'product_type' => $type,
                'price' => 0,
                'is_trial' => true,
                'invoice_limit' => 20,
            ]);
        }
    }

    /** Assert the company's newest active subscription is a proper trial. */
    private function assertProperTrial(Company $company, string $productType): Subscription
    {
        $sub = Subscription::where('company_id', $company->id)
            ->where('active', true)
            ->orderByDesc('id')
            ->first();

        $this->assertNotNull($sub, "Company {$company->id} ({$productType}) has NO active subscription after signup");
        $this->assertTrue(
            $sub->pricing_plan_id !== null || $sub->trial_ends_at !== null,
            'Subscription row must carry a plan or trial_ends_at'
        );
        $this->assertNotNull($sub->end_date, 'Trial must be time-bounded (end_date set)');

        // And the access gate actually grants trial access (not fail-closed).
        $access = SubscriptionAccessService::hasAccess($company->fresh());
        $this->assertTrue($access['allowed'], "hasAccess denied fresh {$productType} signup: {$access['reason']}");

        return $sub;
    }

    // ── DI self-signup ────────────────────────────────────────────────

    public function test_di_registration_creates_trial_subscription(): void
    {
        $this->seedTrialPlans();

        $resp = $this->post('/register', [
            'name' => 'Ali Owner',
            'email' => 'ali@example.com',
            'password' => 'secret-pass-123',
            'password_confirmation' => 'secret-pass-123',
            'company_name' => 'Ali Traders',
            'company_ntn' => '1234567',
        ]);
        $resp->assertSessionHasNoErrors();

        $company = Company::where('name', 'Ali Traders')->firstOrFail();
        $sub = $this->assertProperTrial($company, 'di');
        $this->assertNotNull($sub->pricing_plan_id, 'DI trial should use the seeded DI trial plan');
    }

    public function test_di_registration_without_trial_plan_still_creates_subscription(): void
    {
        // NO pricing plans seeded at all — the old code silently skipped the
        // subscription in this case; the guarantee is it never does now.
        $resp = $this->post('/register', [
            'name' => 'Sana Owner',
            'email' => 'sana@example.com',
            'password' => 'secret-pass-123',
            'password_confirmation' => 'secret-pass-123',
            'company_name' => 'Sana Store',
            'company_ntn' => '7654321',
        ]);
        $resp->assertSessionHasNoErrors();

        $company = Company::where('name', 'Sana Store')->firstOrFail();
        $sub = $this->assertProperTrial($company, 'di');
        $this->assertNull($sub->pricing_plan_id);
        $this->assertNotNull($sub->trial_ends_at, 'Plan-less fallback row must carry trial_ends_at');
    }

    // ── PRA POS signup ────────────────────────────────────────────────

    public function test_pos_registration_creates_trial_subscription(): void
    {
        $this->seedTrialPlans();
        $paid = PricingPlan::create([
            'name' => 'Starter',
            'product_type' => 'pos',
            'price' => 30000,
            'is_trial' => false,
        ]);

        $resp = $this->post('/pos/register', [
            'company_name' => 'Karahi Point',
            'company_ntn' => '9998887',
            'name' => 'Bilal Owner',
            'email' => 'bilal@example.com',
            'phone' => '03001234567',
            'password' => 'secret-pass-123',
            'password_confirmation' => 'secret-pass-123',
            'pos_type' => 'restaurant',
            'pricing_plan_id' => $paid->id,
        ]);
        $resp->assertSessionHasNoErrors();

        $company = Company::where('name', 'Karahi Point')->firstOrFail();
        $sub = $this->assertProperTrial($company, 'pos');
        $this->assertNotNull($sub->pricing_plan_id, 'POS trial should use the seeded POS trial plan');
    }

    public function test_pos_registration_without_trial_plan_still_creates_subscription(): void
    {
        // Only the (required) paid plan exists — no trial plan seed.
        $paid = PricingPlan::create([
            'name' => 'Starter',
            'product_type' => 'pos',
            'price' => 30000,
            'is_trial' => false,
        ]);

        $resp = $this->post('/pos/register', [
            'company_name' => 'Chai Dhaba',
            'company_ntn' => '5554443',
            'name' => 'Usman Owner',
            'email' => 'usman@example.com',
            'password' => 'secret-pass-123',
            'password_confirmation' => 'secret-pass-123',
            'pos_type' => 'general',
            'pricing_plan_id' => $paid->id,
        ]);
        $resp->assertSessionHasNoErrors();

        $company = Company::where('name', 'Chai Dhaba')->firstOrFail();
        $sub = $this->assertProperTrial($company, 'pos');
        $this->assertNotNull($sub->trial_ends_at);
    }

    // ── FBR POS signup ────────────────────────────────────────────────

    public function test_fbr_pos_registration_creates_trial_subscription(): void
    {
        $this->seedTrialPlans();

        $resp = $this->post('/fbr-pos/register', [
            'company_name' => 'City Mart',
            'company_ntn' => '1112223',
            'name' => 'Hina Owner',
            'email' => 'hina@example.com',
            'password' => 'secret-pass-123',
            'password_confirmation' => 'secret-pass-123',
            'pos_type' => 'retail',
        ]);
        $resp->assertSessionHasNoErrors();

        $company = Company::where('name', 'City Mart')->firstOrFail();
        $sub = $this->assertProperTrial($company, 'fbrpos');
        $this->assertNotNull($sub->pricing_plan_id, 'FBR trial should use the seeded fbrpos trial plan');
    }

    public function test_fbr_pos_registration_without_trial_plan_still_creates_subscription(): void
    {
        $resp = $this->post('/fbr-pos/register', [
            'company_name' => 'Metro Shoes',
            'company_ntn' => '3332221',
            'name' => 'Adeel Owner',
            'email' => 'adeel@example.com',
            'password' => 'secret-pass-123',
            'password_confirmation' => 'secret-pass-123',
            'pos_type' => 'retail',
        ]);
        $resp->assertSessionHasNoErrors();

        $company = Company::where('name', 'Metro Shoes')->firstOrFail();
        $sub = $this->assertProperTrial($company, 'fbrpos');
        $this->assertNotNull($sub->trial_ends_at);
    }

    // ── Admin-created companies (service-level guarantee) ─────────────

    public function test_ensure_trial_attaches_plan_trial_for_admin_created_company(): void
    {
        $this->seedTrialPlans();
        $company = Company::create([
            'name' => 'Admin Made Co',
            'product_type' => 'pos',
            'status' => 'approved',
            'company_status' => 'active',
        ]);

        TrialSubscriptionService::ensureTrial($company->id, 'pos', 14);
        $this->assertProperTrial($company, 'pos');
    }

    public function test_ensure_trial_is_idempotent(): void
    {
        $this->seedTrialPlans();
        $company = Company::create([
            'name' => 'Twice Co',
            'product_type' => 'di',
            'status' => 'approved',
            'company_status' => 'active',
        ]);

        $first = TrialSubscriptionService::ensureTrial($company->id, 'di');
        $second = TrialSubscriptionService::ensureTrial($company->id, 'di');

        $this->assertSame($first->id, $second->id, 'ensureTrial must never stack a second active subscription');
        $this->assertSame(1, Subscription::where('company_id', $company->id)->count());
    }

    public function test_ensure_trial_never_stomps_existing_paid_subscription(): void
    {
        $this->seedTrialPlans();
        $paid = PricingPlan::create([
            'name' => 'DI Paid',
            'product_type' => 'di',
            'price' => 12000,
            'is_trial' => false,
        ]);
        $company = Company::create([
            'name' => 'Paid Co',
            'product_type' => 'di',
            'status' => 'approved',
            'company_status' => 'active',
        ]);
        $existing = Subscription::create([
            'company_id' => $company->id,
            'pricing_plan_id' => $paid->id,
            'start_date' => now(),
            'end_date' => now()->addYear(),
            'active' => true,
        ]);

        $returned = TrialSubscriptionService::ensureTrial($company->id, 'di');
        $this->assertSame($existing->id, $returned->id);
        $this->assertSame(1, Subscription::where('company_id', $company->id)->count());
    }
}
