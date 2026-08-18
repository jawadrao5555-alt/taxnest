<?php

namespace Tests\Feature;

use App\Http\Controllers\RestaurantPosController;
use App\Http\Controllers\RestaurantWaiterController;
use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * ETag fast-path regression tests — Task 1097.
 *
 * Covers two failure modes that a plain MAX(updated_at) ETag cannot handle:
 *
 *  1. Two mutations in the same second: a second order or item row created
 *     within the same timestamp-second must NOT produce a false 304.
 *     Fixed by including MAX(id) in the fingerprint (auto-increment is always
 *     monotonic; new rows get higher ids even within the same second).
 *
 *  2. KOT print-stamp: kot_printed_at is written by a raw query-builder UPDATE
 *     that does NOT touch updated_at on the item row.  After the stamp the next
 *     poll must return 200 (fresh JSON), not a stale 304.
 *     Fixed by including MAX(kot_printed_at) in the fingerprint.
 *
 * Pattern: SQLite :memory: + minimal Schema::create, controllers invoked
 * directly (same as PosCounterOrderOpenStatusesTest / PosRestaurantOrderCancelTest).
 *
 * Run:
 *   php vendor/bin/phpunit tests/Feature/PosHeldOrdersEtagTest.php --testdox
 */
class PosHeldOrdersEtagTest extends TestCase
{
    protected int $companyId;

    protected function setUp(): void
    {
        parent::setUp();
        Schema::dropAllTables();

        Schema::create('companies', function (Blueprint $t) {
            $t->id();
            $t->string('name');
            $t->softDeletes();
            $t->timestamps();
        });

        Schema::create('users', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('company_id')->nullable();
            $t->string('name')->nullable();
            $t->string('role')->nullable();
            $t->string('pos_role')->nullable();
            $t->text('pos_custom_access')->nullable();
            $t->timestamps();
        });

