<?php

namespace Tests\Feature;

use Tests\TestCase;
use App\Http\Controllers\PosController;
use App\Models\Company;
use App\Models\RestaurantOrder;
use App\Models\RestaurantOrderItem;
use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;

/**
 * DAY-CLOSE OPEN-ORDERS WARNING (Task: ZFC PIZZA POINT, 3 Aug 2026).
 *
 * ZFC had 5 dine-in tables sitting occupied for 2 days (held orders,
 * Rs 6,260) and nobody noticed — day-close neither touches nor mentions
 * them. openHeldOrdersSummary() feeds the warning on the day-close page
 * and the at-close stamp on the Z-report. Locked guarantees:
 *
 *   1. Held/preparing/ready orders WITH items are counted; table numbers,
 *      distinct table count, total amount and no-table count are reported.
 *   2. Completed/cancelled orders and item-less shells are ignored.
 *   3. Non-restaurant companies get a zeroed summary (warning never renders).
 *   4. Since 10 Aug 2026 (owner rule) the summary also drives the MANUAL
 *      day-close HARD BLOCK in closeDayReport — the HTTP-level blocking test
 *      lives in PosDayCloseAutoFinalizeTest. The 6 AM auto close stays
 *      unblocked (open_orders_at_close stamp instead).
 *
 * Pattern: sqlite :memory: + minimal Schema::create; private method via
 * reflection (same approach as PosDayCloseAutoFinalizeTest).
 */
class PosDayCloseOpenOrdersWarningTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Schema::dropAllTables();

        // PosFeatureService caches restaurantAllowed per company id in a
        // STATIC — earlier suites' verdicts for company 1/2 leak into this
        // one (test passes alone, fails in the full run). Reset it.
        $prop = new \ReflectionProperty(\App\Services\PosFeatureService::class, 'restaurantAllowedCache');
        $prop->setAccessible(true);
        $prop->setValue(null, []);

        Schema::create('companies', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('product_type')->nullable();
            $table->boolean('is_internal_account')->default(false);
            $table->boolean('restaurant_mode')->default(false);
            $table->softDeletes();
            $table->timestamps();
        });

        Schema::create('restaurant_tables', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('company_id');
            $table->string('table_number');
            $table->string('status')->default('available');
            $table->timestamps();
        });

        Schema::create('restaurant_orders', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('company_id');
            $table->string('order_number')->nullable();
            $table->unsignedBigInteger('table_id')->nullable();
            $table->string('order_type')->nullable();
            $table->string('status')->default('held');
            $table->decimal('subtotal', 12, 2)->default(0);
            $table->decimal('total_amount', 12, 2)->default(0);
            $table->timestamps();
        });

        Schema::create('restaurant_order_items', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('order_id');
            $table->string('item_name')->nullable();
            $table->decimal('quantity', 8, 2)->default(1);
            $table->decimal('subtotal', 12, 2)->default(0);
            $table->timestamps();
        });
    }

    private function summarize(Company $company): object
    {
        $method = new \ReflectionMethod(PosController::class, 'openHeldOrdersSummary');
        $method->setAccessible(true);
        return $method->invoke(app(PosController::class), $company->id, $company);
    }

    private function makeCompany(bool $restaurant = true): Company
    {
        // is_internal_account=true → PosFeatureService::restaurantAllowed()
        // short-circuits to yes without a subscription fixture.
        return Company::create([
            'name' => 'ZFC Test',
            'product_type' => 'pos',
            'is_internal_account' => true,
            'restaurant_mode' => $restaurant,
        ]);
    }

    private function order(Company $c, ?int $tableId, string $status, float $amount, bool $withItem = true): RestaurantOrder
    {
        $o = RestaurantOrder::create([
            'company_id' => $c->id,
            'table_id' => $tableId,
            'status' => $status,
            'total_amount' => $amount,
        ]);
        if ($withItem) {
            RestaurantOrderItem::create(['order_id' => $o->id, 'item_name' => 'Pizza', 'quantity' => 1, 'subtotal' => $amount]);
        }
        return $o;
    }

    public function test_open_orders_with_tables_amounts_and_no_table_count(): void
    {
        $c = $this->makeCompany();
        $t3 = \DB::table('restaurant_tables')->insertGetId(['company_id' => $c->id, 'table_number' => '3', 'created_at' => now(), 'updated_at' => now()]);
        $t5 = \DB::table('restaurant_tables')->insertGetId(['company_id' => $c->id, 'table_number' => '5', 'created_at' => now(), 'updated_at' => now()]);

        $this->order($c, $t5, 'held', 1500);
        $this->order($c, $t3, 'preparing', 2000);
        $this->order($c, $t3, 'ready', 760);        // same table — distinct count stays 2
        $this->order($c, null, 'held', 2000);       // takeaway/delivery held, no table

        $s = $this->summarize($c);
        $this->assertSame(4, $s->count);
        $this->assertSame(2, $s->tables);
        $this->assertSame('3, 5', $s->tableNumbers);
        $this->assertEqualsWithDelta(6260.0, $s->amount, 0.01);
        $this->assertSame(1, $s->noTableCount);
    }

    public function test_completed_cancelled_and_itemless_orders_ignored(): void
    {
        $c = $this->makeCompany();
        $t1 = \DB::table('restaurant_tables')->insertGetId(['company_id' => $c->id, 'table_number' => '1', 'created_at' => now(), 'updated_at' => now()]);

        $this->order($c, $t1, 'completed', 900);
        $this->order($c, $t1, 'cancelled', 400);
        $this->order($c, $t1, 'held', 300, withItem: false); // empty shell — no money, no KOT

        $s = $this->summarize($c);
        $this->assertSame(0, $s->count);
        $this->assertSame(0, $s->tables);
        $this->assertSame('', $s->tableNumbers);
    }

    public function test_non_restaurant_company_gets_zeroed_summary(): void
    {
        $c = $this->makeCompany(restaurant: false);
        $this->order($c, null, 'held', 999);

        $s = $this->summarize($c);
        $this->assertSame(0, $s->count);
        $this->assertEqualsWithDelta(0.0, $s->amount, 0.01);
    }

    public function test_other_companies_orders_never_leak(): void
    {
        $mine = $this->makeCompany();
        $other = $this->makeCompany();
        $this->order($other, null, 'held', 5000);

        $s = $this->summarize($mine);
        $this->assertSame(0, $s->count);
    }
}
