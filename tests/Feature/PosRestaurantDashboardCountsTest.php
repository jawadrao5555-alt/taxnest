<?php

namespace Tests\Feature;

use App\Http\Controllers\RestaurantPosController;
use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Restaurant dashboard — open-tables & cancelled counts (Task 161).
 *
 * Task 153 locked the Pending Bills tile for the PRA retail and FBR
 * dashboards (PosPendingBillsTileTest). The restaurant dashboard shares the
 * same tile partial, but its controller-side counts were unlocked. This test
 * locks:
 *
 *   1. openOrdersCount counts ONLY un-settled orders (held/preparing/ready) —
 *      settled (completed) and cancelled orders must NEVER be counted, and
 *      the count is NOT limited to today (a table left open from before the
 *      cutoff is still pending).
 *   2. cancelledTodayCount counts ONLY the current business day's cancelled
 *      orders (COALESCE(cancelled_at, updated_at) window) and is NEVER part
 *      of the tile's pending total badge.
 *   3. The rendered tile links open tables to pos.restaurant.tables and
 *      cancelled orders to pos.restaurant.cancelled-orders.
 *
 * Pattern mirrors PosPendingBillsTileTest: sqlite :memory: + minimal
 * Schema::create, RestaurantPosController::dashboard() invoked directly with
 * the currentCompanyId container binding, view data combined with a real
 * render of the shared tile partial.
 */
class PosRestaurantDashboardCountsTest extends TestCase
{
    protected int $companyId;

    protected function setUp(): void
    {
        parent::setUp();

        Schema::dropAllTables();

        Schema::create('companies', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->boolean('pra_reporting_enabled')->default(false);
            $table->boolean('inventory_enabled')->default(false);
            $table->string('pos_dashboard_style')->nullable();
            $table->string('pos_business_day_cutoff')->nullable();
            $table->softDeletes();
            $table->timestamps();
        });

