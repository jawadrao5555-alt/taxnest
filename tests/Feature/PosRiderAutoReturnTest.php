<?php

namespace Tests\Feature;

use App\Http\Controllers\PosRiderController;
use App\Models\InventoryMovement;
use App\Models\PosTransaction;
use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Rider auto return / credit note + wastage option (Task 586).
 *
 * Marking a delivery "returned" on the deliveries board must auto-create the
 * FULL return bill through the shared PosReturnService — no manual form.
 *
 * Locked invariants:
 *   1. PRA-reported parent → return goes 'pending' (credit-note queue), full
 *      quantities, cash refund, whole-rupee total, parent lines' returned_quantity.
 *   2. Provisional/local parent → LOCAL return (invoice_mode 'local' +
 *      pra_status 'local'), never reported.
 *   3. Wastage choice: wastage=1 → NO stock restore, is_wastage flag set;
 *      default → stock restored (movement + pos_products mirror).
 *   4. Double-refund guard: existing (partial) return → auto SKIPPED, status
 *      still flips, manual prompt shown to managers.
 *   5. Settled bills stay locked (no status change, no return).
 *   6. Cashier AND delivery-manager can trigger the auto return (manual form
 *      stays manager-only — separate gate, untouched).
 *   7. Failure fallback: auto return fails → status/khata drop still applied,
 *      manual prompt shown (board never blocks).
 *   8. Bulk "All Returned": per-bill returns with one wastage choice for all.
 *
 * Pattern mirrors PosPraReturnFlowTest (sqlite :memory:, minimal schema,
 * direct controller invocation, currentCompanyId binding).
 */
class PosRiderAutoReturnTest extends TestCase
{
    protected int $companyId;
    protected int $riderId;

    protected function setUp(): void
    {
        parent::setUp();

        Schema::dropAllTables();
        User::flushScopeColumnCache();

        Schema::create('companies', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->boolean('pra_reporting_enabled')->default(false);
            $table->string('pra_connection_mode')->nullable();
            $table->boolean('inventory_enabled')->default(false);
            $table->boolean('pos_setup_completed')->default(true);
            $table->softDeletes();
            $table->timestamps();
        });

