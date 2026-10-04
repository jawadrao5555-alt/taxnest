<?php

namespace Tests\Feature;

use App\Http\Controllers\RestaurantWaiterController;
use App\Models\PosTransaction;
use App\Models\RestaurantOrder;
use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * COUNTER-ORDER OPEN-STATUS REACHABILITY — Task 644 review fix (Aug 2026).
 *
 * The dashboard Pending-Bills tile counts TABLELESS waiter ("counter") orders
 * in EVERY open status (held/preparing/ready — the legacy KDS status route can
 * move held→preparing→ready) and routes them to the sale-screen bell panel,
 * which is their ONLY surface (owner rule 5 Aug 2026: counter orders never
 * appear on the table board/picker). The incoming/claim/settle trio therefore
 * must serve the SAME slice, or a counted order becomes an unreachable dead
 * end. This test locks:
 *
 *   1. A tableless waiter order is visible in the incoming feed AND claimable
 *      AND settleable in each of held / preparing / ready.
 *   2. Table-attached waiter orders keep the old held-only panel behaviour
 *      (preparing/ready table orders live on the Tables board, not the panel).
 *   3. The dashboard counterOrdersCount slice === the tableless orders served
 *      by the incoming feed (reachability parity).
 *   4. Single-winner claim survives the widening (assigned-elsewhere → 409).
 *
 * Pattern mirrors PosRestaurantOrderCancelTest: sqlite :memory: + minimal
 * Schema::create, controllers invoked directly with the currentCompanyId
 * container binding.
 *
 * Run:
 *   php vendor/bin/phpunit tests/Feature/PosCounterOrderOpenStatusesTest.php --testdox
 */
class PosCounterOrderOpenStatusesTest extends TestCase
{
    protected int $companyId;

    protected function setUp(): void
    {
        parent::setUp();

        Schema::dropAllTables();

        Schema::create('companies', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->softDeletes();
            $table->timestamps();
        });

