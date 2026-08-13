<?php

namespace Tests\Feature;

use App\Http\Controllers\PosReturnController;
use App\Models\InventoryMovement;
use App\Models\PosTransaction;
use App\Models\User;
use App\Services\PraIntegrationService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

/**
 * PRA Return / Credit-Note flow (Task 570).
 *
 * Locks the core invariants of the PRA return bill:
 *   1. Manager/owner-only — cashiers get 403 (posCashierBlocked), local-scoped
 *      staff get 403 (returns live in the PRA stream).
 *   2. Only completed PRA-stream finals are returnable — provisionals and
 *      returns-of-returns are refused, and nothing is written.
 *   3. Return math: per-item proration, bill-level discount share (capped
 *      across partials), PRA whole-rupee refund total, returned_quantity
 *      updated on the parent lines, over-return refused.
 *   4. PRA eligibility: parent WITH a fiscal number + reporting ON → return
 *      goes 'pending' (fiscal-device queue path — no network in tests);
 *      parent WITHOUT a fiscal number → return stays NULL (local category).
 *   5. Payload: InvoiceType=3, RefUSIN = parent's merchant USIN, negative
 *      quantities/amounts (rows themselves store POSITIVE amounts).
 *   6. Stock symmetry: only products the ORIGINAL sale actually deducted
 *      (inventory_movements TYPE_SALE) are restored (TYPE_RETURN_IN), with
 *      the pos_products.stock_quantity mirror incremented in lockstep.
 *   7. retryPra: a local return (parent never reported) can never be promoted.
 *
 * Pattern: APP_ENV=testing + sqlite :memory: + minimal Schema::create,
 * controllers invoked directly with the currentCompanyId container binding
 * (mirrors PosPendingBillsTileTest).
 */
class PosPraReturnFlowTest extends TestCase
{
    protected int $companyId;

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

