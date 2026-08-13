<?php

namespace Tests\Feature;

use App\Http\Controllers\PosController;
use App\Models\PosTransaction;
use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * DAY-CLOSE / REPORT RETURN-NETTING LOCK (Task 576, follows Task 570).
 *
 * Task 570 made every day-close and report surface returns-aware:
 *   - performDayClose (stored Z-report) and the dayCloseReport preview NET
 *     returns out of gross/tax/total and the cash/card/other buckets, keep
 *     bill counts SALES-only, and stamp returns_count / returns_amount.
 *   - The final_local wash NEVER touches return rows (deleting one would
 *     desync returned_quantity, un-net reports, and eat quota).
 *   - reports(): dailySales / paymentSummary / monthlyTrend are SIGNED
 *     (returns subtract), topItems excludes return lines (gross ranking).
 *   - buildReportRangeAnalytics: return transactions are EXCLUDED entirely
 *     (categories / profit / cashiers / payments) — not netted.
 *
 * A future refactor (PosPaymentBuckets, performDayClose restructuring, a new
 * report query) that forgets the return branch would silently corrupt the
 * cash-drawer expected or net sales — these tests make that loud.
 *
 * Pattern: APP_ENV=testing + sqlite :memory: + minimal Schema::create
 * (PosPaymentBucketsTest / PosPendingBillsTileTest approach).
 */
class PosDayCloseReturnNettingTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Schema::dropAllTables();
        User::flushScopeColumnCache();
        // planAllows caches per company id statically — ids restart at 1 after
        // dropAllTables, so a stale cache would leak between tests (the
        // analytics_enabled gate on reports() reads it since Task 664).
        \App\Services\PosFeatureService::flushGateCaches();

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
            $table->timestamps();
        });

        Schema::create('pos_transactions', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('company_id');
            $table->string('invoice_number');
            // Return / credit-note columns (Task 570).
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
            // Frozen cost snapshot (Task 423) — profit basis in range analytics.
            $table->decimal('cost_price', 12, 2)->nullable();
            $table->decimal('subtotal', 12, 2)->default(0);
            $table->boolean('is_tax_exempt')->default(false);
            $table->decimal('tax_rate', 8, 2)->nullable();
            $table->decimal('tax_amount', 12, 2)->nullable();
            $table->decimal('item_discount_amount', 12, 2)->nullable();
            $table->timestamps();
        });

        Schema::create('pos_payments', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('transaction_id');
            $table->string('payment_method')->nullable();
            $table->decimal('amount', 12, 2)->default(0);
            $table->timestamps();
        });

        Schema::create('pos_day_close_reports', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('company_id');
            $table->date('report_date');
            $table->string('report_number')->nullable();
            $table->integer('deleted_final_count')->default(0);
            $table->integer('deleted_provisional_count')->default(0);
            $table->text('local_summary')->nullable();
            $table->text('rider_summary')->nullable();
            $table->integer('total_invoices')->default(0);
            $table->integer('pra_invoices')->default(0);
            $table->integer('local_invoices')->default(0);
            $table->integer('offline_invoices')->default(0);
            $table->decimal('gross_sales', 14, 2)->default(0);
            $table->decimal('total_discount', 14, 2)->default(0);
            $table->decimal('net_sales', 14, 2)->default(0);
            $table->decimal('total_tax', 14, 2)->default(0);
            $table->decimal('total_amount', 14, 2)->default(0);
            $table->decimal('cash_amount', 14, 2)->default(0);
            $table->decimal('card_amount', 14, 2)->default(0);
            $table->decimal('other_amount', 14, 2)->default(0);
            // Returns detail (Task 570).
            $table->integer('returns_count')->default(0);
            $table->decimal('returns_amount', 14, 2)->default(0);
            $table->string('first_invoice_number')->nullable();
            $table->string('last_invoice_number')->nullable();
            $table->timestamp('first_invoice_time')->nullable();
            $table->timestamp('last_invoice_time')->nullable();
            $table->unsignedBigInteger('closed_by')->nullable();
            $table->text('notes')->nullable();
            $table->string('hash')->nullable();
            $table->decimal('opening_float', 14, 2)->nullable();
            $table->decimal('counted_cash', 14, 2)->nullable();
            $table->decimal('expected_cash', 14, 2)->nullable();
            $table->decimal('cash_variance', 14, 2)->nullable();
            $table->timestamps();
        });

        Schema::create('pos_riders', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('company_id');
            $table->string('name');
            $table->timestamps();
        });

        // dayCloseReport / range analytics query these unconditionally.
        Schema::create('restaurant_orders', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('company_id');
            $table->string('status')->nullable();
            $table->unsignedBigInteger('table_id')->nullable();
            $table->string('source')->nullable();
            $table->unsignedBigInteger('pos_transaction_id')->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
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

        Schema::create('subscriptions', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('company_id');
            $table->unsignedBigInteger('pricing_plan_id')->nullable();
            $table->boolean('active')->default(false);
            $table->timestamps();
        });

        Schema::create('pricing_plans', function (Blueprint $table) {
            $table->id();
            $table->string('name')->nullable();
            $table->boolean('restaurant_enabled')->default(false);
            $table->timestamps();
        });

        Schema::create('pos_day_openings', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('company_id');
            $table->date('business_date');
            $table->decimal('opening_cash', 14, 2)->default(0);
            $table->timestamps();
        });
    }

    protected function tearDown(): void
    {
        Auth::guard('pos')->logout();
        parent::tearDown();
    }

    // ── fixtures ────────────────────────────────────────────────────────────

    private function makeCompany(array $attrs = []): int
    {
        return (int) DB::table('companies')->insertGetId(array_merge([
            'name' => 'Netting Test Co',
            'product_type' => 'pos',
            'status' => 'active',
            'pos_dayclose_provisional_action' => 'save',
            'pos_dayclose_final_local_action' => 'save',
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
            'business_date' => now()->toDateString(),
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
     * The canonical netting day (one company, one cashier):
     *   Sale A  — cash, sub 1000, disc 100, tax 170, total 1070 (Burger ×4).
     *   Sale B  — debit_card, sub 500, tax 85, total 585 (Chai ×5).
     *   Return R — cash refund of 2 Burgers off Sale A: sub 200, disc 20,
     *              tax 34, total 214 (row stores POSITIVE amounts).
     * Netted day: gross 1300, discount 80, net 1220, tax 221, total 1441,
     * cash 856 (1070−214), card 585. Sales-only count = 2.
     */
    private function seedNettingDay(int $companyId, ?int $cashierId = null): array
    {
        $saleA = $this->makeTxn($companyId, 'P-0001', [
            'subtotal' => 1000, 'discount_amount' => 100, 'tax_amount' => 170,
            'total_amount' => 1070, 'payment_method' => 'cash', 'created_by' => $cashierId,
        ], [
            ['item_id' => 501, 'item_name' => 'Burger', 'quantity' => 4, 'unit_price' => 250,
             'subtotal' => 1000, 'tax_rate' => 17, 'tax_amount' => 170, 'cost_price' => 150],
        ]);
        $saleB = $this->makeTxn($companyId, 'P-0002', [
            'subtotal' => 500, 'tax_amount' => 85, 'total_amount' => 585,
            'payment_method' => 'debit_card', 'created_by' => $cashierId,
        ], [
            ['item_id' => 502, 'item_name' => 'Chai', 'quantity' => 5, 'unit_price' => 100,
             'subtotal' => 500, 'tax_rate' => 17, 'tax_amount' => 85, 'cost_price' => 40],
        ]);
        $return = $this->makeTxn($companyId, 'RET-0001', [
            'transaction_type' => 'return',
            'parent_transaction_id' => $saleA,
            'pra_status' => 'pending',
            'pra_invoice_number' => null,
            'subtotal' => 200, 'discount_amount' => 20, 'tax_amount' => 34,
            'total_amount' => 214, 'payment_method' => 'cash', 'created_by' => $cashierId,
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

        return [$saleA, $saleB, $return];
    }

    // ── 1. performDayClose: stored Z-report netting ──────────────────────────

    public function test_perform_day_close_nets_returns_and_keeps_counts_sales_only(): void
    {
        $companyId = $this->makeCompany();
        $this->seedNettingDay($companyId);

        $result = (new PosController())->performDayClose($companyId, now()->toDateString(), null);

        $this->assertSame('created', $result['status']);
        $report = DB::table('pos_day_close_reports')->where('company_id', $companyId)->first();
        $this->assertNotNull($report);

        // Netted money figures.
        $this->assertSame(1300.0, (float) $report->gross_sales, 'gross = sales sub − return sub');
        $this->assertSame(80.0, (float) $report->total_discount);
        $this->assertSame(1220.0, (float) $report->net_sales);
        $this->assertSame(221.0, (float) $report->total_tax);
        $this->assertSame(1441.0, (float) $report->total_amount);

        // Counts stay SALES-only (returns carry RET- numbers outside the USIN range).
        $this->assertSame(2, (int) $report->total_invoices);
        $this->assertSame(2, (int) $report->pra_invoices);
        $this->assertSame('P-0001', $report->first_invoice_number);
        $this->assertSame('P-0002', $report->last_invoice_number, 'return must never be the serial-range edge');

        // Returns detail columns.
        $this->assertSame(1, (int) $report->returns_count);
        $this->assertSame(214.0, (float) $report->returns_amount);

        // Cash drawer: cash bucket = cash sales − cash refunds; card untouched.
        $this->assertSame(856.0, (float) $report->cash_amount, '1070 cash sales − 214 cash refund');
        $this->assertSame(585.0, (float) $report->card_amount);
        $this->assertSame(0.0, (float) $report->other_amount);
        // Conservation: buckets sum to the netted day total.
        $this->assertSame(
            (float) $report->total_amount,
            (float) $report->cash_amount + (float) $report->card_amount + (float) $report->other_amount
        );
    }

    // ── 2. final_local wash never touches returns ────────────────────────────

    public function test_final_local_delete_wash_skips_return_rows(): void
    {
        $companyId = $this->makeCompany(['pos_dayclose_final_local_action' => 'delete']);

        // Reporting-OFF final (completed + mode pra + NULL status + no fiscal).
        $saleId = $this->makeTxn($companyId, 'P-0001', [
            'pra_status' => null, 'pra_invoice_number' => null,
            'subtotal' => 100, 'tax_amount' => 0, 'total_amount' => 100,
        ], [
            ['item_name' => 'Widget', 'quantity' => 1, 'unit_price' => 100, 'subtotal' => 100],
        ]);
        // A LOCAL return (same shape as the wash selector except transaction_type).
        $returnId = $this->makeTxn($companyId, 'RET-0001', [
            'transaction_type' => 'return', 'parent_transaction_id' => $saleId,
            'pra_status' => null, 'pra_invoice_number' => null,
            'subtotal' => 40, 'tax_amount' => 0, 'total_amount' => 40,
        ], [
            ['item_name' => 'Widget', 'quantity' => 1, 'unit_price' => 40, 'subtotal' => 40],
        ]);

        $result = (new PosController())->performDayClose($companyId, now()->toDateString(), null);

        $this->assertSame('created', $result['status']);
        $this->assertSame(1, $result['deleted'], 'only the plain reporting-OFF final is washed');
        $this->assertNull(
            PosTransaction::withoutGlobalScope('hide_archived')->find($saleId),
            'plain final must be deleted per policy'
        );
        $survivor = PosTransaction::withoutGlobalScope('hide_archived')->find($returnId);
        $this->assertNotNull($survivor, 'return/credit-note must NEVER be washed');
        $this->assertFalse((bool) $survivor->is_archived, 'return must not be archived either');
        $this->assertSame(1, DB::table('pos_transaction_items')->where('transaction_id', $returnId)->count());

        // Quota add-back counts only the deleted plain final, never the return.
        $report = DB::table('pos_day_close_reports')->where('company_id', $companyId)->first();
        $this->assertSame(1, (int) $report->deleted_final_count);
    }

    // ── 3. dayCloseReport preview matches the stored netting ─────────────────

    public function test_day_close_preview_stats_net_returns_like_the_stored_report(): void
    {
        $companyId = $this->makeCompany();
        $user = $this->makePosUser($companyId);
        $this->seedNettingDay($companyId, $user->id);

        Auth::guard('pos')->setUser($user);
        app()->instance('currentCompanyId', $companyId);
        $request = Request::create('/pos/day-close', 'GET', ['date' => now()->toDateString()]);

        $data = (new PosController())->dayCloseReport($request)->getData();
        $stats = $data['stats'];

        $this->assertSame(2, (int) $stats->total_invoices);
        $this->assertSame(1300.0, (float) $stats->gross_sales);
        $this->assertSame(80.0, (float) $stats->total_discount);
        $this->assertSame(1220.0, (float) $stats->net_sales);
        $this->assertSame(221.0, (float) $stats->total_tax);
        $this->assertSame(1441.0, (float) $stats->total_amount);
        $this->assertSame(856.0, (float) $stats->cash_amount);
        $this->assertSame(585.0, (float) $stats->card_amount);
        $this->assertSame(0.0, (float) $stats->other_amount);
        $this->assertSame(1, (int) $stats->returns_count);
        $this->assertSame(214.0, (float) $stats->returns_amount);
        $this->assertSame('P-0002', $stats->last_invoice->invoice_number, 'serial range stays sales-only');

        // Cashier breakdown: SIGNED revenue/tax, sales-only count.
        $row = $data['cashierBreakdown']['POS Admin'];
        $this->assertSame(2, (int) $row->count);
        $this->assertSame(1441.0, (float) $row->revenue);
        $this->assertSame(221.0, (float) $row->tax);
    }

    // ── 4. reports(): signed headline queries + gross topItems ───────────────

    public function test_reports_daily_payment_monthly_are_netted_and_top_items_exclude_returns(): void
    {
        $companyId = $this->makeCompany();
        $user = $this->makePosUser($companyId);
        $this->seedNettingDay($companyId, $user->id);

        Auth::guard('pos')->setUser($user);
        app()->instance('currentCompanyId', $companyId);
        $request = Request::create('/pos/reports', 'GET');

        $data = (new PosController())->reports($request)->getData();

        // dailySales: netted revenue, sales-only count.
        $today = $data['dailySales']->firstWhere('date', now()->toDateString());
        $this->assertNotNull($today);
        $this->assertSame(2, (int) $today->count);
        $this->assertSame(1441.0, (float) $today->revenue);

        // paymentSummary: cash netted (return refunded in cash), card untouched.
        $byMethod = $data['paymentSummary']->keyBy('payment_method');
        $this->assertSame(1, (int) $byMethod['cash']->count, 'refund row must not count as a bill');
        $this->assertSame(856.0, (float) $byMethod['cash']->total);
        $this->assertSame(136.0, (float) $byMethod['cash']->tax, '170 − 34 refund tax');
        $this->assertSame(585.0, (float) $byMethod['debit_card']->total);
        $this->assertSame(85.0, (float) $byMethod['debit_card']->tax);

        // monthlyTrend: this month netted.
        $month = $data['monthlyTrend']->firstWhere('month', now()->format('Y-m'));
        $this->assertNotNull($month);
        $this->assertSame(2, (int) $month->count);
        $this->assertSame(1441.0, (float) $month->revenue);

        // topItems: GROSS ranking — return lines excluded, never netted.
        $items = $data['topItems']->keyBy('item_name');
        $this->assertSame(4.0, (float) $items['Burger']->total_qty, 'return qty must not join (6) or net (2) the ranking');
        $this->assertSame(1000.0, (float) $items['Burger']->total_revenue);
        $this->assertSame(5.0, (float) $items['Chai']->total_qty);
        $this->assertSame(500.0, (float) $items['Chai']->total_revenue);
    }

    // ── 5. range analytics: returns EXCLUDED entirely ────────────────────────

    public function test_range_analytics_exclude_return_transactions_completely(): void
    {
        $companyId = $this->makeCompany();
        $user = $this->makePosUser($companyId);
        $this->seedNettingDay($companyId, $user->id);

        // reports() now plan-gates the analytics deep dive (Task 664 review):
        // give the company an active subscription so rangeAnalytics is built.
        // pricing_plans here lacks analytics_enabled → gate fails OPEN by the
        // schema-lag convention, which is exactly what this minimal schema needs.
        $planId = DB::table('pricing_plans')->insertGetId([
            'name' => 'Business', 'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('subscriptions')->insert([
            'company_id' => $companyId, 'pricing_plan_id' => $planId,
            'active' => true, 'created_at' => now(), 'updated_at' => now(),
        ]);

        Auth::guard('pos')->setUser($user);
        app()->instance('currentCompanyId', $companyId);
        $request = Request::create('/pos/reports', 'GET');

        $ra = (new PosController())->reports($request)->getData()['rangeAnalytics'];

        // Summary: sales-only, GROSS (excluded, not netted).
        $this->assertSame(2, (int) $ra->summary->bills);
        $this->assertSame(1655.0, (float) $ra->summary->revenue);
        $this->assertSame(255.0, (float) $ra->summary->tax);

        // Categories: return line (Burger ×2) must not shrink Food.
        $this->assertSame(1000.0, (float) $ra->categories['Food']->revenue);
        $this->assertSame(4.0, (float) $ra->categories['Food']->qty);
        $this->assertSame(500.0, (float) $ra->categories['Drinks']->revenue);

        // Profit: cost basis = sale lines' frozen snapshots only.
        // 4×150 (Burger) + 5×40 (Chai) = 800 cost on 1500 costed revenue.
        $this->assertNotNull($ra->profit);
        $this->assertSame(800.0, (float) $ra->profit->cost);
        $this->assertSame(1500.0, (float) $ra->profit->revenue);
        $this->assertSame(700.0, (float) $ra->profit->profit);

        // Cashier ranking: the refund row never joins the cashier's figures.
        $this->assertCount(1, $ra->cashiers);
        $this->assertSame(2, (int) $ra->cashiers[0]->count);
        $this->assertSame(1655.0, (float) $ra->cashiers[0]->revenue);
        $this->assertSame(255.0, (float) $ra->cashiers[0]->tax);

        // Payment mix: cash shows the GROSS cash sale (1070), not 856.
        $this->assertSame(1070.0, (float) $ra->payments['cash']->revenue);
        $this->assertSame(1, (int) $ra->payments['cash']->count);
    }
}
