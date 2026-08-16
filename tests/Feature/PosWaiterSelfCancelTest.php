<?php

namespace Tests\Feature;

use Tests\TestCase;
use App\Http\Controllers\RestaurantWaiterController;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * WAITER SELF-CANCEL — Task 412.
 *
 * A waiter may soft-cancel his OWN still-held waiter order from the tablet
 * before a cashier settles it. Locks:
 *   1. Happy path: own held waiter order → status='cancelled' +
 *      cancelled_at/by = the waiter, table freed.
 *   2. Table NOT freed when a sibling active order still sits on it.
 *   3. Someone else's order → 409, untouched.
 *   4. Already completed (cashier settled) → 409, stays completed.
 *   5. Already cancelled → 409 (no double-cancel side effects).
 *   6. myOrders payload exposes kot_sent_at (the KOT-warning gate in the modal).
 *
 * Pattern: sqlite :memory: + minimal Schema::create, controller invoked
 * directly (same approach as PosWaiterMultiOrderPickerTest).
 */
class PosWaiterSelfCancelTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Schema::dropAllTables();

        Schema::create('companies', function (Blueprint $table) {
            $table->id();
            $table->string('name')->nullable();
            // Task 527: waiter self-cancel is admin-gated (default OFF).
            $table->boolean('pos_waiter_cancel_enabled')->default(false);
            // Task 850: needed by KotPrintService::enqueueVoid.
            $table->json('pos_printer_settings')->nullable();
            $table->boolean('agent_enabled')->default(false);
            $table->timestamp('agent_last_seen')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('users', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('company_id')->nullable();
            $table->string('name')->nullable();
            $table->string('pos_role')->nullable();
            $table->timestamps();
        });

        Schema::create('restaurant_floors', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('company_id');
            $table->string('name');
            $table->timestamps();
        });

        Schema::create('restaurant_tables', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('company_id');
            $table->unsignedBigInteger('floor_id');
            $table->string('table_number');
            $table->integer('seats')->default(4);
            $table->string('status')->default('available');
            $table->unsignedBigInteger('locked_by_user_id')->nullable();
            $table->timestamp('locked_at')->nullable();
            $table->timestamp('occupied_since')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('restaurant_orders', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('company_id');
            $table->string('order_number');
            $table->unsignedBigInteger('table_id')->nullable();
            $table->string('order_type')->default('dine_in');
            $table->string('status')->default('held');
            $table->decimal('subtotal', 12, 2)->default(0);
            $table->decimal('total_amount', 12, 2)->default(0);
            $table->unsignedBigInteger('created_by')->nullable();
            $table->unsignedBigInteger('assigned_cashier_id')->nullable();
            $table->string('source')->default('waiter');
            $table->timestamp('kot_sent_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->unsignedBigInteger('cancelled_by')->nullable();
            $table->timestamps();
        });

        Schema::create('restaurant_order_items', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('order_id');
            $table->string('item_type')->default('manual');
            $table->unsignedBigInteger('item_id')->nullable();
            $table->string('item_name');
            $table->decimal('quantity', 8, 2)->default(1);
            $table->decimal('unit_price', 12, 2)->default(0);
            $table->decimal('subtotal', 12, 2)->default(0);
            $table->string('special_notes')->nullable();
            $table->timestamp('kot_printed_at')->nullable();
            $table->timestamps();
        });

        // Task 850: needed by KotPrintService::enqueueVoid (void slip path).
        Schema::create('pos_stations', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('company_id');
            $table->string('name');
            $table->string('printer_name')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('pos_print_jobs', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('company_id');
            $table->string('type')->default('kot');
            $table->string('target_printer')->nullable();
            $table->unsignedBigInteger('restaurant_order_id')->nullable();
            $table->string('render_query')->nullable();
            $table->json('printed_item_ids')->nullable();
            $table->string('status')->default('pending');
            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamps();
        });
    }

    private function seedData(): array
    {
        // Task 527: the cancel permission is DEFAULT OFF — these locks test
        // the underlying cancel mechanics, so the seed company enables it.
        $companyId = DB::table('companies')->insertGetId([
            'name' => 'Cancel Test Co', 'pos_waiter_cancel_enabled' => true,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $floorId = DB::table('restaurant_floors')->insertGetId([
            'company_id' => $companyId, 'name' => 'Main', 'created_at' => now(), 'updated_at' => now(),
        ]);
        $tableId = DB::table('restaurant_tables')->insertGetId([
            'company_id' => $companyId, 'floor_id' => $floorId,
            'table_number' => 'T-1', 'status' => 'occupied',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $waiterId = DB::table('users')->insertGetId([
            'company_id' => $companyId, 'name' => 'Waiter A', 'pos_role' => 'pos_waiter',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        app()->bind('currentCompanyId', fn () => $companyId);
        Auth::guard('pos')->setUser(\App\Models\User::find($waiterId));

        return [$companyId, $tableId, $waiterId];
    }

    private function makeOrder(int $companyId, ?int $tableId, ?int $createdBy, string $status = 'held', ?string $kotSentAt = null): int
    {
        return DB::table('restaurant_orders')->insertGetId([
            'company_id' => $companyId, 'order_number' => 'W-' . rand(1000, 9999),
            'table_id' => $tableId, 'status' => $status, 'created_by' => $createdBy,
            'source' => 'waiter', 'subtotal' => 100, 'total_amount' => 100,
            'kot_sent_at' => $kotSentAt,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function cancel(int $orderId)
    {
        return app(RestaurantWaiterController::class)->cancelOrder(Request::create('/', 'POST'), $orderId);
    }

    public function test_waiter_cancels_own_held_order_and_table_frees(): void
    {
        [$companyId, $tableId, $waiterId] = $this->seedData();
        $orderId = $this->makeOrder($companyId, $tableId, $waiterId);

        $res = $this->cancel($orderId);
        $this->assertSame(200, $res->getStatusCode());

        $row = DB::table('restaurant_orders')->find($orderId);
        $this->assertSame('cancelled', $row->status);
        $this->assertNotNull($row->cancelled_at);
        $this->assertEquals($waiterId, $row->cancelled_by);

        $table = DB::table('restaurant_tables')->find($tableId);
        $this->assertSame('available', $table->status);
    }

    public function test_table_stays_occupied_when_sibling_active_order_remains(): void
    {
        [$companyId, $tableId, $waiterId] = $this->seedData();
        $orderId = $this->makeOrder($companyId, $tableId, $waiterId);
        $this->makeOrder($companyId, $tableId, $waiterId, 'preparing');

        $this->assertSame(200, $this->cancel($orderId)->getStatusCode());
        $this->assertSame('occupied', DB::table('restaurant_tables')->find($tableId)->status);
    }

    public function test_cannot_cancel_someone_elses_order(): void
    {
        [$companyId, $tableId, $waiterId] = $this->seedData();
        $otherId = DB::table('users')->insertGetId([
            'company_id' => $companyId, 'name' => 'Waiter B', 'pos_role' => 'pos_waiter',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $orderId = $this->makeOrder($companyId, $tableId, $otherId);

        $this->assertSame(409, $this->cancel($orderId)->getStatusCode());
        $this->assertSame('held', DB::table('restaurant_orders')->find($orderId)->status);
    }

    public function test_cannot_cancel_claimed_order(): void
    {
        // Claimed-but-still-held: a cashier took the order (assigned_cashier_id
        // set, e.g. via claimIncoming or send-to-cashier). Waiter cancel must 409
        // and the order stays held for the cashier's checkout.
        [$companyId, $tableId, $waiterId] = $this->seedData();
        $cashierId = DB::table('users')->insertGetId([
            'company_id' => $companyId, 'name' => 'Cashier', 'pos_role' => 'pos_cashier',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $orderId = $this->makeOrder($companyId, $tableId, $waiterId);
        DB::table('restaurant_orders')->where('id', $orderId)->update(['assigned_cashier_id' => $cashierId]);

        $this->assertSame(409, $this->cancel($orderId)->getStatusCode());
        $row = DB::table('restaurant_orders')->find($orderId);
        $this->assertSame('held', $row->status);
        $this->assertNull($row->cancelled_at);
    }

    public function test_cannot_cancel_settled_order(): void
    {
        [$companyId, $tableId, $waiterId] = $this->seedData();
        $orderId = $this->makeOrder($companyId, $tableId, $waiterId, 'completed');

        $this->assertSame(409, $this->cancel($orderId)->getStatusCode());
        $this->assertSame('completed', DB::table('restaurant_orders')->find($orderId)->status);
    }

    public function test_double_cancel_returns_409(): void
    {
        [$companyId, $tableId, $waiterId] = $this->seedData();
        $orderId = $this->makeOrder($companyId, $tableId, $waiterId);

        $this->assertSame(200, $this->cancel($orderId)->getStatusCode());
        $this->assertSame(409, $this->cancel($orderId)->getStatusCode());
    }

    public function test_my_orders_payload_exposes_kot_sent_at(): void
    {
        [$companyId, $tableId, $waiterId] = $this->seedData();
        $this->makeOrder($companyId, $tableId, $waiterId, 'held', now()->toDateTimeString());

        $orders = json_decode(app(RestaurantWaiterController::class)->myOrders()->getContent(), true);
        $this->assertCount(1, $orders);
        $this->assertArrayHasKey('kot_sent_at', $orders[0]);
        $this->assertNotNull($orders[0]['kot_sent_at']);
    }

    /**
     * Task 850: waiter cancels an order whose KOT already printed → a kot_void
     * print job must be created so the kitchen knows to stop cooking.
     */
    public function test_printed_items_trigger_void_job_on_waiter_cancel(): void
    {
        [$companyId, $tableId, $waiterId] = $this->seedData();

        // Enable silent printing with an agent that is "online" (last seen now).
        DB::table('companies')->where('id', $companyId)->update([
            'agent_enabled'        => true,
            'agent_last_seen'      => now()->toDateTimeString(),
            'pos_printer_settings' => json_encode([
                'silent_print_enabled' => true,
                'kot_printer'          => 'KitchenPrinter',
            ]),
        ]);

        $orderId = $this->makeOrder($companyId, $tableId, $waiterId, 'held', now()->toDateTimeString());

        // Mark one item as already printed (KOT reached the kitchen).
        DB::table('restaurant_order_items')->insert([
            'order_id'       => $orderId,
            'item_type'      => 'manual',
            'item_id'        => null,
            'item_name'      => 'Biryani',
            'quantity'       => 2,
            'unit_price'     => 350,
            'subtotal'       => 700,
            'kot_printed_at' => now()->toDateTimeString(), // already sent to kitchen
            'created_at'     => now(),
            'updated_at'     => now(),
        ]);

        $res = $this->cancel($orderId);

        $this->assertSame(200, $res->getStatusCode());
        $data = json_decode($res->getContent(), true);
        $this->assertTrue($data['success']);

        // A kot_void job must exist for this order.
        $job = DB::table('pos_print_jobs')
            ->where('company_id', $companyId)
            ->where('type', 'kot_void')
            ->where('restaurant_order_id', $orderId)
            ->first();
        $this->assertNotNull($job, 'Expected a kot_void print job after waiter cancel of a printed order');
        $this->assertSame('pending', $job->status);

        // The response should confirm the void was queued.
        $this->assertTrue($data['kot_void_queued']);
    }

    /**
     * Task 850: waiter cancels an order whose KOT never printed (fresh hold) →
     * no void job is created (kitchen never got the order, nothing to void).
     */
    public function test_unprinted_items_skip_void_on_waiter_cancel(): void
    {
        [$companyId, $tableId, $waiterId] = $this->seedData();

        DB::table('companies')->where('id', $companyId)->update([
            'agent_enabled'        => true,
            'agent_last_seen'      => now()->toDateTimeString(),
            'pos_printer_settings' => json_encode([
                'silent_print_enabled' => true,
                'kot_printer'          => 'KitchenPrinter',
            ]),
        ]);

        $orderId = $this->makeOrder($companyId, $tableId, $waiterId, 'held', now()->toDateTimeString());

        // Item with kot_printed_at = NULL (KOT never fired).
        DB::table('restaurant_order_items')->insert([
            'order_id'       => $orderId,
            'item_type'      => 'manual',
            'item_id'        => null,
            'item_name'      => 'Chai',
            'quantity'       => 1,
            'unit_price'     => 50,
            'subtotal'       => 50,
            'kot_printed_at' => null,
            'created_at'     => now(),
            'updated_at'     => now(),
        ]);

        $res = $this->cancel($orderId);

        $this->assertSame(200, $res->getStatusCode());

        // No void job — kitchen never received this order.
        $count = DB::table('pos_print_jobs')
            ->where('company_id', $companyId)
            ->where('type', 'kot_void')
            ->where('restaurant_order_id', $orderId)
            ->count();
        $this->assertSame(0, $count, 'No void job expected when KOT was never printed');
    }
}
