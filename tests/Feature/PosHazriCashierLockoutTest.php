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
 * STAFF HAZRI — CASHIER LOCKOUT, BOTH PANELS (Task #564).
 *
 * The hazri report is admin/manager-only on BOTH panels. This test locks in
 * the isPosAdmin() gate over real HTTP:
 *
 *   1. pos_cashier GET /pos/reports/hazri      → 403 (PRA panel)
 *   2. pos_cashier GET /fbr-pos/reports/hazri  → 403 (FBR panel)
 *   3. admin GET on both routes                → 200
 *
 * GOTCHA this test exists to lock in: a seeded "cashier" with base
 * role='company_admin' passes isPosAdmin() regardless of pos_role.  The
 * cashier fixtures here use role='employee' on purpose (a real non-admin
 * base role) so the 403 assertions actually exercise the gate.
 *
 * Pattern: sqlite :memory: + minimal Schema::create in setUp (mirrors
 * Phase3LoginIsolationTest / FbrPosHazriCashierGateTest).
 *
 * Run:
 *   env -u DATABASE_URL -u DB_CONNECTION -u PGHOST -u PGPORT -u PGUSER \
 *     -u PGPASSWORD -u PGDATABASE APP_ENV=testing DB_CONNECTION=sqlite \
 *     DB_DATABASE=':memory:' php vendor/bin/phpunit tests/Feature/PosHazriCashierLockoutTest.php
 */
class PosHazriCashierLockoutTest extends TestCase
{
    private int $praCompanyId;
    private int $fbrCompanyId;

    protected function setUp(): void
    {
        parent::setUp();
        PosFeatureService::flushGateCaches();
        Schema::dropAllTables();
        $this->buildSchema();
        $this->praCompanyId = $this->seedCompany('pos', false);
        $this->fbrCompanyId = $this->seedCompany('fbrpos', true);
    }

    protected function tearDown(): void
    {
        PosFeatureService::flushGateCaches();
        parent::tearDown();
    }

    // ── tests ────────────────────────────────────────────────────────────────

    /** PRA panel: pos_cashier (base role 'employee') → hard 403. */
    public function test_pra_cashier_gets_403_on_hazri_report(): void
    {
        $cashier = $this->makeUser($this->praCompanyId, 'employee', 'pos_cashier', 'cashier@pra.hazri');

        $this->actingAs($cashier, 'pos')
            ->get('/pos/reports/hazri')
            ->assertForbidden();
    }

    /** FBR panel: pos_cashier (base role 'employee') → hard 403. */
    public function test_fbr_cashier_gets_403_on_hazri_report(): void
    {
        $cashier = $this->makeUser($this->fbrCompanyId, 'employee', 'pos_cashier', 'cashier@fbr.hazri');

        $this->actingAs($cashier, 'fbrpos')
            ->get('/fbr-pos/reports/hazri')
            ->assertForbidden();
    }

    /** Admin keeps access on the PRA panel. */
    public function test_pra_admin_gets_200_on_hazri_report(): void
    {
        $admin = $this->makeUser($this->praCompanyId, 'company_admin', 'pos_admin', 'admin@pra.hazri');

        $this->actingAs($admin, 'pos')
            ->get('/pos/reports/hazri')
            ->assertOk();
    }

    /** Admin keeps access on the FBR panel. */
    public function test_fbr_admin_gets_200_on_hazri_report(): void
    {
        $admin = $this->makeUser($this->fbrCompanyId, 'company_admin', 'pos_admin', 'admin@fbr.hazri');

        $this->actingAs($admin, 'fbrpos')
            ->get('/fbr-pos/reports/hazri')
            ->assertOk();
    }

    // ── fixtures ─────────────────────────────────────────────────────────────

    private function seedCompany(string $productType, bool $fbrPos): int
    {
        return DB::table('companies')->insertGetId([
            'name'                => strtoupper($productType) . ' Hazri Lockout Shop',
            'product_type'        => $productType,
            'status'              => 'active',
            'company_status'      => 'approved',
            'is_internal_account' => true,   // planGate bypass — no subscription needed
            'fbr_pos_enabled'     => $fbrPos,
            'pos_setup_completed' => true,
            'created_at'          => now(),
            'updated_at'          => now(),
        ]);
    }

    private function makeUser(int $companyId, ?string $role, string $posRole, string $email): User
    {
        return User::create([
            'company_id' => $companyId,
            'name'       => $posRole === 'pos_cashier' ? 'Cashier' : 'Shop Owner',
            'email'      => $email,
            'password'   => Hash::make('secret'),
            'role'       => $role,
            'pos_role'   => $posRole,
            'is_active'  => true,
        ]);
    }

    // ── schema (union of PRA + FBR hazri needs) ──────────────────────────────

    private function buildSchema(): void
    {
        Schema::create('companies', function (Blueprint $t) {
            $t->id();
            $t->string('name');
            $t->string('email')->nullable();
            $t->string('phone')->nullable();
            $t->string('address')->nullable();
            $t->string('ntn')->nullable();
            $t->string('product_type')->default('pos');
            $t->string('status')->default('active');
            $t->string('company_status')->default('approved');
            $t->boolean('is_internal_account')->default(false);
            $t->integer('invoice_limit_override')->nullable();
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

        Schema::create('pos_user_sessions', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('company_id');
            $t->unsignedBigInteger('user_id');
            $t->timestamp('login_at')->nullable();
            $t->timestamp('logout_at')->nullable();
            $t->timestamp('last_activity_at')->nullable();
            $t->timestamp('last_seen_at')->nullable();
            $t->timestamps();
        });

        Schema::create('pos_transactions', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('company_id');
            $t->unsignedBigInteger('created_by')->nullable();
            $t->string('business_date')->nullable();
            $t->decimal('total_amount', 14, 2)->default(0);
            $t->string('invoice_mode')->default('pra');
            $t->timestamps();
        });

        Schema::create('fbr_pos_transactions', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('company_id');
            $t->unsignedBigInteger('branch_id')->nullable();
            $t->string('invoice_number');
            $t->string('transaction_type')->default('sale');
            $t->string('status')->nullable();
            $t->string('invoice_mode')->nullable();
            $t->string('fbr_status')->nullable();
            $t->string('fbr_invoice_number')->nullable();
            $t->decimal('subtotal', 12, 2)->default(0);
            $t->decimal('discount_amount', 12, 2)->default(0);
            $t->decimal('tax_amount', 12, 2)->default(0);
            $t->decimal('total_amount', 12, 2)->default(0);
            $t->string('payment_method')->nullable();
            $t->unsignedBigInteger('created_by')->nullable();
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

        Schema::create('notifications', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('company_id')->nullable();
            $t->unsignedBigInteger('user_id')->nullable();
            $t->string('type');
            $t->string('title');
            $t->text('message');
            $t->boolean('read')->default(false);
            $t->json('metadata')->nullable();
            $t->timestamps();
        });

        Schema::create('security_logs', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('user_id')->nullable();
            $t->string('action');
            $t->string('ip_address', 45)->nullable();
            $t->text('user_agent')->nullable();
            $t->text('metadata')->nullable();
            $t->timestamp('created_at')->nullable();
        });

        Schema::create('audit_logs', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('user_id')->nullable();
            $t->unsignedBigInteger('company_id')->nullable();
            $t->string('action');
            $t->string('entity_type')->nullable();
            $t->unsignedBigInteger('entity_id')->nullable();
            $t->text('changes')->nullable();
            $t->text('metadata')->nullable();
            $t->string('ip_address', 45)->nullable();
            $t->text('user_agent')->nullable();
            $t->string('hash')->nullable();
            $t->string('previous_hash')->nullable();
            $t->timestamps();
        });
    }
}
