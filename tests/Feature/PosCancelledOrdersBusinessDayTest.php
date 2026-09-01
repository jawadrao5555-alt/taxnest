<?php

namespace Tests\Feature;

use App\Http\Controllers\RestaurantPosController;
use App\Models\RestaurantOrder;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Cancelled Orders report — business-day attribution (owner, 1 Sep 2026).
 *
 * A shop rang up orders on 31 Aug, then voided three of them while doing the
 * next morning's day-close. The report dated them 1 Sep, because it filtered
 * and sorted on COALESCE(cancelled_at, updated_at) — the moment cancel was
 * pressed. That put the cancellations (and their Rs total) on a different day
 * from the sale they belong to, so the report contradicted the day-close it
 * exists to explain.
 *
 * The rule this test locks: a cancelled order belongs to the business day it
 * was PUNCHED on. restaurant_orders has no business_date column, so the window
 * is derived from created_at through PosBusinessDay::windowFor().
 *
 * The private query builder is invoked directly by reflection: the date rule is
 * what matters here, not the plan/role gate that cancelledOrders() applies.
 */
class PosCancelledOrdersBusinessDayTest extends TestCase
{
    protected int $companyId;

    protected function setUp(): void
    {
        parent::setUp();

        Schema::dropAllTables();

        Schema::create('companies', function (Blueprint $table) {
            $table->id();
            $table->string('name')->nullable();
            // NULL → PosBusinessDay falls back to the 06:00 default cutoff.
            $table->string('pos_business_day_cutoff')->nullable();
            $table->softDeletes();
            $table->timestamps();
        });

        Schema::create('users', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('company_id')->nullable();
            $table->string('name')->nullable();
            $table->timestamps();
        });

