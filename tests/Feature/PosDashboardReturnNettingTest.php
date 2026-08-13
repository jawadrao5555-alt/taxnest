<?php

namespace Tests\Feature;

use App\Http\Controllers\PosController;
use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * DASHBOARD RETURN-NETTING LOCK (Task 578, follows Tasks 570/576).
 *
 * Task 570/576 made day-close and reports returns-aware and locked them with
 * PosDayCloseReturnNettingTest. The POS dashboard reads the SAME rows for its
 * "aaj" tiles — if a refactor forgets the return branch there, the cashier
 * sees inflated revenue all day while the Z-report shows less (trust issue).
 *
 * Convention locked here (identical to day-close/reports):
 *   - Money figures are SIGNED — returns subtract (todayStats, monthStats,
 *     periodOrders, paymentBreakdown, yesterdayRevenue).
 *   - Bill counts stay SALES-only (a credit note is not a bill) — incl. the
 *     Saaf praSyncedToday counter.
 *   - Item-level joins (profit engine, top sold, top profit, cost coverage)
 *     EXCLUDE return transactions entirely — like range analytics, never netted.
 *
 * Pattern: APP_ENV=testing + sqlite :memory: + minimal Schema::create
 * (PosPaymentBucketsTest dashboard test + PosDayCloseReturnNettingTest seed).
 */
class PosDashboardReturnNettingTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Schema::dropAllTables();
        User::flushScopeColumnCache();

        Schema::create('companies', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('product_type')->nullable();
            $table->string('status')->nullable();
            $table->string('pos_dayclose_provisional_action')->nullable();
            $table->string('pos_dayclose_final_local_action')->nullable();
            $table->boolean('pos_customer_spend_persist')->default(true);
            $table->string('pos_business_day_cutoff')->nullable();
            $table->boolean('is_internal_account')->default(false);
            $table->integer('invoice_limit_override')->nullable();
            $table->boolean('pra_reporting_enabled')->default(false);
            $table->string('pra_connection_mode')->nullable();
            $table->boolean('pos_setup_completed')->default(true);
            $table->string('pos_dashboard_style')->nullable();
            $table->softDeletes();
            $table->timestamps();
        });

        Schema::create('users', function (Blueprint $table) {
            $table->id();
            $table->string('name')->nullable();
            $table->string('email')->nullable()->unique();
            $table->string('password')->nullable();
            $table->unsignedBigInteger('company_id')->nullable();
            $table->string('role')->nullable();
            $table->string('pos_role')->nullable();
            $table->string('pos_billing_scope')->nullable();
            $table->boolean('is_active')->default(true);
            $table->boolean('pra_reporting_enabled')->default(false);
            $table->timestamps();
        });

        Schema::create('pos_transactions', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('company_id');
            $table->string('invoice_number');
            $table->string('transaction_type')->nullable()->default('sale');
            $table->unsignedBigInteger('parent_transaction_id')->nullable();
            $table->string('business_date')->nullable();
            $table->string('status');
            $table->string('invoice_mode')->nullable();
            $table->string('pra_status')->nullable();
            $table->string('pra_invoice_number')->nullable();
            $table->boolean('is_archived')->default(false);
            $table->timestamp('archived_at')->nullable();
            $table->unsignedBigInteger('archived_by_report_id')->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->unsignedBigInteger('customer_id')->nullable();
            $table->string('customer_name')->nullable();
            $table->string('customer_phone')->nullable();
            $table->decimal('subtotal', 12, 2)->default(0);
            $table->decimal('discount_amount', 12, 2)->default(0);
            $table->decimal('tax_rate', 8, 2)->nullable();
            $table->decimal('tax_amount', 12, 2)->default(0);
            $table->decimal('exempt_amount', 12, 2)->nullable();
            $table->decimal('total_amount', 12, 2)->default(0);
            $table->string('payment_method')->nullable();
            $table->boolean('tax_inclusive')->default(false);
            $table->unsignedBigInteger('rider_id')->nullable();
            $table->string('delivery_status')->nullable();
            $table->unsignedBigInteger('rider_settlement_id')->nullable();
            $table->timestamp('rider_settled_at')->nullable();
            $table->timestamps();
        });

        Schema::create('pos_transaction_items', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('transaction_id');
            $table->string('item_type')->nullable();
            $table->unsignedBigInteger('item_id')->nullable();
            $table->string('item_name');
            $table->decimal('quantity', 12, 3)->default(1);
            $table->decimal('unit_price', 12, 2)->default(0);
            $table->decimal('cost_price', 12, 2)->nullable();
            $table->decimal('subtotal', 12, 2)->default(0);
            $table->boolean('is_tax_exempt')->default(false);
            $table->decimal('tax_rate', 8, 2)->nullable();
            $table->decimal('tax_amount', 12, 2)->nullable();
            $table->decimal('item_discount_amount', 12, 2)->nullable();
            $table->timestamps();
        });

        Schema::create('pos_products', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('company_id');
            $table->string('name');
            $table->string('category')->nullable();
            $table->decimal('price', 12, 2)->default(0);
            $table->decimal('cost_price', 12, 2)->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('pos_day_close_reports', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('company_id');
            $table->date('report_date');
            $table->timestamps();
        });

        Schema::create('pos_day_openings', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('company_id');
            $table->date('business_date');
            $table->decimal('opening_cash', 14, 2)->default(0);
            $table->unsignedBigInteger('entered_by')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();
        });

        Schema::create('notifications', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('company_id')->nullable();
            $table->unsignedBigInteger('user_id')->nullable();
            $table->string('type')->nullable();
            $table->string('title')->nullable();
            $table->text('message')->nullable();
            $table->boolean('read')->default(false);
            $table->text('metadata')->nullable();
            $table->timestamps();
        });
    }

    protected function tearDown(): void
    {
        Auth::guard('pos')->logout();
        parent::tearDown();
    }

    // ── fixtures (seedNettingDay pattern from PosDayCloseReturnNettingTest) ──

    private function makeCompany(array $attrs = []): int
    {
        return (int) DB::table('companies')->insertGetId(array_merge([
            'name' => 'Dashboard Netting Co',
            'product_type' => 'pos',
            'status' => 'active',
            'pos_setup_completed' => true,
            'created_at' => now(), 'updated_at' => now(),
        ], $attrs));
    }

    private function makePosUser(int $companyId): User
    {
        $id = DB::table('users')->insertGetId([
            'name' => 'POS Admin',
            'email' => 'admin' . $companyId . '@taxnest.test',
            'company_id' => $companyId,
            'role' => 'user',
            'pos_role' => 'pos_admin',
            'is_active' => true,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        return User::find($id);
    }

    private function makeTxn(int $companyId, string $number, array $attrs = [], array $items = []): int
    {
        $id = (int) DB::table('pos_transactions')->insertGetId(array_merge([
            'company_id' => $companyId,
            'invoice_number' => $number,
            'transaction_type' => 'sale',
            'business_date' => \App\Services\PosBusinessDay::current($companyId),
            'status' => 'completed',
            'invoice_mode' => 'pra',
            'pra_status' => 'submitted',
            'pra_invoice_number' => 'PRA-' . $number,
            'subtotal' => 0, 'discount_amount' => 0, 'tax_amount' => 0, 'total_amount' => 0,
            'payment_method' => 'cash',
            'created_at' => now(), 'updated_at' => now(),
        ], $attrs));

        foreach ($items as $item) {
            DB::table('pos_transaction_items')->insert(array_merge([
                'transaction_id' => $id,
                'item_type' => 'product',
                'quantity' => 1,
                'created_at' => now(), 'updated_at' => now(),
            ], $item));
        }

        return $id;
    }

    /**
     * The canonical netting day (same numbers as PosDayCloseReturnNettingTest):
     *   Sale A  — cash 1070 (Burger ×4, cost 150).
     *   Sale B  — debit_card 585 (Chai ×5, cost 40).
     *   Return R — cash refund 214 off Sale A (Burger ×2, POSITIVE amounts).
     * Netted "aaj": revenue 1441, bills 2, cash 856, card 585.
     */
    private function seedNettingDay(int $companyId): void
    {
        $saleA = $this->makeTxn($companyId, 'P-0001', [
            'subtotal' => 1000, 'discount_amount' => 100, 'tax_amount' => 170,
            'total_amount' => 1070, 'payment_method' => 'cash',
        ], [
            ['item_id' => 501, 'item_name' => 'Burger', 'quantity' => 4, 'unit_price' => 250,
             'subtotal' => 1000, 'tax_rate' => 17, 'tax_amount' => 170, 'cost_price' => 150],
        ]);
        $this->makeTxn($companyId, 'P-0002', [
            'subtotal' => 500, 'tax_amount' => 85, 'total_amount' => 585,
            'payment_method' => 'debit_card',
        ], [
            ['item_id' => 502, 'item_name' => 'Chai', 'quantity' => 5, 'unit_price' => 100,
             'subtotal' => 500, 'tax_rate' => 17, 'tax_amount' => 85, 'cost_price' => 40],
        ]);
        $this->makeTxn($companyId, 'RET-0001', [
            'transaction_type' => 'return',
            'parent_transaction_id' => $saleA,
            // A SUBMITTED credit note — must still never count as a synced bill.
            'pra_status' => 'submitted',
            'pra_invoice_number' => 'PRA-RET-0001',
            'subtotal' => 200, 'discount_amount' => 20, 'tax_amount' => 34,
            'total_amount' => 214, 'payment_method' => 'cash',
        ], [
            ['item_id' => 501, 'item_name' => 'Burger', 'quantity' => 2, 'unit_price' => 250,
             'subtotal' => 200, 'tax_rate' => 17, 'tax_amount' => 34, 'cost_price' => 150],
        ]);

        DB::table('pos_products')->insert([
            ['id' => 501, 'company_id' => $companyId, 'name' => 'Burger', 'category' => 'Food',
             'price' => 250, 'cost_price' => 150, 'created_at' => now(), 'updated_at' => now()],
            ['id' => 502, 'company_id' => $companyId, 'name' => 'Chai', 'category' => 'Drinks',
             'price' => 100, 'cost_price' => 40, 'created_at' => now(), 'updated_at' => now()],
        ]);
    }

    private function dashboardData(int $companyId, string $style = 'default'): array
    {
        DB::table('companies')->where('id', $companyId)->update(['pos_dashboard_style' => $style]);
        $user = $this->makePosUser($companyId);
        Auth::guard('pos')->setUser($user);
        app()->instance('currentCompanyId', $companyId);

        $view = (new PosController())->dashboard(Request::create('/pos/dashboard', 'GET'));
        $this->assertInstanceOf(\Illuminate\Contracts\View\View::class, $view, 'dashboard must not redirect to setup');

        return $view->getData();
    }

    // ── 1. today/month tiles: signed revenue, sales-only counts ─────────────

    public function test_today_and_month_tiles_net_returns_with_sales_only_counts(): void
    {
        $companyId = $this->makeCompany();
        $this->seedNettingDay($companyId);

        $data = $this->dashboardData($companyId);

        $today = $data['todayStats'];
        $this->assertSame(2, (int) $today->count, 'credit note must not count as a bill');
        $this->assertSame(1441.0, (float) $today->revenue, '1070 + 585 − 214 return');
        $this->assertSame(720.5, (float) $today->avg_ticket, 'netted revenue / sales-only bills');

        $month = $data['monthStats'];
        $this->assertSame(2, (int) $month->count);
        $this->assertSame(1441.0, (float) $month->revenue);
    }

    // ── 2. payment breakdown: per-method signed sums ─────────────────────────

    public function test_payment_breakdown_nets_cash_refund_and_keeps_card_untouched(): void
    {
        $companyId = $this->makeCompany();
        $this->seedNettingDay($companyId);

        $breakdown = collect($this->dashboardData($companyId)['paymentBreakdown'])
            ->keyBy('payment_method');

        $this->assertSame(1, (int) $breakdown['cash']->count, 'refund row must not count as a bill');
        $this->assertSame(856.0, (float) $breakdown['cash']->total, '1070 cash sale − 214 cash refund');
        $this->assertSame(1, (int) $breakdown['debit_card']->count);
        $this->assertSame(585.0, (float) $breakdown['debit_card']->total);
    }

    // ── 3. profit engine + item rankings: returns EXCLUDED entirely ─────────

    public function test_profit_engine_and_item_rankings_exclude_return_lines(): void
    {
        $companyId = $this->makeCompany();
        $this->seedNettingDay($companyId);

        $data = $this->dashboardData($companyId);

        // Profit engine (frozen-cost branch): costed revenue 1500, cost 800.
        $profit = $data['profitStats'];
        $this->assertSame(2, (int) $profit['orders'], 'orders KPI stays sales-only');
        $this->assertSame(1500.0, (float) $profit['revenue'], 'return lines must not join costed revenue');
        $this->assertSame(800.0, (float) $profit['cost'], '4×150 + 5×40 — return qty must not add cost');
        $this->assertSame(700.0, (float) $profit['profit']);
        $this->assertSame(1441.0, (float) $profit['all_revenue'], 'all-lines revenue stays SIGNED-netted');

        // Top sold: gross ranking — return qty neither joins (6) nor nets (2).
        $topSold = collect($data['topSold'])->keyBy('name');
        $this->assertSame(4.0, (float) $topSold['Burger']->qty);
        $this->assertSame(1000.0, (float) $topSold['Burger']->revenue);
        $this->assertSame(5.0, (float) $topSold['Chai']->qty);

        // Top profit: Burger 1000 − 600, Chai 500 − 200 — no return pollution.
        $topProfit = collect($data['topProfit'])->keyBy('name');
        $this->assertSame(400.0, (float) $topProfit['Burger']->profit);
        $this->assertSame(300.0, (float) $topProfit['Chai']->profit);

        // Coverage: 2 sold product lines (both costed) — the return line is
        // not a third line in the denominator.
        $this->assertSame(['with_cost' => 2, 'total' => 2], $data['costCoverage']);
    }

    // ── 4. Saaf extras: yesterday delta netted, synced count sales-only ─────

    public function test_saaf_yesterday_revenue_nets_returns_and_synced_count_is_sales_only(): void
    {
        $companyId = $this->makeCompany();
        $this->seedNettingDay($companyId);

        // Yesterday: one 500 cash sale + a 100 return (both business-dated).
        $biz = \App\Services\PosBusinessDay::current($companyId);
        $yesterday = \Carbon\Carbon::parse($biz)->subDay()->toDateString();
        $sale = $this->makeTxn($companyId, 'P-Y001', [
            'business_date' => $yesterday, 'subtotal' => 500, 'total_amount' => 500,
            'created_at' => now()->subDay(), 'updated_at' => now()->subDay(),
        ]);
        $this->makeTxn($companyId, 'RET-Y001', [
            'transaction_type' => 'return', 'parent_transaction_id' => $sale,
            'business_date' => $yesterday, 'subtotal' => 100, 'total_amount' => 100,
            'created_at' => now()->subDay(), 'updated_at' => now()->subDay(),
        ]);

        $data = $this->dashboardData($companyId, 'saaf');

        $this->assertSame(400.0, (float) $data['yesterdayRevenue'], '500 − 100 yesterday return');
        // Today's return is pra_status=submitted — it must still not count
        // as a synced BILL (2 sales, not 3 rows).
        $this->assertSame(2, (int) $data['praSyncedToday']);
    }
}
