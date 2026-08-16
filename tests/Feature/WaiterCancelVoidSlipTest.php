<?php

namespace Tests\Feature;

use Tests\TestCase;
use App\Http\Controllers\RestaurantWaiterController;
use App\Models\Company;
use App\Models\RestaurantOrder;
use App\Models\RestaurantOrderItem;
use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\View\View;

/**
 * Task 851 — Waiter self-cancel void slip.
 *
 * When a waiter cancels their own held order AFTER a KOT has already been
 * printed, the kitchen must receive a void slip listing every printed dish.
 * Task 840 added this for the cashier deleteOrder path; this test confirms it
 * also covers the waiter cancelOrder path.
 *
 * Pattern: same in-process controller call as PosKotDeltaQtyCarryTest —
 * sqlite :memory: + minimal Schema::create, controller invoked directly.
 */
class WaiterCancelVoidSlipTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Schema::dropAllTables();

        // Clear PosFeatureService static cache so fresh company flags apply.
        $prop = new \ReflectionProperty(\App\Services\PosFeatureService::class, 'restaurantAllowedCache');
        $prop->setAccessible(true);
        $prop->setValue(null, []);

        Schema::create('companies', function (Blueprint $table) {
            $table->id();
            $table->string('name')->nullable();
            $table->string('product_type')->nullable();
            $table->string('status')->nullable();
            $table->boolean('is_internal_account')->default(false);
            $table->boolean('restaurant_mode')->default(false);
            $table->text('feature_flags')->nullable();
            $table->text('pos_printer_settings')->nullable();
            $table->boolean('agent_enabled')->default(false);
            $table->timestamp('agent_last_seen')->nullable();
            $table->boolean('pra_reporting_enabled')->default(false);
            $table->boolean('pos_waiter_cancel_enabled')->default(false);
            $table->softDeletes();
            $table->timestamps();
        });

        Schema::create('users', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('company_id')->nullable();
            $table->string('name')->nullable();
            $table->string('email')->nullable();
            $table->string('role')->nullable();
            $table->string('pos_role')->nullable();
            $table->boolean('is_active')->default(true);
            $table->string('language')->nullable();
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
            $table->decimal('subtotal', 12, 2)->default(0);
            $table->decimal('discount_amount', 12, 2)->default(0);
            $table->decimal('tax_amount', 12, 2)->default(0);
            $table->decimal('total_amount', 12, 2)->default(0);
            $table->string('source')->default('cashier');
            $table->unsignedBigInteger('created_by')->nullable();
            $table->unsignedBigInteger('assigned_cashier_id')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->unsignedBigInteger('cancelled_by')->nullable();
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
            $table->decimal('unit_price', 12, 2)->default(0);
            $table->decimal('subtotal', 12, 2)->default(0);
            $table->string('special_notes')->nullable();
            $table->timestamp('kot_printed_at')->nullable();
            $table->integer('kot_batch_no')->nullable();
            $table->timestamps();
        });

        // KotPrintService::enqueueVoid walks pos_stations — table must exist.
        Schema::create('pos_stations', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('company_id');
            $table->string('name');
            $table->text('categories')->nullable();
            $table->string('printer_name')->nullable();
            $table->boolean('is_active')->default(true);
            $table->integer('sort')->default(0);
            $table->timestamps();
        });

        Schema::create('pos_print_jobs', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('company_id');
            $table->string('type');
            $table->string('target_printer')->nullable();
            $table->unsignedBigInteger('transaction_id')->nullable();
            $table->unsignedBigInteger('restaurant_order_id')->nullable();
            $table->text('render_query')->nullable();
            $table->string('status')->default('pending');
            $table->string('claim_token')->nullable();
            $table->text('printed_item_ids')->nullable();
            $table->text('error')->nullable();
            $table->integer('attempts')->default(0);
            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamps();
        });
    }

    // ── helpers ──────────────────────────────────────────────────────────────

    private function makeCompany(array $extra = []): Company
    {
        $company = Company::create(array_merge([
            'name'               => 'Void Slip Co',
            'product_type'       => 'pos',
            'status'             => 'active',
            'restaurant_mode'    => true,
            'feature_flags'      => ['tables' => true, 'kot' => true, 'kitchen' => true],
            'pra_reporting_enabled' => false,
        ], $extra));

        app()->bind('currentCompanyId', fn () => $company->id);
        return $company;
    }

    /**
     * Make a user and authenticate them on the pos guard.
     * Use pos_admin so the waiter-cancel-enabled gate is bypassed;
     * the void logic is role-agnostic.
     */
    private function makeUser(Company $c, string $posRole = 'pos_admin'): User
    {
        $u = User::create([
            'company_id' => $c->id,
            'name'       => 'Test User',
            'pos_role'   => $posRole,
            'is_active'  => true,
        ]);
        Auth::guard('pos')->setUser($u);
        return $u;
    }

    /** Create a waiter-sourced held order (unclaimed). */
    private function makeWaiterOrder(Company $c, User $u): RestaurantOrder
    {
        return RestaurantOrder::create([
            'company_id'   => $c->id,
            'order_number' => 'ORD-W001',
            'source'       => 'waiter',
            'status'       => 'held',
            'created_by'   => $u->id,
            // assigned_cashier_id intentionally NULL — unclaimed
        ]);
    }

    /** Attach items to an order and stamp them as KOT-printed. */
    private function addPrintedItem(RestaurantOrder $order, string $name, float $qty): RestaurantOrderItem
    {
        return RestaurantOrderItem::create([
            'order_id'       => $order->id,
            'item_type'      => 'manual',
            'item_name'      => $name,
            'quantity'       => $qty,
            'unit_price'     => 100,
            'subtotal'       => $qty * 100,
            'kot_printed_at' => now(),
            'kot_batch_no'   => 1,
        ]);
    }

    /** Attach an item WITHOUT stamping kot_printed_at (not yet printed). */
    private function addUnprintedItem(RestaurantOrder $order, string $name, float $qty): RestaurantOrderItem
    {
        return RestaurantOrderItem::create([
            'order_id'  => $order->id,
            'item_type' => 'manual',
            'item_name' => $name,
            'quantity'  => $qty,
            'unit_price' => 50,
            'subtotal'   => $qty * 50,
        ]);
    }

    private function cancelOrder(int $orderId): \Illuminate\Http\JsonResponse
    {
        $request = Request::create("/pos/restaurant/orders/{$orderId}/cancel", 'DELETE');
        return app(RestaurantWaiterController::class)->cancelOrder($request, $orderId);
    }

    /** Decode the void_items payload from the iframe fallback URL. */
    private function decodeVoidUrl(string $url): array
    {
        parse_str((string) parse_url($url, PHP_URL_QUERY), $q);
        $items = json_decode(base64_decode((string) ($q['void_items'] ?? '')), true);
        $this->assertIsArray($items, 'void_items must decode to a JSON array');
        return $items;
    }

    // ── tests ─────────────────────────────────────────────────────────────────

    /**
     * Core regression: waiter cancels an order that already has KOT-printed
     * items → response carries a void slip URL listing every printed dish.
     * (Agent is offline → queued=false, client falls back to iframe URL.)
     */
    public function test_cancel_with_printed_items_emits_void_slip_url(): void
    {
        $c = $this->makeCompany();
        $u = $this->makeUser($c);
        $order = $this->makeWaiterOrder($c, $u);
        $this->addPrintedItem($order, 'Biryani', 2);
        $this->addPrintedItem($order, 'Raita', 1);

        $resp = $this->cancelOrder($order->id);
        $data = json_decode($resp->getContent(), true);

        $this->assertSame(200, $resp->getStatusCode(), $resp->getContent());
        $this->assertTrue($data['success']);
        $this->assertFalse($data['kot_void_queued'], 'agent offline → not queued, client falls back to iframe');
        $this->assertNotNull($data['kot_void_url'], 'printed items must produce a void slip URL');

        $items = $this->decodeVoidUrl($data['kot_void_url']);
        $this->assertCount(2, $items, 'both printed dishes must appear on the void slip');

        $names = array_column($items, 'item_name');
        $this->assertContains('Biryani', $names);
        $this->assertContains('Raita', $names);

        // Quantities must match what was printed
        $byName = array_column($items, null, 'item_name');
        $this->assertEquals(2.0, (float) $byName['Biryani']['qty']);
        $this->assertEquals(1.0, (float) $byName['Raita']['qty']);
    }

    /**
     * A fresh hold cancelled before ANY KOT was sent must not void anything —
     * the kitchen never saw these dishes.
     */
    public function test_cancel_with_no_printed_items_emits_no_void_slip(): void
    {
        $c = $this->makeCompany();
        $u = $this->makeUser($c);
        $order = $this->makeWaiterOrder($c, $u);
        $this->addUnprintedItem($order, 'Chai', 3);

        $resp = $this->cancelOrder($order->id);
        $data = json_decode($resp->getContent(), true);

        $this->assertSame(200, $resp->getStatusCode(), $resp->getContent());
        $this->assertTrue($data['success']);
        $this->assertNull($data['kot_void_url'], 'nothing printed → no void slip');
        $this->assertFalse($data['kot_void_queued']);
    }

    /**
     * Mixed order: some items printed, some not (waiter added more dishes
     * after the first KOT but before cancelling). Only printed items void.
     */
    public function test_only_printed_items_appear_on_void_slip(): void
    {
        $c = $this->makeCompany();
        $u = $this->makeUser($c);
        $order = $this->makeWaiterOrder($c, $u);
        $this->addPrintedItem($order, 'Karahi', 1);
        $this->addUnprintedItem($order, 'Naan', 4); // added but never KOT-printed

        $resp = $this->cancelOrder($order->id);
        $data = json_decode($resp->getContent(), true);

        $this->assertSame(200, $resp->getStatusCode(), $resp->getContent());
        $this->assertNotNull($data['kot_void_url']);

        $items = $this->decodeVoidUrl($data['kot_void_url']);
        $this->assertCount(1, $items, 'unprinted Naan must NOT appear on the void slip');
        $this->assertSame('Karahi', $items[0]['item_name']);
    }

    /**
     * A claimed order (assigned_cashier_id set) must return 409 and no void
     * slip — the cashier now owns it and handles cancellation from their side.
     */
    public function test_claimed_order_returns_409_and_no_void(): void
    {
        $c = $this->makeCompany();
        $u = $this->makeUser($c);
        $order = $this->makeWaiterOrder($c, $u);
        $this->addPrintedItem($order, 'Sajji', 1);

        // Simulate cashier claim
        $order->assigned_cashier_id = 99;
        $order->save();

        $resp = $this->cancelOrder($order->id);
        $data = json_decode($resp->getContent(), true);

        $this->assertSame(409, $resp->getStatusCode());
        $this->assertFalse($data['success']);
        // No void emitted — the order was NOT actually cancelled
        $fresh = RestaurantOrder::find($order->id);
        $this->assertSame('held', $fresh->status, 'order must remain held after 409');
    }

    /**
     * After a successful cancel the order row must have status='cancelled'
     * and cancelled_at stamped (column present in the test schema).
     */
    public function test_order_row_marked_cancelled_after_void_slip_emitted(): void
    {
        $c = $this->makeCompany();
        $u = $this->makeUser($c);
        $order = $this->makeWaiterOrder($c, $u);
        $this->addPrintedItem($order, 'Daal', 2);

        $resp = $this->cancelOrder($order->id);
        $this->assertSame(200, $resp->getStatusCode());

        $fresh = RestaurantOrder::find($order->id);
        $this->assertSame('cancelled', $fresh->status);
        $this->assertNotNull($fresh->cancelled_at);
    }

    // ── Route-access tests ────────────────────────────────────────────────────

    /**
     * The void-ticket fallback URL must be under pos/waiter/ so that PosAuth's
     * waiter allowlist (str_starts_with($path, 'pos/waiter')) admits it without
     * any middleware change. This test pins the URL shape so a future rename
     * can't silently break waiter access.
     */
    public function test_cancel_void_url_is_under_pos_waiter_prefix(): void
    {
        $c = $this->makeCompany();
        $u = $this->makeUser($c);
        $order = $this->makeWaiterOrder($c, $u);
        $this->addPrintedItem($order, 'Pulao', 1);

        $resp = $this->cancelOrder($order->id);
        $data = json_decode($resp->getContent(), true);

        $this->assertNotNull($data['kot_void_url']);

        // Strip the leading slash for the str_starts_with check that PosAuth runs.
        $path = ltrim(parse_url($data['kot_void_url'], PHP_URL_PATH) ?? '', '/');
        $this->assertTrue(
            str_starts_with($path, 'pos/waiter'),
            "void URL path '{$path}' must start with pos/waiter — PosAuth allowlist covers pos/waiter* only"
        );
    }

    /**
     * A pos_waiter calling waiterVoidTicket for their OWN cancelled order must get
     * a rendered View. void_items are reconstructed from DB — query param ignored
     * (forged-payload guard).
     */
    public function test_pos_waiter_gets_view_with_server_side_void_items(): void
    {
        $c = $this->makeCompany();
        $u = $this->makeUser($c, 'pos_waiter');
        $order = $this->makeWaiterOrder($c, $u);
        $order->status = 'cancelled';
        $order->save();
        $this->addPrintedItem($order, 'Haleem', 2);

        // Supply a FORGED void_items query param — must be ignored entirely.
        $fakePayload = base64_encode(json_encode([
            ['item_type' => 'manual', 'item_id' => null, 'item_name' => 'FORGED', 'notes' => '', 'qty' => 99.0],
        ]));
        $request = Request::create(
            "/pos/waiter/orders/{$order->id}/void-ticket",
            'GET',
            ['void_items' => $fakePayload]
        );

        $response = app(RestaurantWaiterController::class)->waiterVoidTicket($request, $order->id);

        // Must be a View (rendered ticket), never a RedirectResponse.
        $this->assertInstanceOf(View::class, $response);
        $this->assertTrue($response->getData()['void'], 'void flag must be true');

        // Void items come from DB — the forged query param must NOT appear.
        $items = $response->getData()['voidItems'];
        $this->assertCount(1, $items);
        $this->assertSame('Haleem', $items[0]['item_name'], 'must come from DB, not forged query param');
        $this->assertEquals(2.0, (float) $items[0]['qty']);
    }

    /**
     * A pos_waiter must NOT access another waiter's order in the same company
     * (same-company IDOR guard).
     */
    public function test_pos_waiter_denied_for_other_waiters_order_same_company(): void
    {
        $c = $this->makeCompany();
        $u1 = $this->makeUser($c, 'pos_waiter');

        // Second waiter in the SAME company owns a different cancelled order.
        $u2 = User::create(['company_id' => $c->id, 'name' => 'Other Waiter', 'pos_role' => 'pos_waiter', 'is_active' => true]);
        $order2 = RestaurantOrder::create([
            'company_id' => $c->id, 'order_number' => 'ORD-W002', 'source' => 'waiter',
            'status' => 'cancelled', 'created_by' => $u2->id,
        ]);
        $this->addPrintedItem($order2, 'Sajji', 1);

        // u1 authenticated — tries to access u2's order in same company.
        Auth::guard('pos')->setUser($u1);

        $request = Request::create("/pos/waiter/orders/{$order2->id}/void-ticket", 'GET');

        $this->expectException(\Illuminate\Database\Eloquent\ModelNotFoundException::class);
        app(RestaurantWaiterController::class)->waiterVoidTicket($request, $order2->id);
    }

    /**
     * A waiter must NOT access an order belonging to a different company —
     * cross-tenant IDOR guard.
     */
    public function test_pos_waiter_denied_for_other_company_order(): void
    {
        $c1 = $this->makeCompany();
        $u1 = $this->makeUser($c1, 'pos_waiter');

        $c2 = Company::create([
            'name' => 'Other Co', 'product_type' => 'pos', 'status' => 'active',
            'restaurant_mode' => true,
            'feature_flags' => ['tables' => true, 'kot' => true],
        ]);
        $u2 = User::create(['company_id' => $c2->id, 'name' => 'Other Waiter', 'pos_role' => 'pos_waiter', 'is_active' => true]);
        $order2 = RestaurantOrder::create([
            'company_id' => $c2->id, 'order_number' => 'ORD-C2', 'source' => 'waiter',
            'status' => 'cancelled', 'created_by' => $u2->id,
        ]);

        // currentCompanyId still bound to c1 — c2's order must 404.
        Auth::guard('pos')->setUser($u1);

        $request = Request::create("/pos/waiter/orders/{$order2->id}/void-ticket", 'GET');

        $this->expectException(\Illuminate\Database\Eloquent\ModelNotFoundException::class);
        app(RestaurantWaiterController::class)->waiterVoidTicket($request, $order2->id);
    }

    /**
     * A pos_cashier must NOT access any waiter void ticket — they are not an
     * owning waiter nor a supervisor. abort(403) is expected.
     */
    public function test_pos_cashier_denied_waiter_void_ticket(): void
    {
        $c = $this->makeCompany();
        $waiter = User::create(['company_id' => $c->id, 'name' => 'Waiter', 'pos_role' => 'pos_waiter', 'is_active' => true]);
        $order = RestaurantOrder::create([
            'company_id' => $c->id, 'order_number' => 'ORD-CAS', 'source' => 'waiter',
            'status' => 'cancelled', 'created_by' => $waiter->id,
        ]);
        $this->addPrintedItem($order, 'Nihari', 1);

        // Authenticate as cashier.
        $this->makeUser($c, 'pos_cashier');

        $request = Request::create("/pos/waiter/orders/{$order->id}/void-ticket", 'GET');

        $this->expectException(\Symfony\Component\HttpKernel\Exception\HttpException::class);
        app(RestaurantWaiterController::class)->waiterVoidTicket($request, $order->id);
    }

    /**
     * A pos_admin can view any cancelled waiter order in the company —
     * supervision use case; not confined to own orders.
     */
    public function test_pos_admin_can_view_any_cancelled_waiter_order(): void
    {
        $c = $this->makeCompany();
        $waiter = User::create(['company_id' => $c->id, 'name' => 'Waiter', 'pos_role' => 'pos_waiter', 'is_active' => true]);
        $order = RestaurantOrder::create([
            'company_id' => $c->id, 'order_number' => 'ORD-ADM', 'source' => 'waiter',
            'status' => 'cancelled', 'created_by' => $waiter->id,
        ]);
        $this->addPrintedItem($order, 'Karahi', 1);

        // Admin (NOT the order owner) authenticates.
        $admin = $this->makeUser($c, 'pos_admin');

        $request = Request::create("/pos/waiter/orders/{$order->id}/void-ticket", 'GET');
        $response = app(RestaurantWaiterController::class)->waiterVoidTicket($request, $order->id);

        $this->assertInstanceOf(View::class, $response);
        $this->assertCount(1, $response->getData()['voidItems']);
        $this->assertSame('Karahi', $response->getData()['voidItems'][0]['item_name']);
    }
}
