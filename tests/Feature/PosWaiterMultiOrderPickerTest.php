<?php

namespace Tests\Feature;

use Tests\TestCase;
use App\Http\Controllers\RestaurantWaiterController;
use App\Models\RestaurantOrder;
use App\Models\RestaurantOrderItem;
use App\Models\RestaurantTable;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * WAITER TABLET MULTI-ORDER PICKERS — Task 104 (Shift) + Task 108 (Add Items).
 *
 * Locks the end-to-end behavior of the table-picker order-selection step so a
 * regression can never make a waiter append items into (or shift) the WRONG
 * order on a multi-held-order table:
 *
 *   Backend (tables API — the data the picker branches on):
 *   1. A table with 2 HELD orders → held_orders has exactly those 2 entries
 *      (id + order_number + items_count), non-held active orders excluded.
 *   2. A single-held table → held_orders has exactly 1 entry and
 *      order_id/order_number point at it (the direct-flow fast path).
 *   3. appendItems targets ONLY the chosen order id — the sibling held order
 *      on the same table stays byte-for-byte untouched.
 *
 *   Frontend (the actual waiterApp() Alpine logic from waiter.blade.php,
 *   blade-stripped and executed under node):
 *   4. Add Items: 1 held order → direct append (no picker); >1 → picker opens
 *      (appendPickFor), nothing chosen yet; pickAppendOrder(o) → o becomes the
 *      append target.
 *   5. Shift: 1 held order → startShift on it directly (no picker); >1 →
 *      picker opens (shiftPickFor), no shift started; pickShiftOrder(o) →
 *      shift modal opens FOR o (correct order id + source table).
 *   6. Blade wiring: the picker modals actually render from
 *      appendPickFor/shiftPickFor.held_orders and their buttons call
 *      pickAppendOrder/pickShiftOrder.
 *
 * Pattern: APP_ENV=testing + sqlite :memory: + minimal Schema::create,
 * controller invoked directly (same approach as PosRiderSettleInvariantTest);
 * JS logic verified by extracting the real <script> from the blade, replacing
 * blade constructs with literals, and running assertions in node (v20 in dev).
 */
class PosWaiterMultiOrderPickerTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Schema::dropAllTables();

        Schema::create('companies', function (Blueprint $table) {
            $table->id();
            $table->string('name')->nullable();
            $table->text('pos_printer_settings')->nullable();
            $table->boolean('agent_enabled')->default(false);
            $table->timestamp('agent_last_seen')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('users', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('company_id')->nullable();
            $table->string('name')->nullable();
            $table->string('pos_role')->nullable();
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
            $table->unsignedBigInteger('floor_id');
            $table->string('table_number');
            $table->integer('seats')->default(4);
            $table->string('status')->default('available');
            $table->unsignedBigInteger('locked_by_user_id')->nullable();
            $table->timestamp('locked_at')->nullable();
            $table->timestamp('occupied_since')->nullable();
            $table->string('reservation_name')->nullable();
            $table->timestamp('reservation_time')->nullable();
            $table->integer('sort_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('restaurant_orders', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('company_id');
            $table->string('order_number');
            $table->unsignedBigInteger('table_id')->nullable();
            $table->string('order_type')->default('dine_in');
            $table->string('status')->default('held');
            $table->string('customer_name')->nullable();
            $table->string('customer_phone')->nullable();
            $table->decimal('subtotal', 12, 2)->default(0);
            $table->decimal('total_amount', 12, 2)->default(0);
            $table->string('kitchen_notes')->nullable();
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

    private function seedCompanyAndTable(): array
    {
        $companyId = DB::table('companies')->insertGetId([
            'name' => 'Picker Test Co',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $floorId = DB::table('restaurant_floors')->insertGetId([
            'company_id' => $companyId, 'name' => 'Main',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $tableId = DB::table('restaurant_tables')->insertGetId([
            'company_id' => $companyId, 'floor_id' => $floorId,
            'table_number' => 'T-7', 'seats' => 4, 'status' => 'occupied',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        app()->bind('currentCompanyId', fn () => $companyId);

        return [$companyId, $tableId];
    }

    private function makeOrder(int $companyId, ?int $tableId, string $number, string $status, int $items = 0, float $unitPrice = 100.0): int
    {
        $orderId = DB::table('restaurant_orders')->insertGetId([
            'company_id' => $companyId, 'order_number' => $number,
            'table_id' => $tableId, 'status' => $status,
            'subtotal' => $items * $unitPrice, 'total_amount' => round($items * $unitPrice),
            'created_at' => now(), 'updated_at' => now(),
        ]);
        for ($i = 0; $i < $items; $i++) {
            DB::table('restaurant_order_items')->insert([
                'order_id' => $orderId, 'item_type' => 'manual',
                'item_name' => 'Item ' . ($i + 1), 'quantity' => 1,
                'unit_price' => $unitPrice, 'subtotal' => $unitPrice,
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }
        return $orderId;
    }

    private function tablesJson(): array
    {
        $res = app(RestaurantWaiterController::class)->tables();
        return json_decode($res->getContent(), true);
    }

    private function tablesResponse(?string $etag = null)
    {
        $request = Request::create('/pos/waiter/api/tables', 'GET');
        if ($etag) {
            $request->headers->set('If-None-Match', $etag);
        }

        return app(RestaurantWaiterController::class)->tables($request);
    }

    // ── 1+2: tables API held_orders payload ──────────────────────────────

    public function test_tables_api_lists_all_held_orders_and_excludes_non_held(): void
    {
        [$companyId, $tableId] = $this->seedCompanyAndTable();

        $o1 = $this->makeOrder($companyId, $tableId, 'W-101', 'held', 2);
        $o2 = $this->makeOrder($companyId, $tableId, 'W-102', 'held', 3);
        // Non-held ACTIVE order (preparing) — active_orders counts it, but the
        // picker list must NOT offer it (shift/append reject non-held).
        $this->makeOrder($companyId, $tableId, 'W-103', 'preparing', 1);
        // Settled order — invisible everywhere.
        $this->makeOrder($companyId, $tableId, 'W-104', 'completed', 1);

        $tables = $this->tablesJson();
        $this->assertCount(1, $tables);
        $t = $tables[0];

        $this->assertSame(3, $t['active_orders']);
        $this->assertCount(2, $t['held_orders'], 'held_orders must list exactly the HELD orders');
        $this->assertSame(
            [$o1, $o2],
            array_column($t['held_orders'], 'id'),
            'picker list must be the two held orders, never preparing/completed ones'
        );
        $this->assertSame(['W-101', 'W-102'], array_column($t['held_orders'], 'order_number'));
        $this->assertSame([2, 3], array_column($t['held_orders'], 'items_count'), 'items_count shown in the picker must match each order');
        // Legacy single-order fields still point at a HELD order (fast path + tap-ability).
        $this->assertSame($o1, $t['order_id']);
        $this->assertSame('W-101', $t['order_number']);
    }

    public function test_tables_api_single_held_order_yields_one_entry_fast_path(): void
    {
        [$companyId, $tableId] = $this->seedCompanyAndTable();
        $o1 = $this->makeOrder($companyId, $tableId, 'W-201', 'held', 1);

        $t = $this->tablesJson()[0];
        $this->assertCount(1, $t['held_orders']);
        $this->assertSame($o1, $t['held_orders'][0]['id']);
        $this->assertSame($o1, $t['order_id']);
    }

    public function test_tables_api_returns_304_until_visible_table_data_changes(): void
    {
        [$companyId, $tableId] = $this->seedCompanyAndTable();
        $orderId = $this->makeOrder($companyId, $tableId, 'W-ETAG', 'held', 1);

        $first = $this->tablesResponse();
        $etag = $first->headers->get('ETag');
        $this->assertNotEmpty($etag);
        $this->assertSame(304, $this->tablesResponse($etag)->getStatusCode());

        DB::table('restaurant_order_items')
            ->where('order_id', $orderId)
            ->update([
                'item_name' => 'Updated preview item',
                'updated_at' => now()->addSecond(),
            ]);

        $changed = $this->tablesResponse($etag);
        $this->assertSame(200, $changed->getStatusCode());
        $this->assertNotSame($etag, $changed->headers->get('ETag'));
        $this->assertSame('Updated preview item', json_decode($changed->getContent(), true)[0]['held_orders'][0]['items'][0]['name']);
    }

    // ── Read-only table preview (ZFC, 6 Aug 2026) ────────────────────────
    // Counter/desktop-punched orders were invisible to the waiter — the
    // occupied tile only advertised SHIFT. orders_preview must expose EVERY
    // active order (held AND preparing/ready, any creator) with its items,
    // and never the settled ones.

    public function test_tables_api_orders_preview_lists_all_active_orders_with_items(): void
    {
        [$companyId, $tableId] = $this->seedCompanyAndTable();

        $held = $this->makeOrder($companyId, $tableId, 'W-501', 'held', 2, 100);
        // Non-held ACTIVE order (e.g. counter-punched, already preparing) —
        // excluded from held_orders but MUST appear in the preview.
        $prep = $this->makeOrder($companyId, $tableId, 'W-502', 'preparing', 3, 50);
        // Settled — invisible.
        $this->makeOrder($companyId, $tableId, 'W-503', 'completed', 1);

        $t = $this->tablesJson()[0];

        $this->assertArrayHasKey('orders_preview', $t);
        $this->assertSame([$held, $prep], array_column($t['orders_preview'], 'id'),
            'preview must list ALL active orders (held + preparing), never completed');
        $this->assertSame(['held', 'preparing'], array_column($t['orders_preview'], 'status'));
        $this->assertSame(['W-501', 'W-502'], array_column($t['orders_preview'], 'order_number'));
        $this->assertEquals([200.0, 150.0], array_column($t['orders_preview'], 'total_amount'));

        // Items ride each preview entry (name + quantity — the read-only list).
        $prepItems = $t['orders_preview'][1]['items'];
        $this->assertCount(3, $prepItems);
        $this->assertSame('Item 1', $prepItems[0]['name']);
        $this->assertEquals(1.0, $prepItems[0]['quantity']);

        // held_orders stays held-only (shift/append safety unchanged).
        $this->assertSame([$held], array_column($t['held_orders'], 'id'));
    }

    public function test_blade_occupied_tile_always_tappable_and_preview_wired(): void
    {
        $blade = file_get_contents(resource_path('views/pos/waiter.blade.php'));

        // Preview renders from tableActionFor.orders_preview.
        $this->assertStringContainsString('tableActionFor.orders_preview', $blade);
        // Occupied tile must NOT be disabled when there is no held order —
        // the waiter can always open the modal to VIEW the table.
        $this->assertStringNotContainsString('t.status === \'occupied\' && !t.order_id', $blade);
        // Add/Shift buttons stay gated on a held order being present.
        $this->assertStringContainsString('tableActionFor && tableActionFor.order_id', $blade);
    }

    public function test_waiter_network_reads_have_timeout_and_honest_retry_states(): void
    {
        $blade = file_get_contents(resource_path('views/pos/waiter.blade.php'));

        // A stalled request must leave Loading; every waiter request uses the
        // same abortable helper rather than a raw fetch.
        $this->assertStringContainsString('async _fetchWithTimeout(url, options = {}, timeoutMs = 12000)', $blade);
        $this->assertStringNotContainsString("const res = await fetch(", $blade);
        $this->assertStringContainsString('finally {', $blade);
        $this->assertStringContainsString('this.myOrdersError', $blade);
        $this->assertStringContainsString('retryMyOrders()', $blade);
        $this->assertStringContainsString('this._tableRequestSerial', $blade);
    }

    // ── 3: append hits ONLY the chosen order ─────────────────────────────

    public function test_append_items_targets_only_the_chosen_order(): void
    {
        [$companyId, $tableId] = $this->seedCompanyAndTable();
        $o1 = $this->makeOrder($companyId, $tableId, 'W-301', 'held', 2, 100);
        $o2 = $this->makeOrder($companyId, $tableId, 'W-302', 'held', 1, 50);

        $before1 = DB::table('restaurant_orders')->find($o1);

        $userId = DB::table('users')->insertGetId([
            'company_id' => $companyId, 'name' => 'Waiter W', 'pos_role' => 'pos_waiter',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        Auth::guard('pos')->setUser(\App\Models\User::find($userId));

        $req = Request::create('/pos/waiter/orders/' . $o2 . '/items', 'POST', [
            'items' => [['name' => 'Extra Naan', 'quantity' => 2, 'unit_price' => 60]],
        ]);
        $res = app(RestaurantWaiterController::class)->appendItems($req, $o2);
        $data = json_decode($res->getContent(), true);
        $this->assertTrue($data['success'] ?? false, 'append must succeed: ' . $res->getContent());

        // Chosen order (o2) got the delta.
        $this->assertSame(2, DB::table('restaurant_order_items')->where('order_id', $o2)->count());
        $after2 = DB::table('restaurant_orders')->find($o2);
        $this->assertEquals(170.0, (float) $after2->subtotal); // 50 + 2×60

        // Sibling held order on the SAME table: byte-for-byte untouched.
        $after1 = DB::table('restaurant_orders')->find($o1);
        $this->assertSame(2, DB::table('restaurant_order_items')->where('order_id', $o1)->count());
        $this->assertEquals((array) $before1, (array) $after1, 'the other held order must never be touched');
    }

    public function test_append_to_non_held_order_is_rejected(): void
    {
        [$companyId, $tableId] = $this->seedCompanyAndTable();
        $prep = $this->makeOrder($companyId, $tableId, 'W-401', 'preparing', 1);

        $userId = DB::table('users')->insertGetId([
            'company_id' => $companyId, 'name' => 'Waiter W', 'pos_role' => 'pos_waiter',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        Auth::guard('pos')->setUser(\App\Models\User::find($userId));

        $req = Request::create('/pos/waiter/orders/' . $prep . '/items', 'POST', [
            'items' => [['name' => 'X', 'quantity' => 1, 'unit_price' => 10]],
        ]);
        $res = app(RestaurantWaiterController::class)->appendItems($req, $prep);
        $this->assertSame(404, $res->getStatusCode());
        $this->assertSame(1, DB::table('restaurant_order_items')->where('order_id', $prep)->count());
    }

    // ── 4+5: the REAL waiterApp() picker logic, executed under node ──────

    public function test_waiter_app_js_picker_flows_single_vs_multi(): void
    {
        $node = trim((string) shell_exec('command -v node'));
        if ($node === '') {
            $this->markTestSkipped('node not available');
        }

        $js = $this->extractWaiterAppJs();

        $harness = <<<'JS'
// ── Harness: minimal browser stubs, then the real waiterApp() ──
global.window = { scrollTo() {}, location: { href: 'http://t/pos/waiter' } };
global.document = { hidden: true, addEventListener() {} };
global.fetch = async () => ({ ok: false });
global.confirm = () => true;

__WAITER_APP__

const assert = (cond, msg) => { if (!cond) { console.error('FAIL: ' + msg); process.exit(1); } };

(async () => {
    const app = waiterApp();

    const single = { id: 5, table_number: '3', order_id: 11, order_number: 'W-11',
                     held_orders: [{ id: 11, order_number: 'W-11', items_count: 2 }] };
    const multi  = { id: 6, table_number: '4', order_id: 21, order_number: 'W-21',
                     held_orders: [{ id: 21, order_number: 'W-21', items_count: 1 },
                                   { id: 22, order_number: 'W-22', items_count: 4 }] };

    // ── Add Items: single held → DIRECT append, no picker ──
    app.showTables = true; app.tableActionFor = single;
    app.startAppendFromTable(single);
    assert(app.appendPickFor === null, 'single-held append must NOT open the order picker');
    assert(app.appendOrderId === 11, 'single-held append target must be the held order');
    assert(app.appendOrderNumber === 'W-11', 'single-held append shows its order number');
    assert(app.tableActionFor === null && app.showTables === false, 'single-held append closes picker UI');

    // ── Add Items: 2 held → picker opens, NOTHING chosen yet ──
    app.appendOrderId = null; app.appendOrderNumber = ''; app.showTables = true; app.tableActionFor = multi;
    app.startAppendFromTable(multi);
    assert(app.appendPickFor === multi, 'multi-held append must open the order-selection step');
    assert(app.appendOrderId === null, 'no append target until the waiter picks an order');
    assert(app.tableActionFor === null, 'action chooser closes behind the picker');

    // ── waiter picks the SECOND order → THAT order becomes the target ──
    app.pickAppendOrder(multi.held_orders[1]);
    assert(app.appendOrderId === 22, 'chosen order (not the first) must become the append target');
    assert(app.appendOrderNumber === 'W-22', 'append banner shows the chosen order number');
    assert(app.appendPickFor === null && app.showTables === false, 'picker closes after choosing');

    // ── Shift: single held → startShift DIRECTLY on it, no picker ──
    app.shiftFor = null; app.shiftPickFor = null; app.showTables = true;
    app.startShiftFromTable(single);
    assert(app.shiftPickFor === null, 'single-held shift must NOT open the order picker');
    assert(app.shiftFor && app.shiftFor.id === 11, 'single-held shift targets the held order');
    assert(app.shiftFor.table_id === 5, 'shift carries the source table id');
    assert(app.showTables === false, 'table grid closes into the shift modal');

    // ── Shift: 2 held → picker opens, NO shift started ──
    app.shiftFor = null; app.showTables = true;
    app.startShiftFromTable(multi);
    assert(app.shiftPickFor === multi, 'multi-held shift must open the order-selection step');
    assert(app.shiftFor === null, 'no shift target until the waiter picks an order');

    // ── waiter picks the SECOND order → shift modal opens FOR IT ──
    app.pickShiftOrder(multi.held_orders[1]);
    assert(app.shiftFor && app.shiftFor.id === 22, 'chosen order (not the first) must be the shifted order');
    assert(app.shiftFor.order_number === 'W-22', 'shift modal shows the chosen order number');
    assert(app.shiftFor.table_id === 6, 'shift keeps the source table id');
    assert(app.shiftPickFor === null, 'picker closes after choosing');

    // ── Guards: no held order → both are no-ops ──
    const empty = { id: 7, table_number: '5', order_id: null, order_number: null, held_orders: [] };
    app.appendOrderId = null; app.shiftFor = null;
    app.startAppendFromTable(empty);
    app.startShiftFromTable(empty);
    assert(app.appendOrderId === null && app.shiftFor === null && app.appendPickFor === null && app.shiftPickFor === null,
           'tables without a held order must be inert');

    // ── Table poll ETag: cache validator sent; 304 preserves current body ──
    const liveTables = [{ id: 88, table_number: '9', status: 'available' }];
    const fetchCalls = [];
    global.fetch = async (_url, options) => {
        fetchCalls.push(options);
        if (fetchCalls.length === 1) {
            return {
                status: 200,
                ok: true,
                headers: { get: (name) => name === 'ETag' ? '"waiter-etag-1"' : null },
                json: async () => liveTables,
            };
        }
        return {
            status: 304,
            ok: false,
            headers: { get: () => null },
            json: async () => { throw new Error('304 response body must not be parsed'); },
        };
    };

    await app.reloadTablesQuiet();
    assert(app._tableEtag === '"waiter-etag-1"', '200 response ETag must be remembered');
    assert(app.tables === liveTables, '200 response body must update waiter tables');

    await app.reloadTablesQuiet();
    assert(fetchCalls[1].headers['If-None-Match'] === '"waiter-etag-1"', 'next poll must send If-None-Match');
    assert(app.tables === liveTables, '304 must preserve the existing table data');

    console.log('ALL_OK');
})().catch((e) => { console.error('FAIL: ' + (e && e.stack || e)); process.exit(1); });
JS;

        $script = str_replace('__WAITER_APP__', $js, $harness);
        $tmp = tempnam(sys_get_temp_dir(), 'waiterapp_') . '.js';
        file_put_contents($tmp, $script);
        try {
            exec(escapeshellarg($node) . ' ' . escapeshellarg($tmp) . ' 2>&1', $out, $code);
            $output = implode("\n", $out);
            $this->assertSame(0, $code, "node run failed:\n" . $output);
            $this->assertStringContainsString('ALL_OK', $output);
        } finally {
            @unlink($tmp);
        }
    }

    public function test_waiter_app_reuses_the_same_hold_uuid_after_a_network_error(): void
    {
        $node = trim((string) shell_exec('command -v node'));
        if ($node === '') {
            $this->markTestSkipped('node not available');
        }

        $js = $this->extractWaiterAppJs();

        $harness = <<<'JS'
global.window = {
    scrollTo() {},
    location: { href: 'http://t/pos/waiter' },
    crypto: { randomUUID: () => 'waiter-punch-uuid-1010' },
};
global.document = { hidden: true, addEventListener() {} };
global.confirm = () => true;

__WAITER_APP__

const assert = (cond, msg) => { if (!cond) { console.error('FAIL: ' + msg); process.exit(1); } };

(async () => {
    const app = waiterApp();
    app.cart = [{ item_id: 9, name: 'Tea', quantity: 1, unit_price: 100, special_notes: '' }];
    app.orderType = 'takeaway';
    app.showToast = () => {};
    app.loadMyOrders = async () => {};

    const payloads = [];
    let calls = 0;
    global.fetch = async (_url, options) => {
        payloads.push(JSON.parse(options.body));
        calls++;
        if (calls === 1) throw new Error('simulated lost response');
        return {
            ok: true,
            status: 200,
            json: async () => ({ success: true, order_id: 44, order_number: 'ORD-44' }),
        };
    };

    await app.send();
    assert(app.holdAttemptUuid === 'waiter-punch-uuid-1010',
        'failed punch must retain its UUID for retry');
    assert(app.cart.length === 1, 'failed punch must retain the cart for retry');

    await app.send();
    assert(payloads.length === 2, 'retry must send exactly one additional request');
    assert(payloads[0].hold_uuid === 'waiter-punch-uuid-1010',
        'first punch must carry a generated hold_uuid');
    assert(payloads[1].hold_uuid === payloads[0].hold_uuid,
        'retry must reuse the original hold_uuid');
    assert(app.holdAttemptUuid === null, 'successful punch must clear its UUID for the next order');
    console.log('ALL_OK');
})().catch((e) => { console.error('FAIL: ' + (e && e.stack || e)); process.exit(1); });
JS;

        $script = str_replace('__WAITER_APP__', $js, $harness);
        $tmp = tempnam(sys_get_temp_dir(), 'waiterapp_') . '.js';
        file_put_contents($tmp, $script);
        try {
            exec(escapeshellarg($node) . ' ' . escapeshellarg($tmp) . ' 2>&1', $out, $code);
            $output = implode("\n", $out);
            $this->assertSame(0, $code, "node run failed:\n" . $output);
            $this->assertStringContainsString('ALL_OK', $output);
        } finally {
            @unlink($tmp);
        }
    }

    /**
     * Pull the real waiterApp() script out of waiter.blade.php and replace
     * blade constructs with harmless JS literals so node can execute it.
     * If extraction ever breaks (blade restructure), the test FAILS loudly —
     * that is exactly when the picker logic needs re-locking.
     */
    private function extractWaiterAppJs(): string
    {
        $blade = file_get_contents(resource_path('views/pos/waiter.blade.php'));
        $this->assertNotFalse($blade);

        $start = strpos($blade, 'function waiterApp()');
        $this->assertNotFalse($start, 'waiterApp() not found in waiter.blade.php');
        $end = strpos($blade, '</script>', $start);
        $this->assertNotFalse($end, 'closing </script> after waiterApp() not found');
        $js = substr($blade, $start, $end - $start);

        // Blade → JS literals (order matters: raw echoes before {{ }}).
        // @js(...)/@json(...) need BALANCED paren matching — their arguments
        // contain nested calls like collect(...)->map(fn($i) => ...).
        $js = preg_replace('/\{!!.*?!!\}/s', '[]', $js);
        $js = $this->replaceBalancedDirective($js, '@js(', '""');
        $js = $this->replaceBalancedDirective($js, '@json(', '"unknown"');
        $js = preg_replace('/\{\{.*?\}\}/s', '0', $js);

        $this->assertStringNotContainsString('{!!', $js);
        $this->assertStringNotContainsString('{{', $js);
        $this->assertStringNotContainsString('@js(', $js);
        $this->assertStringNotContainsString('@json(', $js);
        return $js;
    }

    /** Replace every `@js(...)`/`@json(...)` (balanced parens) with a literal. */
    private function replaceBalancedDirective(string $js, string $open, string $literal): string
    {
        while (($pos = strpos($js, $open)) !== false) {
            $depth = 0;
            $i = $pos + strlen($open) - 1; // points at the opening '('
            $len = strlen($js);
            for (; $i < $len; $i++) {
                if ($js[$i] === '(') $depth++;
                elseif ($js[$i] === ')' && --$depth === 0) break;
            }
            $this->assertLessThan($len, $i, "unbalanced parens after {$open}");
            $js = substr($js, 0, $pos) . $literal . substr($js, $i + 1);
        }
        return $js;
    }

    // ── 6: blade wiring — the picker modals really use these functions ───

    public function test_blade_wires_picker_modals_to_pick_functions(): void
    {
        $blade = file_get_contents(resource_path('views/pos/waiter.blade.php'));

        // Add Items picker modal renders from appendPickFor.held_orders and
        // each row commits via pickAppendOrder(o).
        $this->assertStringContainsString("appendPickFor ? appendPickFor.held_orders : []", $blade);
        $this->assertStringContainsString('pickAppendOrder(o)', $blade);

        // Shift picker modal renders from shiftPickFor.held_orders and each
        // row commits via pickShiftOrder(o).
        $this->assertStringContainsString("shiftPickFor ? shiftPickFor.held_orders : []", $blade);
        $this->assertStringContainsString('pickShiftOrder(o)', $blade);

        // Entry points from the occupied-tile chooser.
        $this->assertStringContainsString('startAppendFromTable(tableActionFor)', $blade);
        $this->assertStringContainsString('startShiftFromTable(tableActionFor)', $blade);
    }
}