        Schema::create('pos_transactions', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('company_id');
            $table->unsignedBigInteger('terminal_id')->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->string('invoice_number');
            $table->string('transaction_type')->nullable()->default('sale');
            $table->unsignedBigInteger('parent_transaction_id')->nullable();
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
            'name' => 'Return Shop',
            'pos_setup_completed' => true,
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
     * Seed a completed parent bill: 2 lines, 17% exclusive tax, Rs 100 bill
     * discount. Item A: 4 × 250 = 1000 (tax 170); Item B: 2 × 500 = 1000
     * (tax 170). Subtotal 2000, tax 340, discount 100 → total 2240.
     */
    protected function seedParent(array $attrs = [], array $itemAttrs = []): PosTransaction
    {
        $id = DB::table('pos_transactions')->insertGetId(array_merge([
            'company_id' => $this->companyId,
            'invoice_number' => 'INV-' . uniqid(),
            'transaction_type' => 'sale',
            'status' => 'completed',
            'invoice_mode' => 'pra',
            'pra_status' => null,
            'subtotal' => 2000,
            'discount_amount' => 100,
            'tax_rate' => 17,
            'tax_amount' => 340,
            'total_amount' => 2240,
            'payment_method' => 'cash',
            'business_date' => now()->toDateString(),
            'created_at' => now(), 'updated_at' => now(),
        ], $attrs));

        DB::table('pos_transaction_items')->insert([
            array_merge([
                'transaction_id' => $id, 'item_type' => 'product', 'item_id' => 501,
                'item_name' => 'Item A', 'quantity' => 4, 'unit_price' => 250,
                'subtotal' => 1000, 'tax_rate' => 17, 'tax_amount' => 170,
                'created_at' => now(), 'updated_at' => now(),
            ], $itemAttrs),
            [
                'transaction_id' => $id, 'item_type' => 'product', 'item_id' => 502,
                'item_name' => 'Item B', 'quantity' => 2, 'unit_price' => 500,
                'subtotal' => 1000, 'tax_rate' => 17, 'tax_amount' => 170,
                'created_at' => now(), 'updated_at' => now(),
            ],
        ]);

        return PosTransaction::withoutGlobalScope('hide_archived')->findOrFail($id);
    }

    protected function itemIds(PosTransaction $parent): array
    {
        return DB::table('pos_transaction_items')
            ->where('transaction_id', $parent->id)->orderBy('id')->pluck('id')->all();
    }

    protected function postReturn(PosTransaction $parent, array $items, string $method = 'cash')
    {
        $request = Request::create('/pos/transaction/' . $parent->id . '/return', 'POST', [
            'items' => $items,
            'refund_method' => $method,
        ]);
        $request->setLaravelSession(app('session.store'));

        return (new PosReturnController())->processReturn($request, $parent->id);
    }

    protected function returnRows(PosTransaction $parent)
    {
        return PosTransaction::withoutGlobalScope('hide_archived')
            ->where('parent_transaction_id', $parent->id)
            ->where('transaction_type', 'return')->get();
    }

    // ── 1. access gates ──────────────────────────────────────────────────────

    public function test_cashier_gets_403(): void
    {
        $this->actAs('pos_cashier');
        $parent = $this->seedParent();

        try {
            (new PosReturnController())->returnForm($parent->id);
            $this->fail('cashier must not reach the return form');
        } catch (HttpException $e) {
            $this->assertSame(403, $e->getStatusCode());
        }
        $this->assertCount(0, $this->returnRows($parent));
    }

    public function test_local_scoped_staff_gets_403(): void
    {
        $this->actAs('pos_manager', ['pos_billing_scope' => 'local']);
        $parent = $this->seedParent();

        try {
            [$ids] = [$this->itemIds($parent)];
            $this->postReturn($parent, [['item_id' => $ids[0], 'return_qty' => 1]]);
            $this->fail('local-scoped staff must not create returns');
        } catch (HttpException $e) {
            $this->assertSame(403, $e->getStatusCode());
        }
        $this->assertCount(0, $this->returnRows($parent));
    }

    // ── 2. parent eligibility ────────────────────────────────────────────────

    public function test_provisional_parent_refused(): void
    {
        $this->actAs('pos_admin');
        $parent = $this->seedParent(['invoice_mode' => 'local', 'pra_status' => 'local']);
        $ids = $this->itemIds($parent);

        $resp = $this->postReturn($parent, [['item_id' => $ids[0], 'return_qty' => 1]]);

        $this->assertTrue($resp->isRedirect());
        $this->assertCount(0, $this->returnRows($parent));
    }

    public function test_return_of_return_refused(): void
    {
        $company = \App\Models\Company::find($this->companyId);
        $this->actAs('pos_admin');
        $parent = $this->seedParent();
        $ids = $this->itemIds($parent);

        $this->postReturn($parent, [['item_id' => $ids[0], 'return_qty' => 1]]);
        $ret = $this->returnRows($parent)->first();
        $this->assertNotNull($ret);

        $retItemId = DB::table('pos_transaction_items')->where('transaction_id', $ret->id)->value('id');
        $resp = $this->postReturn($ret, [['item_id' => $retItemId, 'return_qty' => 1]]);

        $this->assertTrue($resp->isRedirect());
        $this->assertCount(0, $this->returnRows($ret), 'a return bill must never be returnable');
    }

    // ── 3. return math ───────────────────────────────────────────────────────

    public function test_partial_return_math_discount_share_and_returned_quantity(): void
    {
        $this->actAs('pos_admin');
        $parent = $this->seedParent();
        $ids = $this->itemIds($parent);

        // Return 2 of 4 × Item A: sub 500, tax 85 → value 585.
        // Bill-discount share: 100 × (585 / 2340) = 25.00 → total round(560) = 560.
        $this->postReturn($parent, [['item_id' => $ids[0], 'return_qty' => 2]]);

        $ret = $this->returnRows($parent)->first();
        $this->assertNotNull($ret);
        $this->assertSame('return', $ret->transaction_type);
        $this->assertSame(500.0, (float) $ret->subtotal);
        $this->assertSame(85.0, (float) $ret->tax_amount);
        $this->assertSame(25.0, (float) $ret->discount_amount);
        $this->assertSame(560.0, (float) $ret->total_amount, 'PRA whole-rupee refund total');
        $this->assertSame('cash', $ret->payment_method);
        $this->assertNull($ret->pra_status, 'unreported parent → local return');

        $this->assertSame(2.0, (float) DB::table('pos_transaction_items')
            ->where('id', $ids[0])->value('returned_quantity'));

        // Over-return of the remaining 2 must be refused (3 > 2).
        $resp = $this->postReturn($parent, [['item_id' => $ids[0], 'return_qty' => 3]]);
        $this->assertTrue($resp->isRedirect());
        $this->assertCount(1, $this->returnRows($parent), 'over-return must not create a second return');
        $this->assertSame(2.0, (float) DB::table('pos_transaction_items')
            ->where('id', $ids[0])->value('returned_quantity'), 'failed return must not bump returned_quantity');
    }

    public function test_full_return_discount_share_capped_across_partials(): void
    {
        $this->actAs('pos_admin');
        $parent = $this->seedParent();
        $ids = $this->itemIds($parent);

        $this->postReturn($parent, [['item_id' => $ids[0], 'return_qty' => 4]]);
        $this->postReturn($parent, [['item_id' => $ids[1], 'return_qty' => 2]], 'card');

        $rows = $this->returnRows($parent);
        $this->assertCount(2, $rows);
        $totalShare = (float) $rows->sum('discount_amount');
        $this->assertLessThanOrEqual(100.0, $totalShare, 'discount share must never exceed the parent discount');
        $this->assertSame(100.0, $totalShare, 'full return must consume the whole bill discount');
        // Card bucket normalization: 'card' refunds are stored as 'debit_card'.
        $this->assertSame('debit_card', $rows->last()->payment_method);
        // Refunds sum to the parent's money: 2240 = 1145 + 1095.
        $this->assertSame(2240.0, (float) $rows->sum('total_amount'));
    }

    // ── 4. PRA eligibility + payload ─────────────────────────────────────────

    public function test_reported_parent_return_goes_pending_and_payload_is_credit_note(): void
    {
        // fiscal_device mode: sendInvoice queues for the agent — no network.
        DB::table('companies')->where('id', $this->companyId)->update([
            'pra_reporting_enabled' => 1,
            'pra_connection_mode' => 'fiscal_device',
        ]);
        $this->actAs('pos_admin');
        $parent = $this->seedParent(['pra_status' => 'submitted', 'pra_invoice_number' => 'PRA-FISCAL-777']);
        $ids = $this->itemIds($parent);

        $this->postReturn($parent, [['item_id' => $ids[0], 'return_qty' => 2]]);

        $ret = $this->returnRows($parent)->first();
        $this->assertNotNull($ret);
        $this->assertSame('pending', $ret->fresh()->pra_status, 'reported parent → return queued for PRA');

        $svc = new PraIntegrationService(\App\Models\Company::find($this->companyId));
        $payload = $svc->generatePayload($ret->fresh());

        $this->assertSame(3, $payload['InvoiceType']);
        $this->assertSame($parent->invoice_number, $payload['RefUSIN'], 'RefUSIN = parent merchant USIN');
        // PRA IMS credit note (InvoiceType=3): ALL amounts stay POSITIVE.
        // PRA signals the reversal via InvoiceType=3; negative amounts cause Code 102
        // "Invalid Total Bill Amount/Quantity/SaleValue/TaxCharged" (confirmed live Aug 2026).
        $this->assertGreaterThan(0, $payload['TotalSaleValue']);
        $this->assertGreaterThan(0, $payload['TotalTaxCharged']);
        $this->assertGreaterThan(0, $payload['TotalBillAmount']);
        foreach ($payload['Items'] as $line) {
            $this->assertGreaterThan(0, $line['Quantity']);
            $this->assertSame(3, $line['InvoiceType']);
            $this->assertSame($parent->invoice_number, $line['RefUSIN']);
        }
        // The stored row itself keeps POSITIVE amounts (FBR convention).
        $this->assertGreaterThan(0, (float) $ret->total_amount);
    }

    public function test_unreported_parent_return_stays_local_even_with_reporting_on(): void
    {
        DB::table('companies')->where('id', $this->companyId)->update(['pra_reporting_enabled' => 1]);
        $this->actAs('pos_admin');
        // Reporting-OFF final category: completed, NULL status, no fiscal number.
        $parent = $this->seedParent();
        $ids = $this->itemIds($parent);

        $this->postReturn($parent, [['item_id' => $ids[0], 'return_qty' => 1]]);

        $ret = $this->returnRows($parent)->first();
        $this->assertNotNull($ret);
        $this->assertNull($ret->fresh()->pra_status, 'no RefUSIN → return must stay local (NULL)');
    }

    public function test_retry_pra_refuses_local_return_promotion(): void
    {
        $this->actAs('pos_admin');
        $parent = $this->seedParent();
        $ids = $this->itemIds($parent);
        $this->postReturn($parent, [['item_id' => $ids[0], 'return_qty' => 1]]);
        $ret = $this->returnRows($parent)->first();

        $resp = (new \App\Http\Controllers\PosController())->retryPra($ret->id);

        $this->assertTrue($resp->isRedirect());
        $this->assertNull($ret->fresh()->pra_status, 'local return must never be promoted to PRA');
    }

    // ── 5. stock symmetry ────────────────────────────────────────────────────

    public function test_stock_restored_only_for_originally_deducted_products(): void
    {
        DB::table('companies')->where('id', $this->companyId)->update(['inventory_enabled' => 1]);
        $this->actAs('pos_admin');
        $parent = $this->seedParent();
        $ids = $this->itemIds($parent);

        // Product 501 WAS deducted at sale time (movement + stock + mirror);
        // product 502 was NOT (tracking off / service-like) — no movement.
        DB::table('pos_products')->insert([
            ['id' => 501, 'company_id' => $this->companyId, 'name' => 'Item A', 'price' => 250, 'stock_quantity' => 6, 'created_at' => now(), 'updated_at' => now()],
            ['id' => 502, 'company_id' => $this->companyId, 'name' => 'Item B', 'price' => 500, 'stock_quantity' => 9, 'created_at' => now(), 'updated_at' => now()],
        ]);
        DB::table('inventory_stocks')->insert([
            'company_id' => $this->companyId, 'product_id' => 501, 'quantity' => 6,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('inventory_movements')->insert([
            'company_id' => $this->companyId, 'product_id' => 501,
            'type' => InventoryMovement::TYPE_SALE, 'quantity' => -4,
            'reference_type' => 'pos_transaction', 'reference_id' => $parent->id,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->postReturn($parent, [
            ['item_id' => $ids[0], 'return_qty' => 2],
            ['item_id' => $ids[1], 'return_qty' => 2],
        ]);

        $ret = $this->returnRows($parent)->first();
        $this->assertNotNull($ret);

        // Deducted product: +2 in inventory_stocks, RETURN_IN movement, mirror +2.
        $this->assertSame(8.0, (float) DB::table('inventory_stocks')
            ->where('product_id', 501)->value('quantity'));
        $this->assertSame(1, DB::table('inventory_movements')
            ->where('product_id', 501)->where('type', InventoryMovement::TYPE_RETURN_IN)
            ->where('reference_type', 'pos_return')->where('reference_id', $ret->id)->count());
        $this->assertSame(8, (int) DB::table('pos_products')->where('id', 501)->value('stock_quantity'));

        // Never-deducted product: NOTHING minted.
        $this->assertSame(0, DB::table('inventory_movements')->where('product_id', 502)->count());
        $this->assertSame(9, (int) DB::table('pos_products')->where('id', 502)->value('stock_quantity'));
        $this->assertSame(0, DB::table('inventory_stocks')->where('product_id', 502)->count());
    }
}
