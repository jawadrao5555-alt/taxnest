<?php

namespace Tests\Feature;

use Tests\TestCase;
use App\Http\Controllers\RestaurantWaiterController;
use App\Models\Company;
use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * WAITER DELIVERY/TAKEAWAY SERVER-SIDE GATES — Tasks 527 & 534 (12 Aug 2026).
 *
 * The waiter UI hides the Delivery option entirely and hides Takeaway when the
 * admin toggle is OFF, but the SECURITY BOUNDARY is server-side in
 * RestaurantWaiterController::storeOrder:
 *
 *   1. order_type=delivery  → ALWAYS 403 for pos_waiter; admins/managers on
 *      the same tablet are exempt.
 *   2. order_type=takeaway  → 403 for pos_waiter when the company's
 *      pos_waiter_takeaway_enabled toggle is FALSE; allowed when TRUE
 *      (default ON — missing/NULL column fails OPEN); admins/managers exempt.
 *
 * Pattern: APP_ENV=testing + sqlite :memory: + minimal Schema::create,
 * controller invoked directly (same approach as PosDineInTableRequiredTest).
 */
class PosWaiterDeliveryTakeawayGateTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Schema::dropAllTables();

        // PosFeatureService caches restaurantAllowed per company id in a
        // STATIC — earlier suites' verdicts leak into this one. Reset it.
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
            $table->boolean('pos_waiter_takeaway_enabled')->nullable();
            $table->string('order_match_style')->nullable();
            $table->softDeletes();
            $table->timestamps();
        });

        Schema::create('users', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('company_id')->nullable();
            $table->string('name')->nullable();
            $table->string('email')->nullable();
            $table->string('password')->nullable();
            $table->string('role')->nullable();
            $table->string('pos_role')->nullable();
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
            $table->string('customer_name')->nullable();
            $table->string('customer_phone')->nullable();
            $table->decimal('subtotal', 12, 2)->default(0);
            $table->decimal('tax_amount', 12, 2)->default(0);
            $table->decimal('total_amount', 12, 2)->default(0);
            $table->string('kitchen_notes')->nullable();
            $table->boolean('priority')->default(false);
            $table->unsignedBigInteger('created_by')->nullable();
            $table->unsignedBigInteger('assigned_cashier_id')->nullable();
            $table->string('source')->default('waiter');
            $table->timestamp('kot_sent_at')->nullable();
            $table->integer('kot_print_count')->nullable();
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
    }

    // ── Seed helpers ─────────────────────────────────────────────────────

    /** is_internal_account=true → restaurantAllowed() passes without a plan fixture. */
    private function makeCompany(array $attrs = []): Company
    {
        $company = Company::create(array_merge([
            'name' => 'Gate Co',
            'product_type' => 'pos',
            'is_internal_account' => true,
            'restaurant_mode' => true,
            // tables OFF so no table_id needed anywhere in these tests;
            // kot+kitchen keep restaurantOn() true.
            'feature_flags' => ['tables' => false, 'kot' => true, 'kitchen' => true],
        ], $attrs));
        app()->instance('currentCompanyId', null); // clear any earlier binding
        app()->bind('currentCompanyId', fn () => $company->id);
        return $company;
    }

    private function makeUser(Company $c, string $posRole): User
    {
        $u = User::create([
            'company_id' => $c->id, 'name' => ucfirst($posRole),
            'pos_role' => $posRole, 'is_active' => true,
        ]);
        Auth::guard('pos')->setUser($u);
        return $u;
    }

    private function punch(string $orderType)
    {
        $request = Request::create('/pos/waiter/orders', 'POST', [
            'items' => [['name' => 'Bottle', 'quantity' => 1, 'unit_price' => 100]],
            'order_type' => $orderType,
        ]);
        return app(RestaurantWaiterController::class)->storeOrder($request);
    }

    // ── 1. Delivery: always blocked for waiters ──────────────────────────

    public function test_waiter_delivery_is_always_403(): void
    {
        $c = $this->makeCompany();
        $this->makeUser($c, 'pos_waiter');

        $res = $this->punch('delivery');

        $this->assertSame(403, $res->getStatusCode());
        $body = json_decode($res->getContent(), true);
        $this->assertFalse($body['success']);
        $this->assertSame(__('pos.waiter_delivery_not_allowed'), $body['message']);
        $this->assertSame(0, DB::table('restaurant_orders')->count(), 'no order/KOT may be created');
    }

    public function test_waiter_delivery_blocked_even_when_takeaway_toggle_on(): void
    {
        // Task 534: the takeaway toggle must have NO influence on delivery.
        $c = $this->makeCompany(['pos_waiter_takeaway_enabled' => true]);
        $this->makeUser($c, 'pos_waiter');

        $res = $this->punch('delivery');

        $this->assertSame(403, $res->getStatusCode());
        $this->assertSame(0, DB::table('restaurant_orders')->count());
    }

    public function test_admin_on_tablet_can_punch_delivery(): void
    {
        $c = $this->makeCompany();
        $this->makeUser($c, 'pos_admin');

        $res = $this->punch('delivery');

        $this->assertSame(200, $res->getStatusCode());
        $this->assertSame(1, DB::table('restaurant_orders')->where('order_type', 'delivery')->count());
    }

    public function test_manager_on_tablet_can_punch_delivery(): void
    {
        $c = $this->makeCompany();
        $this->makeUser($c, 'pos_manager');

        $res = $this->punch('delivery');

        $this->assertSame(200, $res->getStatusCode());
        $this->assertSame(1, DB::table('restaurant_orders')->where('order_type', 'delivery')->count());
    }

    // ── 2. Takeaway: admin-controlled toggle (waiters only) ─────────────

    public function test_waiter_takeaway_blocked_when_toggle_off(): void
    {
        $c = $this->makeCompany(['pos_waiter_takeaway_enabled' => false]);
        $this->makeUser($c, 'pos_waiter');

        $res = $this->punch('takeaway');

        $this->assertSame(403, $res->getStatusCode());
        $body = json_decode($res->getContent(), true);
        $this->assertFalse($body['success']);
        $this->assertSame(__('pos.waiter_takeaway_not_allowed'), $body['message']);
        $this->assertSame(0, DB::table('restaurant_orders')->count(), 'no order/KOT may be created');
    }

    public function test_waiter_takeaway_allowed_when_toggle_on(): void
    {
        $c = $this->makeCompany(['pos_waiter_takeaway_enabled' => true]);
        $this->makeUser($c, 'pos_waiter');

        $res = $this->punch('takeaway');

        $this->assertSame(200, $res->getStatusCode());
        $this->assertSame(1, DB::table('restaurant_orders')->where('order_type', 'takeaway')->count());
    }

    public function test_waiter_takeaway_fails_open_when_toggle_null(): void
    {
        // Default ON: existing companies without the column/value keep the
        // current behavior (missing column reads NULL → ?? true).
        $c = $this->makeCompany(); // pos_waiter_takeaway_enabled left NULL
        $this->makeUser($c, 'pos_waiter');

        $res = $this->punch('takeaway');

        $this->assertSame(200, $res->getStatusCode());
        $this->assertSame(1, DB::table('restaurant_orders')->where('order_type', 'takeaway')->count());
    }

    public function test_admin_takeaway_allowed_even_when_toggle_off(): void
    {
        $c = $this->makeCompany(['pos_waiter_takeaway_enabled' => false]);
        $this->makeUser($c, 'pos_admin');

        $res = $this->punch('takeaway');

        $this->assertSame(200, $res->getStatusCode());
        $this->assertSame(1, DB::table('restaurant_orders')->where('order_type', 'takeaway')->count());
    }

    public function test_manager_takeaway_allowed_even_when_toggle_off(): void
    {
        $c = $this->makeCompany(['pos_waiter_takeaway_enabled' => false]);
        $this->makeUser($c, 'pos_manager');

        $res = $this->punch('takeaway');

        $this->assertSame(200, $res->getStatusCode());
        $this->assertSame(1, DB::table('restaurant_orders')->where('order_type', 'takeaway')->count());
    }

    // ── 3. Append gate — Task 626 (13 Aug 2026) ──────────────────────────
    // Toggle OFF = waiter append to an EXISTING takeaway order is also 403
    // (Task 527's append-allow retired by owner decision). Dine-in appends
    // and admin/manager tablets stay untouched.

    private function seedHeldOrder(Company $c, string $orderType): int
    {
        return (int) DB::table('restaurant_orders')->insertGetId([
            'company_id' => $c->id,
            'order_number' => 'ORD-TEST-' . strtoupper(substr(md5($orderType . mt_rand()), 0, 5)),
            'order_type' => $orderType,
            'status' => 'held',
            'subtotal' => 100, 'tax_amount' => 0, 'total_amount' => 100,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function append(int $orderId)
    {
        $request = Request::create('/pos/waiter/orders/' . $orderId . '/items', 'POST', [
            'items' => [['name' => 'Extra Bottle', 'quantity' => 1, 'unit_price' => 50]],
        ]);
        return app(RestaurantWaiterController::class)->appendItems($request, $orderId);
    }

    public function test_waiter_append_to_takeaway_403_when_toggle_off(): void
    {
        $c = $this->makeCompany(['pos_waiter_takeaway_enabled' => false]);
        $this->makeUser($c, 'pos_waiter');
        $id = $this->seedHeldOrder($c, 'takeaway');

        $res = $this->append($id);

        $this->assertSame(403, $res->getStatusCode());
        $body = json_decode($res->getContent(), true);
        $this->assertFalse($body['success']);
        $this->assertSame(__('pos.waiter_takeaway_not_allowed'), $body['message']);
        $this->assertSame(0, DB::table('restaurant_order_items')->count(), 'no items may be appended');
        $this->assertEquals(100, DB::table('restaurant_orders')->where('id', $id)->value('subtotal'), 'totals untouched');
    }

    public function test_waiter_append_to_takeaway_allowed_when_toggle_on(): void
    {
        $c = $this->makeCompany(['pos_waiter_takeaway_enabled' => true]);
        $this->makeUser($c, 'pos_waiter');
        $id = $this->seedHeldOrder($c, 'takeaway');

        $res = $this->append($id);

        $this->assertSame(200, $res->getStatusCode());
        $this->assertSame(1, DB::table('restaurant_order_items')->where('order_id', $id)->count());
    }

    public function test_waiter_append_to_dine_in_allowed_even_when_toggle_off(): void
    {
        $c = $this->makeCompany(['pos_waiter_takeaway_enabled' => false]);
        $this->makeUser($c, 'pos_waiter');
        $id = $this->seedHeldOrder($c, 'dine_in');

        $res = $this->append($id);

        $this->assertSame(200, $res->getStatusCode());
        $this->assertSame(1, DB::table('restaurant_order_items')->where('order_id', $id)->count());
    }

    public function test_admin_append_to_takeaway_allowed_even_when_toggle_off(): void
    {
        $c = $this->makeCompany(['pos_waiter_takeaway_enabled' => false]);
        $this->makeUser($c, 'pos_admin');
        $id = $this->seedHeldOrder($c, 'takeaway');

        $res = $this->append($id);

        $this->assertSame(200, $res->getStatusCode());
        $this->assertSame(1, DB::table('restaurant_order_items')->where('order_id', $id)->count());
    }
}
