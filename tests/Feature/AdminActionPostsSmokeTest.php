<?php

namespace Tests\Feature;

use Tests\TestCase;
use App\Models\AdminUser;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;

/**
 * SMOKE-LOCK — Admin panel POST action buttons must never silently fail.
 *
 * The admin GET pages are already locked (AdminPagesSmokeTest,
 * AdminBillingPagesSmokeTest) and plan create/edit POSTs are covered
 * separately. This test locks the OTHER admin POST paths — company
 * approve/reject/suspend/activate/delete, franchise store/update/toggle,
 * payment-proof approve/reject, sale campaign store/toggle — asserting
 * BOTH the redirect (never a 500 / silent validation bounce with data
 * intact) and the resulting database state, including the dual
 * companies.status + companies.company_status column rule.
 */
class AdminActionPostsSmokeTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Schema::dropAllTables();

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

        Schema::create('companies', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('owner_name')->nullable();
            $table->string('email')->nullable();
            $table->string('phone')->nullable();
            $table->string('mobile')->nullable();
            $table->string('ntn')->nullable();
            $table->string('cnic')->nullable();
            $table->string('address')->nullable();
            $table->string('city')->nullable();
            $table->string('province')->nullable();
            $table->string('product_type')->nullable();
            $table->string('status')->default('approved');
            $table->string('company_status')->default('active');
            $table->unsignedBigInteger('franchise_id')->nullable();
            $table->unsignedBigInteger('requested_plan_id')->nullable();
            $table->boolean('fbr_pos_enabled')->default(false);
            $table->boolean('restaurant_mode')->default(false);
            $table->timestamp('suspended_at')->nullable();
            $table->string('deleted_reason')->nullable();
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
            $table->string('pos_role')->nullable();
            $table->boolean('is_active')->default(true);
            $table->rememberToken();
            $table->timestamps();
        });

        Schema::create('franchises', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('email')->unique();
            $table->string('phone')->nullable();
            $table->string('password')->nullable();
            $table->decimal('commission_rate', 5, 2)->default(0);
            $table->string('status')->default('active');
            $table->timestamps();
        });

        Schema::create('pricing_plans', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('product_type')->default('di');
            $table->decimal('price', 12, 2)->default(0);
            $table->decimal('price_monthly', 12, 2)->nullable();
            $table->decimal('compare_at_price', 12, 2)->nullable();
            $table->integer('invoice_limit')->default(0);
            $table->boolean('is_trial')->default(false);
            $table->text('features')->nullable();
            $table->timestamps();
        });

        Schema::create('subscriptions', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('company_id');
            $table->unsignedBigInteger('pricing_plan_id');
            $table->string('billing_cycle')->nullable();
            $table->decimal('discount_percent', 5, 2)->nullable();
            $table->decimal('final_price', 12, 2)->nullable();
            $table->date('start_date')->nullable();
            $table->date('end_date')->nullable();
            $table->timestamp('trial_ends_at')->nullable();
            $table->boolean('active')->default(true);
            $table->string('override_type')->nullable();
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
            $table->unsignedBigInteger('subscription_id')->nullable();
            $table->string('billing_cycle')->nullable();
            $table->string('file_path')->nullable();
            $table->string('original_name')->nullable();
            $table->string('status')->default('pending');
            $table->text('note')->nullable();
            $table->text('reject_reason')->nullable();
            $table->timestamp('auto_access_until')->nullable();
            $table->unsignedBigInteger('verified_by')->nullable();
            $table->timestamp('verified_at')->nullable();
            $table->timestamps();
        });

        Schema::create('sale_campaigns', function (Blueprint $table) {
            $table->id();
            $table->string('name')->nullable();
            $table->string('scope')->default('all');
            $table->decimal('discount_percent', 5, 2)->default(0);
            $table->timestamp('starts_at')->nullable();
            $table->timestamp('ends_at')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('notifications', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('company_id')->nullable();
            $table->unsignedBigInteger('user_id')->nullable();
            $table->string('type')->nullable();
            $table->string('title')->nullable();
            $table->text('message')->nullable();
            $table->string('action_url')->nullable();
            $table->timestamp('read_at')->nullable();
            $table->timestamps();
        });

        DB::table('admin_users')->insert([
            'name' => 'Smoke Admin',
            'email' => 'smoke-admin@taxnest.test',
            'password' => Hash::make('Smoke@12345'),
            'role' => 'super_admin',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function actingAsAdmin(): self
    {
        return $this->actingAs(AdminUser::first(), 'admin');
    }

    private function makeCompany(array $overrides = []): int
    {
        return DB::table('companies')->insertGetId(array_merge([
            'name' => 'Action Co',
            'owner_name' => 'Owner',
            'product_type' => 'di',
            'status' => 'pending',
            'company_status' => 'pending',
            'created_at' => now(),
            'updated_at' => now(),
        ], $overrides));
    }

    private function makePlan(array $overrides = []): int
    {
        return DB::table('pricing_plans')->insertGetId(array_merge([
            'name' => 'Action Plan',
            'product_type' => 'di',
            'price' => 1500,
            'is_trial' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ], $overrides));
    }

    /** Dual-column assertion — the rule every status flip must obey. */
    private function assertCompanyStatus(int $id, string $status, string $companyStatus): void
    {
        $row = DB::table('companies')->where('id', $id)->first();
        $this->assertNotNull($row, "Company {$id} vanished");
        $this->assertSame($status, $row->status, 'companies.status wrong');
        $this->assertSame($companyStatus, $row->company_status, 'companies.company_status wrong');
    }

    // ── Company approve / reject / suspend / activate / delete ──────────

    public function test_company_approve_flips_both_status_columns(): void
    {
        $id = $this->makeCompany();

        $response = $this->actingAsAdmin()
            ->from('/admin/companies')
            ->post("/admin/companies/{$id}/approve");

        $response->assertRedirect('/admin/companies');
        $response->assertSessionHas('success');
        $response->assertSessionMissing('errors');
        $this->assertCompanyStatus($id, 'approved', 'active');
    }

    public function test_company_approve_activates_requested_plan_for_one_year(): void
    {
        $planId = $this->makePlan(['name' => 'Starter', 'product_type' => 'pos', 'price' => 12000]);
        $id = $this->makeCompany(['product_type' => 'pos', 'requested_plan_id' => $planId]);

        $this->actingAsAdmin()
            ->from('/admin/companies')
            ->post("/admin/companies/{$id}/approve")
            ->assertRedirect('/admin/companies');

        $this->assertCompanyStatus($id, 'approved', 'active');
        $sub = DB::table('subscriptions')->where('company_id', $id)->where('active', true)->first();
        $this->assertNotNull($sub, 'Approval must activate the requested plan');
        $this->assertEquals($planId, $sub->pricing_plan_id);
        $this->assertSame('annual', $sub->billing_cycle);
    }

    public function test_retired_pos_plan_cannot_be_assigned_from_either_admin_route(): void
    {
        $retiredId = $this->makePlan([
            'name' => 'Pro Max',
            'product_type' => 'pos',
            'price' => 50000,
        ]);
        $companyId = $this->makeCompany([
            'product_type' => 'pos',
            'status' => 'approved',
            'company_status' => 'active',
        ]);

        $this->actingAsAdmin()
            ->from("/admin/company/{$companyId}")
            ->post("/admin/company/{$companyId}/change-plan", [
                'pricing_plan_id' => $retiredId,
            ])
            ->assertSessionHas('error');

        $this->actingAsAdmin()
            ->from('/admin/subscriptions')
            ->post('/admin/subscriptions/assign', [
                'company_id' => $companyId,
                'pricing_plan_id' => $retiredId,
                'billing_cycle' => 'annual',
            ])
            ->assertSessionHas('error');

        $this->assertSame(0, DB::table('subscriptions')->where('company_id', $companyId)->count());
    }

    public function test_inactive_historical_pro_max_subscription_cannot_be_reactivated(): void
    {
        $retiredId = $this->makePlan([
            'name' => 'Pro Max',
            'product_type' => 'pos',
            'price' => 50000,
        ]);
        $companyId = $this->makeCompany([
            'product_type' => 'pos',
            'status' => 'approved',
            'company_status' => 'active',
        ]);
        $subscriptionId = DB::table('subscriptions')->insertGetId([
            'company_id' => $companyId,
            'pricing_plan_id' => $retiredId,
            'billing_cycle' => 'annual',
            'start_date' => '2025-01-01',
            'end_date' => '2026-01-01',
            'active' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->actingAsAdmin()
            ->from('/admin/subscriptions')
            ->post("/admin/subscriptions/{$subscriptionId}/toggle")
            ->assertSessionHas('error');

        $this->assertFalse((bool) DB::table('subscriptions')->where('id', $subscriptionId)->value('active'));
    }

    public function test_company_reject_flips_both_status_columns(): void
    {
        $id = $this->makeCompany();

        $this->actingAsAdmin()
            ->from('/admin/companies')
            ->post("/admin/companies/{$id}/reject")
            ->assertRedirect('/admin/companies');

        $this->assertCompanyStatus($id, 'rejected', 'rejected');
    }

    public function test_company_suspend_flips_both_status_columns_and_stamps_time(): void
    {
        $id = $this->makeCompany(['status' => 'approved', 'company_status' => 'active']);

        $response = $this->actingAsAdmin()
            ->from('/admin/companies')
            ->post("/admin/companies/{$id}/suspend");

        $response->assertRedirect('/admin/companies');
        $response->assertSessionHas('success');
        $this->assertCompanyStatus($id, 'suspended', 'suspended');
        $this->assertNotNull(DB::table('companies')->where('id', $id)->value('suspended_at'));
    }

    public function test_company_activate_flips_both_status_columns_and_clears_suspension(): void
    {
        $id = $this->makeCompany([
            'status' => 'suspended', 'company_status' => 'suspended', 'suspended_at' => now(),
        ]);

        $this->actingAsAdmin()
            ->from('/admin/companies')
            ->post("/admin/companies/{$id}/activate")
            ->assertRedirect('/admin/companies');

        $this->assertCompanyStatus($id, 'approved', 'active');
        $this->assertNull(DB::table('companies')->where('id', $id)->value('suspended_at'));
    }

    public function test_company_soft_delete_moves_to_bin(): void
    {
        $id = $this->makeCompany(['status' => 'approved', 'company_status' => 'active']);

        $response = $this->actingAsAdmin()
            ->post("/admin/companies/{$id}/delete", ['reason' => 'Smoke bin test']);

        $response->assertRedirect(route('saas.admin.companies'));
        $response->assertSessionHas('success');
        $row = DB::table('companies')->where('id', $id)->first();
        $this->assertNotNull($row->deleted_at, 'Company must be soft-deleted, not destroyed');
        $this->assertSame('Smoke bin test', $row->deleted_reason);
    }

    // ── Franchise store / update / toggle ───────────────────────────────

    public function test_franchise_store_creates_row(): void
    {
        $response = $this->actingAsAdmin()
            ->from('/admin/franchises')
            ->post('/admin/franchises', [
                'name' => 'Smoke Franchise',
                'email' => 'franchise-post@taxnest.test',
                'phone' => '0300-1234567',
                'commission_rate' => 12.5,
                'password' => 'Secret@123',
            ]);

        $response->assertRedirect('/admin/franchises');
        $response->assertSessionHas('success');
        $response->assertSessionMissing('errors');
        $this->assertDatabaseHas('franchises', [
            'email' => 'franchise-post@taxnest.test',
            'status' => 'active',
        ]);
    }

    public function test_franchise_store_invalid_data_bounces_with_errors_not_silently(): void
    {
        $response = $this->actingAsAdmin()
            ->from('/admin/franchises')
            ->post('/admin/franchises', [
                'name' => 'Bad Franchise',
                'email' => 'not-an-email',
                'commission_rate' => 250, // > 100
                'password' => '123',      // < 6 chars
            ]);

        $response->assertRedirect('/admin/franchises');
        $response->assertSessionHasErrors(['email', 'commission_rate', 'password']);
        $this->assertDatabaseMissing('franchises', ['name' => 'Bad Franchise']);
    }

    public function test_franchise_update_and_toggle(): void
    {
        $id = DB::table('franchises')->insertGetId([
            'name' => 'Old Name', 'email' => 'franchise-upd@taxnest.test',
            'commission_rate' => 5, 'status' => 'active',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->actingAsAdmin()
            ->from('/admin/franchises')
            ->put("/admin/franchises/{$id}", [
                'name' => 'New Name',
                'email' => 'franchise-upd@taxnest.test',
                'commission_rate' => 15,
            ])
            ->assertRedirect('/admin/franchises')
            ->assertSessionHas('success');

        $this->assertDatabaseHas('franchises', ['id' => $id, 'name' => 'New Name']);
        $this->assertEquals(15, (float) DB::table('franchises')->where('id', $id)->value('commission_rate'));

        $this->actingAsAdmin()
            ->from('/admin/franchises')
            ->post("/admin/franchises/{$id}/toggle")
            ->assertRedirect('/admin/franchises');
        $this->assertSame('suspended', DB::table('franchises')->where('id', $id)->value('status'));

        $this->actingAsAdmin()
            ->from('/admin/franchises')
            ->post("/admin/franchises/{$id}/toggle")
            ->assertRedirect('/admin/franchises');
        $this->assertSame('active', DB::table('franchises')->where('id', $id)->value('status'));
    }

    // ── Payment proof approve / reject ──────────────────────────────────

    private function makeProof(int $companyId, int $planId): int
    {
        return DB::table('payment_proofs')->insertGetId([
            'company_id' => $companyId,
            'pricing_plan_id' => $planId,
            'status' => 'pending',
            'file_path' => 'proofs/smoke.jpg',
            'original_name' => 'smoke.jpg',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function test_payment_proof_approve_activates_subscription_and_unlocks_company(): void
    {
        $planId = $this->makePlan();
        $companyId = $this->makeCompany(); // pending/pending — payment must unlock it
        $proofId = $this->makeProof($companyId, $planId);

        $response = $this->actingAsAdmin()
            ->from('/admin/payment-proofs')
            ->post("/admin/payment-proofs/{$proofId}/approve", [
                'pricing_plan_id' => $planId,
                'billing_cycle' => 'annual',
            ]);

        $response->assertRedirect('/admin/payment-proofs');
        $response->assertSessionHas('success');
        $response->assertSessionMissing('errors');

        $proof = DB::table('payment_proofs')->where('id', $proofId)->first();
        $this->assertSame('verified', $proof->status);
        $this->assertNotNull($proof->subscription_id);
        $this->assertNotNull($proof->verified_at);

        $sub = DB::table('subscriptions')->where('id', $proof->subscription_id)->first();
        $this->assertNotNull($sub);
        $this->assertEquals($companyId, $sub->company_id);
        $this->assertEquals(1, (int) $sub->active);

        // Verified payment unlocks the company — BOTH status columns.
        $this->assertCompanyStatus($companyId, 'approved', 'active');
    }

    public function test_payment_proof_approve_never_reverses_a_suspension(): void
    {
        $planId = $this->makePlan();
        $companyId = $this->makeCompany([
            'status' => 'suspended', 'company_status' => 'suspended', 'suspended_at' => now(),
        ]);
        $proofId = $this->makeProof($companyId, $planId);

        $this->actingAsAdmin()
            ->from('/admin/payment-proofs')
            ->post("/admin/payment-proofs/{$proofId}/approve", [
                'pricing_plan_id' => $planId,
                'billing_cycle' => 'annual',
            ])
            ->assertRedirect('/admin/payment-proofs');

        // Proof processed, but the deliberate suspension stands.
        $this->assertSame('verified', DB::table('payment_proofs')->where('id', $proofId)->value('status'));
        $this->assertCompanyStatus($companyId, 'suspended', 'suspended');
    }

    public function test_payment_proof_approve_is_race_safe_against_double_processing(): void
    {
        $planId = $this->makePlan();
        $companyId = $this->makeCompany();
        $proofId = $this->makeProof($companyId, $planId);
        DB::table('payment_proofs')->where('id', $proofId)->update(['status' => 'verified']);

        $response = $this->actingAsAdmin()
            ->from('/admin/payment-proofs')
            ->post("/admin/payment-proofs/{$proofId}/approve", [
                'pricing_plan_id' => $planId,
                'billing_cycle' => 'annual',
            ]);

        $response->assertRedirect('/admin/payment-proofs');
        $response->assertSessionHas('error');
        $this->assertSame(0, DB::table('subscriptions')->count(), 'Already-processed proof must not create a subscription');
    }

    public function test_payment_proof_reject_records_reason_and_reviewer(): void
    {
        $planId = $this->makePlan();
        $companyId = $this->makeCompany();
        $proofId = $this->makeProof($companyId, $planId);

        $response = $this->actingAsAdmin()
            ->from('/admin/payment-proofs')
            ->post("/admin/payment-proofs/{$proofId}/reject", [
                'reject_reason' => 'Blurry screenshot',
            ]);

        $response->assertRedirect('/admin/payment-proofs');
        $response->assertSessionHas('success');

        $proof = DB::table('payment_proofs')->where('id', $proofId)->first();
        $this->assertSame('rejected', $proof->status);
        $this->assertSame('Blurry screenshot', $proof->reject_reason);
        $this->assertNotNull($proof->verified_by);
        $this->assertNotNull($proof->verified_at);
        // Company stays locked — no auto access after a rejection.
        $this->assertCompanyStatus($companyId, 'pending', 'pending');
    }

    // ── Sale campaign store / toggle ─────────────────────────────────────

    public function test_sale_store_creates_active_campaign(): void
    {
        $response = $this->actingAsAdmin()
            ->from('/admin/sales')
            ->post('/admin/sales', [
                'name' => 'Post Smoke Sale',
                'scope' => 'pos',
                'discount_percent' => 20,
            ]);

        $response->assertRedirect('/admin/sales');
        $response->assertSessionHas('success');
        $response->assertSessionMissing('errors');

        $row = DB::table('sale_campaigns')->where('name', 'Post Smoke Sale')->first();
        $this->assertNotNull($row);
        $this->assertSame('pos', $row->scope);
        $this->assertEquals(1, (int) $row->is_active);
        $this->assertNotNull($row->starts_at, 'Blank start must mean live-now');
    }

    public function test_sale_store_invalid_data_bounces_with_errors(): void
    {
        $response = $this->actingAsAdmin()
            ->from('/admin/sales')
            ->post('/admin/sales', [
                'scope' => 'everything',   // not in all,di,pos,fbrpos
                'discount_percent' => 150, // > 100
            ]);

        $response->assertRedirect('/admin/sales');
        $response->assertSessionHasErrors(['scope', 'discount_percent']);
        $this->assertSame(0, DB::table('sale_campaigns')->count());
    }

    public function test_sale_store_rejects_end_before_start(): void
    {
        $response = $this->actingAsAdmin()
            ->from('/admin/sales')
            ->post('/admin/sales', [
                'scope' => 'all',
                'discount_percent' => 10,
                'starts_at' => now()->addDays(5)->toDateString(),
                'ends_at' => now()->addDay()->toDateString(),
            ]);

        $response->assertRedirect('/admin/sales');
        $response->assertSessionHas('error');
        $this->assertSame(0, DB::table('sale_campaigns')->count());
    }

    public function test_sale_toggle_pauses_and_resumes(): void
    {
        $id = DB::table('sale_campaigns')->insertGetId([
            'name' => 'Toggle Sale', 'scope' => 'all', 'discount_percent' => 25,
            'starts_at' => now()->subDay(), 'is_active' => true,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->actingAsAdmin()
            ->from('/admin/sales')
            ->post("/admin/sales/{$id}/toggle")
            ->assertRedirect('/admin/sales')
            ->assertSessionHas('success');
        $this->assertEquals(0, (int) DB::table('sale_campaigns')->where('id', $id)->value('is_active'));

        $this->actingAsAdmin()
            ->from('/admin/sales')
            ->post("/admin/sales/{$id}/toggle")
            ->assertRedirect('/admin/sales');
        $this->assertEquals(1, (int) DB::table('sale_campaigns')->where('id', $id)->value('is_active'));
    }

    // ── Guests never reach the POST actions ──────────────────────────────

    public function test_guests_are_redirected_from_admin_post_actions(): void
    {
        $companyId = $this->makeCompany();

        foreach ([
            "/admin/companies/{$companyId}/approve",
            "/admin/companies/{$companyId}/suspend",
            '/admin/franchises',
            '/admin/sales',
        ] as $url) {
            $this->post($url)->assertRedirect('/admin/login');
        }

        $this->assertCompanyStatus($companyId, 'pending', 'pending');
    }
}
