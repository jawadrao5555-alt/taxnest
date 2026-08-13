<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\PosFeatureService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * FBR BUSINESS PROFILE — ADMIN-ONLY LOCKOUT (Task #579 review fix).
 *
 * /fbr-pos/business-profile now edits the company CNIC — a LOGIN identifier.
 * Unlike the PRA twin (which sits inside the PosAdminOnly route group), the
 * FBR route group has no admin middleware, so the gate lives in the
 * controller. This test locks it in over real HTTP:
 *
 *   1. cashier GET  /fbr-pos/business-profile → 403
 *   2. cashier POST (tries to overwrite company CNIC) → 403, cnic UNCHANGED
 *   3. admin  POST dashed CNIC → redirect, cnic saved as plain digits
 *
 * GOTCHA (same as PosHazriCashierLockoutTest): the cashier fixture must use
 * base role='employee' — a role='company_admin' user passes isPosAdmin()
 * regardless of pos_role, and the 403 assertions would be vacuous.
 *
 * Run:
 *   env -u DATABASE_URL -u DB_CONNECTION -u PGHOST -u PGPORT -u PGUSER \
 *     -u PGPASSWORD -u PGDATABASE APP_ENV=testing DB_CONNECTION=sqlite \
 *     DB_DATABASE=':memory:' php vendor/bin/phpunit tests/Feature/FbrBusinessProfileCnicLockoutTest.php
 */
class FbrBusinessProfileCnicLockoutTest extends TestCase
{
    private int $fbrCompanyId;

    protected function setUp(): void
    {
        parent::setUp();
        PosFeatureService::flushGateCaches();
        Schema::dropAllTables();
        $this->buildSchema();

        $this->fbrCompanyId = DB::table('companies')->insertGetId([
            'name'                => 'FBR CNIC Lockout Shop',
            'product_type'        => 'fbrpos',
            'status'              => 'active',
            'company_status'      => 'approved',
            'is_internal_account' => true,
            'fbr_pos_enabled'     => true,
            'pos_setup_completed' => true,
            'cnic'                => '3520212345678',
            'created_at'          => now(),
            'updated_at'          => now(),
        ]);
    }

    protected function tearDown(): void
    {
        PosFeatureService::flushGateCaches();
        parent::tearDown();
    }

    // ── tests ────────────────────────────────────────────────────────────────

    /** Cashier (base role 'employee') must not even see the page. */
    public function test_cashier_get_business_profile_is_403(): void
    {
        $cashier = $this->makeUser('employee', 'pos_cashier', 'cashier@fbr.cnic');

        $this->actingAs($cashier, 'fbrpos')
            ->get('/fbr-pos/business-profile')
            ->assertForbidden();
    }

    /** Cashier POST is rejected and the company CNIC stays untouched. */
    public function test_cashier_post_cannot_alter_company_cnic(): void
    {
        $cashier = $this->makeUser('employee', 'pos_cashier', 'cashier2@fbr.cnic');

        $this->actingAs($cashier, 'fbrpos')
            ->post('/fbr-pos/business-profile', [
                'name' => 'Hijacked Shop',
                'cnic' => '35202-9999999-9',
            ])
            ->assertForbidden();

        $row = DB::table('companies')->find($this->fbrCompanyId);
        $this->assertSame('3520212345678', $row->cnic, 'cashier POST must never change the company CNIC');
        $this->assertSame('FBR CNIC Lockout Shop', $row->name);
    }

    /** Cashier POST with an EMPTY cnic must not blank the login identifier either. */
    public function test_cashier_post_cannot_blank_company_cnic(): void
    {
        $cashier = $this->makeUser('employee', 'pos_cashier', 'cashier3@fbr.cnic');

        $this->actingAs($cashier, 'fbrpos')
            ->post('/fbr-pos/business-profile', [
                'name' => 'FBR CNIC Lockout Shop',
                'cnic' => '',
            ])
            ->assertForbidden();

        $this->assertSame('3520212345678', DB::table('companies')->find($this->fbrCompanyId)->cnic);
    }

    /** Owner/admin saves a dashed CNIC → stored as plain digits. */
    public function test_admin_post_saves_dashed_cnic_as_digits(): void
    {
        $admin = $this->makeUser('company_admin', 'pos_admin', 'admin@fbr.cnic');

        $this->actingAs($admin, 'fbrpos')
            ->post('/fbr-pos/business-profile', [
                'name' => 'FBR CNIC Lockout Shop',
                'cnic' => '35202-7654321-0',
            ])
            ->assertRedirect(route('fbrpos.business-profile'));

        $this->assertSame('3520276543210', DB::table('companies')->find($this->fbrCompanyId)->cnic);
    }

    // ── fixtures ─────────────────────────────────────────────────────────────

    private function makeUser(?string $role, string $posRole, string $email): User
    {
        return User::create([
            'company_id' => $this->fbrCompanyId,
            'name'       => $posRole === 'pos_cashier' ? 'Cashier' : 'Shop Owner',
            'email'      => $email,
            'password'   => Hash::make('secret'),
            'role'       => $role,
            'pos_role'   => $posRole,
            'is_active'  => true,
        ]);
    }

    // ── schema (minimal fbrpos.auth + company.approval + businessProfile needs) ──

    private function buildSchema(): void
    {
        Schema::create('companies', function (Blueprint $t) {
            $t->id();
            $t->string('name');
            $t->string('email')->nullable();
            $t->string('phone')->nullable();
            $t->string('address')->nullable();
            $t->string('ntn')->nullable();
            $t->string('cnic')->nullable();
            $t->string('product_type')->default('pos');
            $t->string('status')->default('active');
            $t->string('company_status')->default('approved');
            $t->boolean('is_internal_account')->default(false);
            $t->boolean('restaurant_mode')->default(false);
            $t->boolean('fbr_pos_enabled')->default(false);
            $t->boolean('fbr_reporting_enabled')->default(false);
            $t->string('fbr_connection_mode')->nullable();
            $t->string('fbr_environment')->nullable();
            $t->boolean('pos_setup_completed')->default(true);
            $t->string('pos_theme')->nullable();
            $t->string('pos_dashboard_style')->nullable();
            $t->string('default_language')->nullable();
            $t->string('pos_locale')->nullable();
            $t->string('logo_path')->nullable();
            $t->string('print_paper_size')->nullable();
            $t->boolean('kot_align_center')->default(false);
            $t->integer('kot_left_margin_mm')->default(0);
            $t->string('receipt_footer_note')->nullable();
            $t->text('invoice_display_prefs')->nullable();
            $t->json('feature_flags')->nullable();
            $t->softDeletes();
            $t->timestamps();
        });

        Schema::create('users', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('company_id')->nullable();
            $t->string('name');
            $t->string('email')->unique();
            $t->string('phone')->nullable();
            $t->string('username')->nullable();
            $t->string('password');
            $t->string('role')->nullable();
            $t->string('pos_role')->nullable();
            $t->text('pos_custom_access')->nullable();
            $t->unsignedBigInteger('default_branch_id')->nullable();
            $t->boolean('is_active')->default(true);
            $t->boolean('dark_mode')->default(false);
            $t->string('language')->nullable();
            $t->rememberToken();
            $t->timestamps();
        });

        Schema::create('branches', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('company_id');
            $t->string('name')->nullable();
            $t->boolean('is_head_office')->default(false);
            $t->boolean('is_active')->default(true);
            $t->softDeletes();
            $t->timestamps();
        });

        Schema::create('branch_user', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('branch_id');
            $t->unsignedBigInteger('user_id');
            $t->timestamps();
        });

        Schema::create('pricing_plans', function (Blueprint $t) {
            $t->id();
            $t->string('name')->nullable();
            $t->string('product_type')->nullable();
            $t->boolean('is_trial')->default(false);
            $t->decimal('price', 12, 2)->default(0);
            $t->timestamps();
        });

        Schema::create('subscriptions', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('company_id');
            $t->unsignedBigInteger('pricing_plan_id')->nullable();
            $t->boolean('active')->default(true);
            $t->date('start_date')->nullable();
            $t->date('end_date')->nullable();
            $t->timestamp('trial_ends_at')->nullable();
            $t->string('override_type')->default('none');
            $t->timestamp('override_until')->nullable();
            $t->timestamp('override_granted_at')->nullable();
            $t->integer('free_invoice_limit')->nullable();
            $t->timestamps();
        });
    }
}
