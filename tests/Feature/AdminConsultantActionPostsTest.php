<?php

namespace Tests\Feature;

use App\Models\AdminUser;
use App\Models\Company;
use App\Models\ConsultantClientLink;
use App\Models\ConsultantCommission;
use App\Models\ConsultantProfile;
use App\Models\PricingPlan;
use App\Models\Subscription;
use App\Models\User;
use App\Services\ConsultantService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * SMOKE-LOCK — SaaS-admin consultant panel POST actions must never
 * silently fail (same rule as the other admin panels: every action
 * redirects with a success/error flash, wrong input flashes validation
 * errors, and the database state actually changes).
 *
 * Locked routes (admin_users guard, /admin prefix):
 *   POST /admin/consultants/{id}/rate          — commission rate change
 *   POST /admin/consultants/{id}/toggle        — enable/disable profile
 *   POST /admin/consultant-links/{id}/revoke   — admin-side link revoke
 *   POST /admin/consultant-commissions/{id}/paid — mark payout done
 */
class AdminConsultantActionPostsTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Schema::dropAllTables();

        // ── Admin guard ──────────────────────────────────────────────
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

        // ── App-side tables (mirrors ConsultantConsoleTest fixtures) ──
        Schema::create('companies', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('ntn')->nullable();
            $table->string('email')->nullable();
            $table->string('phone')->nullable();
            $table->string('status')->default('pending');
            $table->string('company_status')->default('active');
            $table->string('product_type')->nullable();
            $table->boolean('onboarding_completed')->default(true);
            $table->boolean('is_internal_account')->default(false);
            $table->integer('invoice_limit_override')->nullable();
            $table->unsignedBigInteger('requested_plan_id')->nullable();
            $table->unsignedBigInteger('referred_by_user_id')->nullable();
            $table->string('referral_code_used')->nullable();
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

        Schema::create('invoices', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('company_id')->nullable();
            $table->string('status')->nullable();
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

        Schema::create('audit_logs', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('company_id')->nullable();
            $table->unsignedBigInteger('user_id')->nullable();
            $table->string('action');
            $table->string('entity_type')->nullable();
            $table->unsignedBigInteger('entity_id')->nullable();
            $table->text('old_values')->nullable();
            $table->text('new_values')->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->string('sha256_hash')->nullable();
            $table->timestamp('created_at')->nullable();
        });

        Schema::create('consultant_profiles', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id')->unique();
            $table->string('referral_code', 20)->unique();
            $table->string('status', 20)->default('active');
            $table->decimal('commission_rate', 5, 2)->default(10.00);
            $table->string('payout_notes', 500)->nullable();
            $table->timestamps();
        });

        Schema::create('consultant_client_links', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('consultant_user_id');
            $table->unsignedBigInteger('company_id');
            $table->string('status', 20)->default('pending');
            $table->string('initiated_by', 20)->nullable();
            $table->unsignedBigInteger('approved_by_user_id')->nullable();
            $table->timestamp('approved_at')->nullable();
            $table->string('revoked_by', 20)->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->timestamps();
            $table->unique(['consultant_user_id', 'company_id']);
        });

        Schema::create('consultant_commissions', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('consultant_user_id')->nullable();
            $table->unsignedBigInteger('company_id')->nullable();
            $table->string('company_name')->nullable();
            $table->unsignedBigInteger('subscription_id')->nullable();
            $table->string('description')->nullable();
            $table->decimal('base_amount', 12, 2)->default(0);
            $table->decimal('rate_percent', 5, 2)->default(0);
            $table->decimal('amount', 12, 2)->default(0);
            $table->string('status', 20)->default('pending');
            $table->string('source', 30)->default('subscription');
            $table->timestamp('paid_at')->nullable();
            $table->unsignedBigInteger('paid_by_admin_id')->nullable();
            $table->string('payout_reference')->nullable();
            $table->timestamps();
        });

        DB::table('admin_users')->insert([
            'name' => 'Consultant Panel Admin',
            'email' => 'consultant-admin@taxnest.test',
            'password' => Hash::make('Smoke@12345'),
            'role' => 'super_admin',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        Mail::fake();
        Notification::fake();
    }

    // ── Fixtures ────────────────────────────────────────────────────────

    private function actingAsAdmin(): self
    {
        return $this->actingAs(AdminUser::first(), 'admin');
    }

    private function makeCompany(array $attrs = []): Company
    {
        static $i = 0;
        $i++;
        return Company::create(array_merge([
            'name' => "Company $i",
            'ntn' => "200000$i-$i",
            'product_type' => 'di',
            'status' => 'active',
            'company_status' => 'active',
            'onboarding_completed' => true,
        ], $attrs));
    }

    private function makeUser(Company $company, array $attrs = []): User
    {
        static $j = 0;
        $j++;
        return User::create(array_merge([
            'name' => "User $j",
            'email' => "user$j@test.pk",
            'password' => Hash::make('secret-pass-123'),
            'company_id' => $company->id,
            'role' => 'company_admin',
            'is_active' => true,
        ], $attrs));
    }

    /** @return array{0: User, 1: ConsultantProfile, 2: Company} */
    private function makeConsultant(): array
    {
        $company = $this->makeCompany();
        $user = $this->makeUser($company);
        $profile = ConsultantService::activateProfile($user);
        return [$user, $profile, $company];
    }

    private function makePendingCommission(User $consultant, array $attrs = []): ConsultantCommission
    {
        return ConsultantCommission::create(array_merge([
            'consultant_user_id' => $consultant->id,
            'company_name' => 'Payer Co',
            'description' => 'Plan · monthly',
            'base_amount' => 3000,
            'rate_percent' => 10,
            'amount' => 300,
            'status' => 'pending',
        ], $attrs));
    }

    // ── Rate change ──────────────────────────────────────────────────────

    public function test_rate_update_persists_and_applies_to_future_commissions(): void
    {
        [, $profile] = $this->makeConsultant();

        $response = $this->actingAsAdmin()
            ->from('/admin/consultants')
            ->post("/admin/consultants/{$profile->id}/rate", ['commission_rate' => 17.5]);

        $response->assertRedirect('/admin/consultants');
        $response->assertSessionHas('success');
        $response->assertSessionMissing('errors');
        $this->assertEquals(17.5, (float) $profile->fresh()->commission_rate);
        $this->assertDatabaseHas('admin_audit_logs', [
            'action' => 'Consultant rate updated',
            'target_id' => $profile->id,
        ]);
    }

    public function test_rate_update_invalid_input_flashes_validation_errors_not_silent(): void
    {
        [, $profile] = $this->makeConsultant();
        $original = (float) $profile->commission_rate;

        // Over 100%, non-numeric, and missing — every one must bounce loudly.
        foreach ([['commission_rate' => 150], ['commission_rate' => 'abc'], []] as $payload) {
            $response = $this->actingAsAdmin()
                ->from('/admin/consultants')
                ->post("/admin/consultants/{$profile->id}/rate", $payload);

            $response->assertRedirect('/admin/consultants');
            $response->assertSessionHasErrors(['commission_rate']);
        }

        $this->assertEquals($original, (float) $profile->fresh()->commission_rate, 'Invalid input must never change the rate');
    }

    // ── Toggle enable/disable ────────────────────────────────────────────

    public function test_toggle_disables_consultant_and_blocks_new_commissions(): void
    {
        [$consultant, $profile] = $this->makeConsultant();

        $response = $this->actingAsAdmin()
            ->from('/admin/consultants')
            ->post("/admin/consultants/{$profile->id}/toggle");

        $response->assertRedirect('/admin/consultants');
        $response->assertSessionHas('success');
        $this->assertSame('disabled', $profile->fresh()->status);

        // Disabled consultant must earn NOTHING new from referred payments.
        $referred = $this->makeCompany(['referred_by_user_id' => $consultant->id]);
        $plan = PricingPlan::create(['name' => 'Paid Plan', 'product_type' => 'di', 'price' => 3000, 'is_trial' => false]);
        $sub = Subscription::create([
            'company_id' => $referred->id,
            'pricing_plan_id' => $plan->id,
            'billing_cycle' => 'monthly',
            'final_price' => 3000,
            'active' => true,
        ]);

        $this->assertNull(ConsultantService::recordCommissionForSubscription($sub));
        $this->assertSame(0, ConsultantCommission::count());

        // Toggle back re-enables and commissions flow again.
        $this->actingAsAdmin()
            ->from('/admin/consultants')
            ->post("/admin/consultants/{$profile->id}/toggle")
            ->assertRedirect('/admin/consultants')
            ->assertSessionHas('success');
        $this->assertSame('active', $profile->fresh()->status);

        $commission = ConsultantService::recordCommissionForSubscription($sub);
        $this->assertNotNull($commission);
        $this->assertSame('pending', $commission->status);
    }

    // ── Admin link revoke ────────────────────────────────────────────────

    public function test_admin_revoke_marks_link_revoked_by_admin(): void
    {
        [$consultant] = $this->makeConsultant();
        $client = $this->makeCompany();
        $link = ConsultantClientLink::create([
            'consultant_user_id' => $consultant->id,
            'company_id' => $client->id,
            'status' => 'active',
        ]);

        $response = $this->actingAsAdmin()
            ->from('/admin/consultants')
            ->post("/admin/consultant-links/{$link->id}/revoke");

        $response->assertRedirect('/admin/consultants');
        $response->assertSessionHas('success');

        $link->refresh();
        $this->assertSame('revoked', $link->status);
        $this->assertSame('admin', $link->revoked_by);
        $this->assertNotNull($link->revoked_at);
        $this->assertDatabaseHas('admin_audit_logs', [
            'action' => 'Consultant link revoked',
            'target_id' => $link->id,
        ]);
    }

    public function test_admin_revoke_of_already_revoked_link_flashes_error_not_silent(): void
    {
        [$consultant] = $this->makeConsultant();
        $client = $this->makeCompany();
        $link = ConsultantClientLink::create([
            'consultant_user_id' => $consultant->id,
            'company_id' => $client->id,
            'status' => 'revoked',
            'revoked_by' => 'client',
            'revoked_at' => now(),
        ]);

        $response = $this->actingAsAdmin()
            ->from('/admin/consultants')
            ->post("/admin/consultant-links/{$link->id}/revoke");

        $response->assertRedirect('/admin/consultants');
        $response->assertSessionHas('error');
        $this->assertSame('client', $link->fresh()->revoked_by, 'Second revoke must not overwrite the original revoker');
    }

    public function test_admin_revoke_forces_switched_consultant_out_on_next_request(): void
    {
        [$consultant] = $this->makeConsultant();
        $client = $this->makeCompany();
        $clientAdmin = $this->makeUser($client);

        $link = ConsultantClientLink::create([
            'consultant_user_id' => $consultant->id,
            'company_id' => $client->id,
            'status' => 'active',
        ]);

        // Consultant switches into the client session (web guard).
        $this->actingAs($consultant)->post("/consultant/switch/{$client->id}")->assertRedirect('/dashboard');
        $this->assertAuthenticatedAs($clientAdmin->fresh());

        // SaaS admin revokes the link from the admin panel (admin guard).
        $this->actingAsAdmin()
            ->from('/admin/consultants')
            ->post("/admin/consultant-links/{$link->id}/revoke")
            ->assertRedirect('/admin/consultants')
            ->assertSessionHas('success');
        $this->assertSame('revoked', $link->fresh()->status);

        // The very next request in the switched session must force-exit.
        // (Check the WEB guard explicitly — actingAs(admin) switched the default guard.)
        $this->get('/dashboard')->assertRedirect('/consultant');
        $this->assertAuthenticatedAs($consultant->fresh(), 'web');
        $this->assertFalse(session()->has(ConsultantService::SESSION_KEY));
    }

    // ── Mark commission paid ─────────────────────────────────────────────

    public function test_mark_paid_sets_payout_fields_and_reference(): void
    {
        [$consultant] = $this->makeConsultant();
        $commission = $this->makePendingCommission($consultant);
        $adminId = AdminUser::first()->id;

        $response = $this->actingAsAdmin()
            ->from('/admin/consultants')
            ->post("/admin/consultant-commissions/{$commission->id}/paid", [
                'payout_reference' => 'JazzCash TXN-778899',
            ]);

        $response->assertRedirect('/admin/consultants');
        $response->assertSessionHas('success');
        $response->assertSessionMissing('errors');

        $commission->refresh();
        $this->assertSame('paid', $commission->status);
        $this->assertNotNull($commission->paid_at);
        $this->assertEquals($adminId, $commission->paid_by_admin_id);
        $this->assertSame('JazzCash TXN-778899', $commission->payout_reference);
        $this->assertDatabaseHas('admin_audit_logs', [
            'action' => 'Consultant commission paid',
            'target_id' => $commission->id,
        ]);
    }

    public function test_mark_paid_is_idempotent_double_mark_flashes_error(): void
    {
        [$consultant] = $this->makeConsultant();
        $commission = $this->makePendingCommission($consultant);

        $this->actingAsAdmin()
            ->from('/admin/consultants')
            ->post("/admin/consultant-commissions/{$commission->id}/paid", ['payout_reference' => 'FIRST-REF'])
            ->assertSessionHas('success');

        $firstPaidAt = $commission->fresh()->paid_at;

        // Second mark must bounce with an error and change NOTHING.
        $response = $this->actingAsAdmin()
            ->from('/admin/consultants')
            ->post("/admin/consultant-commissions/{$commission->id}/paid", ['payout_reference' => 'SECOND-REF']);

        $response->assertRedirect('/admin/consultants');
        $response->assertSessionHas('error');

        $commission->refresh();
        $this->assertSame('paid', $commission->status);
        $this->assertSame('FIRST-REF', $commission->payout_reference);
        $this->assertEquals($firstPaidAt, $commission->paid_at);
    }

    public function test_mark_paid_overlong_reference_flashes_validation_error(): void
    {
        [$consultant] = $this->makeConsultant();
        $commission = $this->makePendingCommission($consultant);

        $response = $this->actingAsAdmin()
            ->from('/admin/consultants')
            ->post("/admin/consultant-commissions/{$commission->id}/paid", [
                'payout_reference' => str_repeat('x', 300),
            ]);

        $response->assertRedirect('/admin/consultants');
        $response->assertSessionHasErrors(['payout_reference']);
        $this->assertSame('pending', $commission->fresh()->status, 'Failed validation must not mark paid');
    }

    // ── Guests never reach the POST actions ──────────────────────────────

    public function test_guests_are_redirected_from_consultant_admin_posts(): void
    {
        [$consultant, $profile] = $this->makeConsultant();
        $client = $this->makeCompany();
        $link = ConsultantClientLink::create([
            'consultant_user_id' => $consultant->id,
            'company_id' => $client->id,
            'status' => 'active',
        ]);
        $commission = $this->makePendingCommission($consultant);

        foreach ([
            "/admin/consultants/{$profile->id}/rate",
            "/admin/consultants/{$profile->id}/toggle",
            "/admin/consultant-links/{$link->id}/revoke",
            "/admin/consultant-commissions/{$commission->id}/paid",
        ] as $url) {
            $this->post($url)->assertRedirect('/admin/login');
        }

        $this->assertSame('active', $profile->fresh()->status);
        $this->assertSame('active', $link->fresh()->status);
        $this->assertSame('pending', $commission->fresh()->status);
    }
}