        Schema::create('users', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('company_id')->nullable();
            $table->string('name')->nullable();
            $table->string('role')->nullable();
            $table->string('pos_role')->nullable();
            $table->text('pos_custom_access')->nullable();
            $table->timestamps();
        });

        Schema::create('restaurant_orders', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('company_id');
            $table->unsignedBigInteger('pos_transaction_id')->nullable();
            $table->string('order_number')->nullable();
            $table->unsignedBigInteger('table_id')->nullable();
            $table->string('order_type')->nullable();
            $table->string('source')->nullable();
            $table->string('status');
            $table->string('payment_method')->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->unsignedBigInteger('assigned_cashier_id')->nullable();
            $table->string('customer_name')->nullable();
            $table->string('customer_phone')->nullable();
            $table->string('kitchen_notes')->nullable();
            $table->integer('token_no')->nullable();
            $table->decimal('subtotal', 12, 2)->default(0);
            $table->decimal('total_amount', 12, 2)->default(0);
            $table->timestamp('kot_sent_at')->nullable();
            $table->timestamps();
        });

        Schema::create('restaurant_order_items', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('order_id')->nullable();
            $table->string('item_type')->nullable();
            $table->unsignedBigInteger('item_id')->nullable();
            $table->string('item_name')->nullable();
            $table->decimal('quantity', 12, 3)->default(1);
            $table->decimal('unit_price', 12, 2)->default(0);
            $table->decimal('subtotal', 12, 2)->default(0);
            $table->string('special_notes')->nullable();
            $table->boolean('is_tax_exempt')->default(false);
            $table->timestamp('kot_printed_at')->nullable();
            $table->timestamps();
        });

        Schema::create('restaurant_tables', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('company_id');
            $table->string('table_number')->nullable();
            $table->string('status')->nullable();
            $table->unsignedBigInteger('locked_by_user_id')->nullable();
            $table->timestamp('locked_at')->nullable();
            $table->timestamp('occupied_since')->nullable();
            $table->timestamps();
        });

        $this->companyId = DB::table('companies')->insertGetId([
            'name' => 'Counter House',
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

    protected function makeUser(string $posRole): User
    {
        DB::table('users')->insert([
            'company_id' => $this->companyId,
            'name' => 'U-' . $posRole . '-' . uniqid(),
            'role' => 'user',
            'pos_role' => $posRole,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        return User::orderByDesc('id')->first();
    }

    protected function actAs(User $user): User
    {
        Auth::guard('pos')->setUser($user);

        return $user;
    }

    protected function order(array $attrs = []): int
    {
        $id = DB::table('restaurant_orders')->insertGetId(array_merge([
            'company_id' => $this->companyId,
            'order_number' => 'R-' . uniqid(),
            'status' => 'held',
            'source' => 'waiter',
            'table_id' => null,
            'total_amount' => 500,
            'created_at' => now(), 'updated_at' => now(),
        ], $attrs));
        DB::table('restaurant_order_items')->insert([
            'order_id' => $id,
            'item_type' => 'product',
            'item_id' => 1,
            'item_name' => 'Chai',
            'quantity' => 1,
            'unit_price' => 500,
            'subtotal' => 500,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        return $id;
    }

    protected function table(): int
    {
        return DB::table('restaurant_tables')->insertGetId([
            'company_id' => $this->companyId,
            'table_number' => (string) random_int(1, 99),
            'status' => 'occupied',
            'occupied_since' => now(),
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    protected function feedIds(): array
    {
        return collect((new RestaurantWaiterController())->incomingOrders()->getData())
            ->pluck('id')->map(fn ($id) => (int) $id)->all();
    }

    /** The dashboard's counterOrdersCount slice (RestaurantPosController::dashboard). */
    protected function dashboardCounterIds(): array
    {
        return RestaurantOrder::where('company_id', $this->companyId)
            ->whereIn('status', ['held', 'preparing', 'ready'])
            ->where('source', 'waiter')
            ->whereNull('table_id')
            ->pluck('id')->map(fn ($id) => (int) $id)->all();
    }

    protected function makeTxn(int $id = 9301): PosTransaction
    {
        $txn = new PosTransaction(['payment_method' => 'cash']);
        $txn->id = $id;
        $txn->company_id = $this->companyId;

        return $txn;
    }

    // ── 1. every counted status is visible + claimable + settleable ─────────

    public function test_tableless_counter_order_reachable_in_every_open_status(): void
    {
        $admin = $this->actAs($this->makeUser('pos_admin'));

        foreach (['held', 'preparing', 'ready'] as $i => $status) {
            $orderId = $this->order(['status' => $status]);

            // Visible in the bell-panel feed…
            $this->assertContains($orderId, $this->feedIds(), "counter order in '$status' missing from incoming feed");

            // …claimable…
            $claim = (new RestaurantWaiterController())->claimIncoming(Request::create('/', 'POST'), $orderId);
            $this->assertTrue($claim->getData()->success, "counter order in '$status' not claimable");
            $this->assertSame($admin->id, (int) DB::table('restaurant_orders')->find($orderId)->assigned_cashier_id);

            // …and settleable (shared storeInvoice/completeIncoming path).
            $ok = RestaurantWaiterController::settleWaiterOrder($this->companyId, $orderId, $this->makeTxn(9301 + $i), $admin);
            $this->assertTrue($ok, "counter order in '$status' not settleable");
            $row = DB::table('restaurant_orders')->find($orderId);
            $this->assertSame('completed', $row->status);
            $this->assertSame(9301 + $i, (int) $row->pos_transaction_id);
        }
    }

    // ── 2. table-attached orders keep held-only panel behaviour ─────────────

    public function test_table_attached_preparing_order_stays_off_the_panel(): void
    {
        $this->actAs($this->makeUser('pos_admin'));

        $heldOnTable = $this->order(['table_id' => $this->table()]);                            // held
        $preparingOnTable = $this->order(['table_id' => $this->table(), 'status' => 'preparing']);

        $feed = $this->feedIds();
        $this->assertContains($heldOnTable, $feed, 'held table order should still reach the panel');
        $this->assertNotContains($preparingOnTable, $feed, 'preparing TABLE order belongs to the Tables board, not the panel');
    }

    // ── 3. dashboard slice ↔ feed reachability parity ────────────────────────

    public function test_every_dashboard_counted_counter_order_is_in_the_feed(): void
    {
        $this->actAs($this->makeUser('pos_admin'));

        $counted = [
            $this->order(),                                  // tableless held
            $this->order(['status' => 'preparing']),         // tableless preparing
            $this->order(['status' => 'ready']),             // tableless ready
        ];
        $this->order(['table_id' => $this->table()]);                          // table held — not counted
        $this->order(['table_id' => $this->table(), 'status' => 'ready']);     // table ready — not counted
        $this->order(['status' => 'completed']);                               // settled — not counted
        $this->order(['status' => 'cancelled']);                               // cancelled — not counted

        $dashboardSlice = $this->dashboardCounterIds();
        sort($counted);
        sort($dashboardSlice);
        $this->assertSame($counted, $dashboardSlice, 'dashboard counter slice drifted from the fixture');

        $feed = $this->feedIds();
        foreach ($dashboardSlice as $id) {
            $this->assertContains($id, $feed, "dashboard-counted counter order $id unreachable in the feed — dead end");
        }
    }

    // ── 4. single-winner claim survives the widening ─────────────────────────

    public function test_claim_stays_single_winner_for_non_held_counter_order(): void
    {
        $cashierA = $this->makeUser('pos_cashier');
        $cashierB = $this->makeUser('pos_cashier');
        $orderId = $this->order(['status' => 'ready', 'assigned_cashier_id' => $cashierA->id]);

        $this->actAs($cashierB);
        $claim = (new RestaurantWaiterController())->claimIncoming(Request::create('/', 'POST'), $orderId);

        $this->assertSame(409, $claim->getStatusCode(), 'assigned-elsewhere ready order must stay 409 for another cashier');
        $this->assertSame($cashierA->id, (int) DB::table('restaurant_orders')->find($orderId)->assigned_cashier_id);
    }

    public function test_three_occupied_waiter_tables_open_with_items_in_every_kitchen_status(): void
    {
        $cashier = $this->actAs($this->makeUser('pos_cashier'));
        foreach (['held', 'preparing', 'ready'] as $status) {
            $table = $this->table();
            $id = $this->order(['table_id' => $table, 'order_type' => 'dine_in', 'status' => $status]);
            $claim = (new RestaurantWaiterController())->claimIncoming(Request::create('/', 'POST'), $id);
            $this->assertSame(200, $claim->getStatusCode(), "$status table must open from the board");
            $json = $claim->getData();
            $this->assertTrue($json->success);
            $this->assertSame($id, $json->order->id);
            $this->assertSame($table, $json->order->table_id);
            $this->assertSame('Chai', $json->order->items[0]->name);
            $this->assertSame(500, (int) $json->order->total_amount);
            $this->assertSame($status, DB::table('restaurant_orders')->find($id)->status);
            $this->assertSame('occupied', DB::table('restaurant_tables')->find($table)->status);
            $this->assertSame($cashier->id, (int) DB::table('restaurant_orders')->find($id)->assigned_cashier_id);
            // Reopening one's own order is safe; it does not create a new order/KOT.
            $this->assertSame(200, (new RestaurantWaiterController())->claimIncoming(Request::create('/', 'POST'), $id)->getStatusCode());
        }
        $this->assertSame(3, DB::table('restaurant_orders')->count());
        $this->assertSame(3, DB::table('restaurant_order_items')->count());
    }

    public function test_table_waiter_settles_after_kitchen_transition_once_and_keeps_sibling_occupied(): void
    {
        $cashier = $this->actAs($this->makeUser('pos_cashier'));
        $table = $this->table();
        $id = $this->order(['table_id' => $table, 'assigned_cashier_id' => $cashier->id]);
        $sibling = $this->order(['table_id' => $table, 'status' => 'ready']);
        DB::table('restaurant_orders')->where('id', $id)->update(['status' => 'preparing']);
        $this->assertTrue(RestaurantWaiterController::settleWaiterOrder($this->companyId, $id, $this->makeTxn(), $cashier));
        $this->assertFalse(RestaurantWaiterController::settleWaiterOrder($this->companyId, $id, $this->makeTxn(9999), $cashier));
        $this->assertSame('occupied', DB::table('restaurant_tables')->find($table)->status);
        $this->assertTrue(RestaurantWaiterController::settleWaiterOrder($this->companyId, $sibling, $this->makeTxn(9302), $cashier));
        $this->assertSame('available', DB::table('restaurant_tables')->find($table)->status);
        $this->assertSame(9301, (int) DB::table('restaurant_orders')->find($id)->pos_transaction_id);
    }

    public function test_ready_table_claim_and_settle_preserve_cashier_and_tenant_boundaries(): void
    {
        $owner = $this->makeUser('pos_cashier');
        $other = $this->actAs($this->makeUser('pos_cashier'));
        $id = $this->order(['table_id' => $this->table(), 'status' => 'ready', 'assigned_cashier_id' => $owner->id]);
        $controller = new RestaurantWaiterController();
        $this->assertSame(409, $controller->claimIncoming(Request::create('/', 'POST'), $id)->getStatusCode());
        $this->assertFalse(RestaurantWaiterController::settleWaiterOrder($this->companyId, $id, $this->makeTxn(), $other));
        $this->assertSame($owner->id, (int) DB::table('restaurant_orders')->find($id)->assigned_cashier_id);
        $foreign = $this->order(['company_id' => $this->companyId + 1, 'table_id' => $this->table(), 'status' => 'ready']);
        $admin = $this->actAs($this->makeUser('pos_admin'));
        $this->assertSame(409, $controller->claimIncoming(Request::create('/', 'POST'), $foreign)->getStatusCode());
        $this->assertFalse(RestaurantWaiterController::settleWaiterOrder($this->companyId, $foreign, $this->makeTxn(), $admin));
        $this->assertNull(DB::table('restaurant_orders')->find($foreign)->assigned_cashier_id);
        // The existing explicit admin rescue policy still works within this tenant.
        $this->assertSame(200, $controller->claimIncoming(Request::create('/', 'POST'), $id)->getStatusCode());
        $this->assertTrue(RestaurantWaiterController::settleWaiterOrder($this->companyId, $id, $this->makeTxn(), $admin));
    }

    public function test_closed_table_orders_cannot_be_claimed_or_settled_again(): void
    {
        $admin = $this->actAs($this->makeUser('pos_admin'));
        foreach (['completed', 'cancelled'] as $status) {
            $id = $this->order(['table_id' => $this->table(), 'status' => $status]);
            $this->assertSame(409, (new RestaurantWaiterController())->claimIncoming(Request::create('/', 'POST'), $id)->getStatusCode());
            $this->assertFalse(RestaurantWaiterController::settleWaiterOrder($this->companyId, $id, $this->makeTxn(), $admin));
            $this->assertSame($status, DB::table('restaurant_orders')->find($id)->status);
        }
    }

    public function test_ready_table_online_payment_still_requires_confirmation(): void
    {
        Schema::table('restaurant_orders', fn (Blueprint $t) => $t->timestamp('online_payment_awaited_at')->nullable());
        $cashier = $this->actAs($this->makeUser('pos_cashier'));
        $id = $this->order(['table_id' => $this->table(), 'status' => 'ready', 'online_payment_awaited_at' => now()]);
        $this->assertFalse(RestaurantWaiterController::settleWaiterOrder($this->companyId, $id, $this->makeTxn(), $cashier));
        $this->assertSame('ready', DB::table('restaurant_orders')->find($id)->status);
        $this->assertTrue(RestaurantWaiterController::settleWaiterOrder($this->companyId, $id, $this->makeTxn(), $cashier, true));
        $this->assertNull(DB::table('restaurant_orders')->find($id)->online_payment_awaited_at);
    }

    public function test_preparing_table_stale_edit_revision_is_not_settled(): void
    {
        Schema::table('restaurant_orders', fn (Blueprint $t) => $t->unsignedInteger('edit_revision')->default(0));
        $cashier = $this->actAs($this->makeUser('pos_cashier'));
        $id = $this->order(['table_id' => $this->table(), 'status' => 'preparing', 'edit_revision' => 2]);
        $this->assertFalse(RestaurantWaiterController::settleWaiterOrder($this->companyId, $id, $this->makeTxn(), $cashier, false, 1));
        $this->assertTrue(RestaurantWaiterController::settleWaiterOrder($this->companyId, $id, $this->makeTxn(), $cashier, false, 2));
    }

    public function test_assigned_table_returns_actionable_conflict_without_leaking_items(): void
    {
        $owner = $this->makeUser('pos_admin');
        $this->actAs($this->makeUser('pos_cashier'));
        $id = $this->order(['table_id' => $this->table(), 'assigned_cashier_id' => $owner->id]);
        $controller = app(RestaurantWaiterController::class);
        $response = $controller->claimIncoming(Request::create('/', 'POST', ['allow_reassign' => false]), $id);
        $data = $response->getData(true);
        $this->assertSame(409, $response->getStatusCode());
        $this->assertSame('cashier_assignment_conflict', $data['code']);
        $this->assertSame($owner->name, $data['assigned_cashier']);
        $this->assertSame([], $data['cashiers']);
        $this->assertArrayNotHasKey('items', $data);
        $this->actAs($this->makeUser('pos_manager'));
        $response = $controller->claimIncoming(Request::create('/', 'POST', ['allow_reassign' => false]), $id);
        $this->assertSame(409, $response->getStatusCode(), 'New UI never silently steals an assigned order');
        $this->assertSame($owner->id, DB::table('restaurant_orders')->find($id)->assigned_cashier_id);
        $this->assertNotEmpty($response->getData(true)['cashiers']);
    }

    private function handoffSchema(): void
    {
        Schema::table('restaurant_orders', fn (Blueprint $t) => $t->unsignedInteger('edit_revision')->default(0));
        Schema::create('audit_logs', function (Blueprint $t) {
            $t->id(); $t->unsignedBigInteger('company_id'); $t->unsignedBigInteger('user_id');
            $t->string('action'); $t->string('entity_type'); $t->unsignedBigInteger('entity_id');
            $t->text('old_values'); $t->text('new_values'); $t->string('ip_address')->nullable();
            $t->string('sha256_hash'); $t->timestamp('created_at');
        });
    }

    public function test_manager_handoff_is_audited_and_fences_stale_carts_and_repeat_requests(): void
    {
        $this->handoffSchema();
        $old = $this->makeUser('pos_cashier'); $target = $this->makeUser('pos_cashier');
        $manager = $this->actAs($this->makeUser('pos_manager'));
        $id = $this->order(['table_id' => $this->table(), 'assigned_cashier_id' => $old->id]);
        $request = Request::create('/', 'POST', ['cashier_id' => $target->id, 'assigned_cashier_id' => $old->id, 'revision' => 0]);
        $controller = app(RestaurantWaiterController::class);
        $this->assertSame(200, $controller->transferIncoming($request, $id)->getStatusCode());
        $this->assertSame(409, $controller->transferIncoming($request, $id)->getStatusCode());
        $row = DB::table('restaurant_orders')->find($id);
        $this->assertSame($target->id, $row->assigned_cashier_id); $this->assertSame(1, $row->edit_revision);
        $this->assertSame('held', $row->status);
        $this->assertSame(1, DB::table('audit_logs')->where('action', 'waiter_order_cashier_transferred')->count());
        $this->assertFalse(RestaurantWaiterController::settleWaiterOrder($this->companyId, $id, $this->makeTxn(), $old, false, 0));
        $this->assertFalse(RestaurantWaiterController::settleWaiterOrder($this->companyId, $id, $this->makeTxn(), $manager, false, 0));
        $this->actAs($target);
        $this->assertSame(200, $controller->claimIncoming(Request::create('/', 'POST', ['allow_reassign' => false]), $id)->getStatusCode());
        $this->assertTrue(RestaurantWaiterController::settleWaiterOrder($this->companyId, $id, $this->makeTxn(), $target, false, 1));
    }

    public function test_cashier_cannot_transfer_an_order(): void
    {
        $this->actAs($this->makeUser('pos_cashier'));
        try {
            app(RestaurantWaiterController::class)->transferIncoming(Request::create('/', 'POST'), $this->order());
            $this->fail('Cashier transfer must be forbidden');
        } catch (\Symfony\Component\HttpKernel\Exception\HttpException $e) {
            $this->assertSame(403, $e->getStatusCode());
        }
    }

    public function test_manager_cannot_transfer_to_a_foreign_cashier_or_a_closed_order(): void
    {
        $this->handoffSchema();
        $old = $this->makeUser('pos_cashier'); $target = $this->makeUser('pos_cashier');
        $this->actAs($this->makeUser('pos_manager'));
        $id = $this->order(['assigned_cashier_id' => $old->id, 'status' => 'completed']);
        $request = Request::create('/', 'POST', ['cashier_id' => $target->id, 'assigned_cashier_id' => $old->id, 'revision' => 0]);
        $this->assertSame(409, app(RestaurantWaiterController::class)->transferIncoming($request, $id)->getStatusCode());
        DB::table('users')->where('id', $target->id)->update(['company_id' => 99999]);
        try {
            app(RestaurantWaiterController::class)->transferIncoming($request, $id);
            $this->fail('Foreign cashier must be rejected');
        } catch (\Symfony\Component\HttpKernel\Exception\HttpException $e) {
            $this->assertSame(422, $e->getStatusCode());
        }
        $this->assertSame(0, DB::table('audit_logs')->count());
    }
}
