<?php

namespace Tests\Feature;

use App\Http\Controllers\RestaurantPosController;
use App\Http\Controllers\RestaurantWaiterController;
use App\Models\RestaurantOrderItem;
use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Order-cancel invariants (Task 413, locking Task 409's cancel surfaces).
 *
 * All cancel surfaces (cashier Held Orders modal, takeaway/delivery board,
 * waiter tablet) funnel through RestaurantPosController::deleteOrder, which
 * is a SOFT cancel: order + items stay (status='cancelled') so the Cancelled
 * Orders report keeps its audit trail. This test locks:
 *
 *   1. Cancelling a waiter-source held order works — status='cancelled',
 *      cancelled_at + cancelled_by set — and frees its table when no other
 *      active order remains on it (and does NOT free a still-busy table).
 *   2. Completed orders are rejected with 400 and stay untouched.
 *   3. Cancelled orders appear in the cancelled-orders report query with
 *      creator eager-loaded and cancelled_by preserved.
 *   4. A cancelled waiter order vanishes from the cashier incoming-orders
 *      feed and can no longer be claimed (claimIncoming → 409).
 *
 * Pattern mirrors PosRestaurantDashboardCountsTest: sqlite :memory: +
 * minimal Schema::create, controllers invoked directly with the
 * currentCompanyId container binding.
 */
class PosRestaurantOrderCancelTest extends TestCase
{
    protected int $companyId;

    protected function setUp(): void
    {
        parent::setUp();

        // These tests are about WHAT the cancel writes and what the report
        // shows — not about which day a row lands on. The report now groups by
        // the shop's business day, so between midnight and the 06:00 cutoff an
        // order punched at "now" belongs to YESTERDAY and the fixtures would
        // look empty. Pin the clock to mid-afternoon so the suite behaves the
        // same at 02:00 as it does at noon; the date rule has its own tests.
        \Illuminate\Support\Carbon::setTestNow(
            \Illuminate\Support\Carbon::create(2026, 9, 2, 14, 0, 0)
        );

        Schema::dropAllTables();

        Schema::create('companies', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->boolean('pra_reporting_enabled')->default(false);
            $table->boolean('inventory_enabled')->default(false);
            // Task #643: cashier order-cancel company switch (default OFF).
            $table->boolean('pos_cashier_order_cancel')->default(false);
            // Internal account → planAllows() passes → Custom Access sets are live.
            $table->boolean('is_internal_account')->default(false);
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
            $table->string('order_number')->nullable();
            $table->unsignedBigInteger('table_id')->nullable();
            $table->string('order_type')->nullable();
            $table->string('source')->nullable();
            $table->string('status');
            $table->unsignedBigInteger('created_by')->nullable();
            $table->unsignedBigInteger('assigned_cashier_id')->nullable();
            $table->string('customer_name')->nullable();
            $table->string('customer_phone')->nullable();
            $table->string('kitchen_notes')->nullable();
            $table->decimal('subtotal', 12, 2)->default(0);
            $table->decimal('total_amount', 12, 2)->default(0);
            $table->timestamp('kot_sent_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->unsignedBigInteger('cancelled_by')->nullable();
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
            $table->boolean('was_made')->nullable();
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
            'name' => 'Karahi House',
            'is_internal_account' => true,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        app()->bind('currentCompanyId', fn () => $this->companyId);
    }

    protected function tearDown(): void
    {
        Auth::guard('pos')->logout();
        \Illuminate\Support\Carbon::setTestNow();
        parent::tearDown();
    }

    // ── helpers ──────────────────────────────────────────────────────────────

    protected function makeUser(string $posRole, ?string $name = null): User
    {
        DB::table('users')->insert([
            'company_id' => $this->companyId,
            'name' => $name ?? ('U-' . $posRole . '-' . uniqid()),
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
        return DB::table('restaurant_orders')->insertGetId(array_merge([
            'company_id' => $this->companyId,
            'order_number' => 'R-' . uniqid(),
            'status' => 'held',
            'source' => 'waiter',
            'total_amount' => 500,
            'created_at' => now(), 'updated_at' => now(),
        ], $attrs));
    }

    protected function table(string $status = 'occupied'): int
    {
        return DB::table('restaurant_tables')->insertGetId([
            'company_id' => $this->companyId,
            'table_number' => (string) random_int(1, 99),
            'status' => $status,
            'occupied_since' => $status === 'occupied' ? now() : null,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    /** Task #643: flip the company switch so a plain cashier may cancel. */
    protected function allowCashierCancel(): void
    {
        DB::table('companies')->where('id', $this->companyId)->update(['pos_cashier_order_cancel' => true]);
    }

    protected function cancel(int $orderId, array $payload = []): \Illuminate\Http\JsonResponse
    {
        $request = Request::create('/pos/restaurant/orders/' . $orderId, 'DELETE', $payload);

        return (new RestaurantPosController())->deleteOrder($request, $orderId);
    }

    /** Report query is private — the report page/CSV/PDF all share it. */
    protected function cancelledReportOrders(): \Illuminate\Support\Collection
    {
        $controller = new RestaurantPosController();
        $method = new \ReflectionMethod($controller, 'cancelledOrdersQuery');
        $method->setAccessible(true);

        return $method->invokeArgs($controller, [Request::create('/pos/restaurant/cancelled-orders', 'GET')])->get();
    }

    // ── soft cancel + table freeing ──────────────────────────────────────────

    public function test_cancel_waiter_held_order_soft_cancels_and_frees_table(): void
    {
        $waiter = $this->makeUser('pos_waiter', 'Waiter Wali');
        $cashier = $this->actAs($this->makeUser('pos_cashier', 'Cashier Chacha'));
        $this->allowCashierCancel();

        $tableId = $this->table('occupied');
        $orderId = $this->order(['table_id' => $tableId, 'created_by' => $waiter->id]);
        DB::table('restaurant_order_items')->insert([
            'order_id' => $orderId, 'item_name' => 'Karahi', 'quantity' => 1,
            'subtotal' => 500, 'created_at' => now(), 'updated_at' => now(),
        ]);

        $response = $this->cancel($orderId);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertTrue($response->getData()->success);

        $row = DB::table('restaurant_orders')->find($orderId);
        // SOFT cancel: row survives with status='cancelled' + audit columns set.
        $this->assertSame('cancelled', $row->status);
        $this->assertNotNull($row->cancelled_at);
        $this->assertEquals($cashier->id, $row->cancelled_by);
        // Items survive too (Cancelled Orders report needs them).
        $this->assertSame(1, DB::table('restaurant_order_items')->where('order_id', $orderId)->count());

        // No other active order on the table → freed completely.
        $tbl = DB::table('restaurant_tables')->find($tableId);
        $this->assertSame('available', $tbl->status);
        $this->assertNull($tbl->occupied_since);
        $this->assertNull($tbl->locked_by_user_id);
    }

    public function test_cancel_keeps_table_occupied_when_another_active_order_remains(): void
    {
        $this->actAs($this->makeUser('pos_admin'));

        $tableId = $this->table('occupied');
        $orderId = $this->order(['table_id' => $tableId]);
        // A second, still-active order parked on the SAME table.
        $this->order(['table_id' => $tableId, 'status' => 'preparing']);

        $response = $this->cancel($orderId);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('occupied', DB::table('restaurant_tables')->find($tableId)->status);
    }

    public function test_cancel_takeaway_and_delivery_orders_soft_cancels_with_audit_stamps(): void
    {
        $cashier = $this->actAs($this->makeUser('pos_cashier'));
        $this->allowCashierCancel();

        // Takeaway/delivery board orders: pos-source, no table, typed.
        $takeawayId = $this->order(['source' => 'pos', 'order_type' => 'takeaway', 'table_id' => null]);
        $deliveryId = $this->order(['source' => 'pos', 'order_type' => 'delivery', 'table_id' => null]);

        foreach ([$takeawayId, $deliveryId] as $id) {
            $response = $this->cancel($id);
            $this->assertSame(200, $response->getStatusCode());
            $this->assertTrue($response->getData()->success);

            $row = DB::table('restaurant_orders')->find($id);
            $this->assertSame('cancelled', $row->status);
            $this->assertNotNull($row->cancelled_at);
            $this->assertEquals($cashier->id, $row->cancelled_by);
        }

        // Both land in the cancelled-orders report with their order_type intact.
        $rows = $this->cancelledReportOrders();
        $this->assertEqualsCanonicalizing(
            ['takeaway', 'delivery'],
            $rows->pluck('order_type')->all()
        );
    }

    // ── Task #645: made/unmade marking on takeaway/delivery cancel ──────────

    public function test_takeaway_cancel_with_made_item_ids_marks_waste_for_report(): void
    {
        $this->actAs($this->makeUser('pos_manager'));

        $orderId = $this->order(['source' => 'pos', 'order_type' => 'takeaway', 'table_id' => null, 'total_amount' => 1350]);
        $madeId = DB::table('restaurant_order_items')->insertGetId([
            'order_id' => $orderId, 'item_name' => 'Zinger', 'quantity' => 2,
            'subtotal' => 900, 'kot_printed_at' => now(), 'created_at' => now(), 'updated_at' => now(),
        ]);
        $notMadeId = DB::table('restaurant_order_items')->insertGetId([
            'order_id' => $orderId, 'item_name' => 'Fries', 'quantity' => 1,
            'subtotal' => 450, 'kot_printed_at' => now(), 'created_at' => now(), 'updated_at' => now(),
        ]);

        $response = $this->cancel($orderId, ['made_item_ids' => [$madeId]]);
        $this->assertSame(200, $response->getStatusCode());

        // Made tick persisted per item; unticked explicitly false (asked, said no).
        $this->assertEquals(1, DB::table('restaurant_order_items')->find($madeId)->was_made);
        $this->assertEquals(0, DB::table('restaurant_order_items')->find($notMadeId)->was_made);

        // Report waste (same query as the Cancelled Orders summary): only the
        // MADE item's value counts — takeaway rows included, no Rs 0 gap.
        $waste = (float) \App\Models\RestaurantOrderItem::where('was_made', true)
            ->whereIn('order_id', $this->cancelledReportOrders()->pluck('id'))
            ->sum('subtotal');
        $this->assertSame(900.0, $waste);
    }

    public function test_delivery_cancel_without_made_item_ids_leaves_was_made_null(): void
    {
        $this->actAs($this->makeUser('pos_admin'));

        // No-KOT cancel path: client never sends made_item_ids → NULL = not asked.
        $orderId = $this->order(['source' => 'waiter', 'order_type' => 'delivery', 'table_id' => null]);
        $itemId = DB::table('restaurant_order_items')->insertGetId([
            'order_id' => $orderId, 'item_name' => 'Karahi', 'quantity' => 1,
            'subtotal' => 500, 'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->assertSame(200, $this->cancel($orderId)->getStatusCode());
        $this->assertNull(DB::table('restaurant_order_items')->find($itemId)->was_made);
    }

    public function test_made_state_labels_keep_null_distinct_from_explicit_not_made_and_kot_is_not_made(): void
    {
        $orderId = $this->order(['kot_sent_at' => now()]);
        $made = DB::table('restaurant_order_items')->insertGetId([
            'order_id' => $orderId, 'item_name' => 'Made dish', 'was_made' => true,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $notMade = DB::table('restaurant_order_items')->insertGetId([
            'order_id' => $orderId, 'item_name' => 'Not made dish', 'was_made' => false,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        // This KOT was sent, but no cancellation question was answered.
        $notRecorded = DB::table('restaurant_order_items')->insertGetId([
            'order_id' => $orderId, 'item_name' => 'Legacy dish', 'was_made' => null,
            'kot_printed_at' => now(), 'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->assertSame('Made', RestaurantOrderItem::find($made)->madeStateLabel());
        $this->assertSame('Not made', RestaurantOrderItem::find($notMade)->madeStateLabel());
        $this->assertSame('Not recorded', RestaurantOrderItem::find($notRecorded)->madeStateLabel());
        $this->assertSame(
            0.0,
            (float) RestaurantOrderItem::where('order_id', $orderId)
                ->where('was_made', true)->sum('subtotal'),
            'KOT-sent, NULL-state items must not become waste'
        );
    }

    // ── Task #933: '…' menu dine-in cancel stamps was_made in report ─────────

    /**
     * The '…' menu on the Table Board (heldMenuDelete) feeds the same rich
     * cancel modal as the board "Free Table" button, then calls boardCancelConfirm
     * which POSTs made_item_ids to deleteOrder.  This test locks the server-side
     * half of that round-trip for a dine-in held order that already has a KOT
     * (kot_sent_at set) — the only case where the cashier sees made/not-made
     * toggles and the client sends made_item_ids.
     *
     * Confirms:
     *   - checked item  → was_made = 1
     *   - unchecked item → was_made = 0
     *   - both items appear in the cancelled-orders report
     *   - only the checked item's subtotal counts toward waste
     *   - a second cancel call on a no-KOT dine-in order (body = {}) leaves
     *     was_made NULL on all items (not asked = never recorded)
     */
    public function test_dine_in_dot_menu_cancel_with_made_item_ids_stamps_was_made_in_report(): void
    {
        $manager = $this->actAs($this->makeUser('pos_manager', 'Manager Mehboob'));
        $tableId = $this->table('occupied');

        // ── Order 1: KOT was sent; cashier marks Chapli as made, Raita as not ──
        $orderId = $this->order([
            'order_type' => 'dine_in',
            'source'     => 'waiter',
            'table_id'   => $tableId,
            'total_amount' => 1100,
            'kot_sent_at'  => now(),
        ]);
        $chapliId = DB::table('restaurant_order_items')->insertGetId([
            'order_id' => $orderId, 'item_name' => 'Chapli Kabab', 'quantity' => 2,
            'subtotal' => 800, 'kot_printed_at' => now(),
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $raitaId = DB::table('restaurant_order_items')->insertGetId([
            'order_id' => $orderId, 'item_name' => 'Raita', 'quantity' => 1,
            'subtotal' => 300, 'kot_printed_at' => now(),
            'created_at' => now(), 'updated_at' => now(),
        ]);

        // boardCancelConfirm sends made_item_ids = [chapliId] (Chapli was made)
        $response = $this->cancel($orderId, ['made_item_ids' => [$chapliId]]);
        $this->assertSame(200, $response->getStatusCode());
        $this->assertTrue($response->getData()->success);

        // was_made stamped correctly per-item.
        $this->assertEquals(1, DB::table('restaurant_order_items')->find($chapliId)->was_made,
            'Chapli (checked) must have was_made = 1');
        $this->assertEquals(0, DB::table('restaurant_order_items')->find($raitaId)->was_made,
            'Raita (unchecked) must have was_made = 0');

        // Both items survive (soft cancel) and appear in the report.
        $reportIds = $this->cancelledReportOrders()->pluck('id');
        $this->assertContains($orderId, $reportIds->all(), 'Cancelled dine-in order must appear in report');
        $this->assertSame(2, DB::table('restaurant_order_items')->where('order_id', $orderId)->count(),
            'Items must survive soft-cancel for the report');

        // Waste = only the made item's subtotal (Chapli 800, not Raita 300).
        $waste = (float) \App\Models\RestaurantOrderItem::where('was_made', true)
            ->whereIn('order_id', $reportIds)
            ->sum('subtotal');
        $this->assertSame(800.0, $waste, 'Waste must count only was_made = 1 items');

        // ── Order 2: no KOT (fresh hold, never sent to kitchen) — client sends
        //    empty body ({}) → was_made must stay NULL (never asked) ──────────
        $noKotId = $this->order([
            'order_type'  => 'dine_in',
            'source'      => 'pos',
            'table_id'    => null,
            'total_amount' => 500,
            // no kot_sent_at
        ]);
        $itemId = DB::table('restaurant_order_items')->insertGetId([
            'order_id' => $noKotId, 'item_name' => 'Lassi', 'quantity' => 1,
            'subtotal' => 500, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->assertSame(200, $this->cancel($noKotId)->getStatusCode());
        $this->assertNull(DB::table('restaurant_order_items')->find($itemId)->was_made,
            'No-KOT order: was_made must stay NULL when made_item_ids was never sent');
    }

    // ── completed orders are protected ───────────────────────────────────────

    public function test_completed_order_cannot_be_cancelled(): void
    {
        $this->actAs($this->makeUser('pos_admin'));

        $orderId = $this->order(['status' => 'completed']);

        $response = $this->cancel($orderId);

        $this->assertSame(400, $response->getStatusCode());
        $this->assertFalse($response->getData()->success);
        // Untouched: still completed, no cancel stamps.
        $row = DB::table('restaurant_orders')->find($orderId);
        $this->assertSame('completed', $row->status);
        $this->assertNull($row->cancelled_at);
        $this->assertNull($row->cancelled_by);
    }

    public function test_other_company_and_missing_orders_rejected(): void
    {
        $this->actAs($this->makeUser('pos_admin'));

        $otherCompany = DB::table('companies')->insertGetId([
            'name' => 'Other Shop', 'created_at' => now(), 'updated_at' => now(),
        ]);
        $foreign = $this->order(['company_id' => $otherCompany]);

        $this->assertSame(404, $this->cancel($foreign)->getStatusCode());
        $this->assertSame(404, $this->cancel(999999)->getStatusCode());
        // Foreign order untouched.
        $this->assertSame('held', DB::table('restaurant_orders')->find($foreign)->status);
    }

    // ── cancelled-orders report ──────────────────────────────────────────────

    public function test_cancelled_order_appears_in_report_with_creator_and_cancelled_by(): void
    {
        $waiter = $this->makeUser('pos_waiter', 'Waiter Wali');
        $manager = $this->actAs($this->makeUser('pos_manager', 'Manager Sahib'));

        $orderId = $this->order(['created_by' => $waiter->id]);
        $this->cancel($orderId);
        // Noise: an active order must NOT appear in the report.
        $this->order(['status' => 'held']);

        $rows = $this->cancelledReportOrders();

        $this->assertCount(1, $rows);
        $row = $rows->first();
        $this->assertSame($orderId, $row->id);
        $this->assertSame('cancelled', $row->status);
        // Creator eager-loaded (report shows who placed it) + who cancelled it.
        $this->assertTrue($row->relationLoaded('creator'));
        $this->assertSame('Waiter Wali', $row->creator->name);
        $this->assertEquals($manager->id, $row->cancelled_by);
    }

    // ── incoming feed & claimable set ────────────────────────────────────────

    public function test_cancelled_waiter_order_leaves_incoming_feed_and_is_unclaimable(): void
    {
        $waiter = $this->makeUser('pos_waiter');
        $cashier = $this->actAs($this->makeUser('pos_cashier'));
        $this->allowCashierCancel();

        $cancelledId = $this->order(['created_by' => $waiter->id]);
        $liveId = $this->order(['created_by' => $waiter->id]);

        $waiterController = new RestaurantWaiterController();

        // Before cancel: both waiter-held orders are in the incoming feed.
        $ids = collect($waiterController->incomingOrders()->getData())->pluck('id')->all();
        $this->assertContains($cancelledId, $ids);
        $this->assertContains($liveId, $ids);

        $this->cancel($cancelledId);

        // After cancel: gone from the feed, live order still there.
        $ids = collect($waiterController->incomingOrders()->getData())->pluck('id')->all();
        $this->assertNotContains($cancelledId, $ids);
        $this->assertContains($liveId, $ids);

        // And it is no longer claimable — atomic claim must refuse (409).
        $claim = $waiterController->claimIncoming(Request::create('/', 'POST'), $cancelledId);
        $this->assertSame(409, $claim->getStatusCode());
        $this->assertNull(DB::table('restaurant_orders')->find($cancelledId)->assigned_cashier_id);

        // Sanity: the live order still claims fine.
        $claim = $waiterController->claimIncoming(Request::create('/', 'POST'), $liveId);
        $this->assertSame(200, $claim->getStatusCode());
        $this->assertEquals($cashier->id, DB::table('restaurant_orders')->find($liveId)->assigned_cashier_id);
    }

    // ── Task #643: role gating (cashier deny by default, admin/manager allow) ──

    public function test_cashier_cancel_denied_by_default_403(): void
    {
        $waiter = $this->makeUser('pos_waiter');
        $this->actAs($this->makeUser('pos_cashier'));

        $orderId = $this->order(['created_by' => $waiter->id]);

        $response = $this->cancel($orderId);

        $this->assertSame(403, $response->getStatusCode());
        $this->assertFalse($response->getData()->success);
        // Order untouched — still held, no cancel audit stamps.
        $row = DB::table('restaurant_orders')->find($orderId);
        $this->assertSame('held', $row->status);
        $this->assertNull($row->cancelled_at);
    }

    public function test_manager_and_admin_can_cancel_without_toggle(): void
    {
        $waiter = $this->makeUser('pos_waiter');
        foreach (['pos_manager', 'pos_admin'] as $role) {
            $this->actAs($this->makeUser($role));
            $orderId = $this->order(['created_by' => $waiter->id]);
            $response = $this->cancel($orderId);
            $this->assertSame(200, $response->getStatusCode(), $role);
            $this->assertSame('cancelled', DB::table('restaurant_orders')->find($orderId)->status, $role);
        }
    }

    public function test_company_toggle_reopens_cashier_cancel(): void
    {
        $waiter = $this->makeUser('pos_waiter');
        $this->actAs($this->makeUser('pos_cashier'));
        $this->allowCashierCancel();

        $orderId = $this->order(['created_by' => $waiter->id]);

        $response = $this->cancel($orderId);
        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('cancelled', DB::table('restaurant_orders')->find($orderId)->status);
    }

    public function test_custom_access_tick_wins_both_ways(): void
    {
        $waiter = $this->makeUser('pos_waiter');

        // Ticked order_cancel → allowed even though the company switch is OFF.
        $granted = $this->makeUser('pos_cashier');
        DB::table('users')->where('id', $granted->id)->update(['pos_custom_access' => '["order_cancel"]']);
        $this->actAs($granted->fresh());
        $orderId = $this->order(['created_by' => $waiter->id]);
        $this->assertSame(200, $this->cancel($orderId)->getStatusCode());

        // Unticked (set exists without order_cancel) → denied even with switch ON.
        $this->allowCashierCancel();
        $denied = $this->makeUser('pos_cashier');
        DB::table('users')->where('id', $denied->id)->update(['pos_custom_access' => '["reports"]']);
        $this->actAs($denied->fresh());
        $orderId2 = $this->order(['created_by' => $waiter->id]);
        $this->assertSame(403, $this->cancel($orderId2)->getStatusCode());
        $this->assertSame('held', DB::table('restaurant_orders')->find($orderId2)->status);
    }
}