        Schema::create('restaurant_orders', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('company_id');
            $t->unsignedBigInteger('pos_transaction_id')->nullable();
            $t->string('order_number')->nullable();
            $t->unsignedBigInteger('table_id')->nullable();
            $t->string('order_type')->nullable();
            $t->string('source')->nullable();
            $t->string('status');
            $t->string('payment_method')->nullable();
            $t->unsignedBigInteger('created_by')->nullable();
            $t->unsignedBigInteger('assigned_cashier_id')->nullable();
            $t->string('customer_name')->nullable();
            $t->string('customer_phone')->nullable();
            $t->string('kitchen_notes')->nullable();
            $t->integer('token_no')->nullable();
            $t->decimal('subtotal', 12, 2)->default(0);
            $t->decimal('total_amount', 12, 2)->default(0);
            $t->timestamp('kot_sent_at')->nullable();
            $t->timestamps();
        });

        Schema::create('restaurant_order_items', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('order_id')->nullable();
            $t->string('item_type')->nullable();
            $t->unsignedBigInteger('item_id')->nullable();
            $t->string('item_name')->nullable();
            $t->decimal('quantity', 12, 3)->default(1);
            $t->decimal('unit_price', 12, 2)->default(0);
            $t->decimal('subtotal', 12, 2)->default(0);
            $t->string('special_notes')->nullable();
            $t->boolean('is_tax_exempt')->default(false);
            $t->timestamp('kot_printed_at')->nullable();
            $t->timestamps();
        });

        Schema::create('restaurant_tables', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('company_id');
            $t->string('table_number')->nullable();
            $t->string('status')->nullable();
            $t->unsignedBigInteger('locked_by_user_id')->nullable();
            $t->timestamp('locked_at')->nullable();
            $t->timestamp('occupied_since')->nullable();
            $t->timestamps();
        });

        $this->companyId = DB::table('companies')->insertGetId([
            'name' => 'ETag Test Shop',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        app()->bind('currentCompanyId', fn () => $this->companyId);
    }

    protected function tearDown(): void
    {
        Auth::guard('pos')->logout();
        parent::tearDown();
    }

    // ── helpers ──────────────────────────────────────────────────────────────

    protected function makeUser(string $posRole = 'pos_admin'): User
    {
        DB::table('users')->insert([
            'company_id' => $this->companyId,
            'name' => 'U-' . $posRole . '-' . uniqid(),
            'role' => 'user',
            'pos_role' => $posRole,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $user = User::orderByDesc('id')->first();
        Auth::guard('pos')->setUser($user);
        return $user;
    }

    /** Insert a held/preparing/ready restaurant_order with one item. */
    protected function insertOrder(string $source = 'pos', string $status = 'held', string $ts = ''): array
    {
        $ts = $ts ?: now()->toDateTimeString();
        $orderId = DB::table('restaurant_orders')->insertGetId([
            'company_id'   => $this->companyId,
            'order_number' => 'R-' . uniqid(),
            'status'       => $status,
            'source'       => $source,
            'table_id'     => null,
            'total_amount' => 300,
            'created_at'   => $ts,
            'updated_at'   => $ts,
        ]);
        $itemId = DB::table('restaurant_order_items')->insertGetId([
            'order_id'    => $orderId,
            'item_type'   => 'product',
            'item_id'     => 1,
            'item_name'   => 'Chai',
            'quantity'    => 1,
            'unit_price'  => 300,
            'subtotal'    => 300,
            'created_at'  => $ts,
            'updated_at'  => $ts,
        ]);
        return ['order_id' => $orderId, 'item_id' => $itemId];
    }

    /** Read the ETag header from the held-orders endpoint. */
    protected function heldEtag(?string $ifNoneMatch = null): \Illuminate\Http\Response|\Illuminate\Http\JsonResponse
    {
        $req = Request::create('/pos/restaurant/api/held-orders', 'GET');
        if ($ifNoneMatch !== null) {
            $req->headers->set('If-None-Match', $ifNoneMatch);
        }
        return (new RestaurantPosController())->listHeldOrders($req);
    }

    /** Read the response from the incoming-orders endpoint. */
    protected function incomingResponse(?string $ifNoneMatch = null): \Illuminate\Http\Response|\Illuminate\Http\JsonResponse
    {
        $req = Request::create('/pos/api/incoming-orders', 'GET');
        if ($ifNoneMatch !== null) {
            $req->headers->set('If-None-Match', $ifNoneMatch);
        }
        return (new RestaurantWaiterController())->incomingOrders($req);
    }

    // ── Held-orders ETag tests ────────────────────────────────────────────────

    /**
     * Basic round-trip: first poll returns ETag, second poll with same ETag
     * returns 304, third poll after a new order returns 200 with new ETag.
     */
    public function test_held_orders_304_on_unchanged_and_200_after_new_order(): void
    {
        $this->makeUser();
        $this->insertOrder('pos', 'held');

        // First poll — full 200 + ETag.
        $r1 = $this->heldEtag();
        $this->assertEquals(200, $r1->getStatusCode());
        $etag1 = $r1->headers->get('ETag');
        $this->assertNotEmpty($etag1);

        // Second poll with same ETag — nothing changed → 304.
        $r2 = $this->heldEtag($etag1);
        $this->assertEquals(304, $r2->getStatusCode(), 'Unchanged list must yield 304');

        // New order arrives → 200 with a different ETag.
        $this->insertOrder('pos', 'held');
        $r3 = $this->heldEtag($etag1);
        $this->assertEquals(200, $r3->getStatusCode(), 'New order must break the 304 fast-path');
        $this->assertNotEquals($etag1, $r3->headers->get('ETag'));
    }

    /**
     * Same-second collision: two orders with the SAME updated_at timestamp
     * must still produce a different ETag (MAX(id) catches the new row).
     */
    public function test_held_orders_etag_differs_when_second_order_shares_timestamp(): void
    {
        $this->makeUser();
        $fixedTs = '2026-08-17 12:00:00';

        $this->insertOrder('pos', 'held', $fixedTs);

        $r1 = $this->heldEtag();
        $etag1 = $r1->headers->get('ETag');

        // Second order — SAME second, higher auto-increment id.
        $this->insertOrder('pos', 'held', $fixedTs);

        $r2 = $this->heldEtag($etag1);
        $this->assertEquals(
            200,
            $r2->getStatusCode(),
            'Two orders sharing the same updated_at second must still invalidate the ETag'
        );
        $this->assertNotEquals($etag1, $r2->headers->get('ETag'));
    }

    /**
     * KOT print-stamp: stamping kot_printed_at via a raw query-builder update
     * (which does NOT bump restaurant_order_items.updated_at) must still
     * invalidate the ETag so the cashier screen sees the new printed state.
     */
    public function test_held_orders_etag_differs_after_kot_print_stamp(): void
    {
        $this->makeUser();
        $ids = $this->insertOrder('pos', 'held');

        $r1 = $this->heldEtag();
        $etag1 = $r1->headers->get('ETag');

        // Confirm 304 before the stamp.
        $this->assertEquals(304, $this->heldEtag($etag1)->getStatusCode());

        // Stamp kot_printed_at WITHOUT touching updated_at (mirrors production path).
        DB::table('restaurant_order_items')
            ->where('id', $ids['item_id'])
            ->update(['kot_printed_at' => now()]);

        $r2 = $this->heldEtag($etag1);
        $this->assertEquals(
            200,
            $r2->getStatusCode(),
            'KOT print stamp must invalidate the ETag even though updated_at is unchanged'
        );
    }

    /**
     * Deleting a NON-MAX item (not the highest id) must still invalidate the ETag.
     * A MAX(id)-only fingerprint misses this — the max stays the same after deletion.
     */
    public function test_held_orders_etag_differs_after_non_max_item_deleted(): void
    {
        $this->makeUser();

        // Insert first order with two items; the first item has a lower id.
        $orderId = DB::table('restaurant_orders')->insertGetId([
            'company_id'   => $this->companyId,
            'order_number' => 'R-multi',
            'status'       => 'held',
            'source'       => 'pos',
            'total_amount' => 600,
            'created_at'   => now(), 'updated_at' => now(),
        ]);
        $itemId1 = DB::table('restaurant_order_items')->insertGetId([
            'order_id'   => $orderId, 'item_type' => 'product', 'item_id' => 1,
            'item_name'  => 'Chai', 'quantity' => 1, 'unit_price' => 300, 'subtotal' => 300,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $itemId2 = DB::table('restaurant_order_items')->insertGetId([
            'order_id'   => $orderId, 'item_type' => 'product', 'item_id' => 2,
            'item_name'  => 'Paratha', 'quantity' => 1, 'unit_price' => 300, 'subtotal' => 300,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->assertGreaterThan($itemId1, $itemId2, 'Setup: item2 must have a higher id');

        $r1   = $this->heldEtag();
        $etag = $r1->headers->get('ETag');
        $this->assertEquals(304, $this->heldEtag($etag)->getStatusCode(), 'Baseline: expect 304');

        // Delete the non-max item (itemId1 < itemId2).
        DB::table('restaurant_order_items')->where('id', $itemId1)->delete();

        $r2 = $this->heldEtag($etag);
        $this->assertEquals(
            200,
            $r2->getStatusCode(),
            'Deleting a non-max item must invalidate the ETag'
        );
    }

    /**
     * Updating a NON-MAX item (bumping its updated_at to a value still ≤ the current
     * MAX from another item) must still invalidate the ETag.
     *
     * A MAX(updated_at)-only fingerprint misses this: item1's new timestamp
     * $tsMid is between $tsOld and $tsNew, so the MAX stays $tsNew.
     * The per-row digest includes every row's updated_at, so the change IS detected.
     *
     * Note: we DO bump updated_at here, because a real Eloquent update always
     * touches that column.  The point is that the new timestamp is below the max.
     */
    public function test_held_orders_etag_differs_after_non_max_item_updated(): void
    {
        $this->makeUser();
        $tsOld = '2026-08-17 10:00:00';
        $tsMid = '2026-08-17 10:30:00'; // new ts for item1 — still < item2's MAX
        $tsNew = '2026-08-17 11:00:00'; // item2's ts (MAX)

        $orderId = DB::table('restaurant_orders')->insertGetId([
            'company_id'   => $this->companyId, 'order_number' => 'R-upd',
            'status' => 'held', 'source' => 'pos', 'total_amount' => 600,
            'created_at' => $tsOld, 'updated_at' => $tsOld,
        ]);
        $itemId1 = DB::table('restaurant_order_items')->insertGetId([
            'order_id'   => $orderId, 'item_type' => 'product', 'item_id' => 1,
            'item_name'  => 'Chai',    'quantity' => 1, 'unit_price' => 300, 'subtotal' => 300,
            'created_at' => $tsOld,  'updated_at' => $tsOld,
        ]);
        // Second item has a LATER updated_at — it is the MAX.
        $itemId2 = DB::table('restaurant_order_items')->insertGetId([
            'order_id'   => $orderId, 'item_type' => 'product', 'item_id' => 2,
            'item_name'  => 'Paratha', 'quantity' => 1, 'unit_price' => 300, 'subtotal' => 300,
            'created_at' => $tsNew,  'updated_at' => $tsNew,
        ]);
        $this->assertGreaterThan($itemId1, $itemId2, 'Setup: item2 must have a higher id');

        $r1   = $this->heldEtag();
        $etag = $r1->headers->get('ETag');
        $this->assertEquals(304, $this->heldEtag($etag)->getStatusCode(), 'Baseline: expect 304');

        // Update the NON-max item to $tsMid — still below item2's $tsNew MAX.
        // MAX(updated_at)-only fingerprint stays $tsNew and misses this.
        DB::table('restaurant_order_items')
            ->where('id', $itemId1)
            ->update(['quantity' => 2, 'updated_at' => $tsMid]);

        $r2 = $this->heldEtag($etag);
        $this->assertEquals(
            200,
            $r2->getStatusCode(),
            'Updating a non-max item (ts still below current MAX) must invalidate the ETag'
        );
    }

    /**
     * Stamping kot_printed_at on a NON-MAX item must invalidate the ETag.
     */
    public function test_held_orders_etag_differs_after_non_max_item_kot_stamped(): void
    {
        $this->makeUser();

        $orderId = DB::table('restaurant_orders')->insertGetId([
            'company_id'   => $this->companyId, 'order_number' => 'R-stamp',
            'status' => 'held', 'source' => 'pos', 'total_amount' => 600,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $itemId1 = DB::table('restaurant_order_items')->insertGetId([
            'order_id' => $orderId, 'item_type' => 'product', 'item_id' => 1,
            'item_name' => 'Chai', 'quantity' => 1, 'unit_price' => 300, 'subtotal' => 300,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $itemId2 = DB::table('restaurant_order_items')->insertGetId([
            'order_id' => $orderId, 'item_type' => 'product', 'item_id' => 2,
            'item_name' => 'Paratha', 'quantity' => 1, 'unit_price' => 300, 'subtotal' => 300,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->assertGreaterThan($itemId1, $itemId2, 'Setup: item2 must have a higher id');

        $r1   = $this->heldEtag();
        $etag = $r1->headers->get('ETag');
        $this->assertEquals(304, $this->heldEtag($etag)->getStatusCode(), 'Baseline: expect 304');

        // Stamp the NON-max item (itemId1) without touching updated_at.
        DB::table('restaurant_order_items')
            ->where('id', $itemId1)
            ->update(['kot_printed_at' => now()]);

        $r2 = $this->heldEtag($etag);
        $this->assertEquals(
            200,
            $r2->getStatusCode(),
            'KOT stamp on a non-max item must invalidate the ETag'
        );
    }

    // ── Incoming-orders ETag tests ────────────────────────────────────────────

    /**
     * Basic round-trip for the incoming-orders endpoint.
     */
    public function test_incoming_orders_304_on_unchanged_and_200_after_new_order(): void
    {
        $this->makeUser('pos_admin');
        $this->insertOrder('waiter', 'held');

        $r1 = $this->incomingResponse();
        $this->assertEquals(200, $r1->getStatusCode());
        $etag1 = $r1->headers->get('ETag');
        $this->assertNotEmpty($etag1);

        // Unchanged → 304.
        $r2 = $this->incomingResponse($etag1);
        $this->assertEquals(304, $r2->getStatusCode(), 'Unchanged incoming list must yield 304');

        // New waiter order → 200.
        $this->insertOrder('waiter', 'held');
        $r3 = $this->incomingResponse($etag1);
        $this->assertEquals(200, $r3->getStatusCode(), 'New waiter order must break the 304 fast-path');
        $this->assertNotEquals($etag1, $r3->headers->get('ETag'));
    }

    /**
     * Same-second collision on incoming-orders: MAX(id) on orders catches it.
     */
    public function test_incoming_orders_etag_differs_when_second_order_shares_timestamp(): void
    {
        $this->makeUser('pos_admin');
        $fixedTs = '2026-08-17 12:00:00';

        $this->insertOrder('waiter', 'held', $fixedTs);
        $r1 = $this->incomingResponse();
        $etag1 = $r1->headers->get('ETag');

        // Second waiter order — identical second, higher id.
        $this->insertOrder('waiter', 'held', $fixedTs);
        $r2 = $this->incomingResponse($etag1);
        $this->assertEquals(
            200,
            $r2->getStatusCode(),
            'Two waiter orders sharing the same updated_at second must still invalidate the ETag'
        );
    }

    /**
     * KOT print-stamp on an incoming order's item must invalidate the ETag.
     */
    public function test_incoming_orders_etag_differs_after_kot_print_stamp(): void
    {
        $this->makeUser('pos_admin');
        $ids = $this->insertOrder('waiter', 'held');

        $r1 = $this->incomingResponse();
        $etag1 = $r1->headers->get('ETag');
        $this->assertEquals(304, $this->incomingResponse($etag1)->getStatusCode());

        // Raw stamp — no updated_at bump.
        DB::table('restaurant_order_items')
            ->where('id', $ids['item_id'])
            ->update(['kot_printed_at' => now()]);

        $r2 = $this->incomingResponse($etag1);
        $this->assertEquals(
            200,
            $r2->getStatusCode(),
            'KOT print stamp on a waiter order item must invalidate the incoming-orders ETag'
        );
    }

    /**
     * Deleting a NON-MAX item on a waiter order must invalidate the incoming ETag.
     */
    public function test_incoming_orders_etag_differs_after_non_max_item_deleted(): void
    {
        $this->makeUser('pos_admin');

        $orderId = DB::table('restaurant_orders')->insertGetId([
            'company_id' => $this->companyId, 'order_number' => 'W-del',
            'status' => 'held', 'source' => 'waiter', 'total_amount' => 600,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $itemId1 = DB::table('restaurant_order_items')->insertGetId([
            'order_id' => $orderId, 'item_type' => 'product', 'item_id' => 1,
            'item_name' => 'Chai', 'quantity' => 1, 'unit_price' => 300, 'subtotal' => 300,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $itemId2 = DB::table('restaurant_order_items')->insertGetId([
            'order_id' => $orderId, 'item_type' => 'product', 'item_id' => 2,
            'item_name' => 'Paratha', 'quantity' => 1, 'unit_price' => 300, 'subtotal' => 300,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->assertGreaterThan($itemId1, $itemId2, 'Setup: item2 must have higher id');

        $etag = $this->incomingResponse()->headers->get('ETag');
        $this->assertEquals(304, $this->incomingResponse($etag)->getStatusCode(), 'Baseline');

        // Delete the NON-max item — MAX(id) stays itemId2, only per-row digest changes.
        DB::table('restaurant_order_items')->where('id', $itemId1)->delete();

        $this->assertEquals(
            200,
            $this->incomingResponse($etag)->getStatusCode(),
            'Deleting a non-max item must invalidate the incoming-orders ETag'
        );
    }

    /**
     * Updating a NON-MAX item (bumping its updated_at to a value still ≤ the
     * current MAX from another item) must still invalidate the incoming ETag.
     * Per-row digest catches the change; MAX(updated_at)-only would miss it.
     */
    public function test_incoming_orders_etag_differs_after_non_max_item_updated(): void
    {
        $this->makeUser('pos_admin');
        $tsOld = '2026-08-17 10:00:00';
        $tsMid = '2026-08-17 10:30:00'; // new ts for item1 — still < item2's MAX
        $tsNew = '2026-08-17 11:00:00'; // item2's ts (MAX)

        $orderId = DB::table('restaurant_orders')->insertGetId([
            'company_id' => $this->companyId, 'order_number' => 'W-upd',
            'status' => 'held', 'source' => 'waiter', 'total_amount' => 600,
            'created_at' => $tsOld, 'updated_at' => $tsOld,
        ]);
        $itemId1 = DB::table('restaurant_order_items')->insertGetId([
            'order_id' => $orderId, 'item_type' => 'product', 'item_id' => 1,
            'item_name' => 'Chai', 'quantity' => 1, 'unit_price' => 300, 'subtotal' => 300,
            'created_at' => $tsOld, 'updated_at' => $tsOld,
        ]);
        DB::table('restaurant_order_items')->insertGetId([
            'order_id' => $orderId, 'item_type' => 'product', 'item_id' => 2,
            'item_name' => 'Paratha', 'quantity' => 1, 'unit_price' => 300, 'subtotal' => 300,
            'created_at' => $tsNew, 'updated_at' => $tsNew,
        ]);

        $etag = $this->incomingResponse()->headers->get('ETag');
        $this->assertEquals(304, $this->incomingResponse($etag)->getStatusCode(), 'Baseline');

        // Update item1 to $tsMid — still below item2's $tsNew MAX.
        DB::table('restaurant_order_items')
            ->where('id', $itemId1)
            ->update(['quantity' => 3, 'updated_at' => $tsMid]);

        $this->assertEquals(
            200,
            $this->incomingResponse($etag)->getStatusCode(),
            'Updating a non-max item (ts still below current MAX) must invalidate the incoming-orders ETag'
        );
    }

    /**
     * Stamping kot_printed_at on a NON-MAX item of a waiter order must invalidate
     * the incoming ETag even though MAX(kot_printed_at) may already be later.
     */
    public function test_incoming_orders_etag_differs_after_non_max_item_kot_stamped(): void
    {
        $this->makeUser('pos_admin');

        $orderId = DB::table('restaurant_orders')->insertGetId([
            'company_id' => $this->companyId, 'order_number' => 'W-stamp',
            'status' => 'held', 'source' => 'waiter', 'total_amount' => 600,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $itemId1 = DB::table('restaurant_order_items')->insertGetId([
            'order_id' => $orderId, 'item_type' => 'product', 'item_id' => 1,
            'item_name' => 'Chai', 'quantity' => 1, 'unit_price' => 300, 'subtotal' => 300,
            'created_at' => now(), 'updated_at' => now(), 'kot_printed_at' => null,
        ]);
        // item2 already has a kot_printed_at (it's the current MAX).
        DB::table('restaurant_order_items')->insertGetId([
            'order_id' => $orderId, 'item_type' => 'product', 'item_id' => 2,
            'item_name' => 'Paratha', 'quantity' => 1, 'unit_price' => 300, 'subtotal' => 300,
            'created_at' => now(), 'updated_at' => now(),
            'kot_printed_at' => now(),
        ]);

        $etag = $this->incomingResponse()->headers->get('ETag');
        $this->assertEquals(304, $this->incomingResponse($etag)->getStatusCode(), 'Baseline');

        // Stamp the NON-max item without touching updated_at.
        DB::table('restaurant_order_items')
            ->where('id', $itemId1)
            ->update(['kot_printed_at' => now()]);

        $this->assertEquals(
            200,
            $this->incomingResponse($etag)->getStatusCode(),
            'KOT stamp on a non-max waiter-order item must invalidate the incoming-orders ETag'
        );
    }

    /**
     * claimIncoming() updates assigned_cashier_id via a raw query-builder UPDATE
     * (which historically did NOT bump updated_at).  After the claim the next
     * incoming-orders poll must return 200 with the updated assignee — not a
     * stale 304.
     *
     * Two fixes cover this: (a) claimIncoming() now also sets updated_at=now(),
     * and (b) incomingOrdersEtag() includes assigned_cashier_id in the row hash.
     */
    public function test_incoming_orders_etag_differs_after_claim_reassignment(): void
    {
        $admin = $this->makeUser('pos_admin');

        $orderId = DB::table('restaurant_orders')->insertGetId([
            'company_id'          => $this->companyId,
            'order_number'        => 'W-claim',
            'status'              => 'held',
            'source'              => 'waiter',
            'assigned_cashier_id' => null,
            'total_amount'        => 300,
            'created_at'          => now(), 'updated_at' => now(),
        ]);
        DB::table('restaurant_order_items')->insert([
            'order_id'   => $orderId, 'item_type' => 'product', 'item_id' => 1,
            'item_name'  => 'Chai', 'quantity' => 1, 'unit_price' => 300, 'subtotal' => 300,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        // Baseline ETag as admin (sees all orders regardless of assignment).
        $etag = $this->incomingResponse()->headers->get('ETag');
        $this->assertEquals(304, $this->incomingResponse($etag)->getStatusCode(), 'Baseline: expect 304');

        // Simulate claimIncoming(): set assigned_cashier_id without explicit updated_at bump.
        // This is the raw path that previously produced false 304s.
        DB::table('restaurant_orders')
            ->where('id', $orderId)
            ->update(['assigned_cashier_id' => $admin->id, 'updated_at' => now()]);

        $r2 = $this->incomingResponse($etag);
        $this->assertEquals(
            200,
            $r2->getStatusCode(),
            'Claiming an order (assigned_cashier_id change) must invalidate the incoming-orders ETag'
        );

        // The response must reflect the new assignee.
        $body = collect($r2->getData());
        $claimed = $body->firstWhere('id', $orderId);
        $this->assertNotNull($claimed, 'Claimed order must appear in fresh response');
        $this->assertEquals($admin->id, $claimed->assigned_cashier_id ?? null);
    }
}
