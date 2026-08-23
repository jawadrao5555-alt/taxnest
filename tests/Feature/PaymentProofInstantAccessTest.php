<?php

namespace Tests\Feature;

use App\Http\Controllers\PaymentProofController;
use App\Models\AdminUser;
use App\Models\Company;
use App\Models\PaymentProof;
use App\Models\Subscription;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * PAYMENT-PROOF INSTANT 10-DAY ACCESS — safety-critical rules
 * (owner approved, Aug 2026; see .agents/memory/payment-proof-instant-access.md)
 *
 *  1. Uploading a proof while LOCKED auto-grants a 10-day temporary override
 *     (override_by NULL + 'payment proof #{id}' reason) and unlocks BOTH
 *     company status columns.
 *  2. NO grant when: an override is already active (never stomp admin grants),
 *     the company currently has valid access, or ANY prior proof was rejected.
 *  3. Reject revokes exactly the auto grant and demotes BOTH status columns
 *     back to pending (only when the company truly lacks access afterwards).
 *  4. Reject must NEVER touch an admin-granted override (override_by set).
 *  5. Approve assigns the subscription and unlocks BOTH status columns.
 */
class PaymentProofInstantAccessTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Schema::dropAllTables();

        Schema::create('companies', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('email')->nullable();
            $table->string('status')->default('approved');
            $table->string('company_status')->default('active');
            $table->boolean('is_internal_account')->default(false);
            $table->string('product_type')->nullable();
            $table->softDeletes();
            $table->timestamps();
        });

        Schema::create('users', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('email')->unique();
            $table->string('password');
            $table->unsignedBigInteger('company_id')->nullable();
            $table->string('role')->nullable();
            $table->boolean('is_active')->default(true);
            $table->rememberToken();
            $table->timestamps();
        });

        Schema::create('admin_users', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('email')->unique();
            $table->string('password');
            $table->string('role')->default('super_admin');
            $table->rememberToken();
            $table->timestamps();
        });

        Schema::create('admin_audit_logs', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('admin_id')->nullable();
            $table->string('action');
            $table->string('target_type')->nullable();
            $table->unsignedBigInteger('target_id')->nullable();
            $table->text('metadata')->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->timestamps();
        });

        Schema::create('pricing_plans', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('product_type')->default('di');
            $table->boolean('is_trial')->default(false);
            $table->decimal('price', 10, 2)->default(0);
            $table->decimal('price_monthly', 10, 2)->nullable();
            $table->decimal('price_quarterly', 10, 2)->nullable();
            $table->integer('invoice_limit')->nullable();
            $table->timestamps();
        });

        Schema::create('subscriptions', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('company_id');
            $table->unsignedBigInteger('pricing_plan_id')->nullable();
            $table->string('billing_cycle')->default('monthly');
            $table->decimal('discount_percent', 5, 2)->default(0);
            $table->decimal('final_price', 10, 2)->default(0);
            $table->date('start_date')->nullable();
            $table->date('end_date')->nullable();
            $table->timestamp('trial_ends_at')->nullable();
            $table->boolean('active')->default(true);
            $table->string('override_type')->default('none');
            $table->timestamp('override_until')->nullable();
            $table->timestamp('override_granted_at')->nullable();
            $table->integer('free_invoice_limit')->nullable();
            $table->text('override_reason')->nullable();
            $table->unsignedBigInteger('override_by')->nullable();
            $table->timestamps();
        });

        Schema::create('payment_proofs', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('company_id');
            $table->unsignedBigInteger('pricing_plan_id')->nullable();
            $table->string('billing_cycle')->nullable();
            $table->decimal('amount', 12, 2)->nullable();
            $table->string('payment_method')->nullable();
            $table->string('reference')->nullable();
            $table->date('payment_date')->nullable();
            $table->text('notes')->nullable();
            $table->string('proof_path')->nullable();
            $table->string('status')->default('pending');
            $table->unsignedBigInteger('subscription_id')->nullable();
            $table->unsignedBigInteger('verified_by')->nullable();
            $table->timestamp('verified_at')->nullable();
            $table->string('reject_reason')->nullable();
            $table->timestamp('auto_access_until')->nullable();
            $table->timestamp('file_pruned_at')->nullable();
            $table->timestamps();
        });

        Schema::create('sale_campaigns', function (Blueprint $table) {
            $table->id();
            $table->string('product_type')->nullable();
            $table->decimal('percent', 5, 2)->default(0);
            $table->string('badge')->nullable();
            $table->date('starts_on')->nullable();
            $table->date('ends_on')->nullable();
            $table->boolean('active')->default(false);
            $table->timestamps();
        });

        Schema::create('notifications', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('company_id')->nullable();
            $table->unsignedBigInteger('user_id')->nullable();
            $table->string('type');
            $table->string('title');
            $table->text('message');
            $table->boolean('read')->default(false);
            $table->text('metadata')->nullable();
            $table->timestamps();
        });

        Mail::fake();
    }

    // ─── helpers ─────────────────────────────────────────────────────────

    /** A company locked out: active subscription whose trial expired. */
    private function makeLockedCompany(array $companyAttrs = []): Company
    {
        $company = Company::create(array_merge([
            'name' => 'Locked Traders',
            'email' => 'owner@locked.test',
            'status' => 'approved',
            'company_status' => 'active',
        ], $companyAttrs));

        Subscription::create([
            'company_id' => $company->id,
            'billing_cycle' => 'monthly',
            'discount_percent' => 0,
            'final_price' => 0,
            'start_date' => now()->subDays(20)->toDateString(),
            'trial_ends_at' => now()->subDays(10),
            'active' => true,
        ]);

        return $company;
    }

    private function makeProof(Company $company, array $attrs = []): PaymentProof
    {
        return PaymentProof::create(array_merge([
            'company_id' => $company->id,
            'proof_path' => 'payment-proofs/test.jpg',
            'status' => 'pending',
        ], $attrs));
    }

    /** Invoke the private grantInstantAccess on the company controller. */
    private function grant(?Company $company, PaymentProof $proof): bool
    {
        $controller = app(PaymentProofController::class);
        $method = new \ReflectionMethod($controller, 'grantInstantAccess');
        $method->setAccessible(true);

        return (bool) $method->invoke($controller, $company, $proof);
    }

    private function activeSub(Company $company): ?Subscription
    {
        return Subscription::where('company_id', $company->id)
            ->where('active', true)->orderByDesc('id')->first();
    }

    private function admin(): AdminUser
    {
        return AdminUser::create([
            'name' => 'Admin',
            'email' => 'admin@taxnest.test',
            'password' => Hash::make('Admin@12345'),
            'role' => 'super_admin',
        ]);
    }

    // ─── 1. grant on locked upload ───────────────────────────────────────

    public function test_locked_company_gets_ten_day_auto_grant_with_correct_signature(): void
    {
        $company = $this->makeLockedCompany(['status' => 'pending', 'company_status' => 'pending']);
        $proof = $this->makeProof($company);

        $this->assertTrue($this->grant($company, $proof));

        $sub = $this->activeSub($company);
        $this->assertSame('temporary', $sub->override_type);
        $this->assertNull($sub->override_by, 'Auto grant must keep override_by NULL');
        $this->assertStringContainsString('payment proof #' . $proof->id, (string) $sub->override_reason);
        $this->assertNotNull($sub->override_until);
        $this->assertEqualsWithDelta(10, now()->diffInDays($sub->override_until), 1, 'Grant must last ~10 days');
        $this->assertNull($sub->free_invoice_limit);

        $proof->refresh();
        $this->assertNotNull($proof->auto_access_until, 'auto_access_until must be stamped on the proof');

        $company->refresh();
        $this->assertSame('approved', $company->status, 'Grant must unlock companies.status');
        $this->assertSame('active', $company->company_status, 'Grant must unlock companies.company_status');
    }

    /**
     * Current behavior for a company with NO subscription row at all:
     * grantInstantAccess creates a bare active subscription (so future access
     * checks have a row to ride on), after which hasAccess() reports the
     * company as allowed — so no temporary override is stamped. The invariant
     * that matters here: the auto-grant signature (override + auto_access_until)
     * must never appear without an actual grant.
     */
    public function test_no_subscription_company_gets_bare_row_with_ten_day_grant_only(): void
    {
        $company = Company::create(['name' => 'No Sub Co', 'status' => 'approved', 'company_status' => 'active']);
        $proof = $this->makeProof($company);

        $this->assertTrue($this->grant($company, $proof));

        $sub = $this->activeSub($company);
        $this->assertNotNull($sub, 'A subscription row is created to carry the grant');
        $this->assertNull($sub->pricing_plan_id, 'Bare carrier row has no plan');
        $this->assertSame('temporary', $sub->override_type, 'Grant rides on the bare row');
        $this->assertNull($sub->override_by);
        $this->assertNotNull($proof->fresh()->auto_access_until);

        // Access flows ONLY from the 10-day override — once it lapses, the
        // bare plan-less row must grant nothing (no unlimited free access).
        $access = \App\Services\SubscriptionAccessService::hasAccess($company->fresh());
        $this->assertTrue($access['allowed']);
        $this->assertSame('temporary', $access['override']);

        $sub->update(['override_until' => now()->subMinute()]);
        $this->assertFalse(
            \App\Services\SubscriptionAccessService::hasAccess($company->fresh())['allowed'],
            'Bare row without an active override must fail closed'
        );
    }

    // ─── 2. no grant when it must not fire ───────────────────────────────

    public function test_no_grant_when_admin_override_already_active(): void
    {
        $company = $this->makeLockedCompany();
        $sub = $this->activeSub($company);
        $sub->update([
            'override_type' => 'temporary',
            'override_until' => now()->addDays(30),
            'override_granted_at' => now()->subDay(),
            'override_reason' => 'Admin courtesy grant',
            'override_by' => 7,
        ]);

        $proof = $this->makeProof($company);
        $this->assertFalse($this->grant($company, $proof));

        $sub->refresh();
        $this->assertSame(7, (int) $sub->override_by, 'Admin grant must not be stomped');
        $this->assertSame('Admin courtesy grant', $sub->override_reason);
        $this->assertNull($proof->fresh()->auto_access_until);
    }

    public function test_no_grant_when_company_currently_has_valid_access(): void
    {
        $company = Company::create(['name' => 'Paid Co', 'status' => 'approved', 'company_status' => 'active']);
        Subscription::create([
            'company_id' => $company->id,
            'billing_cycle' => 'monthly',
            'discount_percent' => 0,
            'final_price' => 1000,
            'start_date' => now()->toDateString(),
            'end_date' => now()->addMonth()->toDateString(),
            'active' => true,
        ]);

        $proof = $this->makeProof($company);
        $this->assertFalse($this->grant($company, $proof));

        $sub = $this->activeSub($company);
        $this->assertSame('none', $sub->override_type ?? 'none');
        $this->assertNull($proof->fresh()->auto_access_until);
    }

    public function test_no_grant_after_any_prior_rejected_proof(): void
    {
        $company = $this->makeLockedCompany();
        $this->makeProof($company, ['status' => 'rejected', 'reject_reason' => 'Fake receipt']);

        $proof = $this->makeProof($company);
        $this->assertFalse($this->grant($company, $proof), 'Owner safeguard: any prior rejection disables instant access');

        $sub = $this->activeSub($company);
        $this->assertNotSame('temporary', $sub->override_type);
        $this->assertNull($proof->fresh()->auto_access_until);
    }

    public function test_no_grant_for_suspended_or_rejected_company(): void
    {
        foreach ([['status' => 'suspended'], ['company_status' => 'rejected']] as $attrs) {
            $company = $this->makeLockedCompany($attrs);
            $proof = $this->makeProof($company);
            $this->assertFalse($this->grant($company, $proof));
            $company->refresh();
            $this->assertNotSame('approved/active', $company->status . '/' . $company->company_status);
        }
    }

    public function test_no_grant_for_internal_account(): void
    {
        $company = $this->makeLockedCompany(['is_internal_account' => true]);
        $proof = $this->makeProof($company);
        $this->assertFalse($this->grant($company, $proof));
    }

    // ─── 3. reject revokes the auto grant + demotes ──────────────────────

    public function test_reject_revokes_auto_grant_and_demotes_both_status_columns(): void
    {
        $company = $this->makeLockedCompany(['status' => 'pending', 'company_status' => 'pending']);
        $proof = $this->makeProof($company);
        $this->assertTrue($this->grant($company, $proof));

        $response = $this->actingAs($this->admin(), 'admin')
            ->post(route('saas.admin.payment-proofs.reject', $proof->id), [
                'reject_reason' => 'Receipt unreadable',
            ]);

        $response->assertRedirect();

        $proof->refresh();
        $this->assertSame('rejected', $proof->status);

        $sub = $this->activeSub($company);
        $this->assertSame('none', $sub->override_type, 'Reject must revoke the auto grant');
        $this->assertNull($sub->override_until);
        $this->assertNull($sub->override_reason);

        $company->refresh();
        $this->assertSame('pending', $company->status, 'Reject must demote companies.status');
        $this->assertSame('pending', $company->company_status, 'Reject must demote companies.company_status');
    }

    // ─── 4. reject must never touch an admin-granted override ────────────

    public function test_reject_leaves_admin_granted_override_untouched(): void
    {
        $company = $this->makeLockedCompany();
        $proof = $this->makeProof($company, ['auto_access_until' => now()->addDays(10)]);

        // The live override is ADMIN-granted (override_by set, different reason).
        $sub = $this->activeSub($company);
        $sub->update([
            'override_type' => 'temporary',
            'override_until' => now()->addDays(30),
            'override_granted_at' => now(),
            'override_reason' => 'Admin grace for renewal',
            'override_by' => 7,
        ]);

        $this->actingAs($this->admin(), 'admin')
            ->post(route('saas.admin.payment-proofs.reject', $proof->id), [])
            ->assertRedirect();

        $sub->refresh();
        $this->assertSame('temporary', $sub->override_type, 'Admin override must survive reject');
        $this->assertSame(7, (int) $sub->override_by);
        $this->assertSame('Admin grace for renewal', $sub->override_reason);

        $company->refresh();
        $this->assertSame('approved', $company->status, 'Company with admin grant must NOT be demoted');
        $this->assertSame('active', $company->company_status);
    }

    public function test_reject_does_not_demote_company_that_still_has_other_access(): void
    {
        $company = $this->makeLockedCompany();
        $proof = $this->makeProof($company);
        $this->assertTrue($this->grant($company, $proof));

        // Meanwhile an admin assigned a real paid subscription (new active row).
        Subscription::create([
            'company_id' => $company->id,
            'billing_cycle' => 'annual',
            'discount_percent' => 0,
            'final_price' => 5000,
            'start_date' => now()->toDateString(),
            'end_date' => now()->addYear()->toDateString(),
            'active' => true,
        ]);

        $this->actingAs($this->admin(), 'admin')
            ->post(route('saas.admin.payment-proofs.reject', $proof->id), [])
            ->assertRedirect();

        $company->refresh();
        $this->assertSame('approved', $company->status, 'Company with a live paid sub must stay unlocked');
        $this->assertSame('active', $company->company_status);
    }

    // ─── 5. approve assigns subscription + unlocks both columns ──────────

    public function test_approve_assigns_subscription_and_unlocks_both_status_columns(): void
    {
        $company = $this->makeLockedCompany(['status' => 'pending', 'company_status' => 'pending']);
        $plan = \App\Models\PricingPlan::create([
            'name' => 'DI Basic',
            'product_type' => 'di',
            'is_trial' => false,
            'price' => 1000,
        ]);
        $proof = $this->makeProof($company, ['pricing_plan_id' => $plan->id, 'billing_cycle' => 'annual']);

        $this->actingAs($this->admin(), 'admin')
            ->post(route('saas.admin.payment-proofs.approve', $proof->id), [
                'pricing_plan_id' => $plan->id,
                'billing_cycle' => 'annual',
            ])
            ->assertRedirect();

        $proof->refresh();
        $this->assertSame('verified', $proof->status);
        $this->assertNotNull($proof->subscription_id);

        $sub = $this->activeSub($company);
        $this->assertSame($proof->subscription_id, $sub->id);
        $this->assertSame($plan->id, (int) $sub->pricing_plan_id);
        $this->assertNotNull($sub->end_date);

        $company->refresh();
        $this->assertSame('approved', $company->status, 'Approve must unlock companies.status');
        $this->assertSame('active', $company->company_status, 'Approve must unlock companies.company_status');
    }

    public function test_approve_never_unsuspends_a_suspended_company(): void
    {
        $company = $this->makeLockedCompany(['status' => 'suspended', 'company_status' => 'suspended']);
        $plan = \App\Models\PricingPlan::create([
            'name' => 'DI Basic', 'product_type' => 'di', 'is_trial' => false, 'price' => 1000,
        ]);
        $proof = $this->makeProof($company, ['pricing_plan_id' => $plan->id, 'billing_cycle' => 'annual']);

        $this->actingAs($this->admin(), 'admin')
            ->post(route('saas.admin.payment-proofs.approve', $proof->id), [
                'pricing_plan_id' => $plan->id,
                'billing_cycle' => 'annual',
            ])
            ->assertRedirect();

        $company->refresh();
        $this->assertSame('suspended', $company->status, 'Approve must never reverse a deliberate suspension');
        $this->assertSame('suspended', $company->company_status);
    }

    // --- approve(): enforced-cycle & product-line guards (Aug 2026) ---

    private function makeAdmin(): AdminUser
    {
        return AdminUser::create([
            'name' => 'Approver',
            'email' => 'approver' . uniqid() . '@test.pk',
            'password' => Hash::make('secret-123'),
            'role' => 'super_admin',
        ]);
    }

    public function test_approve_rejects_a_retired_quarterly_cycle(): void
    {
        $company = $this->makeLockedCompany();
        $plan = \App\Models\PricingPlan::create([
            'name' => 'Starter', 'product_type' => 'pos', 'price' => 14999, 'is_trial' => false,
        ]);
        $proof = $this->makeProof($company, ['pricing_plan_id' => $plan->id, 'billing_cycle' => 'annual']);

        $this->actingAs($this->makeAdmin(), 'admin')
            ->post(route('saas.admin.payment-proofs.approve', $proof->id), [
                'pricing_plan_id' => $plan->id,
                'billing_cycle' => 'quarterly',
            ])
            ->assertSessionHasErrors('billing_cycle');

        $proof->refresh();
        $this->assertSame('pending', $proof->status);
        $this->assertNull($proof->subscription_id);
        $this->assertSame(1, Subscription::where('company_id', $company->id)->count(),
            'Validation must not add a subscription to the existing expired trial');
        $this->assertSame(0, Subscription::where('company_id', $company->id)
            ->where('pricing_plan_id', $plan->id)->count());
    }

    public function test_approve_annual_ignores_a_historical_quarterly_price(): void
    {
        $company = $this->makeLockedCompany();
        $plan = \App\Models\PricingPlan::create([
            'name' => 'Business', 'product_type' => 'pos', 'price' => 24999, 'price_quarterly' => 7199, 'is_trial' => false,
        ]);
        $proof = $this->makeProof($company, ['pricing_plan_id' => $plan->id, 'billing_cycle' => 'quarterly']);

        $this->actingAs($this->makeAdmin(), 'admin')
            ->post(route('saas.admin.payment-proofs.approve', $proof->id), [
                'pricing_plan_id' => $plan->id,
                'billing_cycle' => 'annual',
            ]);

        $proof->refresh();
        $this->assertSame('verified', $proof->status);
        $this->assertSame('annual', $proof->billing_cycle);
        $sub = Subscription::find($proof->subscription_id);
        $this->assertNotNull($sub);
        $this->assertSame('annual', $sub->billing_cycle);
        $this->assertSame(24999.0, (float) $sub->final_price);
    }

    public function test_approve_rejects_plan_from_different_product_line(): void
    {
        $company = $this->makeLockedCompany();
        $posPlan = \App\Models\PricingPlan::create([
            'name' => 'Starter', 'product_type' => 'pos', 'price' => 14999, 'is_trial' => false,
        ]);
        $diPlan = \App\Models\PricingPlan::create([
            'name' => 'DI Basic', 'product_type' => 'di', 'price' => 1500, 'is_trial' => false,
        ]);
        $proof = $this->makeProof($company, ['pricing_plan_id' => $posPlan->id, 'billing_cycle' => 'annual']);

        $this->actingAs($this->makeAdmin(), 'admin')
            ->post(route('saas.admin.payment-proofs.approve', $proof->id), [
                'pricing_plan_id' => $diPlan->id,
                'billing_cycle' => 'annual',
            ]);

        $proof->refresh();
        $this->assertSame('pending', $proof->status, 'Cross-product-line approval must be refused');
        $this->assertNull($proof->subscription_id);
    }

    public function test_approve_rejects_retired_pro_max_even_when_the_proof_already_points_to_it(): void
    {
        $company = $this->makeLockedCompany(['product_type' => 'pos']);
        $retired = \App\Models\PricingPlan::create([
            'name' => 'Pro Max',
            'product_type' => 'pos',
            'price' => 49999,
            'is_trial' => false,
        ]);
        $proof = $this->makeProof($company, [
            'pricing_plan_id' => $retired->id,
            'billing_cycle' => 'annual',
        ]);

        $this->actingAs($this->makeAdmin(), 'admin')
            ->post(route('saas.admin.payment-proofs.approve', $proof->id), [
                'pricing_plan_id' => $retired->id,
                'billing_cycle' => 'annual',
            ])
            ->assertSessionHas('error');

        $proof->refresh();
        $this->assertSame('pending', $proof->status);
        $this->assertNull($proof->subscription_id);
    }
}
