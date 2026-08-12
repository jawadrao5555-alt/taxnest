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
 * WAITER CANCEL — CLAIM/SETTLE RACE SAFETY — Task 548.
 *
 * RestaurantWaiterController::cancelOrder flips an order to 'cancelled'
 * through a single atomic conditional UPDATE: only the waiter's OWN,
 * still-'held', UN-CLAIMED (assigned_cashier_id NULL) waiter order may
 * cancel — anything else gets 409 and the row stays untouched. This
 * guarantees a cashier's loaded cart never has its order vanish from
 * underneath it. Task 544 locked the permission-toggle gate
 * (PosWaiterCancelGateTest); this suite locks the race guard itself:
 *
 *   1. Claimed order (assigned_cashier_id set) → 409, still 'held'.
 *   2. Settled/completed (or otherwise non-'held') order → 409.
 *   3. Another waiter's order → 409.
 *   4. Table-free: after a successful cancel the table flips to
 *      'available' only when no other live order sits on it.
 *
 * Pattern: APP_ENV=testing + sqlite :memory: + minimal Schema::create,
 * controller invoked directly (same as PosWaiterCancelGateTest).
 */
class PosWaiterCancelRaceTest extends TestCase
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

        Schema::create('restaurant_tables', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('company_id');
            $table->string('name')->nullable();
            $table->string('status')->default('available');
            $table->unsignedBigInteger('locked_by_user_id')->nullable();
            $table->timestamp('locked_at')->nullable();
            $table->timestamp('occupied_since')->nullable();
            $table->timestamps();
        });
    }

    // ── Seed helpers ─────────────────────────────────────────────────────

    /** Toggle ON so the permission gate never interferes with race tests. */
    private function makeCompany(array $attrs = []): Company
    {
        $company = Company::create(array_merge([
            'name' => 'Cancel Race Co',
            'product_type' => 'pos',
            'is_internal_account' => true,
            'restaurant_mode' => true,
            'feature_flags' => ['tables' => true, 'kot' => true, 'kitchen' => true],
            'pos_waiter_cancel_enabled' => true,
        ], $attrs));
        app()->instance('currentCompanyId', null); // clear any earlier binding
        app()->bind('currentCompanyId', fn () => $company->id);
        return $company;
    }

    private function makeUser(Company $c, string $posRole, ?string $name = null): User
    {
        $u = User::create([
            'company_id' => $c->id, 'name' => $name ?? ucfirst($posRole),
            'pos_role' => $posRole, 'is_active' => true,
        ]);
        Auth::guard('pos')->setUser($u);
        return $u;
    }

    private function seedOrder(Company $c, User $owner, array $attrs = []): int
    {
        return DB::table('restaurant_orders')->insertGetId(array_merge([
            'company_id' => $c->id,
            'order_number' => 'W-001',
            'order_type' => 'takeaway',
            'status' => 'held',
            'source' => 'waiter',
            'created_by' => $owner->id,
            'assigned_cashier_id' => null,
            'total_amount' => 100,
            'created_at' => now(), 'updated_at' => now(),
        ], $attrs));
    }

    private function seedTable(Company $c, string $status = 'occupied'): int
    {
        return DB::table('restaurant_tables')->insertGetId([
            'company_id' => $c->id,
            'name' => 'T1',
            'status' => $status,
            'occupied_since' => now(),
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function cancel(int $orderId)
    {
        $request = Request::create("/pos/waiter/orders/{$orderId}/cancel", 'POST');
        return app(RestaurantWaiterController::class)->cancelOrder($request, $orderId);
    }

    private function assertConflictUntouched($res, int $id, string $expectedStatus = 'held'): void
    {
        $this->assertSame(409, $res->getStatusCode());
        $body = json_decode($res->getContent(), true);
        $this->assertFalse($body['success']);
        $row = DB::table('restaurant_orders')->where('id', $id)->first();
        $this->assertSame($expectedStatus, $row->status,
            'order row must be untouched — the atomic UPDATE must not fire');
        $this->assertNull($row->cancelled_at);
        $this->assertNull($row->cancelled_by);
    }

    // ── 1. Claimed order: cashier ne pakar liya → 409, still held ────────

    public function test_claimed_order_cannot_be_cancelled_by_waiter(): void
    {
        $c = $this->makeCompany();
        $waiter = $this->makeUser($c, 'pos_waiter');
        $cashier = User::create(['company_id' => $c->id, 'name' => 'Cashier', 'pos_role' => 'pos_cashier', 'is_active' => true]);
        $id = $this->seedOrder($c, $waiter, ['assigned_cashier_id' => $cashier->id]);

        $res = $this->cancel($id);

        $this->assertConflictUntouched($res, $id);
        $this->assertEquals($cashier->id,
            DB::table('restaurant_orders')->where('id', $id)->value('assigned_cashier_id'),
            'claim must survive the failed cancel');
    }

    public function test_claimed_order_blocked_even_for_admin(): void
    {
        // The race guard sits BELOW the role/toggle gate — even roles that
        // bypass the permission toggle cannot cancel a claimed order.
        $c = $this->makeCompany(['pos_waiter_cancel_enabled' => false]);
        $admin = $this->makeUser($c, 'pos_admin');
        $id = $this->seedOrder($c, $admin, ['assigned_cashier_id' => 999]);

        $res = $this->cancel($id);

        $this->assertConflictUntouched($res, $id);
    }

    // ── 2. Settled / completed / non-held order → 409 ───────────────────

    public function test_completed_order_cannot_be_cancelled(): void
    {
        $c = $this->makeCompany();
        $waiter = $this->makeUser($c, 'pos_waiter');
        $id = $this->seedOrder($c, $waiter, ['status' => 'completed']);

        $res = $this->cancel($id);

        $this->assertConflictUntouched($res, $id, 'completed');
    }

    public function test_already_cancelled_order_returns_conflict(): void
    {
        $c = $this->makeCompany();
        $waiter = $this->makeUser($c, 'pos_waiter');
        $id = $this->seedOrder($c, $waiter, ['status' => 'cancelled']);

        $res = $this->cancel($id);

        $this->assertSame(409, $res->getStatusCode());
        $this->assertSame('cancelled', DB::table('restaurant_orders')->where('id', $id)->value('status'));
    }

    // ── 3. Another waiter's order → 409 ─────────────────────────────────

    public function test_other_waiters_order_cannot_be_cancelled(): void
    {
        $c = $this->makeCompany();
        $other = User::create(['company_id' => $c->id, 'name' => 'Other Waiter', 'pos_role' => 'pos_waiter', 'is_active' => true]);
        $id = $this->seedOrder($c, $other); // owned by the OTHER waiter

        $this->makeUser($c, 'pos_waiter', 'Acting Waiter'); // authenticated actor

        $res = $this->cancel($id);

        $this->assertConflictUntouched($res, $id);
    }

    public function test_other_companys_order_cannot_be_cancelled(): void
    {
        $c1 = $this->makeCompany(['name' => 'Other Co']);
        $foreignWaiter = User::create(['company_id' => $c1->id, 'name' => 'Foreign', 'pos_role' => 'pos_waiter', 'is_active' => true]);
        $id = $this->seedOrder($c1, $foreignWaiter);

        $c2 = $this->makeCompany(['name' => 'Acting Co']); // rebinds currentCompanyId
        $waiter = $this->makeUser($c2, 'pos_waiter');
        DB::table('restaurant_orders')->where('id', $id)->update(['created_by' => $waiter->id]);

        $res = $this->cancel($id);

        $this->assertConflictUntouched($res, $id);
    }

    // ── 4. Table-free logic ──────────────────────────────────────────────

    public function test_table_freed_when_no_other_live_order(): void
    {
        $c = $this->makeCompany();
        $waiter = $this->makeUser($c, 'pos_waiter');
        $tableId = $this->seedTable($c);
        $id = $this->seedOrder($c, $waiter, ['order_type' => 'dine_in', 'table_id' => $tableId]);

        $res = $this->cancel($id);

        $this->assertSame(200, $res->getStatusCode());
        $this->assertSame('cancelled', DB::table('restaurant_orders')->where('id', $id)->value('status'));
        $table = DB::table('restaurant_tables')->where('id', $tableId)->first();
        $this->assertSame('available', $table->status);
        $this->assertNull($table->locked_by_user_id);
        $this->assertNull($table->occupied_since);
    }

    public function test_table_stays_occupied_when_another_live_order_exists(): void
    {
        $c = $this->makeCompany();
        $waiter = $this->makeUser($c, 'pos_waiter');
        $tableId = $this->seedTable($c);
        $id = $this->seedOrder($c, $waiter, ['order_type' => 'dine_in', 'table_id' => $tableId]);
        // A second live order (preparing) still sits on the same table.
        $this->seedOrder($c, $waiter, [
            'order_number' => 'W-002', 'order_type' => 'dine_in',
            'table_id' => $tableId, 'status' => 'preparing',
        ]);

        $res = $this->cancel($id);

        $this->assertSame(200, $res->getStatusCode());
        $this->assertSame('occupied', DB::table('restaurant_tables')->where('id', $tableId)->value('status'),
            'table must stay occupied while another live order uses it');
    }
}
