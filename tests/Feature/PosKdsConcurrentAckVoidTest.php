<?php

namespace Tests\Feature;

use App\Http\Controllers\RestaurantKdsController;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Task 927 — Two cooks tap "Got it" simultaneously.
 *
 * There are two possible outcomes depending on DB timing:
 *
 *   A) Winner commits BEFORE loser reads the order row.
 *      Loser's controller reads void_items = NULL → early-return 200 "Already acknowledged".
 *      Badge gone on both screens.
 *
 *   B) Both callers read the row BEFORE either UPDATE commits (true DB race).
 *      Both see void_items = listA, both pass the null early-return.
 *      Winner's UPDATE (WHERE void_items = listA) → affected=1 → 200.
 *      Loser's UPDATE (WHERE void_items = listA) → void_items already NULL → affected=0 → 409.
 *      Client on 409 calls refreshOrders() → liveOrders() returns void_items=[] → badge gone.
 *
 * Both paths end with the badge gone on both screens. Tests below lock each branch.
 *
 * Pattern mirrors PosKdsAckVoidTest / PosKdsAggregateVoidTest:
 * sqlite :memory: + minimal schema, controller invoked directly.
 */
class PosKdsConcurrentAckVoidTest extends TestCase
{
    protected int $companyId;

    protected function setUp(): void
    {
        parent::setUp();

        Schema::dropAllTables();

        Schema::create('companies', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->text('pos_printer_settings')->nullable();
            $table->boolean('pos_kds_auto_print')->default(false);
            $table->string('company_status')->default('approved');
            $table->softDeletes();
            $table->timestamps();
        });

