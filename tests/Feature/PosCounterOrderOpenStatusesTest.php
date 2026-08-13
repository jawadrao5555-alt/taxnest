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
}
