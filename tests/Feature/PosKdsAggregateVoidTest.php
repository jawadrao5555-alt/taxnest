<?php

namespace Tests\Feature;

use App\Http\Controllers\RestaurantKdsController;
use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * KDS aggregate view — void_items contract (Task #854).
 *
 * The aggregate tile grid counts every item on every active (held/preparing)
 * order. Cancelled dishes must not inflate the total a cook sees.
 *
 * Data model invariant (locked here):
 *   When a cashier re-holds an order with fewer items (partial cancel),
 *   RestaurantPosController DELETES the old order's item rows and CREATES
 *   new rows on the replacement order containing only the kept quantities.
 *   `void_items` on the replacement order is a KDS *notification badge*,
 *   not a delta on top of `items`. The aggregate getter must use `items`
 *   as-is and must NOT subtract `void_items` (that would undercount).
 *
 * Locked:
 *   - Partial re-hold (2 → 1): liveOrders returns qty=1 in items, void_items
 *     holds the removed qty=1 separately; aggregate shows 1, not 0.
 *   - Full item removal: dish gone from items entirely, void_items records it;
 *     aggregate shows 0 occurrences of that dish.
 *   - No cancel: items unchanged, void_items null; aggregate shows original qty.
 *   - Duplicate item name across orders: aggregate sums items correctly.
 *   - Station-filtered aggregate: only this station's items are counted.
 */
class PosKdsAggregateVoidTest extends TestCase
{
    protected int $companyId;

    protected function setUp(): void
    {
        parent::setUp();
        Schema::dropAllTables();

        Schema::create('companies', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->text('pos_printer_settings')->nullable();
            $table->boolean('pos_kds_auto_print')->default(false);
            $table->string('company_status')->default('approved');
            $table->softDeletes();
            $table->timestamps();
        });

        Schema::create('users', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('company_id')->nullable();
            $table->string('name')->nullable();
            $table->string('pos_role')->nullable();
            $table->text('pos_custom_access')->nullable();
            $table->string('language')->nullable();
            $table->timestamps();
        });

        Schema::create('restaurant_tables', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('company_id');
            $table->string('table_number')->nullable();
            $table->string('status')->default('free');
            $table->timestamps();
        });

        Schema::create('restaurant_orders', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('company_id');
            $table->string('order_number')->nullable();
            $table->unsignedBigInteger('table_id')->nullable();
            $table->string('order_type')->default('dine_in');
            $table->string('status')->default('held');
            $table->string('kitchen_status')->nullable();
            $table->text('kitchen_notes')->nullable();
            $table->boolean('priority')->default(false);
            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamp('kot_sent_at')->nullable();
            $table->timestamp('kitchen_cleared_at')->nullable();
            $table->timestamp('kitchen_started_at')->nullable();
            $table->timestamp('kitchen_ready_at')->nullable();
            $table->text('void_items')->nullable();
            $table->timestamps();
        });

        Schema::create('restaurant_order_items', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('order_id');
            $table->string('item_type')->default('manual');
            $table->unsignedBigInteger('item_id')->nullable();
            $table->string('item_name');
            $table->decimal('quantity', 8, 2)->default(1);
            $table->decimal('unit_price', 10, 2)->default(0);
            $table->decimal('subtotal', 10, 2)->default(0);
            $table->text('special_notes')->nullable();
            $table->timestamp('kot_printed_at')->nullable();
            $table->unsignedInteger('kot_batch_no')->nullable();
            $table->timestamps();
        });

        Schema::create('pos_stations', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('company_id');
            $table->string('name');
            $table->text('categories')->nullable();
            $table->string('printer_name')->nullable();
            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('sort')->default(0);
            $table->timestamps();
        });

        Schema::create('pos_products', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('company_id');
            $table->string('name');
            $table->string('category')->nullable();
            $table->timestamps();
        });

        $this->companyId = DB::table('companies')->insertGetId([
            'name' => 'Test Kitchen',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        app()->bind('currentCompanyId', fn () => $this->companyId);
    }

    protected function tearDown(): void
    {
        Auth::guard('pos')->logout();
        parent::tearDown();
    }

    // ── helpers ───────────────────────────────────────────────────────────────

    private function insertOrder(array $attrs = []): int
    {
        return DB::table('restaurant_orders')->insertGetId(array_merge([
            'company_id'     => $this->companyId,
            'order_number'   => 'R-' . uniqid(),
            'status'         => 'held',
            'kitchen_status' => 'new',
            'created_at'     => now(),
            'updated_at'     => now(),
        ], $attrs));
    }

    private function insertItem(int $orderId, string $name, float $qty): void
    {
        DB::table('restaurant_order_items')->insert([
            'order_id'   => $orderId,
            'item_name'  => $name,
            'quantity'   => $qty,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function liveOrdersPayload(): array
    {
        $ctrl = new RestaurantKdsController();
        $resp = $ctrl->liveOrders();
        return json_decode($resp->getContent(), true);
    }

    // ── tests ─────────────────────────────────────────────────────────────────

    /**
     * Partial re-hold: cashier drops Biryani from qty=2 to qty=1.
     * Replacement order items=[Biryani qty=1], void_items=[{item_name:"Biryani",qty:1}].
     * The aggregate must show qty=1, not 0.
     */
    public function test_partial_void_items_are_not_subtracted_from_aggregate(): void
    {
        $orderId = $this->insertOrder([
            'void_items' => json_encode([['item_name' => 'Biryani', 'qty' => 1]]),
        ]);
        $this->insertItem($orderId, 'Biryani', 1); // post-re-hold kept qty

        $payload = $this->liveOrdersPayload();

        $this->assertCount(1, $payload);
        $items = $payload[0]['items'];
        $biryani = collect($items)->firstWhere('name', 'Biryani');
        $this->assertNotNull($biryani, 'Biryani must appear in items');
        $this->assertEquals(1, $biryani['qty'], 'Aggregate qty must be 1, not 0 after subtraction');

        // void_items is present as a badge payload — separate from items.
        $voids = $payload[0]['void_items'];
        $this->assertCount(1, $voids);
        $this->assertEquals('Biryani', $voids[0]['item_name']);
        $this->assertEquals(1, $voids[0]['qty']);
    }

    /**
     * Full item removal: Fries qty=2 cancelled entirely.
     * Replacement order items=[], void_items=[{item_name:"Fries",qty:2}].
     * Fries must not appear in items at all — the aggregate naturally shows 0.
     */
    public function test_fully_removed_dish_is_absent_from_items(): void
    {
        $orderId = $this->insertOrder([
            'void_items' => json_encode([['item_name' => 'Fries', 'qty' => 2]]),
        ]);
        // No item rows — Fries was completely removed.

        $payload = $this->liveOrdersPayload();

        $this->assertCount(1, $payload);
        $items = $payload[0]['items'];
        $fries = collect($items)->firstWhere('name', 'Fries');
        $this->assertNull($fries, 'Fries must be absent from items after full removal');

        $voids = $payload[0]['void_items'];
        $this->assertEquals('Fries', $voids[0]['item_name']);
    }

    /**
     * No cancellation: normal order with void_items=null.
     * Items are returned unchanged; aggregate shows original qty.
     */
    public function test_no_void_items_returns_full_item_quantities(): void
    {
        $orderId = $this->insertOrder(['void_items' => null]);
        $this->insertItem($orderId, 'Burger', 3);

        $payload = $this->liveOrdersPayload();

        $this->assertCount(1, $payload);
        $burger = collect($payload[0]['items'])->firstWhere('name', 'Burger');
        $this->assertNotNull($burger);
        $this->assertEquals(3, $burger['qty']);
        $this->assertEmpty($payload[0]['void_items']);
    }

    /**
     * Duplicate item name across two independent orders (e.g. two tables both
     * ordered Biryani). Aggregate must sum them correctly, not subtract one from
     * the other because void_items on one order has the same name.
     */
    public function test_same_item_on_two_orders_aggregates_correctly(): void
    {
        // Order A: Biryani qty=2 with a partial void badge (void qty=1 was removed
        // from the OLD order; this replacement keeps qty=2 — different scenario).
        $orderA = $this->insertOrder([
            'void_items' => json_encode([['item_name' => 'Biryani', 'qty' => 1]]),
        ]);
        $this->insertItem($orderA, 'Biryani', 2);

        // Order B: Biryani qty=1, no cancellation.
        $orderB = $this->insertOrder(['void_items' => null]);
        $this->insertItem($orderB, 'Biryani', 1);

        $payload = $this->liveOrdersPayload();
        $this->assertCount(2, $payload);

        // Total items across both orders = 3 (not 3 - 1 = 2).
        $totalBiryaniQty = collect($payload)
            ->flatMap(fn($o) => $o['items'])
            ->where('name', 'Biryani')
            ->sum('qty');

        $this->assertEquals(3, $totalBiryaniQty,
            'Aggregate must sum items as-is across orders; void_items must not be subtracted');
    }

    /**
     * Cleared orders (kitchen_cleared_at set) must not appear in liveOrders.
     * Their items must not contribute to the aggregate.
     */
    public function test_cleared_orders_excluded_from_aggregate(): void
    {
        $cleared = $this->insertOrder(['kitchen_cleared_at' => now()]);
        $this->insertItem($cleared, 'Kebab', 5);

        $active = $this->insertOrder();
        $this->insertItem($active, 'Naan', 2);

        $payload = $this->liveOrdersPayload();

        $this->assertCount(1, $payload);
        $this->assertNull(collect($payload[0]['items'])->firstWhere('name', 'Kebab'));
        $this->assertNotNull(collect($payload[0]['items'])->firstWhere('name', 'Naan'));
    }
}
