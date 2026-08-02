<?php

namespace Tests\Feature;

use Tests\TestCase;
use App\Models\AdminUser;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;

/**
 * SMOKE-LOCK — Admin company create/edit and subscription-assign forms
 * must never silently fail.
 *
 * Companion to AdminActionPostsSmokeTest (action buttons) and
 * AdminPlanStorePostTest (plan forms). This locks:
 *   - POST /admin/companies            (store: company + admin user + trial)
 *   - PUT  /admin/companies/{id}       (update incl. admin credentials)
 *   - POST /admin/companies/{id}/limits (limits override)
 *   - POST /admin/companies/{id}/override/lifetime
 *   - POST /admin/companies/{id}/override/temporary
 *   - DELETE /admin/companies/{id}/override
 * asserting BOTH the redirect (never a 500 / silent validation bounce)
 * and the resulting database state, including the dual
 * companies.status + companies.company_status column rule.
 */
class AdminCompanyFormPostsSmokeTest extends TestCase
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
            $table->string('business_activity')->nullable();
            $table->string('website')->nullable();
            $table->string('product_type')->nullable();
            $table->string('status')->default('approved');
            $table->string('company_status')->default('active');
            $table->unsignedBigInteger('franchise_id')->nullable();
            $table->unsignedBigInteger('requested_plan_id')->nullable();
            $table->decimal('standard_tax_rate', 5, 2)->nullable();
            $table->string('invoice_number_prefix')->nullable();
            $table->string('fbr_environment')->nullable();
            $table->string('fbr_registration_no')->nullable();
            $table->string('fbr_business_name')->nullable();
            $table->boolean('fbr_pos_enabled')->default(false);
            $table->string('fbr_pos_environment')->nullable();
            $table->string('fbr_pos_id')->nullable();
            $table->boolean('fbr_reporting_enabled')->default(false);
            $table->boolean('pra_reporting_enabled')->default(false);
            $table->string('pra_environment')->nullable();
            $table->string('pra_pos_id')->nullable();
            $table->integer('invoice_limit_override')->nullable();
            $table->integer('user_limit_override')->nullable();
            $table->integer('branch_limit_override')->nullable();
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
            $table->integer('invoice_limit')->default(0);
            $table->boolean('is_trial')->default(false);
            $table->text('features')->nullable();
            $table->timestamps();
        });

        Schema::create('subscriptions', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('company_id');
            $table->unsignedBigInteger('pricing_plan_id')->nullable();
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

        // Anti-reuse credential ledger written by the store() path.
        Schema::create('registered_credentials', function (Blueprint $table) {
            $table->id();
            $table->string('credential_type', 20);
            $table->string('credential_value', 191);
            $table->string('product_type', 20)->nullable();
            $table->unsignedBigInteger('company_id')->nullable();
            $table->timestamp('created_at')->nullable();
            $table->unique(['credential_type', 'credential_value']);
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
            'name' => 'Form Co',
            'owner_name' => 'Owner',
            'product_type' => 'di',
            'status' => 'approved',
            'company_status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ], $overrides));
    }

    private function assertCompanyStatus(int $id, string $status, string $companyStatus): void
    {
        $row = DB::table('companies')->where('id', $id)->first();
        $this->assertNotNull($row, "Company {$id} vanished");
        $this->assertSame($status, $row->status, 'companies.status wrong');
        $this->assertSame($companyStatus, $row->company_status, 'companies.company_status wrong');
    }

    private function validStorePayload(array $overrides = []): array
    {
        return array_merge([
            'name' => 'Stored Co',
            'owner_name' => 'Store Owner',
            'product_type' => 'di',
            'email' => 'stored-co@taxnest.test',
            'phone' => '0300-1112223',
            'status' => 'approved',
            'admin_name' => 'Co Admin',
            'admin_email' => 'stored-admin@taxnest.test',
            'admin_password' => 'Secret@123',
        ], $overrides);
    }

    // ── Company store ────────────────────────────────────────────────────

    public function test_company_store_creates_company_admin_user_trial_and_ledger(): void
    {
        $response = $this->actingAsAdmin()
            ->from('/admin/companies/create')
            ->post('/admin/companies', $this->validStorePayload());

        $company = DB::table('companies')->where('name', 'Stored Co')->first();
        $this->assertNotNull($company, 'Company row must be created');

        $response->assertRedirect(route('saas.admin.companies.show', $company->id));
        $response->assertSessionHas('success');
        $response->assertSessionMissing('errors');

        // Dual status columns: chosen status + always-active company_status.
        $this->assertCompanyStatus((int) $company->id, 'approved', 'active');

        // Company admin user attached to the new company.
        $user = DB::table('users')->where('email', 'stored-admin@taxnest.test')->first();
        $this->assertNotNull($user, 'Admin user must be created');
        $this->assertEquals($company->id, $user->company_id);
        $this->assertSame('company_admin', $user->role);
        $this->assertTrue(Hash::check('Secret@123', $user->password));

        // Every company starts with a subscription row (trial).
        $sub = DB::table('subscriptions')->where('company_id', $company->id)->where('active', true)->first();
        $this->assertNotNull($sub, 'Admin-created company must get a trial subscription');
        $this->assertNotNull($sub->trial_ends_at);

        // Credential ledger recorded (anti-reuse).
        $this->assertDatabaseHas('registered_credentials', [
            'credential_type' => 'email',
            'credential_value' => 'stored-admin@taxnest.test',
            'company_id' => $company->id,
        ]);
    }

    public function test_company_store_fbrpos_flags_and_pos_role(): void
    {
        $this->actingAsAdmin()
            ->post('/admin/companies', $this->validStorePayload([
                'name' => 'FBR Shop',
                'product_type' => 'fbrpos',
                'email' => 'fbr-shop@taxnest.test',
                'admin_email' => 'fbr-shop-admin@taxnest.test',
            ]))
            ->assertSessionMissing('errors')
            ->assertSessionHas('success');

        $company = DB::table('companies')->where('name', 'FBR Shop')->first();
        $this->assertNotNull($company);
        $this->assertEquals(1, (int) $company->fbr_pos_enabled);
        $this->assertEquals(1, (int) $company->fbr_reporting_enabled);
        $this->assertSame('sandbox', $company->fbr_pos_environment);
        $this->assertSame('pos_admin', DB::table('users')->where('email', 'fbr-shop-admin@taxnest.test')->value('pos_role'));
    }

    public function test_company_store_invalid_data_bounces_with_errors_not_silently(): void
    {
        $response = $this->actingAsAdmin()
            ->from('/admin/companies/create')
            ->post('/admin/companies', [
                'name' => 'Bad Co',
                // owner_name missing
                'product_type' => 'shop',        // not in di,pos,fbrpos
                'email' => 'not-an-email',
                'status' => 'live',              // not in approved,pending
                'admin_name' => 'X',
                'admin_email' => 'also-bad',
                'admin_password' => '123',       // < 6 chars
            ]);

        $response->assertRedirect('/admin/companies/create');
        $response->assertSessionHasErrors([
            'owner_name', 'product_type', 'email', 'status', 'admin_email', 'admin_password',
        ]);
        $this->assertSame(0, DB::table('companies')->count());
        $this->assertSame(0, DB::table('users')->count());
    }

    public function test_company_store_rejects_duplicate_admin_email(): void
    {
        DB::table('users')->insert([
            'name' => 'Existing', 'email' => 'taken@taxnest.test',
            'password' => Hash::make('x'), 'created_at' => now(), 'updated_at' => now(),
        ]);

        $response = $this->actingAsAdmin()
            ->from('/admin/companies/create')
            ->post('/admin/companies', $this->validStorePayload([
                'admin_email' => 'taken@taxnest.test',
            ]));

        $response->assertRedirect('/admin/companies/create');
        $response->assertSessionHasErrors(['admin_email']);
        $this->assertSame(0, DB::table('companies')->count());
    }

    // ── Company update ───────────────────────────────────────────────────

    public function test_company_update_saves_profile_fields(): void
    {
        $id = $this->makeCompany();

        $response = $this->actingAsAdmin()
            ->from("/admin/companies/{$id}/edit")
            ->put("/admin/companies/{$id}", [
                'name' => 'Renamed Co',
                'owner_name' => 'New Owner',
                'email' => 'renamed@taxnest.test',
                'city' => 'Lahore',
                'standard_tax_rate' => 17.5,
            ]);

        $response->assertRedirect(route('saas.admin.companies.show', $id));
        $response->assertSessionHas('success');
        $response->assertSessionMissing('errors');

        $row = DB::table('companies')->where('id', $id)->first();
        $this->assertSame('Renamed Co', $row->name);
        $this->assertSame('New Owner', $row->owner_name);
        $this->assertSame('Lahore', $row->city);
        $this->assertEquals(17.5, (float) $row->standard_tax_rate);
        // Update never touches the status columns.
        $this->assertCompanyStatus($id, 'approved', 'active');
    }

    public function test_company_update_invalid_data_bounces_and_leaves_row_unchanged(): void
    {
        $id = $this->makeCompany(['name' => 'Keep Me']);

        $response = $this->actingAsAdmin()
            ->from("/admin/companies/{$id}/edit")
            ->put("/admin/companies/{$id}", [
                'name' => '',                     // required
                'email' => 'not-an-email',
                'standard_tax_rate' => 250,       // > 100
                'admin_password' => '123',        // < 6
            ]);

        $response->assertRedirect("/admin/companies/{$id}/edit");
        $response->assertSessionHasErrors(['name', 'email', 'standard_tax_rate', 'admin_password']);
        $this->assertSame('Keep Me', DB::table('companies')->where('id', $id)->value('name'));
    }

    public function test_company_update_rotates_admin_credentials(): void
    {
        $id = $this->makeCompany();
        $adminId = DB::table('users')->insertGetId([
            'name' => 'Co Admin', 'email' => 'old-admin@taxnest.test',
            'password' => Hash::make('OldPass@1'), 'company_id' => $id,
            'role' => 'company_admin', 'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->actingAsAdmin()
            ->put("/admin/companies/{$id}", [
                'name' => 'Form Co',
                'admin_email' => 'new-admin@taxnest.test',
                'admin_password' => 'NewPass@1',
            ])
            ->assertRedirect(route('saas.admin.companies.show', $id))
            ->assertSessionHas('success');

        $user = DB::table('users')->where('id', $adminId)->first();
        $this->assertSame('new-admin@taxnest.test', $user->email);
        $this->assertTrue(Hash::check('NewPass@1', $user->password));
    }

    public function test_company_update_rejects_admin_email_taken_by_another_user(): void
    {
        $id = $this->makeCompany();
        DB::table('users')->insert([
            ['name' => 'Co Admin', 'email' => 'co-admin@taxnest.test', 'password' => Hash::make('x'),
             'company_id' => $id, 'role' => 'company_admin', 'created_at' => now(), 'updated_at' => now()],
            ['name' => 'Other', 'email' => 'other-user@taxnest.test', 'password' => Hash::make('x'),
             'company_id' => null, 'role' => null, 'created_at' => now(), 'updated_at' => now()],
        ]);

        $response = $this->actingAsAdmin()
            ->from("/admin/companies/{$id}/edit")
            ->put("/admin/companies/{$id}", [
                'name' => 'Form Co',
                'admin_email' => 'other-user@taxnest.test',
            ]);

        $response->assertRedirect("/admin/companies/{$id}/edit");
        $response->assertSessionHasErrors(['admin_email']);
        $this->assertSame('co-admin@taxnest.test', DB::table('users')->where('company_id', $id)->value('email'));
    }

    // ── Limits override ──────────────────────────────────────────────────

    public function test_limits_update_saves_all_three_overrides(): void
    {
        $id = $this->makeCompany();

        $response = $this->actingAsAdmin()
            ->from("/admin/companies/{$id}")
            ->post("/admin/companies/{$id}/limits", [
                'invoice_limit_override' => 5000,
                'user_limit_override' => 25,
                'branch_limit_override' => 3,
            ]);

        $response->assertRedirect("/admin/companies/{$id}");
        $response->assertSessionHas('success');
        $response->assertSessionMissing('errors');

        $row = DB::table('companies')->where('id', $id)->first();
        $this->assertEquals(5000, (int) $row->invoice_limit_override);
        $this->assertEquals(25, (int) $row->user_limit_override);
        $this->assertEquals(3, (int) $row->branch_limit_override);
    }

    public function test_limits_update_invalid_data_bounces_with_errors(): void
    {
        $id = $this->makeCompany(['invoice_limit_override' => 100]);

        $response = $this->actingAsAdmin()
            ->from("/admin/companies/{$id}")
            ->post("/admin/companies/{$id}/limits", [
                'invoice_limit_override' => -5,
                'user_limit_override' => 'lots',
            ]);

        $response->assertRedirect("/admin/companies/{$id}");
        $response->assertSessionHasErrors(['invoice_limit_override', 'user_limit_override']);
        $this->assertEquals(100, (int) DB::table('companies')->where('id', $id)->value('invoice_limit_override'));
    }

    // ── Subscription overrides: lifetime / temporary / remove ───────────

    private function makeSubscription(int $companyId, array $overrides = []): int
    {
        return DB::table('subscriptions')->insertGetId(array_merge([
            'company_id' => $companyId,
            'pricing_plan_id' => null,
            'billing_cycle' => 'monthly',
            'active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ], $overrides));
    }

    public function test_grant_lifetime_sets_override_on_existing_subscription_and_unlocks_pending_company(): void
    {
        $id = $this->makeCompany(['status' => 'pending', 'company_status' => 'pending']);
        $subId = $this->makeSubscription($id, ['active' => false]);

        $response = $this->actingAsAdmin()
            ->from("/admin/companies/{$id}")
            ->post("/admin/companies/{$id}/override/lifetime", ['reason' => 'VIP client']);

        $response->assertRedirect("/admin/companies/{$id}");
        $response->assertSessionHas('success');
        $response->assertSessionMissing('errors');

        // Override lands on the SAME row hasAccess() reads, re-activated.
        $this->assertSame(1, DB::table('subscriptions')->where('company_id', $id)->count(), 'Must reuse the existing subscription row');
        $sub = DB::table('subscriptions')->where('id', $subId)->first();
        $this->assertSame('lifetime', $sub->override_type);
        $this->assertNull($sub->override_until);
        $this->assertSame('VIP client', $sub->override_reason);
        $this->assertNotNull($sub->override_granted_at);
        $this->assertNotNull($sub->override_by);
        $this->assertEquals(1, (int) $sub->active);

        // Grant unlocks a pending company — BOTH status columns.
        $this->assertCompanyStatus($id, 'approved', 'active');
    }

    public function test_grant_lifetime_creates_subscription_when_none_exists_and_never_reverses_suspension(): void
    {
        $id = $this->makeCompany([
            'status' => 'suspended', 'company_status' => 'suspended', 'suspended_at' => now(),
        ]);

        $this->actingAsAdmin()
            ->from("/admin/companies/{$id}")
            ->post("/admin/companies/{$id}/override/lifetime")
            ->assertRedirect("/admin/companies/{$id}")
            ->assertSessionHas('success');

        $sub = DB::table('subscriptions')->where('company_id', $id)->first();
        $this->assertNotNull($sub, 'Grant must create a subscription row when none exists');
        $this->assertSame('lifetime', $sub->override_type);
        $this->assertEquals(1, (int) $sub->active);

        // Deliberate suspension stands.
        $this->assertCompanyStatus($id, 'suspended', 'suspended');
    }

    public function test_grant_temporary_sets_until_and_invoice_limit(): void
    {
        $id = $this->makeCompany(['status' => 'pending', 'company_status' => 'pending']);
        $subId = $this->makeSubscription($id);
        $until = now()->addDays(30)->toDateString();

        $response = $this->actingAsAdmin()
            ->from("/admin/companies/{$id}")
            ->post("/admin/companies/{$id}/override/temporary", [
                'until' => $until,
                'free_invoice_limit' => 500,
                'reason' => 'Payment on the way',
            ]);

        $response->assertRedirect("/admin/companies/{$id}");
        $response->assertSessionHas('success');
        $response->assertSessionMissing('errors');

        $sub = DB::table('subscriptions')->where('id', $subId)->first();
        $this->assertSame('temporary', $sub->override_type);
        $this->assertStringStartsWith($until, (string) $sub->override_until);
        $this->assertEquals(500, (int) $sub->free_invoice_limit);
        $this->assertSame('Payment on the way', $sub->override_reason);
        $this->assertCompanyStatus($id, 'approved', 'active');
    }

    public function test_grant_temporary_invalid_data_bounces_and_leaves_subscription_unchanged(): void
    {
        $id = $this->makeCompany();
        $subId = $this->makeSubscription($id);

        $response = $this->actingAsAdmin()
            ->from("/admin/companies/{$id}")
            ->post("/admin/companies/{$id}/override/temporary", [
                'until' => now()->subDay()->toDateString(), // must be after today
                'free_invoice_limit' => 0,                   // min:1
            ]);

        $response->assertRedirect("/admin/companies/{$id}");
        $response->assertSessionHasErrors(['until', 'free_invoice_limit']);

        $sub = DB::table('subscriptions')->where('id', $subId)->first();
        $this->assertNull($sub->override_type);
        $this->assertNull($sub->override_until);
    }

    public function test_remove_override_resets_all_override_fields(): void
    {
        $id = $this->makeCompany();
        $subId = $this->makeSubscription($id, [
            'override_type' => 'temporary',
            'override_until' => now()->addDays(10),
            'override_granted_at' => now(),
            'free_invoice_limit' => 100,
            'override_reason' => 'Old grant',
            'override_by' => 1,
        ]);

        $response = $this->actingAsAdmin()
            ->from("/admin/companies/{$id}")
            ->delete("/admin/companies/{$id}/override");

        $response->assertRedirect("/admin/companies/{$id}");
        $response->assertSessionHas('success');

        $sub = DB::table('subscriptions')->where('id', $subId)->first();
        $this->assertSame('none', $sub->override_type);
        $this->assertNull($sub->override_until);
        $this->assertNull($sub->override_granted_at);
        $this->assertNull($sub->free_invoice_limit);
        $this->assertNull($sub->override_reason);
        $this->assertNull($sub->override_by);
    }

    public function test_remove_override_without_subscription_flashes_error_not_500(): void
    {
        $id = $this->makeCompany();

        $response = $this->actingAsAdmin()
            ->from("/admin/companies/{$id}")
            ->delete("/admin/companies/{$id}/override");

        $response->assertRedirect("/admin/companies/{$id}");
        $response->assertSessionHas('error');
    }

    // ── Guests never reach these POSTs ───────────────────────────────────

    public function test_guests_are_redirected_from_company_form_posts(): void
    {
        $id = $this->makeCompany(['status' => 'pending', 'company_status' => 'pending']);

        $this->post('/admin/companies', $this->validStorePayload())->assertRedirect('/admin/login');
        $this->put("/admin/companies/{$id}", ['name' => 'Hacked'])->assertRedirect('/admin/login');
        $this->post("/admin/companies/{$id}/limits", ['invoice_limit_override' => 9])->assertRedirect('/admin/login');
        $this->post("/admin/companies/{$id}/override/lifetime")->assertRedirect('/admin/login');
        $this->delete("/admin/companies/{$id}/override")->assertRedirect('/admin/login');

        $this->assertSame('Form Co', DB::table('companies')->where('id', $id)->value('name'));
        $this->assertSame(0, DB::table('subscriptions')->count());
        $this->assertCompanyStatus($id, 'pending', 'pending');
    }
}
