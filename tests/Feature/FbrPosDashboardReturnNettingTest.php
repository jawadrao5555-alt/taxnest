<?php

namespace Tests\Feature;

use Tests\TestCase;
use App\Models\Company;
use App\Models\User;
use App\Services\PosFeatureService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;

/**
 * FBR DASHBOARD / REPORTS RETURN-NETTING LOCK (Task 591 — FBR mirror of the
 * PRA lock PosDashboardReturnNettingTest / Task 578, convention from 570/576).
 *
 * As soon as FBR credit notes go live (rider-return auto credit note, X-Way
 * verification), the FBR dashboard and reports tiles must not show inflated
 * figures. Convention locked here (identical to the PRA side):
 *   - Money figures are SIGNED — returns subtract (dashboard today/month
 *     tiles, reports today/month tiles, dailySales, paymentBreakdown).
 *   - Bill counts stay SALES-only (a credit note is not a bill).
 *   - Range analytics EXCLUDE return transactions entirely — item rankings,
 *     payments, cashier stats are never polluted or netted by return lines.
 *
 * Pattern: sqlite :memory: + minimal Schema::create in setUp (schema mirrors
 * FbrPosDashboardUnclosedDaysWarningTest; numbers mirror the PRA netting day).
 *
 * Run:
 *   env -u DATABASE_URL -u DB_CONNECTION -u PGHOST -u PGPORT -u PGUSER \
 *     -u PGPASSWORD -u PGDATABASE APP_ENV=testing DB_CONNECTION=sqlite \
 *     DB_DATABASE=':memory:' php vendor/bin/phpunit tests/Feature/FbrPosDashboardReturnNettingTest.php
 */
class FbrPosDashboardReturnNettingTest extends TestCase
{
    private Company $company;
    private User $posAdmin;

    protected function setUp(): void
    {
        parent::setUp();
        PosFeatureService::flushGateCaches();
        Schema::dropAllTables();
        $this->buildSchema();
        [$this->company, $this->posAdmin] = $this->seedShop();
        $this->seedNettingDay();
    }

    protected function tearDown(): void
    {
        PosFeatureService::flushGateCaches();
        parent::tearDown();
    }

    // ── 1. dashboard today/month tiles: signed money, sales-only counts ─────

    public function test_dashboard_tiles_net_returns_with_sales_only_counts(): void
    {
        $response = $this->actingAs($this->posAdmin, 'fbrpos')->get('/fbr-pos/dashboard');
        $response->assertOk();

        $today = $response->viewData('todayStats');
        $this->assertSame(2, (int) $today->count, 'credit note must not count as a bill');
        $this->assertSame(1441.0, (float) $today->revenue, '1070 + 585 − 214 return');
        $this->assertSame(221.0, (float) $today->tax, '170 + 85 − 34 return tax');

        $month = $response->viewData('monthStats');
        $this->assertSame(2, (int) $month->count);
        $this->assertSame(1441.0, (float) $month->revenue);
        $this->assertSame(221.0, (float) $month->tax);
    }

    // ── 2. reports tiles + dailySales + paymentBreakdown ────────────────────

    public function test_reports_tiles_daily_and_payment_breakdown_are_netted(): void
    {
        $response = $this->actingAs($this->posAdmin, 'fbrpos')->get('/fbr-pos/reports');
        $response->assertOk();

        $today = $response->viewData('todayStats');
        $this->assertSame(2, (int) $today->count);
        $this->assertSame(1441.0, (float) $today->revenue);
        $this->assertSame(221.0, (float) $today->tax);
        $this->assertSame(80.0, (float) $today->discount, '100 − 20 return discount share');

        $month = $response->viewData('monthStats');
        $this->assertSame(2, (int) $month->count);
        $this->assertSame(1441.0, (float) $month->revenue);

        $daily = collect($response->viewData('dailySales'))->keyBy('date');
        $todayKey = now()->toDateString();
        $this->assertSame(2, (int) $daily[$todayKey]->count, 'return row must not count as a bill in the trend');
        $this->assertSame(1441.0, (float) $daily[$todayKey]->revenue);

        $payments = collect($response->viewData('paymentBreakdown'))->keyBy('payment_method');
        $this->assertSame(1, (int) $payments['cash']->count, 'refund row must not count as a cash bill');
        $this->assertSame(856.0, (float) $payments['cash']->revenue, '1070 cash sale − 214 cash refund');
        $this->assertSame(1, (int) $payments['debit_card']->count);
        $this->assertSame(585.0, (float) $payments['debit_card']->revenue);
    }

    // ── 3. range analytics: return rows EXCLUDED entirely ───────────────────