        Schema::create('users', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('company_id')->nullable();
            $table->string('name')->nullable();
            $table->string('role')->nullable();
            $table->string('pos_role')->nullable();
            $table->string('pos_billing_scope')->nullable();
            $table->boolean('pra_reporting_enabled')->nullable();
            $table->unsignedBigInteger('default_branch_id')->nullable();
            $table->timestamps();
        });

        Schema::create('pos_riders', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('company_id');
            $table->string('name');
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('pos_transactions', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('company_id');
            $table->unsignedBigInteger('terminal_id')->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->string('invoice_number');
            $table->string('transaction_type')->nullable()->default('sale');
            $table->unsignedBigInteger('parent_transaction_id')->nullable();
            $table->boolean('is_wastage')->default(false);
            $table->string('business_date')->nullable();
            $table->string('status');
            $table->string('invoice_mode')->nullable();
            $table->string('pra_status')->nullable();
            $table->string('pra_invoice_number')->nullable();
            $table->boolean('is_archived')->default(false);
            $table->unsignedBigInteger('customer_id')->nullable();
            $table->string('customer_name')->nullable();
            $table->string('customer_phone')->nullable();
            $table->decimal('subtotal', 12, 2)->default(0);
            $table->decimal('discount_amount', 12, 2)->default(0);
            $table->decimal('tax_rate', 8, 2)->nullable();
            $table->decimal('tax_amount', 12, 2)->default(0);
            $table->decimal('exempt_amount', 12, 2)->default(0);
            $table->decimal('total_amount', 12, 2)->default(0);
            $table->boolean('tax_inclusive')->default(false);
            $table->decimal('tax_menu_rate', 8, 2)->nullable();
            $table->string('payment_method')->nullable();
            // Rider / delivery columns
            $table->unsignedBigInteger('rider_id')->nullable();
            $table->string('delivery_status')->nullable();
            $table->unsignedBigInteger('rider_settlement_id')->nullable();
            $table->decimal('rider_partial_paid', 12, 2)->nullable();
            $table->timestamp('rider_assigned_at')->nullable();
            $table->timestamp('delivered_at')->nullable();
            $table->timestamps();
        });

        Schema::create('pos_transaction_items', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('transaction_id');
            $table->unsignedBigInteger('parent_item_id')->nullable();
            $table->string('item_type')->nullable();
            $table->unsignedBigInteger('item_id')->nullable();
            $table->string('item_name')->nullable();
            $table->decimal('quantity', 12, 3)->default(1);
            $table->decimal('returned_quantity', 10, 3)->default(0);
            $table->decimal('unit_price', 12, 2)->default(0);
            $table->decimal('cost_price', 12, 2)->nullable();
            $table->decimal('subtotal', 12, 2)->default(0);
            $table->boolean('is_tax_exempt')->default(false);
            $table->boolean('is_third_schedule')->default(false);
            $table->decimal('tax_rate', 8, 2)->nullable();
            $table->decimal('tax_amount', 12, 2)->default(0);
            $table->decimal('item_discount_amount', 12, 2)->default(0);
            $table->timestamps();
        });

        Schema::create('pos_products', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('company_id');
            $table->string('name');
            $table->decimal('price', 12, 2)->default(0);
            $table->decimal('cost_price', 12, 2)->nullable();
            $table->integer('stock_quantity')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('inventory_stocks', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('company_id');
            $table->unsignedBigInteger('product_id');
            $table->unsignedBigInteger('branch_id')->nullable();
            $table->decimal('quantity', 12, 3)->default(0);
            $table->decimal('min_stock_level', 12, 3)->default(0);
            $table->decimal('avg_purchase_price', 12, 2)->default(0);
            $table->decimal('last_purchase_price', 12, 2)->default(0);
            $table->timestamps();
        });

        Schema::create('inventory_movements', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('company_id');
            $table->unsignedBigInteger('product_id');
            $table->unsignedBigInteger('branch_id')->nullable();
            $table->string('type');
            $table->decimal('quantity', 12, 3)->default(0);
            $table->decimal('unit_price', 12, 2)->default(0);
            $table->decimal('total_price', 12, 2)->default(0);
            $table->decimal('balance_after', 12, 3)->default(0);
            $table->string('reference_type')->nullable();
            $table->unsignedBigInteger('reference_id')->nullable();
            $table->string('reference_number')->nullable();
            $table->text('notes')->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamps();
        });

        // PosBusinessDay consults day-close reports (creating hook stamp).
        Schema::create('pos_day_close_reports', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('company_id');
            $table->string('report_date');
            $table->timestamps();
        });

        $this->companyId = DB::table('companies')->insertGetId([
            'name' => 'Rider Return Shop',
            'pos_setup_completed' => true,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->riderId = DB::table('pos_riders')->insertGetId([
            'company_id' => $this->companyId,
            'name' => 'Rider R1',
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

    protected function actAs(string $posRole, array $attrs = []): User
    {
        DB::table('users')->insert(array_merge([
            'company_id' => $this->companyId,
            'name' => 'U-' . $posRole,
            'role' => 'user',
            'pos_role' => $posRole,
            'created_at' => now(), 'updated_at' => now(),
        ], $attrs));
        $user = User::orderByDesc('id')->first();
        Auth::guard('pos')->setUser($user);

        return $user;
    }

    /**
     * Same bill shape as PosPraReturnFlowTest: 2 lines, 17% exclusive tax,
     * Rs 100 bill discount → subtotal 2000, tax 340, total 2240. Assigned to
     * the rider, cash-on-delivery.
     */
    protected function seedDelivery(array $attrs = []): PosTransaction
    {
        $id = DB::table('pos_transactions')->insertGetId(array_merge([
            'company_id' => $this->companyId,
            'invoice_number' => 'INV-' . uniqid(),
            'transaction_type' => 'sale',
            'status' => 'completed',
            'invoice_mode' => 'pra',
            'pra_status' => 'submitted',
            'pra_invoice_number' => 'PRA-FISCAL-' . uniqid(),
            'subtotal' => 2000,
            'discount_amount' => 100,
            'tax_rate' => 17,
            'tax_amount' => 340,
            'total_amount' => 2240,
            'payment_method' => 'cash',
            'rider_id' => $this->riderId,
            'delivery_status' => 'dispatched',
            'business_date' => now()->toDateString(),
            'created_at' => now(), 'updated_at' => now(),
        ], $attrs));

        DB::table('pos_transaction_items')->insert([
            [
                'transaction_id' => $id, 'item_type' => 'product', 'item_id' => 501,
                'item_name' => 'Item A', 'quantity' => 4, 'unit_price' => 250,
                'subtotal' => 1000, 'tax_rate' => 17, 'tax_amount' => 170,
                'created_at' => now(), 'updated_at' => now(),
            ],
            [
                'transaction_id' => $id, 'item_type' => 'product', 'item_id' => 502,
                'item_name' => 'Item B', 'quantity' => 2, 'unit_price' => 500,
                'subtotal' => 1000, 'tax_rate' => 17, 'tax_amount' => 170,
                'created_at' => now(), 'updated_at' => now(),
            ],
        ]);

        return PosTransaction::withoutGlobalScope('hide_archived')->findOrFail($id);
    }

    protected function markReturned(PosTransaction $txn, array $params = [])
    {
        $request = Request::create('/pos/deliveries/' . $txn->id . '/status', 'POST', array_merge([
            'delivery_status' => 'returned',
        ], $params));
        $request->setLaravelSession(app('session.store'));

        return (new PosRiderController())->updateStatus($request, $txn->id);
    }

    protected function bulkReturned(array $params = [])
    {
        $request = Request::create('/pos/deliveries/rider/' . $this->riderId . '/bulk-status', 'POST', array_merge([
            'delivery_status' => 'returned',
        ], $params));
        $request->setLaravelSession(app('session.store'));

        return (new PosRiderController())->bulkStatus($request, $this->riderId);
    }

    protected function returnRows(PosTransaction $parent)
    {
        return PosTransaction::withoutGlobalScope('hide_archived')
            ->where('parent_transaction_id', $parent->id)
            ->where('transaction_type', 'return')->get();
    }

    /** Enable fiscal-device PRA mode — sendInvoice queues, no network. */
    protected function enableFiscalDevice(): void
    {
        DB::table('companies')->where('id', $this->companyId)->update([
            'pra_reporting_enabled' => 1,
            'pra_connection_mode' => 'fiscal_device',
        ]);
    }

    // ── 1. PRA parent → full credit note, pending ────────────────────────────

    public function test_cashier_marking_returned_creates_full_pending_credit_note(): void
    {
        $this->enableFiscalDevice();
        $this->actAs('pos_cashier');
        $parent = $this->seedDelivery();

        $this->markReturned($parent);

        $this->assertSame('returned', $parent->fresh()->delivery_status);

        $rows = $this->returnRows($parent);
        $this->assertCount(1, $rows, 'exactly one auto return bill');
        $ret = $rows->first();

        $this->assertSame('return', $ret->transaction_type);
        $this->assertSame('pra', $ret->invoice_mode);
        $this->assertSame('pending', $ret->fresh()->pra_status, 'PRA credit note queued');
        $this->assertSame('cash', $ret->payment_method, 'refund method is always cash');
        $this->assertStringStartsWith('RET-', $ret->invoice_number);
        // Full bill: 2000 + 340 − 100 discount share = 2240 (whole rupee).
        $this->assertSame(2240.0, (float) $ret->total_amount);
        $this->assertSame(100.0, (float) $ret->discount_amount);

        // Every parent line fully returned (over-return guard state).
        $qtys = DB::table('pos_transaction_items')->where('transaction_id', $parent->id)
            ->orderBy('id')->pluck('returned_quantity')->map(fn ($v) => (float) $v)->all();
        $this->assertSame([4.0, 2.0], $qtys);

        // Return has its own full-quantity lines.
        $this->assertSame(2, DB::table('pos_transaction_items')->where('transaction_id', $ret->id)->count());
    }

    // ── 2. Local/provisional parent → local return ───────────────────────────

    public function test_local_parent_creates_local_stream_return(): void
    {
        $this->actAs('pos_cashier');
        $parent = $this->seedDelivery([
            'invoice_mode' => 'local',
            'pra_status' => 'local',
            'pra_invoice_number' => null,
        ]);

        $this->markReturned($parent);

        $this->assertSame('returned', $parent->fresh()->delivery_status);
        $rows = $this->returnRows($parent);
        $this->assertCount(1, $rows);
        $this->assertSame('local', $rows->first()->invoice_mode, 'local parent → local return');
        $this->assertSame('local', $rows->first()->pra_status, 'never reported to PRA');
    }

    // ── 3. Wastage vs default restock ────────────────────────────────────────

    protected function seedInventoryForItemA(PosTransaction $parent): void
    {
        DB::table('companies')->where('id', $this->companyId)->update(['inventory_enabled' => 1]);
        DB::table('pos_products')->insert([
            'id' => 501, 'company_id' => $this->companyId, 'name' => 'Item A',
            'price' => 250, 'stock_quantity' => 10,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('inventory_stocks')->insert([
            'company_id' => $this->companyId, 'product_id' => 501, 'quantity' => 10,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        // Original sale deducted Item A (symmetry guard needs the movement).
        DB::table('inventory_movements')->insert([
            'company_id' => $this->companyId, 'product_id' => 501,
            'type' => InventoryMovement::TYPE_SALE, 'quantity' => -4,
            'reference_type' => 'pos_transaction', 'reference_id' => $parent->id,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    public function test_default_choice_restores_stock(): void
    {
        $this->actAs('pos_cashier');
        $parent = $this->seedDelivery();
        $this->seedInventoryForItemA($parent);

        $this->markReturned($parent); // no wastage param → default restock

        $ret = $this->returnRows($parent)->first();
        $this->assertNotNull($ret);
        $this->assertFalse((bool) $ret->is_wastage);

        $this->assertSame(1, DB::table('inventory_movements')
            ->where('product_id', 501)->where('type', InventoryMovement::TYPE_RETURN_IN)->count(),
            'RETURN_IN movement written');
        $this->assertSame(14.0, (float) DB::table('inventory_stocks')
            ->where('product_id', 501)->value('quantity'), 'stock restored 10 → 14');
        $this->assertSame(14, (int) DB::table('pos_products')->where('id', 501)->value('stock_quantity'),
            'pos_products mirror incremented');
    }

    public function test_wastage_choice_skips_stock_restore_and_flags_row(): void
    {
        $this->actAs('pos_cashier');
        $parent = $this->seedDelivery();
        $this->seedInventoryForItemA($parent);

        $this->markReturned($parent, ['wastage' => '1']);

        $ret = $this->returnRows($parent)->first();
        $this->assertNotNull($ret, 'return bill still created');
        $this->assertTrue((bool) $ret->is_wastage, 'wastage flag recorded');

        $this->assertSame(0, DB::table('inventory_movements')
            ->where('type', InventoryMovement::TYPE_RETURN_IN)->count(), 'no restock movement');
        $this->assertSame(10.0, (float) DB::table('inventory_stocks')
            ->where('product_id', 501)->value('quantity'), 'stock unchanged');
        $this->assertSame(10, (int) DB::table('pos_products')->where('id', 501)->value('stock_quantity'));
    }

    // ── 4. Double-refund guard ───────────────────────────────────────────────

    public function test_existing_partial_return_skips_auto_and_prompts_manager(): void
    {
        $this->actAs('pos_manager');
        $parent = $this->seedDelivery();

        // A partial return already exists against this bill.
        DB::table('pos_transactions')->insert([
            'company_id' => $this->companyId,
            'invoice_number' => 'RET-OLD-1',
            'transaction_type' => 'return',
            'parent_transaction_id' => $parent->id,
            'status' => 'completed',
            'invoice_mode' => 'pra',
            'total_amount' => 500,
            'business_date' => now()->toDateString(),
            'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('pos_transaction_items')->where('transaction_id', $parent->id)
            ->where('item_id', 501)->update(['returned_quantity' => 1]);

        $resp = $this->markReturned($parent);

        $this->assertSame('returned', $parent->fresh()->delivery_status, 'status still flips');
        $this->assertCount(1, $this->returnRows($parent), 'NO second auto return — double refund never');
        // Manual prompt fallback for the manager.
        $this->assertNotNull($resp->getSession()->get('return_prompt_url'), 'manual return prompt shown');
    }

    // ── 5. Settled bills stay locked ─────────────────────────────────────────

    public function test_settled_bill_is_locked_no_status_change_no_return(): void
    {
        $this->actAs('pos_manager');
        $parent = $this->seedDelivery(['rider_settlement_id' => 77]);

        $this->markReturned($parent);

        $this->assertSame('dispatched', $parent->fresh()->delivery_status, 'settled → status locked');
        $this->assertCount(0, $this->returnRows($parent), 'no return bill');
    }

    // ── 6. Delivery-manager trigger + bulk with wastage ──────────────────────

    public function test_delivery_manager_bulk_returned_creates_returns_with_wastage(): void
    {
        $this->enableFiscalDevice();
        $this->actAs('pos_delivery');
        $p1 = $this->seedDelivery();
        $p2 = $this->seedDelivery(['delivery_status' => 'assigned']);
        $this->seedInventoryForItemA($p1);

        $this->bulkReturned(['wastage' => '1']);

        $this->assertSame('returned', $p1->fresh()->delivery_status);
        $this->assertSame('returned', $p2->fresh()->delivery_status);

        $r1 = $this->returnRows($p1);
        $r2 = $this->returnRows($p2);
        $this->assertCount(1, $r1);
        $this->assertCount(1, $r2);
        $this->assertTrue((bool) $r1->first()->is_wastage, 'wastage choice applies to ALL bills');
        $this->assertTrue((bool) $r2->first()->is_wastage);
        $this->assertSame('pending', $r1->first()->fresh()->pra_status);

        // Wastage → stock untouched even though Item A was deducted at sale.
        $this->assertSame(10.0, (float) DB::table('inventory_stocks')
            ->where('product_id', 501)->value('quantity'));
    }

    // ── 7. Failure fallback never blocks the board ───────────────────────────

    public function test_auto_return_failure_still_flips_status_and_prompts(): void
    {
        $this->actAs('pos_manager');
        $parent = $this->seedDelivery();
        // Sabotage: no items on the parent → service refuses ('return_no_items').
        DB::table('pos_transaction_items')->where('transaction_id', $parent->id)->delete();

        $resp = $this->markReturned($parent);

        $this->assertSame('returned', $parent->fresh()->delivery_status, 'khata drop / status survives failure');
        $this->assertCount(0, $this->returnRows($parent));
        $this->assertNotNull($resp->getSession()->get('return_prompt_url'), 'manual prompt fallback shown');
    }

    // ── 8. Cashier gets no manual prompt but auto return works (regression) ──

    public function test_cashier_failure_gets_no_manual_prompt(): void
    {
        $this->actAs('pos_cashier');
        $parent = $this->seedDelivery();
        DB::table('pos_transaction_items')->where('transaction_id', $parent->id)->delete();

        $resp = $this->markReturned($parent);

        $this->assertSame('returned', $parent->fresh()->delivery_status);
        $this->assertCount(0, $this->returnRows($parent));
        $this->assertNull($resp->getSession()->get('return_prompt_url'), 'manual form stays manager-only');
    }
}