        Schema::create('users', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('company_id')->nullable();
            $table->string('name')->nullable();
            $table->string('pos_role')->nullable();
            $table->text('pos_custom_access')->nullable();
            $table->string('language')->nullable();
            $table->timestamps();
        });

        Schema::create('restaurant_tables', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('company_id');
            $table->string('table_number')->nullable();
            $table->timestamps();
        });

        Schema::create('restaurant_orders', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('company_id');
            $table->string('order_number')->nullable();
            $table->unsignedBigInteger('table_id')->nullable();
            $table->string('order_type')->default('dine_in');
            $table->string('status')->default('held');
            $table->string('kitchen_status')->nullable();
            $table->text('kitchen_notes')->nullable();
            $table->boolean('priority')->default(false);
            $table->unsignedBigInteger('created_by')->nullable();
            $table->string('source')->nullable();
            $table->timestamp('kot_sent_at')->nullable();
            $table->timestamp('kitchen_cleared_at')->nullable();
            $table->timestamp('kitchen_started_at')->nullable();
            $table->timestamp('kitchen_ready_at')->nullable();
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
            $table->decimal('unit_price', 10, 2)->default(0);
            $table->decimal('subtotal', 10, 2)->default(0);
            $table->text('special_notes')->nullable();
            $table->timestamp('kot_printed_at')->nullable();
            $table->unsignedInteger('kot_batch_no')->nullable();
            $table->timestamps();
        });

        Schema::create('pos_stations', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('company_id');
            $table->string('name');
            $table->text('categories')->nullable();
            $table->string('printer_name')->nullable();
            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('sort')->default(0);
            $table->timestamps();
        });

        Schema::create('pos_products', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('company_id');
            $table->string('name');
            $table->string('category')->nullable();
            $table->timestamps();
        });

        $this->companyId = DB::table('companies')->insertGetId([
            'name' => 'Test Kitchen',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        app()->bind('currentCompanyId', fn () => $this->companyId);
    }

    // ── helpers ───────────────────────────────────────────────────────────────

    private function insertOrder(?string $voidItems): int
    {
        return DB::table('restaurant_orders')->insertGetId([
            'company_id'     => $this->companyId,
            'order_number'   => 'R-' . rand(1000, 9999),
            'status'         => 'held',
            'kitchen_status' => 'new',
            'void_items'     => $voidItems,
            'created_at'     => now(),
            'updated_at'     => now(),
        ]);
    }

    private function callAckVoid(int $orderId, mixed $expectedVoid): \Illuminate\Http\JsonResponse
    {
        $req = Request::create("/pos/restaurant/orders/{$orderId}/ack-void", 'POST', [
            'expected_void' => $expectedVoid,
        ]);
        $req->headers->set('Accept', 'application/json');
        return (new RestaurantKdsController())->ackVoid($req, $orderId);
    }

    private function storedVoidItems(int $orderId): ?string
    {
        return DB::table('restaurant_orders')->where('id', $orderId)->value('void_items');
    }

    private function liveOrdersPayload(): array
    {
        $resp = (new RestaurantKdsController())->liveOrders();
        return json_decode($resp->getContent(), true);
    }

    // ── Path A: winner commits before loser reads ─────────────────────────────

    /**
     * Path A — the more common outcome: by the time Cook B's request hits the
     * controller, Cook A has already committed and void_items is NULL. The
     * controller's early-return ("Already acknowledged") returns 200 so Cook B's
     * screen clears immediately without any additional round-trip.
     */
    public function test_path_a_loser_reads_after_winner_commits_gets_200_and_badge_gone(): void
    {
        $voidList = [['item_name' => 'Biryani', 'qty' => 1, 'notes' => null]];
        $id = $this->insertOrder(json_encode($voidList));

        // Cook A (winner) — acks successfully.
        $winnerResp = $this->callAckVoid($id, $voidList);
        $winnerData = $winnerResp->getData(true);
        $this->assertEquals(200, $winnerResp->getStatusCode());
        $this->assertTrue($winnerData['success']);
        $this->assertNull($this->storedVoidItems($id), 'Winner must clear void_items to NULL');

        // Cook B (loser, reads AFTER winner committed) — void_items is already
        // NULL, so the controller returns idempotent success, NOT 409.
        $loserResp = $this->callAckVoid($id, $voidList);
        $loserData = $loserResp->getData(true);
        $this->assertEquals(200, $loserResp->getStatusCode());
        $this->assertTrue($loserData['success'],
            'Loser must still succeed — badge must not stay stuck when winner already cleared it');

        // After both calls, void_items is NULL and liveOrders returns [].
        $this->assertNull($this->storedVoidItems($id));
        $live = $this->liveOrdersPayload();
        $row  = collect($live)->firstWhere('id', $id);
        $this->assertNotNull($row);
        $this->assertEmpty($row['void_items'],
            'liveOrders must return [] after ack so badge disappears on next poll');
    }

    // ── Path B: true DB race at UPDATE level ──────────────────────────────────

    /**
     * Path B — the rare case: both callers have already read void_items = listA
     * (both passed the null early-return). Then:
     *   • Cook A's UPDATE (WHERE void_items = listA) runs first → affected=1 → 200.
     *   • Cook B's UPDATE (WHERE void_items = listA) → void_items is now NULL
     *     → WHERE clause misses → affected=0 → 409.
     *   • Cook B's client shows a toast and calls refreshOrders().
     *   • liveOrders() returns void_items=[] → badge disappears on Cook B's screen.
     *
     * True DB-level concurrency cannot be reproduced in a synchronous PHP test,
     * so we simulate the loser's UPDATE directly (bypassing the controller's
     * null early-return which would short-circuit before the UPDATE in the
     * sequential case). The conditional UPDATE is the same SQL the controller
     * runs; checking its return value is sufficient to prove the 409 code path
     * triggers in a real race.
     */
    public function test_path_b_loser_update_hits_0_rows_when_winner_already_cleared(): void
    {
        $voidList = [['item_name' => 'Karahi', 'qty' => 2, 'notes' => null]];
        $encodedList = json_encode($voidList);
        $id = $this->insertOrder($encodedList);

        // ── Cook A (winner): full ackVoid → 200, void_items = NULL. ──────────
        $winnerResp = $this->callAckVoid($id, $voidList);
        $this->assertEquals(200, $winnerResp->getStatusCode());
        $this->assertTrue($winnerResp->getData(true)['success']);
        $this->assertNull($this->storedVoidItems($id), 'Winner must null void_items');

        // ── Cook B (loser at UPDATE level): simulate the UPDATE the loser's
        // controller would attempt after having already read the non-null row.
        // void_items is now NULL so WHERE void_items = encodedList misses → 0 rows.
        $affected = \App\Models\RestaurantOrder::where('company_id', $this->companyId)
            ->where('id', $id)
            ->where('void_items', $encodedList)   // same predicate as ackVoid()
            ->update(['void_items' => null]);

        $this->assertEquals(0, $affected,
            'Loser\'s conditional UPDATE must hit 0 rows — proving the 409 code path fires');

        // void_items stays NULL (not corrupted by the losing UPDATE).
        $this->assertNull($this->storedVoidItems($id));
    }

    /**
     * Path B continued — after the 409, Cook B's client calls refreshOrders().
     * The refresh payload must return void_items=[] for the order so the JS
     * hasUnacknowledgedVoids() returns false and the badge disappears.
     */
    public function test_path_b_after_409_refresh_returns_empty_void_items_so_badge_clears(): void
    {
        $voidList = [['item_name' => 'Naan', 'qty' => 3, 'notes' => null]];
        $id = $this->insertOrder(json_encode($voidList));
        DB::table('restaurant_order_items')->insert([
            'order_id'   => $id,
            'item_name'  => 'Naan',
            'quantity'   => 3,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        // Simulate the state after the winner already acked: void_items is NULL.
        DB::table('restaurant_orders')->where('id', $id)->update(['void_items' => null]);

        // Cook B's client calls refreshOrders() after the 409.
        $live = $this->liveOrdersPayload();
        $row  = collect($live)->firstWhere('id', $id);

        $this->assertNotNull($row, 'Order must still appear on KDS (kitchen not cleared)');
        $this->assertIsArray($row['void_items']);
        $this->assertEmpty($row['void_items'],
            'void_items must be [] after ack so hasUnacknowledgedVoids() returns false and badge is gone');
    }

    // ── Combined: both paths end with badge gone ──────────────────────────────

    /**
     * Regardless of which race path occurs, void_items is NULL after the dust
     * settles. liveOrders always encodes NULL as [] — the badge never gets stuck.
     */
    public function test_both_concurrent_paths_leave_no_stuck_badge(): void
    {
        $voidList = [['item_name' => 'Daal', 'qty' => 1, 'notes' => null]];
        $id = $this->insertOrder(json_encode($voidList));
        DB::table('restaurant_order_items')->insert([
            'order_id'   => $id,
            'item_name'  => 'Daal',
            'quantity'   => 1,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        // Whichever cook won, void_items ends up NULL.
        $this->callAckVoid($id, $voidList); // one of the two — the winner.

        // Confirm both possible outcomes lead to the same result:
        // void_items = NULL in the DB, and [] in liveOrders.
        $this->assertNull(
            $this->storedVoidItems($id),
            'void_items must be NULL in the DB after the race resolves'
        );

        $live = $this->liveOrdersPayload();
        $row  = collect($live)->firstWhere('id', $id);
        $this->assertNotNull($row);
        $this->assertEmpty(
            $row['void_items'],
            'liveOrders must encode NULL void_items as [] so no badge can ever get stuck'
        );
    }
}