        Schema::create('users', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('company_id')->nullable();
            $table->string('name')->nullable();
            $table->string('role')->nullable();
            $table->string('pos_role')->nullable();
            $table->boolean('pra_reporting_enabled')->nullable();
            $table->timestamps();
        });

        Schema::create('restaurant_orders', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('company_id');
            $table->string('order_number')->nullable();
            $table->unsignedBigInteger('table_id')->nullable();
            $table->string('order_type')->nullable();
            $table->string('status');
            $table->decimal('subtotal', 12, 2)->default(0);
            $table->decimal('discount_amount', 12, 2)->default(0);
            $table->decimal('tax_amount', 12, 2)->default(0);
            $table->decimal('total_amount', 12, 2)->default(0);
            $table->decimal('estimated_cost', 12, 2)->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->timestamp('kot_sent_at')->nullable();
            $table->timestamp('kitchen_started_at')->nullable();
            $table->timestamp('kitchen_ready_at')->nullable();
            $table->timestamp('kitchen_cleared_at')->nullable();
            $table->timestamps();
        });

        Schema::create('restaurant_order_items', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('restaurant_order_id')->nullable();
            $table->unsignedBigInteger('order_id')->nullable();
            $table->string('item_name')->nullable();
            $table->decimal('quantity', 12, 3)->default(1);
            $table->decimal('subtotal', 12, 2)->default(0);
            $table->timestamps();
        });

        Schema::create('restaurant_tables', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('company_id');
            $table->string('table_number')->nullable();
            $table->string('status')->nullable();
            $table->timestamps();
        });

        Schema::create('ingredients', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('company_id');
            $table->string('name')->nullable();
            $table->boolean('is_active')->default(true);
            $table->decimal('current_stock', 12, 3)->default(0);
            $table->decimal('min_stock_level', 12, 3)->default(0);
            $table->timestamps();
        });

        Schema::create('pos_transactions', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('company_id');
            $table->string('invoice_number');
            $table->string('business_date')->nullable();
            $table->string('status');
            $table->string('invoice_mode')->nullable();
            $table->string('pra_status')->nullable();
            $table->boolean('is_archived')->default(false);
            $table->decimal('total_amount', 12, 2)->default(0);
            $table->timestamps();
        });

        // PosBusinessDay consults day-close reports pre-cutoff.
        Schema::create('pos_day_close_reports', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('company_id');
            $table->string('report_date');
            $table->timestamps();
        });

        $this->companyId = DB::table('companies')->insertGetId([
            'name' => 'Karahi House',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        app()->bind('currentCompanyId', fn () => $this->companyId);
    }

    protected function tearDown(): void
    {
        \Carbon\Carbon::setTestNow();
        Auth::guard('pos')->logout();
        parent::tearDown();
    }

    // ── helpers ──────────────────────────────────────────────────────────────

    protected function actAs(string $posRole): User
    {
        DB::table('users')->insert([
            'company_id' => $this->companyId,
            'name' => 'U-' . $posRole,
            'role' => 'user',
            'pos_role' => $posRole,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $user = User::orderByDesc('id')->first();
        Auth::guard('pos')->setUser($user);

        return $user;
    }

    protected function order(array $attrs = []): int
    {
        return DB::table('restaurant_orders')->insertGetId(array_merge([
            'company_id' => $this->companyId,
            'order_number' => 'R-' . uniqid(),
            'status' => 'held',
            'total_amount' => 500,
            'created_at' => now(), 'updated_at' => now(),
        ], $attrs));
    }

    protected function dashboardData(): array
    {
        return (new RestaurantPosController())->dashboard()->getData();
    }

    /** Render the shared tile partial exactly like the restaurant dashboard includes it. */
    protected function renderTile(array $viewData): string
    {
        return view('pos.partials.pending-bills-tile', $viewData)->render();
    }

    // ── openOrdersCount ──────────────────────────────────────────────────────

    public function test_open_orders_count_only_held_preparing_ready_never_settled_or_cancelled(): void
    {
        $this->actAs('pos_admin');

        // Counted: one of each open status.
        $this->order(['status' => 'held']);
        $this->order(['status' => 'preparing']);
        $this->order(['status' => 'ready']);
        // Counted: an old table still open from BEFORE today's cutoff — a
        // table left open yesterday is still pending.
        $old = $this->order(['status' => 'held']);
        DB::table('restaurant_orders')->where('id', $old)
            ->update(['created_at' => now()->subDays(2)]);
        // NEVER counted: settled order.
        $this->order(['status' => 'completed']);
        // NEVER counted: cancelled order.
        $this->order(['status' => 'cancelled', 'cancelled_at' => now()]);

        $data = $this->dashboardData();

        $this->assertSame(4, $data['openOrdersCount']);
        $this->assertTrue($data['isRestaurant']);
        $this->assertTrue($data['isAdmin']);
    }

    // ── cancelledTodayCount ──────────────────────────────────────────────────

    public function test_cancelled_count_is_business_day_only_and_never_in_pending_total(): void
    {
        $this->actAs('pos_admin');

        // Counted: cancelled within the current business day.
        $this->order(['status' => 'cancelled', 'cancelled_at' => now()]);
        // Counted: cancelled_at NULL fallback → updated_at (column is new).
        $this->order(['status' => 'cancelled', 'cancelled_at' => null]);
        // NEVER counted: cancelled on a previous business day.
        $oldCancel = $this->order(['status' => 'cancelled', 'cancelled_at' => now()->subDays(2)]);
        DB::table('restaurant_orders')->where('id', $oldCancel)
            ->update(['created_at' => now()->subDays(2), 'updated_at' => now()->subDays(2)]);
        // One open order so the pending total is deterministic.
        $this->order(['status' => 'held']);

        $data = $this->dashboardData();

        $this->assertSame(2, $data['cancelledTodayCount']);
        $this->assertSame(1, $data['openOrdersCount']);
        $this->assertSame(0, $data['pendingProvisional']);

        // Tile badge total = provisional + open ONLY — cancelled is
        // informational and must NEVER inflate the pending total.
        $html = $this->renderTile($data);
        $this->assertStringContainsString('>1</span>', $html);
        $this->assertStringNotContainsString('>3</span>', $html);
        // Cancelled count renders in its own card.
        $this->assertStringContainsString('>2</span>', $html);
    }

    // ── today-sales business-day window (Task 167) ──────────────────────────
    //
    // The dashboard's "aaj" metrics (todaySales/todayTax/todayDiscount/
    // todayProfit/todayOrders/completedCount) must use the BUSINESS-day
    // window ($today = bizDate + cutoff, default 06:00) — never
    // whereDate(created_at). A 00:00–06:00 sale belongs to the PREVIOUS
    // business day (this exact regression showed as "dashboard sab Rs 0").

    /** Freeze time at a deterministic post-cutoff moment: today 14:00. */
    protected function freezeAfternoon(): \Carbon\Carbon
    {
        $now = \Carbon\Carbon::now(config('app.timezone'))->setTime(14, 0, 0);
        \Carbon\Carbon::setTestNow($now);

        return $now;
    }

    protected function completedSale(\Carbon\Carbon $at, array $attrs = []): int
    {
        $id = $this->order(array_merge([
            'status' => 'completed',
            'total_amount' => 1000,
            'tax_amount' => 160,
            'discount_amount' => 50,
            'estimated_cost' => 400,
        ], $attrs));
        DB::table('restaurant_orders')->where('id', $id)
            ->update(['created_at' => $at, 'updated_at' => $at]);

        return $id;
    }

    public function test_pre_cutoff_sale_counts_in_yesterday_not_today(): void
    {
        $this->actAs('pos_admin');
        $now = $this->freezeAfternoon();

        // 02:00 today (pre-cutoff) → previous business day.
        $this->completedSale($now->copy()->setTime(2, 0, 0));

        $data = $this->dashboardData();

        $this->assertSame(0.0, (float) $data['todaySales']);
        $this->assertSame(0.0, (float) $data['todayTax']);
        $this->assertSame(0.0, (float) $data['todayDiscount']);
        $this->assertSame(0.0, (float) $data['todayProfit']);
        $this->assertSame(0, $data['todayOrders']);
        $this->assertSame(0, $data['completedCount']);
        // ...but it DOES belong to yesterday's business day.
        $this->assertSame(1000.0, (float) $data['yesterdaySales']);
    }

    public function test_post_cutoff_sale_counts_in_today_totals(): void
    {
        $this->actAs('pos_admin');
        $now = $this->freezeAfternoon();

        // 09:30 today (after 06:00 cutoff) → today's business day.
        $this->completedSale($now->copy()->setTime(9, 30, 0));
        // Non-completed order after cutoff: in todayOrders, never in sales.
        $held = $this->order(['status' => 'held', 'total_amount' => 700]);
        DB::table('restaurant_orders')->where('id', $held)
            ->update(['created_at' => $now->copy()->setTime(10, 0, 0)]);

        $data = $this->dashboardData();

        $this->assertSame(1000.0, (float) $data['todaySales']);
        $this->assertSame(160.0, (float) $data['todayTax']);
        $this->assertSame(50.0, (float) $data['todayDiscount']);
        // Profit = sales − estimated_cost.
        $this->assertSame(600.0, (float) $data['todayProfit']);
        $this->assertSame(2, $data['todayOrders']);
        $this->assertSame(1, $data['completedCount']);
        $this->assertSame(0.0, (float) $data['yesterdaySales']);
    }

    public function test_yesterday_sales_window_is_cutoff_to_cutoff(): void
    {
        $this->actAs('pos_admin');
        $now = $this->freezeAfternoon();

        // Yesterday 02:00 = BEFORE yesterday's cutoff → day-before-yesterday's
        // business day; must NOT count in yesterdaySales.
        $this->completedSale($now->copy()->subDay()->setTime(2, 0, 0), ['total_amount' => 300]);
        // Yesterday 20:00 = inside yesterday's business day.
        $this->completedSale($now->copy()->subDay()->setTime(20, 0, 0), ['total_amount' => 800]);
        // Today 02:00 (pre-cutoff) also lands in yesterday's business day.
        $this->completedSale($now->copy()->setTime(2, 0, 0), ['total_amount' => 200]);

        $data = $this->dashboardData();

        $this->assertSame(1000.0, (float) $data['yesterdaySales']);
        $this->assertSame(0.0, (float) $data['todaySales']);
    }

    // ── 7-din sales chart business-day bars (Task 168) ──────────────────────
    //
    // Each chart bar must be a BUSINESS-day window (cutoff→cutoff), matching
    // the day-close report — never whereDate(created_at).

    public function test_chart_pre_cutoff_sale_lands_in_previous_days_bar(): void
    {
        $this->actAs('pos_admin');
        $now = $this->freezeAfternoon();

        // Today 02:00 (pre-cutoff) → yesterday's bar, NOT today's.
        $this->completedSale($now->copy()->setTime(2, 0, 0), ['total_amount' => 250]);
        // Today 09:00 (post-cutoff) → today's bar.
        $this->completedSale($now->copy()->setTime(9, 0, 0), ['total_amount' => 1000]);
        // Yesterday 20:00 → yesterday's bar.
        $this->completedSale($now->copy()->subDay()->setTime(20, 0, 0), ['total_amount' => 500]);

        $data = $this->dashboardData();

        $chart = $data['salesChartData'];
        $this->assertCount(7, $chart);
        // Last bar = today's business day, second-last = yesterday's.
        $this->assertSame(1000.0, (float) $chart[6]);
        $this->assertSame(750.0, (float) $chart[5]);
        // Nothing leaked into older bars.
        $this->assertSame(0.0, (float) array_sum(array_slice($chart, 0, 5)));
    }

    public function test_chart_bars_align_with_business_day_totals(): void
    {
        $this->actAs('pos_admin');
        $now = $this->freezeAfternoon();

        // Sales across the 7-bar window edges: 6 business days ago at 10:00
        // (inside first bar) and 7 business days ago at 20:00 (outside).
        $this->completedSale($now->copy()->subDays(6)->setTime(10, 0, 0), ['total_amount' => 300]);
        $this->completedSale($now->copy()->subDays(7)->setTime(20, 0, 0), ['total_amount' => 900]);
        // Non-completed order never appears in the chart.
        $held = $this->order(['status' => 'held', 'total_amount' => 700]);
        DB::table('restaurant_orders')->where('id', $held)
            ->update(['created_at' => $now->copy()->setTime(10, 0, 0)]);

        $data = $this->dashboardData();
        $chart = $data['salesChartData'];

        $this->assertSame(300.0, (float) $chart[0]);
        $this->assertSame(300.0, (float) array_sum($chart));
        // Chart's today bar equals the dashboard's todaySales figure.
        $this->assertSame((float) $data['todaySales'], (float) $chart[6]);
    }

    // ── tile render: links + include contract ───────────────────────────────

    public function test_tile_links_open_tables_and_cancelled_to_their_report_pages(): void
    {
        $this->actAs('pos_admin');
        $this->order(['status' => 'preparing']);
        $this->order(['status' => 'cancelled', 'cancelled_at' => now()]);

        $html = $this->renderTile($this->dashboardData());

        $this->assertNotSame('', trim($html));
        $this->assertStringContainsString(route('pos.restaurant.tables'), $html);
        $this->assertStringContainsString(route('pos.restaurant.cancelled-orders'), $html);
    }

    public function test_restaurant_dashboard_blade_includes_shared_tile(): void
    {
        // Lock the include contract: the restaurant dashboard wrapper must
        // keep including the shared tile (which reads the controller's
        // isRestaurant/openOrdersCount/cancelledTodayCount view data).
        $blade = file_get_contents(resource_path('views/pos/restaurant/dashboard.blade.php'));
        $this->assertStringContainsString("pos.partials.pending-bills-tile", $blade);
    }
}
