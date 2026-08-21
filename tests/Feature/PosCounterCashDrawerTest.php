<?php

namespace Tests\Feature;

use App\Http\Controllers\PosController;
use App\Models\PosCounterClose;
use App\Models\PosDayOpening;
use App\Models\User;
use App\Services\PosCounterDrawer;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * PER-COUNTER CASH DRAWER + PER-COUNTER DAY CLOSE (Task 1375).
 *
 * Do ya teen counters wali shop mein har counter par alag cash rakhi hoti hai,
 * so the evening count happens counter by counter. The invariants that make the
 * owner's numbers trustworthy — and that a refactor could silently break:
 *
 *   1. The drawers TILE the shop: every bill lands in exactly one drawer, so
 *      counter cash sales sum to the shop's cash figure.
 *   2. Each counter carries its OWN opening float; the shop's opening is their
 *      sum (a single-row read would drop the second counter's money).
 *   3. Closing one counter freezes ONLY that counter — the other counters keep
 *      billing and NO Z-report is created yet.
 *   4. The shop's day closes automatically when the LAST used drawer closes,
 *      with the counter reconciliation frozen onto the Z-report.
 *   5. A shop that never made a counter sees no counter rows at all — day close
 *      stays exactly as it was, with no new blocker.
 *
 * Pattern: APP_ENV=testing + sqlite :memory: + minimal Schema::create.
 */
class PosCounterCashDrawerTest extends TestCase
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

        Schema::create('pos_terminals', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('company_id');
            $table->string('terminal_name');
            $table->string('terminal_code')->nullable();
            $table->string('location')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('pos_counter_closes', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('company_id');
            $table->unsignedBigInteger('branch_id')->default(0);
            $table->unsignedBigInteger('terminal_id')->default(0);
            $table->date('business_date');
            $table->decimal('opening_float', 14, 2)->nullable();
            $table->decimal('cash_sales', 14, 2)->default(0);
            $table->decimal('expected_cash', 14, 2)->default(0);
            $table->decimal('counted_cash', 14, 2)->nullable();
            $table->decimal('cash_variance', 14, 2)->nullable();
            $table->integer('bills_count')->default(0);
            $table->decimal('total_sales', 14, 2)->default(0);
            $table->unsignedBigInteger('closed_by')->nullable();
            $table->text('notes')->nullable();
            $table->timestamp('closed_at')->nullable();
            $table->timestamps();
            $table->unique(['company_id', 'branch_id', 'terminal_id', 'business_date'], 'pos_counter_closes_scope_unique');
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
            // Task 1349: the counter every bill was billed on.
            $table->unsignedBigInteger('terminal_id')->nullable();
            $table->unsignedBigInteger('customer_id')->nullable();
            $table->string('customer_name')->nullable();
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
            $table->text('stream_summary')->nullable();
            // Task 1375: frozen counter-wise cash reconciliation.
            $table->text('counter_summary')->nullable();
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

        Schema::create('pos_day_openings', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('company_id');
            $table->unsignedBigInteger('branch_id')->default(0);
            // Task 1375: 0 = shop drawer / no counter.
            $table->unsignedBigInteger('terminal_id')->default(0);
            $table->date('business_date');
            $table->decimal('opening_cash', 14, 2)->default(0);
            $table->unsignedBigInteger('entered_by')->nullable();
            $table->string('notes', 500)->nullable();
            $table->timestamps();
            $table->unique(['company_id', 'branch_id', 'terminal_id', 'business_date'], 'pos_day_openings_scope_terminal_date_unique');
        });

        Schema::create('pos_riders', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('company_id');
            $table->string('name');
            $table->timestamps();
        });

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
    }

    protected function tearDown(): void
    {
        Auth::guard('pos')->logout();
        parent::tearDown();
    }

    // ── fixtures ────────────────────────────────────────────────────────────

    private function makeCompany(): int
    {
        return (int) DB::table('companies')->insertGetId([
            'name' => 'Do Counter Karyana',
            'product_type' => 'pos',
            'status' => 'active',
            'pos_dayclose_provisional_action' => 'save',
            'pos_dayclose_final_local_action' => 'save',
            'pos_setup_completed' => true,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function makeAdmin(int $companyId): User
    {
        $id = DB::table('users')->insertGetId([
            'name' => 'Malik',
            'email' => 'malik' . $companyId . '@taxnest.test',
            'company_id' => $companyId,
            'role' => 'user',
            'pos_role' => 'pos_admin',
            'is_active' => true,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        return User::find($id);
    }

    private function makeCounter(int $companyId, string $name): int
    {
        return (int) DB::table('pos_terminals')->insertGetId([
            'company_id' => $companyId,
            'terminal_name' => $name,
            'is_active' => true,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function makeBill(int $companyId, string $number, ?int $terminalId, float $total, string $method = 'cash'): int
    {
        return (int) DB::table('pos_transactions')->insertGetId([
            'company_id' => $companyId,
            'invoice_number' => $number,
            'transaction_type' => 'sale',
            'business_date' => now()->toDateString(),
            'status' => 'completed',
            'invoice_mode' => 'pra',
            'pra_status' => 'submitted',
            'pra_invoice_number' => 'PRA-' . $number,
            'terminal_id' => $terminalId,
            'subtotal' => $total, 'tax_amount' => 0, 'total_amount' => $total,
            'payment_method' => $method,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    /** The controller acts on the pos guard + the resolved company. */
    private function actAs(int $companyId, User $user): void
    {
        app()->instance('currentCompanyId', $companyId);
        Auth::guard('pos')->setUser($user);
    }

    private function req(array $data): Request
    {
        $request = Request::create('/pos/day-close/counter', 'POST', $data);
        $request->setLaravelSession(app('session.store'));

        return $request;
    }

    private function rows(int $companyId)
    {
        $date = now()->toDateString();
        $txns = \App\Models\PosTransaction::where('company_id', $companyId)
            ->where('business_date', $date)->get();

        return PosCounterDrawer::rows($companyId, null, $date, $txns);
    }

    // ── 1. the drawers tile the shop ────────────────────────────────────────

    public function test_counter_rows_tile_the_shop_cash(): void
    {
        $companyId = $this->makeCompany();
        $c1 = $this->makeCounter($companyId, 'Counter 1');
        $c2 = $this->makeCounter($companyId, 'Counter 2');

        $this->makeBill($companyId, 'A-1', $c1, 1000);
        $this->makeBill($companyId, 'A-2', $c1, 500, 'debit_card');
        $this->makeBill($companyId, 'B-1', $c2, 700);
        // Legacy bill from before counters existed / a device that sent none.
        $this->makeBill($companyId, 'X-1', null, 300);

        $rows = $this->rows($companyId)->keyBy('terminal_id');

        $this->assertSame(1000.0, (float) $rows[$c1]['cash_sales'], 'card sale is not cash');
        $this->assertSame(700.0, (float) $rows[$c2]['cash_sales']);
        $this->assertSame(300.0, (float) $rows[0]['cash_sales'], 'counter-less bills sit on the shop drawer');

        // The rows sum to the shop's cash — never more, never less.
        $totals = PosCounterDrawer::totals($this->rows($companyId));
        $this->assertSame(2000.0, (float) $totals['cash_sales']);
        $this->assertSame(4, (int) $totals['bills']);
    }

    // ── 2. every counter keeps its OWN opening float ────────────────────────

    public function test_each_counter_records_its_own_opening_and_shop_total_is_the_sum(): void
    {
        $companyId = $this->makeCompany();
        $admin = $this->makeAdmin($companyId);
        $c1 = $this->makeCounter($companyId, 'Counter 1');
        $c2 = $this->makeCounter($companyId, 'Counter 2');
        $this->actAs($companyId, $admin);

        $controller = new PosController();
        $controller->saveDayOpening($this->req(['opening_cash' => 3000, 'terminal_id' => $c1]));
        $controller->saveDayOpening($this->req(['opening_cash' => 2000, 'terminal_id' => $c2]));

        $drawers = PosDayOpening::drawersForDate($companyId, now()->toDateString());
        $this->assertSame(3000.0, (float) $drawers[$c1]);
        $this->assertSame(2000.0, (float) $drawers[$c2]);

        // The shop's float = the sum; reading one row would lose Rs 2000.
        $this->assertSame(5000.0, PosDayOpening::totalForDate($companyId, now()->toDateString()));

        // Two counters = two drawer rows: the second float must NOT overwrite
        // the first (it would if the upsert key forgot the counter).
        $this->assertSame(2, DB::table('pos_day_openings')->where('company_id', $companyId)->count());
    }

    // ── 3. closing ONE counter must not end the shop's day ──────────────────

    public function test_closing_one_counter_freezes_it_and_leaves_the_other_billing(): void
    {
        $companyId = $this->makeCompany();
        $admin = $this->makeAdmin($companyId);
        $c1 = $this->makeCounter($companyId, 'Counter 1');
        $c2 = $this->makeCounter($companyId, 'Counter 2');
        $this->actAs($companyId, $admin);

        $this->makeBill($companyId, 'A-1', $c1, 1000);
        $this->makeBill($companyId, 'B-1', $c2, 700);

        $controller = new PosController();
        $controller->saveDayOpening($this->req(['opening_cash' => 1000, 'terminal_id' => $c1]));
        // Counted Rs 50 short.
        $controller->closeCounter($this->req(['terminal_id' => $c1, 'counted_cash' => 1950]));

        $close = PosCounterClose::where('company_id', $companyId)->where('terminal_id', $c1)->first();
        $this->assertNotNull($close, 'closing a counter writes exactly one row');
        $this->assertSame(2000.0, (float) $close->expected_cash, '1000 float + 1000 cash sale');
        $this->assertSame(-50.0, (float) $close->cash_variance);

        // The OTHER counter is untouched — no day close, no lock.
        $this->assertTrue(PosCounterDrawer::isClosed($companyId, $c1, now()->toDateString()));
        $this->assertFalse(PosCounterDrawer::isClosed($companyId, $c2, now()->toDateString()));
        $this->assertSame(0, DB::table('pos_day_close_reports')->where('company_id', $companyId)->count(),
            'the shop day must NOT close while another counter is still open');

        // A late bill on the OPEN counter still lands, and the closed counter's
        // frozen figures do not move with it.
        $this->makeBill($companyId, 'B-2', $c2, 300);
        $rows = $this->rows($companyId)->keyBy('terminal_id');
        $this->assertSame(1000.0, (float) $rows[$c1]['cash_sales'], 'frozen');
        $this->assertSame(1000.0, (float) $rows[$c2]['cash_sales'], 'still billing');
    }

    // ── 4. the LAST counter's close ends the shop's day ─────────────────────

    public function test_last_counter_close_closes_the_day_and_freezes_counter_summary(): void
    {
        $companyId = $this->makeCompany();
        $admin = $this->makeAdmin($companyId);
        $c1 = $this->makeCounter($companyId, 'Counter 1');
        $c2 = $this->makeCounter($companyId, 'Counter 2');
        $this->actAs($companyId, $admin);

        $this->makeBill($companyId, 'A-1', $c1, 1000);
        $this->makeBill($companyId, 'B-1', $c2, 700);

        $controller = new PosController();
        $controller->saveDayOpening($this->req(['opening_cash' => 1000, 'terminal_id' => $c1]));
        $controller->saveDayOpening($this->req(['opening_cash' => 500, 'terminal_id' => $c2]));
        $controller->closeCounter($this->req(['terminal_id' => $c1, 'counted_cash' => 2000]));
        $this->assertSame(0, DB::table('pos_day_close_reports')->where('company_id', $companyId)->count());

        $controller->closeCounter($this->req(['terminal_id' => $c2, 'counted_cash' => 1100]));

        $report = DB::table('pos_day_close_reports')->where('company_id', $companyId)->first();
        $this->assertNotNull($report, 'sab counter close = poora din close');
        // Shop figures are exactly the sum of the drawers.
        $this->assertSame(1500.0, (float) $report->opening_float, '1000 + 500');
        $this->assertSame(3100.0, (float) $report->counted_cash, '2000 + 1100');
        $this->assertSame(3200.0, (float) $report->expected_cash, '1500 float + 1700 cash');
        $this->assertSame(-100.0, (float) $report->cash_variance);

        // The counter reconciliation is FROZEN on the Z-report (the wash can
        // delete rows a later recompute would miss).
        $frozen = json_decode($report->counter_summary, true);
        $this->assertIsArray($frozen);
        $byId = collect($frozen)->keyBy('terminal_id');
        $this->assertSame(2000.0, (float) $byId[$c1]['expected']);
        $this->assertSame(0.0, (float) $byId[$c1]['variance']);
        $this->assertSame(1200.0, (float) $byId[$c2]['expected']);
        $this->assertSame(-100.0, (float) $byId[$c2]['variance']);
        $this->assertSame('Counter 2', $byId[$c2]['name']);
    }

    // ── 5. counter-less shops keep the old day close, unchanged ─────────────

    public function test_shop_without_counters_gets_no_counter_rows(): void
    {
        $companyId = $this->makeCompany();
        $this->makeBill($companyId, 'A-1', null, 1000);
        $this->makeBill($companyId, 'A-2', null, 500);

        $this->assertTrue($this->rows($companyId)->isEmpty(),
            'no counters = no counter card, no new day-close rule');
        $this->assertFalse(PosCounterDrawer::enabled($companyId));

        // And the plain day close still works exactly as before.
        $result = (new PosController())->performDayClose($companyId, now()->toDateString(), null);
        $this->assertSame('created', $result['status']);
        $report = DB::table('pos_day_close_reports')->where('company_id', $companyId)->first();
        $this->assertNull($report->counter_summary, 'nothing frozen for a counter-less shop');
    }

    // ── 6. reopening puts a miscounted counter back on the floor ────────────

    public function test_reopen_counter_removes_the_close_and_refuses_when_not_closed(): void
    {
        $companyId = $this->makeCompany();
        $admin = $this->makeAdmin($companyId);
        $c1 = $this->makeCounter($companyId, 'Counter 1');
        $c2 = $this->makeCounter($companyId, 'Counter 2');
        $this->actAs($companyId, $admin);

        $this->makeBill($companyId, 'A-1', $c1, 1000);
        $this->makeBill($companyId, 'B-1', $c2, 700);

        $controller = new PosController();
        $controller->closeCounter($this->req(['terminal_id' => $c1, 'counted_cash' => 1000]));
        $this->assertTrue(PosCounterDrawer::isClosed($companyId, $c1, now()->toDateString()));

        $controller->reopenCounter($this->req(['terminal_id' => $c1]));
        $this->assertFalse(PosCounterDrawer::isClosed($companyId, $c1, now()->toDateString()));
        $this->assertSame(0, PosCounterClose::where('company_id', $companyId)->count());

        // A counter that was never closed cannot be reopened.
        $response = $controller->reopenCounter($this->req(['terminal_id' => $c2]));
        $this->assertNotNull($response->getSession()->get('error'));
    }

    // ── 7. a closed counter refuses a second close ──────────────────────────

    public function test_closed_counter_cannot_be_closed_twice(): void
    {
        $companyId = $this->makeCompany();
        $admin = $this->makeAdmin($companyId);
        $c1 = $this->makeCounter($companyId, 'Counter 1');
        $c2 = $this->makeCounter($companyId, 'Counter 2');
        $this->actAs($companyId, $admin);

        $this->makeBill($companyId, 'A-1', $c1, 1000);
        $this->makeBill($companyId, 'B-1', $c2, 700);

        $controller = new PosController();
        $controller->closeCounter($this->req(['terminal_id' => $c1, 'counted_cash' => 1000]));
        $response = $controller->closeCounter($this->req(['terminal_id' => $c1, 'counted_cash' => 999]));

        $this->assertNotNull($response->getSession()->get('error'));
        $this->assertSame(1000.0,
            (float) PosCounterClose::where('company_id', $companyId)->where('terminal_id', $c1)->value('counted_cash'),
            'the first count stands');
    }
}