        Schema::create('restaurant_tables', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('company_id')->nullable();
            $table->string('table_number')->nullable();
            $table->string('status')->nullable();
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
            $table->unsignedBigInteger('created_by')->nullable();
            $table->unsignedBigInteger('cancelled_by')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            // genuineCancelled() is hasColumn-guarded on this one.
            $table->timestamp('superseded_at')->nullable();
            $table->timestamp('kot_sent_at')->nullable();
            $table->timestamps();
        });

        Schema::create('restaurant_order_items', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('order_id');
            $table->string('item_name')->nullable();
            $table->integer('quantity')->default(1);
            $table->decimal('unit_price', 12, 2)->default(0);
            $table->decimal('subtotal', 12, 2)->default(0);
            $table->boolean('was_made')->default(false);
            $table->timestamps();
        });

        // PosBusinessDay consults day-close reports for a pre-cutoff moment; if
        // the table is missing it swallows the error and falls back to the
        // calendar date, which would quietly hide the very bug under test.
        Schema::create('pos_day_close_reports', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('company_id');
            $table->date('report_date');
            $table->timestamps();
        });

        $this->companyId = DB::table('companies')->insertGetId([
            'name' => 'Test Restaurant', 'created_at' => now(), 'updated_at' => now(),
        ]);
        app()->instance('currentCompanyId', $this->companyId);

        // The cutoff cache is static (per-request in production, but per-PROCESS
        // in the suite) and sqlite reuses company id 1 for every test, so a
        // custom cutoff set by one test would leak into the next.
        \App\Services\PosBusinessDay::forgetCutoff($this->companyId);
    }

    protected function tearDown(): void
    {
        \Carbon\Carbon::setTestNow();
        parent::tearDown();
    }

    /**
     * Freeze the clock. Business-day assertions are only deterministic with a
     * fixed post-cutoff "now" — run live between 00:00 and 05:59 and today's
     * rows land on the PREVIOUS business day, turning this green by day and
     * red at night.
     */
    protected function freezeAt(string $datetime): \Carbon\Carbon
    {
        $now = \Carbon\Carbon::parse($datetime, config('app.timezone'));
        \Carbon\Carbon::setTestNow($now);

        return $now;
    }

    protected function order(string $punchedAt, ?string $cancelledAt, float $amount = 500): int
    {
        $id = DB::table('restaurant_orders')->insertGetId([
            'company_id' => $this->companyId,
            'order_number' => 'R-' . uniqid(),
            'status' => 'cancelled',
            'total_amount' => $amount,
            'cancelled_at' => $cancelledAt,
            'created_at' => $punchedAt,
            // updated_at deliberately tracks the cancel moment: that is what the
            // old query fell back to when cancelled_at was NULL.
            'updated_at' => $cancelledAt ?? $punchedAt,
        ]);
        DB::table('restaurant_order_items')->insert([
            'order_id' => $id, 'item_name' => 'Biryani', 'quantity' => 1,
            'unit_price' => $amount, 'subtotal' => $amount,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        return $id;
    }

    /** Run the report's shared query for an explicit business-date range. */
    protected function reportIds(string $from, string $to): array
    {
        $controller = app(RestaurantPosController::class);
        $method = new \ReflectionMethod($controller, 'cancelledOrdersQuery');
        $method->setAccessible(true);
        $request = Request::create('/pos/restaurant/cancelled-orders', 'GET', ['from' => $from, 'to' => $to]);

        return $method->invoke($controller, $request)->pluck('id')->all();
    }

    public function test_order_punched_yesterday_and_cancelled_this_morning_belongs_to_yesterday(): void
    {
        $this->freezeAt('2026-09-01 14:00:00');

        // The owner's case: rung up on the 31st, voided during the 1st's
        // morning day-close (08:30, i.e. AFTER the 06:00 cutoff).
        $yesterdayOrder = $this->order('2026-08-31 20:00:00', '2026-09-01 08:30:00', 1360);
        // A genuinely-1-Sep order, for contrast.
        $todayOrder = $this->order('2026-09-01 09:00:00', '2026-09-01 10:00:00', 400);

        $this->assertSame([$yesterdayOrder], $this->reportIds('2026-08-31', '2026-08-31'),
            'An order punched on 31 Aug must report under 31 Aug even though cancel was pressed on 1 Sep.');
        $this->assertSame([$todayOrder], $this->reportIds('2026-09-01', '2026-09-01'),
            'The 1 Sep view must not inherit the previous day\'s cancellations.');
    }

    public function test_post_midnight_order_belongs_to_the_previous_trading_day(): void
    {
        $this->freezeAt('2026-09-01 14:00:00');

        // Punched at 01:30 on 1 Sep — before the 06:00 cutoff, so this is still
        // the 31 Aug trading day, exactly like a late-night sale.
        $lateNight = $this->order('2026-09-01 01:30:00', '2026-09-01 02:00:00');

        $this->assertSame([$lateNight], $this->reportIds('2026-08-31', '2026-08-31'));
        $this->assertSame([], $this->reportIds('2026-09-01', '2026-09-01'));
    }

    public function test_window_is_half_open_so_the_cutoff_starts_the_next_day(): void
    {
        $this->freezeAt('2026-09-01 14:00:00');

        // 05:59:59 still belongs to 31 Aug; 06:00:00 opens 1 Sep.
        $before = $this->order('2026-09-01 05:59:59', '2026-09-01 07:00:00');
        $after = $this->order('2026-09-01 06:00:00', '2026-09-01 07:00:00');

        $this->assertSame([$before], $this->reportIds('2026-08-31', '2026-08-31'));
        $this->assertSame([$after], $this->reportIds('2026-09-01', '2026-09-01'));
    }

    public function test_custom_company_cutoff_is_respected(): void
    {
        DB::table('companies')->where('id', $this->companyId)
            ->update(['pos_business_day_cutoff' => '04:00']);
        \App\Services\PosBusinessDay::forgetCutoff($this->companyId);
        $this->freezeAt('2026-09-01 14:00:00');

        // With a 04:00 cutoff a 05:00 order is already the NEW day's business.
        $fiveAm = $this->order('2026-09-01 05:00:00', '2026-09-01 09:00:00');

        $this->assertSame([], $this->reportIds('2026-08-31', '2026-08-31'));
        $this->assertSame([$fiveAm], $this->reportIds('2026-09-01', '2026-09-01'));
    }

    public function test_multi_day_range_spans_whole_trading_days(): void
    {
        $this->freezeAt('2026-09-01 14:00:00');

        $aug30 = $this->order('2026-08-30 21:00:00', '2026-08-31 07:00:00');
        $aug31 = $this->order('2026-08-31 20:00:00', '2026-09-01 08:30:00');
        $sep1 = $this->order('2026-09-01 09:00:00', '2026-09-01 10:00:00');

        $ids = $this->reportIds('2026-08-30', '2026-09-01');
        sort($ids);
        $this->assertSame([$aug30, $aug31, $sep1], $ids);
    }

    public function test_report_defaults_to_the_current_business_day_not_the_calendar_date(): void
    {
        // 02:00 — the shop is still trading its previous day. Defaulting to the
        // calendar date would open the page on an empty "tomorrow".
        $this->freezeAt('2026-09-01 02:00:00');

        $stillYesterdays = $this->order('2026-08-31 23:00:00', '2026-09-01 01:00:00');

        $controller = app(RestaurantPosController::class);
        $method = new \ReflectionMethod($controller, 'cancelledOrdersQuery');
        $method->setAccessible(true);
        $from = null;
        $to = null;
        $ids = $method->invokeArgs(
            $controller,
            [Request::create('/pos/restaurant/cancelled-orders'), &$from, &$to]
        )->pluck('id')->all();

        $this->assertSame('2026-08-31', $from, 'Default range must be the open trading day.');
        $this->assertSame('2026-08-31', $to);
        $this->assertSame([$stillYesterdays], $ids);
    }

    public function test_supersede_ghosts_and_live_orders_are_still_excluded(): void
    {
        $this->freezeAt('2026-09-01 14:00:00');

        $real = $this->order('2026-09-01 09:00:00', '2026-09-01 10:00:00');

        $ghost = $this->order('2026-09-01 09:10:00', '2026-09-01 10:10:00');
        DB::table('restaurant_orders')->where('id', $ghost)
            ->update(['superseded_at' => '2026-09-01 10:10:00']);

        $open = $this->order('2026-09-01 09:20:00', null);
        DB::table('restaurant_orders')->where('id', $open)->update(['status' => 'held']);

        $this->assertSame([$real], $this->reportIds('2026-09-01', '2026-09-01'));
    }

    public function test_rows_are_ordered_by_the_order_time(): void
    {
        $this->freezeAt('2026-09-01 14:00:00');

        // Punched first but cancelled LAST — ordering must follow the order time.
        $early = $this->order('2026-09-01 09:00:00', '2026-09-01 13:00:00');
        $late = $this->order('2026-09-01 11:00:00', '2026-09-01 11:30:00');

        $this->assertSame([$late, $early], $this->reportIds('2026-09-01', '2026-09-01'));
    }

    public function test_window_helper_is_half_open_and_cutoff_aware(): void
    {
        [$start, $end] = \App\Services\PosBusinessDay::windowFor($this->companyId, '2026-08-31', '2026-08-31');

        $this->assertSame('2026-08-31 06:00:00', $start->format('Y-m-d H:i:s'));
        $this->assertSame('2026-09-01 06:00:00', $end->format('Y-m-d H:i:s'));
    }
}
