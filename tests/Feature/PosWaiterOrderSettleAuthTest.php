<?php

namespace Tests\Feature;

use Tests\TestCase;
use App\Http\Controllers\RestaurantWaiterController;
use App\Models\PosTransaction;
use App\Models\RestaurantOrder;
use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * WAITER-ORDER SETTLE AUTHORIZATION LOCK — Task 646 (Aug 2026).
 *
 * settleWaiterOrder() is the shared settle used by BOTH
 * RestaurantWaiterController::completeIncoming (client fallback) and
 * PosController::storeInvoice (pre-response settle so the first receipt can
 * print the waiter's name). The order id is CLIENT-SUPPLIED on both paths, so
 * the helper itself is the security boundary. Policy mirrors claimIncoming:
 *
 *   • assignee cashier          → settles (status completed + txn linked + table freed)
 *   • unassigned order          → any cashier settles
 *   • ANOTHER cashier           → refused; order untouched, table stays occupied
 *   • POS admin/manager         → may settle any (off-shift-cashier rescue)
 *   • already settled           → refused (atomic single-winner claim)
 *
 * Run:
 *   php vendor/bin/phpunit tests/Feature/PosWaiterOrderSettleAuthTest.php --testdox
 */
class PosWaiterOrderSettleAuthTest extends TestCase
{
    private const COMPANY_ID = 521;
    private const TABLE_ID   = 31;

    protected function setUp(): void
    {
        parent::setUp();
        Schema::dropAllTables();

        Schema::create('restaurant_orders', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('company_id');
            $t->unsignedBigInteger('pos_transaction_id')->nullable();
            $t->string('order_number');
            $t->string('source')->default('waiter');
            $t->string('status')->default('held');
            $t->string('payment_method')->nullable();
            $t->unsignedBigInteger('assigned_cashier_id')->nullable();
            $t->unsignedBigInteger('table_id')->nullable();
            $t->timestamps();
        });

        Schema::create('restaurant_tables', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('company_id');
            $t->string('status')->default('occupied');
            $t->unsignedBigInteger('locked_by_user_id')->nullable();
            $t->timestamp('locked_at')->nullable();
            $t->timestamp('occupied_since')->nullable();
            $t->timestamps();
        });
        DB::table('restaurant_tables')->insert([
            'id' => self::TABLE_ID,
            'company_id' => self::COMPANY_ID,
            'status' => 'occupied',
            'occupied_since' => now(),
        ]);
    }

    private function makeOrder(?int $assignedTo, array $attrs = []): int
    {
        return DB::table('restaurant_orders')->insertGetId(array_merge([
            'company_id' => self::COMPANY_ID,
            'order_number' => 'ORD-260813-SETL1',
            'source' => 'waiter',
            'status' => 'held',
            'assigned_cashier_id' => $assignedTo,
            'table_id' => self::TABLE_ID,
            'created_at' => now(),
            'updated_at' => now(),
        ], $attrs));
    }

    /** Unsaved user shim — the helper only reads id + isPosAdmin() (pos_role/role). */
    private function makeUser(int $id, string $posRole): User
    {
        $u = new User();
        $u->id = $id;
        $u->pos_role = $posRole;
        $u->role = null;
        return $u;
    }

    private function makeTxn(int $id = 9201): PosTransaction
    {
        $txn = new PosTransaction(['payment_method' => 'cash']);
        $txn->id = $id;
        $txn->company_id = self::COMPANY_ID;
        return $txn;
    }

    private function orderRow(int $id): object
    {
        return DB::table('restaurant_orders')->find($id);
    }

    public function test_assignee_cashier_settles_links_txn_and_frees_table(): void
    {
        $cashier = $this->makeUser(11, 'pos_cashier');
        $orderId = $this->makeOrder(11);

        $ok = RestaurantWaiterController::settleWaiterOrder(self::COMPANY_ID, $orderId, $this->makeTxn(), $cashier);

        $this->assertTrue($ok);
        $row = $this->orderRow($orderId);
        $this->assertSame('completed', $row->status);
        $this->assertSame(9201, (int) $row->pos_transaction_id);
        $this->assertSame('cash', $row->payment_method);
        $this->assertSame('available', DB::table('restaurant_tables')->find(self::TABLE_ID)->status);
    }

    public function test_unassigned_order_settles_for_any_cashier(): void
    {
        $orderId = $this->makeOrder(null);
        $ok = RestaurantWaiterController::settleWaiterOrder(self::COMPANY_ID, $orderId, $this->makeTxn(), $this->makeUser(12, 'pos_cashier'));
        $this->assertTrue($ok);
        $this->assertSame('completed', $this->orderRow($orderId)->status);
    }

    public function test_other_cashier_cannot_settle_order_assigned_elsewhere(): void
    {
        $orderId = $this->makeOrder(11); // assigned to cashier 11
        $intruder = $this->makeUser(99, 'pos_cashier');

        $ok = RestaurantWaiterController::settleWaiterOrder(self::COMPANY_ID, $orderId, $this->makeTxn(), $intruder);

        $this->assertFalse($ok);
        $row = $this->orderRow($orderId);
        $this->assertSame('held', $row->status);
        $this->assertNull($row->pos_transaction_id);
        // Table must stay occupied — the refused settle must not free it.
        $this->assertSame('occupied', DB::table('restaurant_tables')->find(self::TABLE_ID)->status);
    }

    public function test_pos_admin_may_settle_order_assigned_to_someone_else(): void
    {
        $orderId = $this->makeOrder(11);
        $admin = $this->makeUser(2, 'pos_admin');
        $this->assertTrue(RestaurantWaiterController::settleWaiterOrder(self::COMPANY_ID, $orderId, $this->makeTxn(), $admin));
        $this->assertSame('completed', $this->orderRow($orderId)->status);
    }

    public function test_already_settled_order_is_refused_single_winner(): void
    {
        $cashier = $this->makeUser(11, 'pos_cashier');
        $orderId = $this->makeOrder(11);
        $this->assertTrue(RestaurantWaiterController::settleWaiterOrder(self::COMPANY_ID, $orderId, $this->makeTxn(9201), $cashier));
        $this->assertFalse(RestaurantWaiterController::settleWaiterOrder(self::COMPANY_ID, $orderId, $this->makeTxn(9202), $cashier));
        $this->assertSame(9201, (int) $this->orderRow($orderId)->pos_transaction_id);
    }

    public function test_missing_user_is_refused(): void
    {
        $orderId = $this->makeOrder(null);
        $this->assertFalse(RestaurantWaiterController::settleWaiterOrder(self::COMPANY_ID, $orderId, $this->makeTxn(), null));
        $this->assertSame('held', $this->orderRow($orderId)->status);
    }
}
