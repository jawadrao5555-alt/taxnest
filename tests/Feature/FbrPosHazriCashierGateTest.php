<?php

namespace Tests\Feature;

use Tests\TestCase;
use App\Models\Company;
use App\Models\User;
use App\Services\PosFeatureService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Carbon\Carbon;

/**
 * FBR STAFF HAZRI — CASHIER LOCKOUT GUARD (Task #562, feature Task #560).
 *
 * FbrPosController::hazriReport is ADMIN/MANAGER-ONLY (cashiers must never
 * see staff attendance). Locked guarantees, over real HTTP:
 *
 *   1. pos_cashier GET /fbr-pos/reports/hazri → 403.
 *   2. pos_cashier GET /fbr-pos/reports → the teal Staff Hazri button
 *      (link to fbrpos.reports.hazri) is NOT rendered.
 *   3. Admin GET /fbr-pos/reports/hazri → 200; button renders on /fbr-pos/reports.
 *
 * GOTCHA this test exists to lock in: a seeded "cashier" with base
 * role='company_admin' passes isPosAdmin() regardless of pos_role — the
 * cashier fixture here uses role='user' on purpose (mirrors real cashiers).
 *
 * Pattern: sqlite :memory: + minimal Schema::create in setUp (schema mirrors
 * FbrPosDayCloseStrandedBannerTest).
 *
 * Run:
 *   env -u DATABASE_URL -u DB_CONNECTION -u PGHOST -u PGPORT -u PGUSER \
 *     -u PGPASSWORD -u PGDATABASE APP_ENV=testing DB_CONNECTION=sqlite \
 *     DB_DATABASE=':memory:' php vendor/bin/phpunit tests/Feature/FbrPosHazriCashierGateTest.php
 */
class FbrPosHazriCashierGateTest extends TestCase
{
    private Company $company;
    private User $posAdmin;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(now()->setTime(12, 0));
        PosFeatureService::flushGateCaches();
        Schema::dropAllTables();
        $this->buildSchema();
        [$this->company, $this->posAdmin] = $this->seedShop();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        PosFeatureService::flushGateCaches();
        parent::tearDown();
    }

    // ─────────────────────────────────────────────────────────────────────────
    // TESTS
    // ─────────────────────────────────────────────────────────────────────────

    /** pos_cashier hitting the hazri report directly → hard 403. */
    public function test_cashier_gets_403_on_hazri_report(): void
    {
        $cashier = $this->makeCashier();

        $this->actingAs($cashier, 'fbrpos')
            ->get('/fbr-pos/reports/hazri')
            ->assertForbidden();
    }

    /** pos_cashier on the reports page → no Staff Hazri button rendered. */
    public function test_cashier_does_not_see_hazri_button_on_reports_page(): void
    {
        $cashier = $this->makeCashier();

        $response = $this->actingAs($cashier, 'fbrpos')->get('/fbr-pos/reports');
        $response->assertOk();
        $response->assertDontSee(route('fbrpos.reports.hazri'), false);
    }

    /** Admin keeps full access: hazri page 200 + button on reports page. */
    public function test_admin_sees_hazri_report_and_button(): void
    {
        $this->actingAs($this->posAdmin, 'fbrpos')
            ->get('/fbr-pos/reports/hazri')
            ->assertOk();

        $response = $this->actingAs($this->posAdmin, 'fbrpos')->get('/fbr-pos/reports');
        $response->assertOk();
        $response->assertSee(route('fbrpos.reports.hazri'), false);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Fixtures (mirror FbrPosDayCloseStrandedBannerTest)
    // ─────────────────────────────────────────────────────────────────────────

    private function makeCashier(): User
    {
        // role MUST stay a non-admin base role: 'company_admin' would make
        // isPosAdmin() true and silently defeat this whole test.
        return User::create([
            'name' => 'Cashier', 'email' => 'cashier@fbrhazri.pk',
            'password' => bcrypt('secret'), 'company_id' => $this->company->id,
            'role' => 'user', 'pos_role' => 'pos_cashier',
            'is_active' => true,
        ]);
    }

    private function seedShop(): array
    {
        $planId = DB::table('pricing_plans')->insertGetId([
            'name' => 'FBR Business', 'product_type' => 'fbrpos',
            'is_trial' => false,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $company = Company::create([
            'name' => 'Hazri Gate FBR Shop', 'product_type' => 'fbrpos',
            'status' => 'active', 'company_status' => 'active',
            'is_internal_account' => false,
            'fbr_reporting_enabled' => false,
            'fbr_pos_enabled' => true,
        ]);

        DB::table('subscriptions')->insert([
            'company_id' => $company->id, 'pricing_plan_id' => $planId,
            'active' => true, 'override_type' => 'none',
            'start_date' => now()->subMonth()->toDateString(),
            'end_date' => now()->addMonth()->toDateString(),
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $user = User::create([
            'name' => 'Admin', 'email' => 'admin@fbrhazri.pk',
            'password' => bcrypt('secret'), 'company_id' => $company->id,
            'role' => 'company_admin', 'pos_role' => 'pos_admin',
            'is_active' => true,
        ]);

        return [$company, $user];
    }

    private function buildSchema(): void
    {
        Schema::create('companies', function (Blueprint $t) {
            $t->id();
            $t->string('name');
            $t->string('product_type')->default('fbrpos');
            $t->string('status')->default('active');
            $t->string('company_status')->default('active');
            $t->boolean('is_internal_account')->default(false);
            $t->integer('invoice_limit_override')->nullable();
            $t->boolean('fbr_reporting_enabled')->default(false);
            $t->boolean('fbr_pos_enabled')->default(true);
            $t->string('fbr_connection_mode')->nullable();
            $t->string('fbr_environment')->nullable();
            $t->string('pos_dashboard_style')->nullable();
            $t->string('default_language')->nullable();
            $t->softDeletes();
            $t->timestamps();
        });

        Schema::create('users', function (Blueprint $t) {
            $t->id();
            $t->string('name')->nullable();
            $t->string('email')->nullable()->unique();
            $t->string('password')->nullable();
            $t->unsignedBigInteger('company_id')->nullable();
            $t->unsignedBigInteger('default_branch_id')->nullable();
            $t->string('role')->nullable();
            $t->string('pos_role')->nullable();
            $t->boolean('is_active')->default(true);
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
            $t->decimal('fbr_service_charge', 12, 2)->nullable();
            $t->decimal('total_amount', 12, 2)->default(0);
            $t->string('payment_method')->nullable();
            $t->unsignedBigInteger('created_by')->nullable();
            $t->timestamps();
        });

        Schema::create('fbr_pos_transaction_items', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('transaction_id');
            $t->unsignedBigInteger('product_id')->nullable();
            $t->string('item_name');
            $t->decimal('quantity', 12, 4)->default(1);
            $t->decimal('subtotal', 12, 2)->default(0);
            $t->decimal('tax_amount', 12, 2)->nullable();
            $t->decimal('item_discount', 12, 2)->nullable();
            $t->decimal('promotion_discount', 12, 2)->nullable();
            $t->timestamps();
        });

        Schema::create('pos_user_sessions', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('company_id');
            $t->unsignedBigInteger('user_id');
            $t->timestamp('login_at')->nullable();
            $t->timestamp('logout_at')->nullable();
            $t->timestamp('last_seen_at')->nullable();
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
    }
}
