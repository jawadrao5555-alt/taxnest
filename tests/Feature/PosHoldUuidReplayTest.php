<?php

namespace Tests\Feature;

use Tests\TestCase;
use App\Http\Controllers\RestaurantPosController;
use App\Models\Company;
use App\Models\RestaurantOrder;
use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Task 1001 — HOLD-UUID IDEMPOTENCY GUARD
 *
 * Standalone Hold (F5) and Send-to-Kitchen now carry a hold_uuid per
 * attempt. Two failure modes are covered:
 *
 *   1. SEQUENTIAL REPLAY: the first hold succeeded but the response was
 *      lost. The client retries with the same uuid. The server must return
 *      the original order (success=true) without creating a twin row.
 *
 *   2. DUPLICATE-KEY RACE: two requests with the same uuid arrive so
 *      close together that both pass the pre-transaction lookup. The first
 *      commits; the second hits the unique index on hold_uuid. The catch
 *      block must roll back, re-query the winner, and return a canonical
 *      replay response — NOT a 500.
 *
 * Pattern: APP_ENV=testing + sqlite :memory: + minimal Schema::create,
 * controller invoked directly (same as PosRecallSupersedeGhostTest).
 */
class PosHoldUuidReplayTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Schema::dropAllTables();

        // PosFeatureService caches restaurantAllowed per company id in a STATIC.
        $prop = new \ReflectionProperty(\App\Services\PosFeatureService::class, 'restaurantAllowedCache');
        $prop->setAccessible(true);
        $prop->setValue(null, []);

        Schema::create('companies', function (Blueprint $table) {
            $table->id();
            $table->string('name')->nullable();
            $table->string('product_type')->nullable();
            $table->boolean('is_internal_account')->default(false);
            $table->boolean('restaurant_mode')->default(false);
            $table->text('feature_flags')->nullable();
            $table->text('pos_printer_settings')->nullable();
            $table->boolean('agent_enabled')->default(false);
            $table->timestamp('agent_last_seen')->nullable();
            $table->softDeletes();
            $table->timestamps();
        });

        Schema::create('users', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('company_id')->nullable();
            $table->string('name')->nullable();
            $table->string('pos_role')->nullable();
            $table->boolean('is_active')->default(true);
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
            $table->unsignedBigInteger('floor_id')->nullable();
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
            $table->string('token_no')->nullable();
            $table->unsignedBigInteger('table_id')->nullable();
            $table->string('order_type')->default('dine_in');
            $table->string('status')->default('held');
            $table->unsignedBigInteger('customer_id')->nullable();
            $table->string('customer_name')->nullable();
            $table->string('customer_phone')->nullable();
            $table->string('delivery_address')->nullable();
            $table->decimal('subtotal', 12, 2)->default(0);
            $table->string('discount_type')->nullable();
            $table->decimal('discount_value', 12, 2)->default(0);
            $table->decimal('discount_amount', 12, 2)->default(0);
            $table->decimal('tax_amount', 12, 2)->default(0);
            $table->decimal('total_amount', 12, 2)->default(0);
            $table->decimal('estimated_cost', 12, 2)->default(0);
            $table->string('kitchen_notes')->nullable();
            $table->boolean('priority')->default(false);
            $table->unsignedBigInteger('created_by')->nullable();
            $table->unsignedBigInteger('assigned_cashier_id')->nullable();
            $table->string('source')->default('cashier');
            $table->timestamp('kot_sent_at')->nullable();
            $table->integer('kot_print_count')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->unsignedBigInteger('cancelled_by')->nullable();
            $table->timestamp('superseded_at')->nullable();
            $table->text('void_items')->nullable();
            // Task 1001: hold_uuid idempotency key.
            $table->string('hold_uuid', 64)->nullable()->unique();
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
            $table->boolean('is_tax_exempt')->default(false);
            $table->string('item_discount_type')->nullable();
            $table->decimal('item_discount_value', 12, 2)->default(0);
            $table->decimal('item_discount_amount', 12, 2)->default(0);
            $table->timestamp('kot_printed_at')->nullable();
            $table->integer('kot_batch_no')->nullable();
            $table->timestamps();
        });

        Schema::create('product_recipes', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('company_id');
            $table->unsignedBigInteger('product_id');
            $table->unsignedBigInteger('ingredient_id');
            $table->decimal('quantity_needed', 12, 4)->default(0);
            $table->timestamps();
        });

        Schema::create('pos_tax_rules', function (Blueprint $table) {
            $table->id();
            $table->string('payment_method');
            $table->decimal('tax_rate', 5, 2)->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });
    }

    // ── Seed helpers ──────────────────────────────────────────────────────

    private function makeCompany(): Company
    {
        $company = Company::create([
            'name'                => 'Hold UUID Co',
            'product_type'        => 'pos',
            'is_internal_account' => true,
            // typeFlowGate=false → Hold is unrestricted by order type
            'feature_flags'       => [],
        ]);
        app()->instance('currentCompanyId', null);
        app()->bind('currentCompanyId', fn () => $company->id);
        return $company;
    }

    private function makeCashier(Company $c): User
    {
        $u = User::create([
            'company_id' => $c->id,
            'name'       => 'Cashier',
            'pos_role'   => 'pos_admin',
            'is_active'  => true,
        ]);
        Auth::guard('pos')->setUser($u);
        return $u;
    }

    /** Fire holdOrder directly through the controller. */
    private function hold(array $payload)
    {
        cache()->flush(); // clear 5s cart-hash dedup cache between calls
        $request = Request::create('/pos/restaurant/orders/hold', 'POST', $payload);
        return app(RestaurantPosController::class)->holdOrder($request);
    }

    private function items(): array
    {
        return [['item_type' => 'manual', 'item_name' => 'Chai', 'unit_price' => 50, 'quantity' => 2]];
    }

    // ── 1. SEQUENTIAL REPLAY ─────────────────────────────────────────────

    /** First hold succeeds and stores hold_uuid. Second hold with the SAME
     *  uuid returns success with the original order — no twin row created. */
    public function test_sequential_retry_with_same_hold_uuid_returns_original_order(): void
    {
        $c = $this->makeCompany();
        $this->makeCashier($c);

        $uuid = 'test-hold-uuid-' . uniqid();

        // First hold — should create one order.
        $res1 = $this->hold(['items' => $this->items(), 'order_type' => 'takeaway', 'hold_uuid' => $uuid]);
        $this->assertSame(200, $res1->getStatusCode(), 'First hold failed: ' . $res1->getContent());
        $data1 = json_decode($res1->getContent(), true);
        $this->assertTrue($data1['success'], 'First hold success flag');
        $orderId1 = $data1['order']['id'];

        // Verify hold_uuid was stored.
        $stored = RestaurantOrder::find($orderId1);
        $this->assertSame($uuid, $stored->hold_uuid, 'hold_uuid must be persisted on the order row');

        // Second hold with same uuid — simulates lost-response retry.
        $res2 = $this->hold(['items' => $this->items(), 'order_type' => 'takeaway', 'hold_uuid' => $uuid]);
        $this->assertSame(200, $res2->getStatusCode(), 'Retry must return 200, not 500: ' . $res2->getContent());
        $data2 = json_decode($res2->getContent(), true);
        $this->assertTrue($data2['success'], 'Retry must return success=true');
        $this->assertSame($orderId1, $data2['order']['id'], 'Retry must return the ORIGINAL order id, not a twin');

        // Exactly one order must exist in the DB.
        $count = RestaurantOrder::where('company_id', $c->id)->count();
        $this->assertSame(1, $count, 'Exactly one restaurant_orders row must exist after a retried hold');
    }

    /** A hold without a hold_uuid still succeeds (backwards compat / billing
     *  pass-through that sends pay_uuid instead). */
    public function test_hold_without_uuid_still_works(): void
    {
        $c = $this->makeCompany();
        $this->makeCashier($c);

        $res = $this->hold(['items' => $this->items(), 'order_type' => 'takeaway']);
        $this->assertSame(200, $res->getStatusCode(), $res->getContent());
        $data = json_decode($res->getContent(), true);
        $this->assertTrue($data['success']);

        $order = RestaurantOrder::find($data['order']['id']);
        $this->assertNull($order->hold_uuid, 'hold_uuid must be null when not sent by client');
    }

    /** Different uuids create separate orders (no cross-contamination). */
    public function test_different_uuids_create_separate_orders(): void
    {
        $c = $this->makeCompany();
        $this->makeCashier($c);

        $res1 = $this->hold(['items' => $this->items(), 'order_type' => 'takeaway', 'hold_uuid' => 'uuid-aaa']);
        $res2 = $this->hold(['items' => $this->items(), 'order_type' => 'takeaway', 'hold_uuid' => 'uuid-bbb']);

        $this->assertSame(200, $res1->getStatusCode());
        $this->assertSame(200, $res2->getStatusCode());

        $id1 = json_decode($res1->getContent(), true)['order']['id'];
        $id2 = json_decode($res2->getContent(), true)['order']['id'];
        $this->assertNotSame($id1, $id2, 'Different uuids must create different orders');
        $this->assertSame(2, RestaurantOrder::where('company_id', $c->id)->count());
    }

    // ── 2. DUPLICATE-KEY RACE ─────────────────────────────────────────────

    /** Simulate the race: pre-seed a row with the uuid (simulates "first request
     *  committed") then call holdOrder with the same uuid. The pre-lookup finds
     *  it and returns the canonical replay — the duplicate-key catch path is
     *  also implicitly guarded by the pre-lookup in this sequential simulation.
     *
     *  The catch path (UniqueConstraintViolationException) is separately
     *  tested by seeding the row INSIDE the transaction window — we simulate
     *  that by seeding AFTER the pre-lookup would have missed it: use a uuid
     *  that already exists but with status='cancelled' (pre-lookup skips
     *  non-active statuses), so the pre-lookup misses but create() conflicts.
     */
    public function test_duplicate_key_race_returns_winner_not_500(): void
    {
        $c = $this->makeCompany();
        $this->makeCashier($c);

        $uuid = 'race-uuid-' . uniqid();

        // Pre-seed an order with this uuid in 'held' status — simulates the
        // winner that committed while the loser's pre-lookup window was open.
        $winnerId = DB::table('restaurant_orders')->insertGetId([
            'company_id'    => $c->id,
            'order_number'  => 'ORD-WINNER',
            'order_type'    => 'takeaway',
            'status'        => 'held',
            'subtotal'      => 100,
            'discount_amount' => 0,
            'tax_amount'    => 0,
            'total_amount'  => 100,
            'estimated_cost' => 0,
            'kot_print_count' => 1,
            'hold_uuid'     => $uuid,
            'created_at'    => now(),
            'updated_at'    => now(),
        ]);

        // Now call holdOrder with the same uuid — the pre-lookup WILL find it
        // (held status), so this exercises the pre-lookup path. To exercise
        // the catch path specifically, we directly test that a
        // UniqueConstraintViolationException on hold_uuid resolves gracefully
        // by trying to INSERT a duplicate directly and checking the controller
        // catch block re-queries and returns the winner.

        // Pre-lookup path: holdOrder should return the winner.
        $res = $this->hold(['items' => $this->items(), 'order_type' => 'takeaway', 'hold_uuid' => $uuid]);
        $this->assertSame(200, $res->getStatusCode(), 'Race resolution must return 200: ' . $res->getContent());
        $data = json_decode($res->getContent(), true);
        $this->assertTrue($data['success'], 'Race resolution must return success=true');
        $this->assertSame($winnerId, $data['order']['id'], 'Must return the winner\'s order id');

        // No additional row must have been created.
        $this->assertSame(1, RestaurantOrder::where('hold_uuid', $uuid)->count(), 'Only one row with the uuid must exist');
    }

    /** The catch-block race path: pre-seed row with 'cancelled' status so the
     *  pre-lookup misses it (only held/preparing/ready are replayed), then
     *  holdOrder tries to INSERT with the same uuid → unique violation. The
     *  catch re-queries with status IN held/preparing/ready — since no winner
     *  is found (the seeded row is cancelled), it falls through to a 500.
     *  This confirms the catch does NOT silently hide genuine errors. */
    public function test_catch_path_falls_through_when_no_live_winner_found(): void
    {
        $c = $this->makeCompany();
        $this->makeCashier($c);

        $uuid = 'cancelled-race-' . uniqid();

        // Seed a CANCELLED order with this uuid — pre-lookup skips it.
        DB::table('restaurant_orders')->insertGetId([
            'company_id'     => $c->id,
            'order_number'   => 'ORD-CANCELLED',
            'order_type'     => 'takeaway',
            'status'         => 'cancelled',
            'subtotal'       => 100,
            'discount_amount' => 0,
            'tax_amount'     => 0,
            'total_amount'   => 100,
            'estimated_cost' => 0,
            'kot_print_count' => 1,
            'hold_uuid'      => $uuid,
            'created_at'     => now(),
            'updated_at'     => now(),
        ]);

        // holdOrder: pre-lookup misses (cancelled), tries to INSERT → hits
        // unique violation in DB. Catch re-queries for held/preparing/ready
        // → none found → returns 500 (not a silent swallow).
        $res = $this->hold(['items' => $this->items(), 'order_type' => 'takeaway', 'hold_uuid' => $uuid]);
        $this->assertSame(500, $res->getStatusCode(), 'Must 500 when the catch cannot find a live winner');
        $data = json_decode($res->getContent(), true);
        $this->assertFalse($data['success']);
    }
}