    public function test_range_analytics_exclude_return_rows_entirely(): void
    {
        $response = $this->actingAs($this->posAdmin, 'fbrpos')->get('/fbr-pos/reports');
        $response->assertOk();

        $analytics = $response->viewData('rangeAnalytics');

        $this->assertSame(2, (int) $analytics->summary->bills, 'return row is not a bill in the deep dive');
        $this->assertSame(1655.0, (float) $analytics->summary->revenue, 'gross 1070 + 585 — excluded, never netted');

        $payments = collect($analytics->payments);
        $this->assertSame(1, (int) $payments['cash']->count);
        $this->assertSame(1070.0, (float) $payments['cash']->revenue, 'refund must not join or net the cash bucket');

        // Item rankings stay GROSS — the returned Burger qty neither joins (6)
        // nor nets (2).
        $products = collect($analytics->products);
        $this->assertSame(4.0, (float) $products['Burger']->qty);
        $this->assertSame(1000.0, (float) $products['Burger']->revenue);
        $this->assertSame(5.0, (float) $products['Chai']->qty);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Fixtures
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * The canonical netting day (same numbers as the PRA lock):
     *   Sale A  — cash 1070, tax 170, discount 100 (Burger ×4).
     *   Sale B  — debit_card 585, tax 85 (Chai ×5).
     *   Return R — cash credit note 214 off Sale A (tax 34, discount 20,
     *              POSITIVE amounts, Burger ×2).
     * Netted "aaj": revenue 1441, tax 221, discount 80, bills 2, cash 856.
     */
    private function seedNettingDay(): void
    {
        $saleA = $this->makeTxn('FPOS-0001', [
            'subtotal' => 1000, 'discount_amount' => 100, 'tax_amount' => 170,
            'total_amount' => 1070, 'payment_method' => 'cash',
        ], [
            ['product_id' => 501, 'item_name' => 'Burger', 'quantity' => 4,
             'subtotal' => 1000, 'tax_amount' => 170],
        ]);
        $this->makeTxn('FPOS-0002', [
            'subtotal' => 500, 'tax_amount' => 85, 'total_amount' => 585,
            'payment_method' => 'debit_card',
        ], [
            ['product_id' => 502, 'item_name' => 'Chai', 'quantity' => 5,
             'subtotal' => 500, 'tax_amount' => 85],
        ]);
        $this->makeTxn('FRET-0001', [
            'transaction_type' => 'return',
            'parent_transaction_id' => $saleA,
            // A SUBMITTED credit note — must still never count as a bill.
            'fbr_status' => 'submitted',
            'fbr_invoice_number' => 'FBR-RET-0001',
            'subtotal' => 200, 'discount_amount' => 20, 'tax_amount' => 34,
            'total_amount' => 214, 'payment_method' => 'cash',
        ], [
            ['product_id' => 501, 'item_name' => 'Burger', 'quantity' => 2,
             'subtotal' => 200, 'tax_amount' => 34],
        ]);
    }

    private function makeTxn(string $number, array $attrs = [], array $items = []): int
    {
        $id = (int) DB::table('fbr_pos_transactions')->insertGetId(array_merge([
            'company_id' => $this->company->id,
            'invoice_number' => $number,
            'transaction_type' => 'sale',
            'status' => 'completed',
            'invoice_mode' => 'fbr',
            'fbr_status' => 'submitted',
            'subtotal' => 0, 'discount_amount' => 0, 'tax_amount' => 0, 'total_amount' => 0,
            'payment_method' => 'cash',
            'created_by' => $this->posAdmin->id,
            'created_at' => now(), 'updated_at' => now(),
        ], $attrs));

        foreach ($items as $item) {
            DB::table('fbr_pos_transaction_items')->insert(array_merge([
                'transaction_id' => $id,
                'quantity' => 1,
                'created_at' => now(), 'updated_at' => now(),
            ], $item));
        }

        return $id;
    }

    private function seedShop(): array
    {
        $planId = DB::table('pricing_plans')->insertGetId([
            'name' => 'FBR Business', 'product_type' => 'fbrpos',
            'is_trial' => false,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $company = Company::create([
            'name' => 'Netting FBR Shop', 'product_type' => 'fbrpos',
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
            'name' => 'Admin', 'email' => 'admin@fbrnetting.pk',
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
            $t->string('pos_dayclose_provisional_action')->nullable();
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
            $t->unsignedBigInteger('parent_transaction_id')->nullable();
            $t->string('status')->nullable();
            $t->string('invoice_mode')->nullable();
            $t->string('fbr_status')->nullable();
            $t->string('fbr_invoice_number')->nullable();
            $t->unsignedBigInteger('customer_id')->nullable();
            $t->string('customer_name')->nullable();
            $t->string('customer_phone')->nullable();
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
            $t->decimal('cost_price', 12, 2)->nullable();
            $t->timestamps();
        });

        Schema::create('fbr_day_close_reports', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('company_id');
            $t->date('report_date');
            $t->string('report_number')->nullable();
            $t->integer('total_invoices')->default(0);
            $t->decimal('total_amount', 14, 2)->default(0);
            $t->unsignedBigInteger('closed_by')->nullable();
            $t->timestamps();
            $t->unique(['company_id', 'report_date']);
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
