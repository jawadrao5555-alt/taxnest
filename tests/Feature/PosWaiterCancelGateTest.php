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
 * WAITER SELF-CANCEL SERVER-SIDE GATE — Task 527 (12 Aug 2026), tests Task 544.
 *
 * Waiter self-cancel (Task 412) became an admin-controlled permission in
 * Task 527: companies.pos_waiter_cancel_enabled, DEFAULT OFF (missing/NULL
 * column fails CLOSED — the opposite polarity of the takeaway toggle).
 * The security boundary is server-side in
 * RestaurantWaiterController::cancelOrder:
 *
 *   1. pos_waiter role → 403 when pos_waiter_cancel_enabled is FALSE or
 *      NULL (default OFF); allowed only when TRUE. Order must stay 'held'.
 *   2. Admins/managers using the same tablet are NEVER blocked by the toggle.
 *
 * Pattern: APP_ENV=testing + sqlite :memory: + minimal Schema::create,
 * controller invoked directly (same as PosWaiterDeliveryTakeawayGateTest).
 */
class PosWaiterCancelGateTest extends TestCase
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
            $table->boolean('pos_waiter_cancel_enabled')->nullable();
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
    }

    // ── Seed helpers ─────────────────────────────────────────────────────

    /** is_internal_account=true → restaurantAllowed() passes without a plan fixture. */
    private function makeCompany(array $attrs = []): Company
    {
        $company = Company::create(array_merge([
            'name' => 'Cancel Gate Co',
            'product_type' => 'pos',
            'is_internal_account' => true,
            'restaurant_mode' => true,
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

    /** Held, un-claimed waiter order owned by $u — the cancellable shape. */
    private function seedOrder(Company $c, User $u): int
    {
        return DB::table('restaurant_orders')->insertGetId([
            'company_id' => $c->id,
            'order_number' => 'W-001',
            'order_type' => 'takeaway',
            'status' => 'held',
            'source' => 'waiter',
            'created_by' => $u->id,
            'assigned_cashier_id' => null,
            'total_amount' => 100,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function cancel(int $orderId)
    {
        $request = Request::create("/pos/waiter/orders/{$orderId}/cancel", 'POST');
        return app(RestaurantWaiterController::class)->cancelOrder($request, $orderId);
    }

    // ── 1. Waiter: admin-controlled toggle, DEFAULT OFF ─────────────────

    public function test_waiter_cancel_blocked_when_toggle_off(): void
    {
        $c = $this->makeCompany(['pos_waiter_cancel_enabled' => false]);
        $u = $this->makeUser($c, 'pos_waiter');
        $id = $this->seedOrder($c, $u);

        $res = $this->cancel($id);

        $this->assertSame(403, $res->getStatusCode());
        $body = json_decode($res->getContent(), true);
        $this->assertFalse($body['success']);
        $this->assertSame(__('pos.waiter_cancel_not_allowed'), $body['message']);
        $this->assertSame('held', DB::table('restaurant_orders')->where('id', $id)->value('status'),
            'order must remain held — nothing may be cancelled');
    }

    public function test_waiter_cancel_blocked_when_toggle_null_default_off(): void
    {
        // Default OFF: missing/NULL column fails CLOSED (?? false) — the
        // opposite polarity of the takeaway toggle. This is the regression
        // this suite exists to lock.
        $c = $this->makeCompany(); // pos_waiter_cancel_enabled left NULL
        $u = $this->makeUser($c, 'pos_waiter');
        $id = $this->seedOrder($c, $u);

        $res = $this->cancel($id);

        $this->assertSame(403, $res->getStatusCode());
        $this->assertSame('held', DB::table('restaurant_orders')->where('id', $id)->value('status'));
    }

    public function test_waiter_cancel_allowed_when_toggle_on(): void
    {
        $c = $this->makeCompany(['pos_waiter_cancel_enabled' => true]);
        $u = $this->makeUser($c, 'pos_waiter');
        $id = $this->seedOrder($c, $u);

        $res = $this->cancel($id);

        $this->assertSame(200, $res->getStatusCode());
        $body = json_decode($res->getContent(), true);
        $this->assertTrue($body['success']);
        $row = DB::table('restaurant_orders')->where('id', $id)->first();
        $this->assertSame('cancelled', $row->status);
        $this->assertNotNull($row->cancelled_at);
        $this->assertEquals($u->id, $row->cancelled_by);
    }

    // ── 2. Admins/managers on the tablet: never blocked by the toggle ───

    public function test_admin_cancel_allowed_even_when_toggle_off(): void
    {
        $c = $this->makeCompany(['pos_waiter_cancel_enabled' => false]);
        $u = $this->makeUser($c, 'pos_admin');
        $id = $this->seedOrder($c, $u);

        $res = $this->cancel($id);

        $this->assertSame(200, $res->getStatusCode());
        $this->assertSame('cancelled', DB::table('restaurant_orders')->where('id', $id)->value('status'));
    }

    public function test_manager_cancel_allowed_even_when_toggle_off(): void
    {
        $c = $this->makeCompany(['pos_waiter_cancel_enabled' => false]);
        $u = $this->makeUser($c, 'pos_manager');
        $id = $this->seedOrder($c, $u);

        $res = $this->cancel($id);

        $this->assertSame(200, $res->getStatusCode());
        $this->assertSame('cancelled', DB::table('restaurant_orders')->where('id', $id)->value('status'));
    }
}
