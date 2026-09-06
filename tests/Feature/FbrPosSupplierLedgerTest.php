<?php

namespace Tests\Feature;

use Tests\TestCase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use App\Models\PurchaseOrder;
use App\Models\PurchaseReturn;
use App\Services\SupplierLedgerService;

/**
 * FBR POS STOCK — distributor ledger, scheme/bonus costing, purchase returns
 * (Task 1580).
 *
 * Locks the money invariants of the supplier ledger:
 *
 *   1. Costing spread — a "10+1 @ Rs100, 5% line disc, Rs50 invoice disc"
 *      purchase books 11 units at (1000 − 50 − 50) / 11 = 81.8182 each, the
 *      PO's total is the NET amount, avg kharid follows the net cost.
 *   2. Paid-now → a supplier_payments row; the remainder is the balance.
 *      Balance = billed − paid − returns − claim credits, ONE service.
 *   3. Void purchase → billed drops to zero, its payment stays as an
 *      unallocated credit (negative balance), nothing drifts.
 *   4. Return of 2 units → stock −2, credit note = 2 × net unit cost, the
 *      statement's closing balance reconciles to the rupee; a return cannot
 *      exceed what the bill delivered; a bill with returns cannot be voided.
 *   5. Payment void is the only edit: a voided payment stops counting, a
 *      second void is refused.
 *   6. Cashiers / local viewers are 403 on every ledger surface; a branch
 *      filter only sees its own branch's money.
 *
 * Pattern: APP_ENV=testing + sqlite :memory: + minimal Schema::create.
 */
class FbrPosSupplierLedgerTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        \App\Services\PosFeatureService::flushGateCaches();
        SupplierLedgerService::flushSchemaCache();
        \App\Services\BranchStockService::flushMemo();
        app()->forgetInstance(\App\Services\BranchContextService::class);

        Schema::dropAllTables();

        Schema::create('companies', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('product_type')->nullable();
            $table->string('status')->nullable();
            $table->string('default_language')->nullable();
            $table->boolean('is_internal_account')->default(false);
            $table->boolean('inventory_enabled')->default(true);
            $table->boolean('fbr_pos_enabled')->default(true);
            $table->string('phone')->nullable();
            $table->softDeletes();
            $table->timestamps();
        });

        Schema::create('users', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('email')->unique();
            $table->string('password');
            $table->unsignedBigInteger('company_id')->nullable();
            $table->string('role')->nullable();
            $table->string('pos_role')->nullable();
            $table->boolean('is_active')->default(true);
            $table->string('language')->nullable();
            $table->unsignedBigInteger('default_branch_id')->nullable();
            $table->rememberToken();
            $table->timestamps();
        });
        Schema::create('branch_user', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('branch_id');
            $table->unsignedBigInteger('user_id');
            $table->timestamps();
        });

        Schema::create('branches', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('company_id');
            $table->string('name')->nullable();
            $table->boolean('is_head_office')->default(false);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('products', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('company_id');
            $table->string('name');
            $table->string('sku')->nullable();
            $table->string('barcode')->nullable();
            $table->string('uom')->nullable();
            $table->decimal('default_price', 12, 2)->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('inventory_stocks', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('company_id');
            $table->unsignedBigInteger('branch_id')->nullable();
            $table->unsignedBigInteger('product_id');
            $table->decimal('quantity', 12, 4)->default(0);
            $table->decimal('min_stock_level', 12, 4)->default(0);
            $table->decimal('avg_purchase_price', 12, 2)->default(0);
            $table->decimal('last_purchase_price', 12, 2)->default(0);
            $table->timestamps();
        });

        Schema::create('inventory_movements', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('company_id');
            $table->unsignedBigInteger('branch_id')->nullable();
            $table->unsignedBigInteger('product_id');
            $table->string('type');
            $table->decimal('quantity', 12, 4)->default(0);
            $table->decimal('unit_price', 12, 2)->default(0);
            $table->decimal('total_price', 14, 2)->default(0);
            $table->decimal('balance_after', 12, 4)->nullable();
            $table->string('reference_type')->nullable();
            $table->unsignedBigInteger('reference_id')->nullable();
            $table->string('reference_number')->nullable();
            $table->text('notes')->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamps();
        });

        Schema::create('suppliers', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('company_id');
            $table->string('name');
            $table->string('phone')->nullable();
            $table->string('city')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('purchase_orders', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('company_id');
            $table->unsignedBigInteger('supplier_id')->nullable();
            $table->unsignedBigInteger('branch_id')->nullable();
            $table->string('po_number');
            $table->string('supplier_invoice_no', 60)->nullable();
            $table->string('status');
            $table->date('order_date')->nullable();
            $table->date('expected_date')->nullable();
            $table->date('received_date')->nullable();
            $table->decimal('total_amount', 14, 2)->default(0);
            $table->decimal('gross_amount', 15, 2)->nullable();
            $table->decimal('line_discount_amount', 15, 2)->default(0);
            $table->decimal('invoice_discount_amount', 15, 2)->default(0);
            $table->text('notes')->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamps();
        });

        Schema::create('purchase_order_items', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('purchase_order_id');
            $table->unsignedBigInteger('product_id');
            $table->decimal('quantity', 12, 4)->default(0);
            $table->decimal('unit_price', 12, 2)->default(0);
            $table->decimal('total_price', 14, 2)->default(0);
            $table->decimal('received_quantity', 12, 4)->default(0);
            $table->decimal('bonus_qty', 15, 3)->default(0);
            $table->decimal('discount_pct', 6, 2)->default(0);
            $table->decimal('discount_amount', 15, 2)->default(0);
            $table->decimal('net_total', 15, 2)->nullable();
            $table->decimal('net_unit_cost', 15, 4)->nullable();
            $table->timestamps();
        });

        Schema::create('supplier_payments', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('company_id');
            $table->unsignedBigInteger('branch_id')->nullable();
            $table->unsignedBigInteger('supplier_id');
            $table->unsignedBigInteger('purchase_order_id')->nullable();
            $table->decimal('amount', 15, 2)->default(0);
            $table->string('method', 24)->default('cash');
            $table->date('paid_on')->nullable();
            $table->string('reference', 64)->nullable();
            $table->string('notes', 500)->nullable();
            $table->string('status', 12)->default('active');
            $table->timestamp('voided_at')->nullable();
            $table->unsignedBigInteger('voided_by')->nullable();
            $table->string('void_reason', 200)->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamps();
        });

        Schema::create('purchase_returns', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('company_id');
            $table->unsignedBigInteger('branch_id')->nullable();
            $table->unsignedBigInteger('supplier_id')->nullable();
            $table->unsignedBigInteger('purchase_order_id')->nullable();
            $table->string('return_number', 30);
            $table->string('reason', 20)->default('surplus');
            $table->string('supplier_reference', 60)->nullable();
            $table->decimal('credit_amount', 15, 2)->default(0);
            $table->string('status', 12)->default('posted');
            $table->date('returned_on')->nullable();
            $table->string('notes', 500)->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamps();
        });

        Schema::create('purchase_return_items', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('purchase_return_id');
            $table->unsignedBigInteger('product_id');
            $table->unsignedBigInteger('purchase_order_item_id')->nullable();
            $table->unsignedBigInteger('batch_id')->nullable();
            $table->string('batch_number', 60)->nullable();
            $table->date('expiry_date')->nullable();
            $table->decimal('quantity', 15, 3)->default(0);
            $table->decimal('unit_cost', 15, 4)->default(0);
            $table->decimal('total', 15, 2)->default(0);
            $table->timestamps();
        });

        // plan.limit:inventory needs these.
        Schema::create('pricing_plans', function (Blueprint $table) {
            $table->id();
            $table->string('name')->nullable();
            $table->string('product_type')->nullable();
            $table->boolean('is_trial')->default(false);
            $table->decimal('price', 12, 2)->default(0);
            $table->timestamps();
        });
        Schema::create('subscriptions', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('company_id');
            $table->boolean('active')->default(false);
            $table->string('override_type')->nullable();
            $table->timestamp('override_until')->nullable();
            $table->unsignedBigInteger('pricing_plan_id')->nullable();
            $table->timestamp('override_granted_at')->nullable();
            $table->timestamps();
        });

        SupplierLedgerService::flushSchemaCache();
    }

    protected function tearDown(): void
    {
        \App\Services\PosFeatureService::flushGateCaches();
        SupplierLedgerService::flushSchemaCache();
        \App\Services\BranchStockService::flushMemo();
        app()->forgetInstance(\App\Services\BranchContextService::class);
        parent::tearDown();
    }

    // ── Fixtures ─────────────────────────────────────────────────────────────

    private function makeCompany(string $name = 'Ledger Co'): int
    {
        $id = (int) DB::table('companies')->insertGetId([
            'name' => $name,
            'product_type' => 'fbrpos',
            'status' => 'active',
            'inventory_enabled' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('subscriptions')->insert([
            'company_id' => $id,
            'active' => true,
            'override_type' => 'lifetime',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $id;
    }

    private function makeUser(int $companyId, ?string $posRole = null): \App\Models\User
    {
        $id = DB::table('users')->insertGetId([
            'name' => 'Shop Owner',
            'email' => 'owner' . $companyId . ($posRole ?? 'admin') . uniqid() . '@ledger.test',
            'password' => bcrypt('Secret@12345'),
            'company_id' => $companyId,
            'role' => 'company_admin',
            'pos_role' => $posRole,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return \App\Models\User::find($id);
    }

    private function makeProduct(int $companyId, string $name = 'Panadol'): int
    {
        return (int) DB::table('products')->insertGetId([
            'company_id' => $companyId,
            'name' => $name,
            'uom' => 'U',
            'default_price' => 100,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function makeSupplier(int $companyId, string $name = 'Muller Pharma'): int
    {
        return (int) DB::table('suppliers')->insertGetId([
            'company_id' => $companyId,
            'name' => $name,
            'phone' => '03001234567',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /** The reference scenario: 10 + 1 bonus @ Rs100, 5% line disc, Rs50 invoice disc, Rs300 paid now. */
    private function postScenarioPurchase(\App\Models\User $user, int $supplierId, int $productId, array $overrides = [])
    {
        return $this->actingAs($user, 'fbrpos')->post('/fbr-pos/stock/purchase', array_merge([
            'supplier_id' => $supplierId,
            'supplier_invoice_no' => 'INV-779',
            'items' => [[
                'product_id' => $productId,
                'quantity' => 10,
                'unit_price' => 100,
                'bonus_qty' => 1,
                'discount_pct' => 5,
            ]],
            'invoice_discount' => 50,
            'paid_amount' => 300,
            'paid_method' => 'online',
        ], $overrides));
    }

    private function stockQty(int $companyId, int $productId): float
    {
        return (float) DB::table('inventory_stocks')->where('company_id', $companyId)->where('product_id', $productId)->sum('quantity');
    }

    // ── 1. Costing spread ────────────────────────────────────────────────────

    public function test_bonus_and_discounts_spread_net_cost_over_all_units(): void
    {
        $companyId = $this->makeCompany();
        $user = $this->makeUser($companyId);
        $supplierId = $this->makeSupplier($companyId);
        $productId = $this->makeProduct($companyId);

        $this->postScenarioPurchase($user, $supplierId, $productId)->assertRedirect();

        $po = DB::table('purchase_orders')->where('company_id', $companyId)->first();
        $this->assertNotNull($po);
        $this->assertSame(PurchaseOrder::STATUS_RECEIVED, $po->status);
        $this->assertSame('INV-779', $po->supplier_invoice_no);
        $this->assertEqualsWithDelta(1000.00, (float) $po->gross_amount, 0.001);
        $this->assertEqualsWithDelta(50.00, (float) $po->line_discount_amount, 0.001);
        $this->assertEqualsWithDelta(50.00, (float) $po->invoice_discount_amount, 0.001);
        // PO total is the NET amount the distributor is actually owed.
        $this->assertEqualsWithDelta(900.00, (float) $po->total_amount, 0.001);

        $item = DB::table('purchase_order_items')->where('purchase_order_id', $po->id)->first();
        $this->assertEqualsWithDelta(10, (float) $item->quantity, 0.0001);
        $this->assertEqualsWithDelta(1, (float) $item->bonus_qty, 0.0001);
        $this->assertEqualsWithDelta(11, (float) $item->received_quantity, 0.0001);
        $this->assertEqualsWithDelta(5, (float) $item->discount_pct, 0.001);
        // Line discount (50) + its share of the invoice discount (50) = 100 off 1000.
        $this->assertEqualsWithDelta(100.00, (float) $item->discount_amount, 0.001);
        $this->assertEqualsWithDelta(900.00, (float) $item->net_total, 0.001);
        $this->assertEqualsWithDelta(900 / 11, (float) $item->net_unit_cost, 0.0001);

        // Stock got ALL eleven units at the diluted cost, never the gross rate.
        $this->assertEqualsWithDelta(11, $this->stockQty($companyId, $productId), 0.0001);
        $stock = DB::table('inventory_stocks')->where('company_id', $companyId)->where('product_id', $productId)->first();
        $this->assertEqualsWithDelta(81.82, (float) $stock->avg_purchase_price, 0.01);
        $this->assertEqualsWithDelta(81.82, (float) $stock->last_purchase_price, 0.01);

        $move = DB::table('inventory_movements')->where('company_id', $companyId)->where('product_id', $productId)->first();
        $this->assertEqualsWithDelta(11, (float) $move->quantity, 0.0001);
        $this->assertEqualsWithDelta(81.82, (float) $move->unit_price, 0.01);
    }

    public function test_paid_now_cannot_exceed_net_bill(): void
    {
        $companyId = $this->makeCompany();
        $user = $this->makeUser($companyId);
        $supplierId = $this->makeSupplier($companyId);
        $productId = $this->makeProduct($companyId);

        $this->postScenarioPurchase($user, $supplierId, $productId, ['paid_amount' => 901])->assertSessionHas('error');
        $this->assertSame(0, DB::table('purchase_orders')->count());
        $this->assertSame(0, DB::table('supplier_payments')->count());
        $this->assertEqualsWithDelta(0, $this->stockQty($companyId, $productId), 0.0001);
    }

    public function test_paid_now_without_supplier_is_refused(): void
    {
        $companyId = $this->makeCompany();
        $user = $this->makeUser($companyId);
        $productId = $this->makeProduct($companyId);

        $this->postScenarioPurchase($user, 0, $productId, ['supplier_id' => null, 'paid_amount' => 100])->assertSessionHas('error');
        $this->assertSame(0, DB::table('purchase_orders')->count());
    }

    // ── 2. Paid-now → payment row → balance ─────────────────────────────────

    public function test_paid_now_books_a_payment_and_the_rest_is_the_balance(): void
    {
        $companyId = $this->makeCompany();
        $user = $this->makeUser($companyId);
        $supplierId = $this->makeSupplier($companyId);
        $productId = $this->makeProduct($companyId);

        $this->postScenarioPurchase($user, $supplierId, $productId)->assertRedirect();

        $pay = DB::table('supplier_payments')->where('company_id', $companyId)->first();
        $this->assertNotNull($pay);
        $this->assertEqualsWithDelta(300.00, (float) $pay->amount, 0.001);
        $this->assertSame('online', $pay->method);
        $this->assertSame('active', $pay->status);
        $this->assertSame($supplierId, (int) $pay->supplier_id);
        $this->assertNotNull($pay->purchase_order_id);

        $bal = SupplierLedgerService::balanceFor($companyId, $supplierId);
        $this->assertEqualsWithDelta(900.00, $bal->billed, 0.001);
        $this->assertEqualsWithDelta(300.00, $bal->paid, 0.001);
        $this->assertEqualsWithDelta(600.00, $bal->balance, 0.001);

        $totals = SupplierLedgerService::totals($companyId);
        $this->assertEqualsWithDelta(600.00, $totals['payable'], 0.001);
        $this->assertSame(1, $totals['suppliers_due']);
    }

    // ── 3. Void purchase leaves the payment as an unallocated credit ─────────

    public function test_void_purchase_reverses_billing_but_keeps_payment_as_credit(): void
    {
        $companyId = $this->makeCompany();
        $user = $this->makeUser($companyId);
        $supplierId = $this->makeSupplier($companyId);
        $productId = $this->makeProduct($companyId);

        $this->postScenarioPurchase($user, $supplierId, $productId)->assertRedirect();
        $poId = (int) DB::table('purchase_orders')->value('id');

        $this->actingAs($user, 'fbrpos')->post("/fbr-pos/stock/purchase/{$poId}/void")->assertRedirect();

        $this->assertSame(PurchaseOrder::STATUS_CANCELLED, DB::table('purchase_orders')->where('id', $poId)->value('status'));
        // Void reverses exactly the 11 received units at the net cost.
        $this->assertEqualsWithDelta(0, $this->stockQty($companyId, $productId), 0.0001);
        $out = DB::table('inventory_movements')->where('company_id', $companyId)->where('type', 'return_out')->first();
        $this->assertNotNull($out);
        $this->assertEqualsWithDelta(11, (float) $out->quantity, 0.0001);
        $this->assertEqualsWithDelta(81.82, (float) $out->unit_price, 0.01);

        $bal = SupplierLedgerService::balanceFor($companyId, $supplierId);
        $this->assertEqualsWithDelta(0.00, $bal->billed, 0.001);
        $this->assertEqualsWithDelta(300.00, $bal->paid, 0.001);
        $this->assertEqualsWithDelta(-300.00, $bal->balance, 0.001, 'the Rs300 already paid must stay as the shop\'s advance');
        $this->assertSame('active', DB::table('supplier_payments')->value('status'));

        $totals = SupplierLedgerService::totals($companyId);
        $this->assertEqualsWithDelta(0.00, $totals['payable'], 0.001);
        $this->assertEqualsWithDelta(300.00, $totals['advance'], 0.001);
    }

    // ── 4. Purchase return ──────────────────────────────────────────────────

    public function test_return_against_bill_moves_stock_out_and_credits_the_ledger(): void
    {
        $companyId = $this->makeCompany();
        $user = $this->makeUser($companyId);
        $supplierId = $this->makeSupplier($companyId);
        $productId = $this->makeProduct($companyId);

        $this->postScenarioPurchase($user, $supplierId, $productId)->assertRedirect();
        $poId = (int) DB::table('purchase_orders')->value('id');
        $itemId = (int) DB::table('purchase_order_items')->value('id');

        $res = $this->actingAs($user, 'fbrpos')->post('/fbr-pos/stock/returns', [
            'purchase_order_id' => $poId,
            'reason' => 'surplus',
            'items' => [[
                'product_id' => $productId,
                'purchase_order_item_id' => $itemId,
                'quantity' => 2,
                'unit_cost' => round(900 / 11, 2),
            ]],
        ]);
        $res->assertRedirect();
        $res->assertSessionHas('success');

        $ret = DB::table('purchase_returns')->where('company_id', $companyId)->first();
        $this->assertNotNull($ret);
        $this->assertSame('posted', $ret->status);
        $this->assertSame($supplierId, (int) $ret->supplier_id, 'supplier is pinned to the bill\'s supplier');
        $this->assertSame($poId, (int) $ret->purchase_order_id);
        $this->assertStringStartsWith('PR-', $ret->return_number);
        $this->assertEqualsWithDelta(2 * 81.82, (float) $ret->credit_amount, 0.001);

        $line = DB::table('purchase_return_items')->where('purchase_return_id', $ret->id)->first();
        $this->assertEqualsWithDelta(2, (float) $line->quantity, 0.0001);
        $this->assertSame($itemId, (int) $line->purchase_order_item_id);

        // Stock 11 → 9 through the normal reversal path.
        $this->assertEqualsWithDelta(9, $this->stockQty($companyId, $productId), 0.0001);
        $mv = DB::table('inventory_movements')->where('reference_type', 'purchase_return')->first();
        $this->assertNotNull($mv);
        $this->assertSame('return_out', $mv->type);
        $this->assertEqualsWithDelta(2, (float) $mv->quantity, 0.0001);

        // Ledger: 900 − 300 − 163.64 = 436.36, and the statement agrees.
        $bal = SupplierLedgerService::balanceFor($companyId, $supplierId);
        $this->assertEqualsWithDelta(163.64, $bal->returned, 0.001);
        $this->assertEqualsWithDelta(436.36, $bal->balance, 0.001);

        $st = SupplierLedgerService::statement($companyId, $supplierId, null, null, null);
        $this->assertEqualsWithDelta(0, $st['opening'], 0.001);
        $this->assertEqualsWithDelta(436.36, $st['closing'], 0.001);
        $this->assertCount(3, $st['rows']);
        $kinds = array_column($st['rows'], 'kind');
        $this->assertSame(['purchase', 'return', 'payment'], $kinds);
        $this->assertEqualsWithDelta(436.36, end($st['rows'])['balance'], 0.001);
        $this->assertEqualsWithDelta(900.00, $st['period']['billed'], 0.001);
        $this->assertEqualsWithDelta(300.00, $st['period']['paid'], 0.001);
        $this->assertEqualsWithDelta(163.64, $st['period']['returned'], 0.001);

        // The statement page and print render with these numbers.
        $this->actingAs($user, 'fbrpos')->get("/fbr-pos/stock/suppliers/{$supplierId}/statement")
            ->assertOk()->assertSee('436.36')->assertSee($ret->return_number);
        $this->actingAs($user, 'fbrpos')->get("/fbr-pos/stock/returns/{$ret->id}/print")
            ->assertOk()->assertSee($ret->return_number)->assertSee('163.64');
        $csv = $this->actingAs($user, 'fbrpos')->get("/fbr-pos/stock/suppliers/{$supplierId}/statement?export=csv");
        $csv->assertOk()->assertHeader('content-type', 'text/csv; charset=UTF-8');
        $this->assertStringContainsString('436.36', $csv->streamedContent());
    }

    public function test_return_cannot_exceed_what_the_bill_delivered(): void
    {
        $companyId = $this->makeCompany();
        $user = $this->makeUser($companyId);
        $supplierId = $this->makeSupplier($companyId);
        $productId = $this->makeProduct($companyId);

        $this->postScenarioPurchase($user, $supplierId, $productId)->assertRedirect();
        $poId = (int) DB::table('purchase_orders')->value('id');
        $itemId = (int) DB::table('purchase_order_items')->value('id');

        $this->actingAs($user, 'fbrpos')->post('/fbr-pos/stock/returns', [
            'purchase_order_id' => $poId,
            'reason' => 'wrong',
            'items' => [['product_id' => $productId, 'purchase_order_item_id' => $itemId, 'quantity' => 12, 'unit_cost' => 81.82]],
        ])->assertSessionHas('error');

        $this->assertSame(0, DB::table('purchase_returns')->count());
        $this->assertEqualsWithDelta(11, $this->stockQty($companyId, $productId), 0.0001);
        $this->assertEqualsWithDelta(600.00, SupplierLedgerService::balanceFor($companyId, $supplierId)->balance, 0.001);
    }

    public function test_bill_with_a_posted_return_cannot_be_voided(): void
    {
        $companyId = $this->makeCompany();
        $user = $this->makeUser($companyId);
        $supplierId = $this->makeSupplier($companyId);
        $productId = $this->makeProduct($companyId);

        $this->postScenarioPurchase($user, $supplierId, $productId)->assertRedirect();
        $poId = (int) DB::table('purchase_orders')->value('id');
        $itemId = (int) DB::table('purchase_order_items')->value('id');
        $this->actingAs($user, 'fbrpos')->post('/fbr-pos/stock/returns', [
            'purchase_order_id' => $poId,
            'reason' => 'surplus',
            'items' => [['product_id' => $productId, 'purchase_order_item_id' => $itemId, 'quantity' => 2, 'unit_cost' => 81.82]],
        ])->assertSessionHas('success');

        $this->actingAs($user, 'fbrpos')->post("/fbr-pos/stock/purchase/{$poId}/void")->assertSessionHas('error');
        $this->assertSame(PurchaseOrder::STATUS_RECEIVED, DB::table('purchase_orders')->where('id', $poId)->value('status'));
        $this->assertEqualsWithDelta(9, $this->stockQty($companyId, $productId), 0.0001, 'no stock reversed twice');
    }

    public function test_free_form_return_needs_a_supplier_and_credits_them(): void
    {
        $companyId = $this->makeCompany();
        $user = $this->makeUser($companyId);
        $supplierId = $this->makeSupplier($companyId);
        $productId = $this->makeProduct($companyId);
        \App\Services\InventoryService::addStock($companyId, $productId, 5, 50, \App\Models\InventoryMovement::TYPE_PURCHASE, null, null, null, $user->id);

        $this->actingAs($user, 'fbrpos')->post('/fbr-pos/stock/returns', [
            'reason' => 'damaged',
            'items' => [['product_id' => $productId, 'quantity' => 1, 'unit_cost' => 50]],
        ])->assertSessionHas('error');
        $this->assertSame(0, DB::table('purchase_returns')->count());

        $this->actingAs($user, 'fbrpos')->post('/fbr-pos/stock/returns', [
            'supplier_id' => $supplierId,
            'reason' => 'damaged',
            'items' => [['product_id' => $productId, 'quantity' => 1, 'unit_cost' => 50]],
        ])->assertSessionHas('success');

        $this->assertEqualsWithDelta(4, $this->stockQty($companyId, $productId), 0.0001);
        $this->assertEqualsWithDelta(-50.00, SupplierLedgerService::balanceFor($companyId, $supplierId)->balance, 0.001);
    }

    // ── 5. Payment record / void ─────────────────────────────────────────────

    public function test_payment_void_is_the_only_edit_and_never_double_voids(): void
    {
        $companyId = $this->makeCompany();
        $user = $this->makeUser($companyId);
        $supplierId = $this->makeSupplier($companyId);
        $productId = $this->makeProduct($companyId);
        $this->postScenarioPurchase($user, $supplierId, $productId, ['paid_amount' => 0])->assertRedirect();

        $this->actingAs($user, 'fbrpos')->post('/fbr-pos/stock/payments', [
            'supplier_id' => $supplierId,
            'amount' => 250,
            'method' => 'cheque',
            'reference' => 'CHQ-1',
        ])->assertSessionHas('success');
        $this->assertEqualsWithDelta(650.00, SupplierLedgerService::balanceFor($companyId, $supplierId)->balance, 0.001);

        $payId = (int) DB::table('supplier_payments')->value('id');
        $this->actingAs($user, 'fbrpos')->post("/fbr-pos/stock/payments/{$payId}/void")->assertSessionHas('success');
        $row = DB::table('supplier_payments')->where('id', $payId)->first();
        $this->assertSame('void', $row->status);
        $this->assertNotNull($row->voided_at);
        $this->assertSame($user->id, (int) $row->voided_by);
        $this->assertEqualsWithDelta(900.00, SupplierLedgerService::balanceFor($companyId, $supplierId)->balance, 0.001);

        // Statement keeps the voided row, flagged and moneyless.
        $st = SupplierLedgerService::statement($companyId, $supplierId, null, null, null);
        $voidRows = array_values(array_filter($st['rows'], fn ($r) => $r['kind'] === 'payment'));
        $this->assertCount(1, $voidRows);
        $this->assertTrue($voidRows[0]['void']);
        $this->assertEqualsWithDelta(0, $voidRows[0]['credit'], 0.001);

        $this->actingAs($user, 'fbrpos')->post("/fbr-pos/stock/payments/{$payId}/void")->assertSessionHas('error');
        $this->assertSame(1, DB::table('supplier_payments')->count());
    }

    public function test_future_dated_payment_is_refused(): void
    {
        $companyId = $this->makeCompany();
        $user = $this->makeUser($companyId);
        $supplierId = $this->makeSupplier($companyId);

        $this->actingAs($user, 'fbrpos')->post('/fbr-pos/stock/payments', [
            'supplier_id' => $supplierId,
            'amount' => 10,
            'method' => 'cash',
            'paid_on' => now()->addDay()->toDateString(),
        ])->assertSessionHas('error');
        $this->assertSame(0, DB::table('supplier_payments')->count());
    }

    // ── 6. Role gating, panel isolation, branch scoping ─────────────────────

    public function test_cashier_and_local_viewer_are_blocked_on_every_ledger_surface(): void
    {
        $companyId = $this->makeCompany();
        $owner = $this->makeUser($companyId);
        $supplierId = $this->makeSupplier($companyId);
        $productId = $this->makeProduct($companyId);
        $this->postScenarioPurchase($owner, $supplierId, $productId)->assertRedirect();
        $poId = (int) DB::table('purchase_orders')->value('id');
        $payId = (int) DB::table('supplier_payments')->value('id');

        foreach (['pos_cashier', 'local_viewer'] as $role) {
            $blocked = $this->makeUser($companyId, $role);
            $this->actingAs($blocked, 'fbrpos')->get("/fbr-pos/stock/suppliers/{$supplierId}/statement")->assertStatus(403);
            $this->actingAs($blocked, 'fbrpos')->get("/fbr-pos/stock/suppliers/{$supplierId}/statement/pdf")->assertStatus(403);
            $this->actingAs($blocked, 'fbrpos')->get('/fbr-pos/stock/returns')->assertStatus(403);
            $this->actingAs($blocked, 'fbrpos')->get("/fbr-pos/stock/purchases/{$poId}/lines")->assertStatus(403);
            $this->actingAs($blocked, 'fbrpos')->post('/fbr-pos/stock/payments', [
                'supplier_id' => $supplierId, 'amount' => 5, 'method' => 'cash',
            ])->assertStatus(403);
            $this->actingAs($blocked, 'fbrpos')->post("/fbr-pos/stock/payments/{$payId}/void")->assertStatus(403);
            $this->actingAs($blocked, 'fbrpos')->post('/fbr-pos/stock/returns', [
                'purchase_order_id' => $poId, 'reason' => 'surplus',
                'items' => [['product_id' => $productId, 'quantity' => 1, 'unit_cost' => 80]],
            ])->assertStatus(403);
        }

        $this->assertSame(1, DB::table('supplier_payments')->count());
        $this->assertSame('active', DB::table('supplier_payments')->value('status'));
        $this->assertSame(0, DB::table('purchase_returns')->count());
        $this->assertEqualsWithDelta(11, $this->stockQty($companyId, $productId), 0.0001);
    }

    public function test_another_company_cannot_read_or_touch_the_ledger(): void
    {
        $companyA = $this->makeCompany('A');
        $ownerA = $this->makeUser($companyA);
        $supplierA = $this->makeSupplier($companyA);
        $productA = $this->makeProduct($companyA);
        $this->postScenarioPurchase($ownerA, $supplierA, $productA)->assertRedirect();
        $poA = (int) DB::table('purchase_orders')->value('id');
        $payA = (int) DB::table('supplier_payments')->value('id');

        $companyB = $this->makeCompany('B');
        $ownerB = $this->makeUser($companyB);

        // Panel isolation turns an HTML 404 into a redirect+flash inside the
        // FBR panel; JSON callers get the real 404.
        $this->actingAs($ownerB, 'fbrpos')->get("/fbr-pos/stock/suppliers/{$supplierA}/statement")
            ->assertRedirect('/fbr-pos/dashboard')->assertSessionHas('error');
        $this->actingAs($ownerB, 'fbrpos')->getJson("/fbr-pos/stock/purchases/{$poA}/lines")->assertStatus(404);
        $this->actingAs($ownerB, 'fbrpos')->post("/fbr-pos/stock/payments/{$payA}/void")
            ->assertRedirect('/fbr-pos/dashboard')->assertSessionHas('error');
        $this->actingAs($ownerB, 'fbrpos')->post('/fbr-pos/stock/payments', [
            'supplier_id' => $supplierA, 'amount' => 5, 'method' => 'cash',
        ])->assertSessionHas('error');
        $this->actingAs($ownerB, 'fbrpos')->post('/fbr-pos/stock/returns', [
            'purchase_order_id' => $poA, 'reason' => 'surplus',
            'items' => [['product_id' => $productA, 'quantity' => 1, 'unit_cost' => 80]],
        ]);

        $this->assertSame('active', DB::table('supplier_payments')->where('id', $payA)->value('status'));
        $this->assertSame(1, DB::table('supplier_payments')->count());
        $this->assertSame(0, DB::table('purchase_returns')->count());
        $this->assertEqualsWithDelta(600.00, SupplierLedgerService::balanceFor($companyA, $supplierA)->balance, 0.001);
    }

    public function test_branch_filter_only_sees_its_own_branch_money(): void
    {
        $companyId = $this->makeCompany();
        $supplierId = $this->makeSupplier($companyId);
        $b1 = (int) DB::table('branches')->insertGetId(['company_id' => $companyId, 'name' => 'Main', 'is_head_office' => true, 'created_at' => now(), 'updated_at' => now()]);
        $b2 = (int) DB::table('branches')->insertGetId(['company_id' => $companyId, 'name' => 'Cantt', 'created_at' => now(), 'updated_at' => now()]);

        DB::table('purchase_orders')->insert([
            ['company_id' => $companyId, 'supplier_id' => $supplierId, 'branch_id' => $b1, 'po_number' => 'P1', 'status' => 'received', 'received_date' => now()->toDateString(), 'total_amount' => 1000, 'created_at' => now(), 'updated_at' => now()],
            ['company_id' => $companyId, 'supplier_id' => $supplierId, 'branch_id' => $b2, 'po_number' => 'P2', 'status' => 'received', 'received_date' => now()->toDateString(), 'total_amount' => 400, 'created_at' => now(), 'updated_at' => now()],
        ]);
        DB::table('supplier_payments')->insert([
            ['company_id' => $companyId, 'supplier_id' => $supplierId, 'branch_id' => $b1, 'amount' => 100, 'method' => 'cash', 'status' => 'active', 'paid_on' => now()->toDateString(), 'created_at' => now(), 'updated_at' => now()],
            ['company_id' => $companyId, 'supplier_id' => $supplierId, 'branch_id' => $b2, 'amount' => 50, 'method' => 'cash', 'status' => 'active', 'paid_on' => now()->toDateString(), 'created_at' => now(), 'updated_at' => now()],
        ]);
        DB::table('purchase_returns')->insert([
            'company_id' => $companyId, 'supplier_id' => $supplierId, 'branch_id' => $b2, 'return_number' => 'PR-1', 'reason' => 'surplus', 'credit_amount' => 30, 'status' => 'posted', 'returned_on' => now()->toDateString(), 'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->assertEqualsWithDelta(1400 - 150 - 30, SupplierLedgerService::balanceFor($companyId, $supplierId)->balance, 0.001, 'company-wide');
        $this->assertEqualsWithDelta(900.00, SupplierLedgerService::balanceFor($companyId, $supplierId, $b1)->balance, 0.001);
        $this->assertEqualsWithDelta(320.00, SupplierLedgerService::balanceFor($companyId, $supplierId, $b2)->balance, 0.001);

        $st = SupplierLedgerService::statement($companyId, $supplierId, $b2, null, null);
        $this->assertCount(3, $st['rows']);
        $this->assertEqualsWithDelta(320.00, $st['closing'], 0.001);
    }

    public function test_date_filter_carries_the_opening_balance_forward(): void
    {
        $companyId = $this->makeCompany();
        $supplierId = $this->makeSupplier($companyId);

        DB::table('purchase_orders')->insert([
            ['company_id' => $companyId, 'supplier_id' => $supplierId, 'po_number' => 'OLD', 'status' => 'received', 'received_date' => '2026-01-10', 'total_amount' => 1000, 'created_at' => '2026-01-10 10:00:00', 'updated_at' => now()],
            ['company_id' => $companyId, 'supplier_id' => $supplierId, 'po_number' => 'NEW', 'status' => 'received', 'received_date' => '2026-03-05', 'total_amount' => 200, 'created_at' => '2026-03-05 10:00:00', 'updated_at' => now()],
            ['company_id' => $companyId, 'supplier_id' => $supplierId, 'po_number' => 'GONE', 'status' => 'cancelled', 'received_date' => '2026-03-06', 'total_amount' => 999, 'created_at' => '2026-03-06 10:00:00', 'updated_at' => now()],
        ]);
        DB::table('supplier_payments')->insert([
            ['company_id' => $companyId, 'supplier_id' => $supplierId, 'amount' => 400, 'method' => 'cash', 'status' => 'active', 'paid_on' => '2026-02-01', 'created_at' => '2026-02-01 10:00:00', 'updated_at' => now()],
        ]);

        $st = SupplierLedgerService::statement($companyId, $supplierId, null, '2026-03-01', '2026-03-31');
        $this->assertEqualsWithDelta(600.00, $st['opening'], 0.001, '1000 billed − 400 paid before March');
        $this->assertCount(2, $st['rows']);
        $this->assertEqualsWithDelta(800.00, $st['closing'], 0.001);
        // The cancelled bill is listed for the audit trail but carries no money.
        $gone = array_values(array_filter($st['rows'], fn ($r) => $r['ref'] === 'GONE'))[0];
        $this->assertTrue($gone['void']);
        $this->assertEqualsWithDelta(0, $gone['debit'], 0.001);
        $this->assertEqualsWithDelta(200.00, $st['period']['billed'], 0.001);
    }
    // ── 7. Adversarial: bill-linked returns are authoritative, per-bill serialised ─

    public function test_bill_linked_return_ignores_posted_cost_and_needs_a_real_line(): void
    {
        $companyId = $this->makeCompany();
        $user = $this->makeUser($companyId);
        $supplierId = $this->makeSupplier($companyId);
        $productId = $this->makeProduct($companyId);
        $otherProduct = $this->makeProduct($companyId, 'Brufen');

        $this->postScenarioPurchase($user, $supplierId, $productId)->assertRedirect();
        $poId = (int) DB::table('purchase_orders')->value('id');
        $itemId = (int) DB::table('purchase_order_items')->value('id');

        // (a) No line id → refused (the old path silently skipped the cap).
        $this->actingAs($user, 'fbrpos')->post('/fbr-pos/stock/returns', [
            'purchase_order_id' => $poId, 'reason' => 'surplus',
            'items' => [['product_id' => $productId, 'quantity' => 2, 'unit_cost' => 5000]],
        ])->assertSessionHas('error');
        // (b) A line id of the bill but another product → refused.
        $this->actingAs($user, 'fbrpos')->post('/fbr-pos/stock/returns', [
            'purchase_order_id' => $poId, 'reason' => 'surplus',
            'items' => [['product_id' => $otherProduct, 'purchase_order_item_id' => $itemId, 'quantity' => 1, 'unit_cost' => 10]],
        ])->assertSessionHas('error');
        // (c) A line id that is not on this bill at all → refused.
        $this->actingAs($user, 'fbrpos')->post('/fbr-pos/stock/returns', [
            'purchase_order_id' => $poId, 'reason' => 'surplus',
            'items' => [['product_id' => $productId, 'purchase_order_item_id' => $itemId + 99, 'quantity' => 1, 'unit_cost' => 10]],
        ])->assertSessionHas('error');
        $this->assertSame(0, DB::table('purchase_returns')->count());
        $this->assertEqualsWithDelta(11, $this->stockQty($companyId, $productId), 0.0001);

        // (d) A valid line but an inflated posted cost → credited at the BILL's
        // net unit cost (81.82), never the posted 5000.
        $this->actingAs($user, 'fbrpos')->post('/fbr-pos/stock/returns', [
            'purchase_order_id' => $poId, 'reason' => 'surplus',
            'items' => [['product_id' => $productId, 'purchase_order_item_id' => $itemId, 'quantity' => 2, 'unit_cost' => 5000]],
        ])->assertRedirect('/fbr-pos/stock/returns')->assertSessionHas('success');
        $ret = PurchaseReturn::first();
        $this->assertEqualsWithDelta(163.64, (float) $ret->credit_amount, 0.001);
        $line = DB::table('purchase_return_items')->where('purchase_return_id', $ret->id)->first();
        $this->assertEqualsWithDelta(81.8182, (float) $line->unit_cost, 0.001);
        $mv = DB::table('inventory_movements')->where('reference_type', 'purchase_return')->first();
        $this->assertEqualsWithDelta(81.8182, (float) $mv->unit_price, 0.001, 'stock leaves at the bill cost too');
        $this->assertEqualsWithDelta(600 - 163.64, SupplierLedgerService::balanceFor($companyId, $supplierId)->balance, 0.001);
    }

    public function test_return_cap_holds_across_documents_and_duplicate_lines_in_one_form(): void
    {
        $companyId = $this->makeCompany();
        $user = $this->makeUser($companyId);
        $supplierId = $this->makeSupplier($companyId);
        $productId = $this->makeProduct($companyId);

        $this->postScenarioPurchase($user, $supplierId, $productId)->assertRedirect();
        $poId = (int) DB::table('purchase_orders')->value('id');
        $itemId = (int) DB::table('purchase_order_items')->value('id');

        // Two rows for the same line in ONE form: 6 + 6 = 12 > 11 delivered →
        // the whole document is refused (nothing partial).
        $this->actingAs($user, 'fbrpos')->post('/fbr-pos/stock/returns', [
            'purchase_order_id' => $poId, 'reason' => 'surplus',
            'items' => [
                ['product_id' => $productId, 'purchase_order_item_id' => $itemId, 'quantity' => 6, 'unit_cost' => 81.82],
                ['product_id' => $productId, 'purchase_order_item_id' => $itemId, 'quantity' => 6, 'unit_cost' => 81.82],
            ],
        ])->assertSessionHas('error');
        $this->assertSame(0, DB::table('purchase_returns')->count());
        $this->assertSame(0, DB::table('purchase_return_items')->count());
        $this->assertEqualsWithDelta(11, $this->stockQty($companyId, $productId), 0.0001);

        // Two documents: 6 then 6 — the second (stale form) is refused, 6 then 5 passes.
        $post = fn (float $qty) => $this->actingAs($user, 'fbrpos')->post('/fbr-pos/stock/returns', [
            'purchase_order_id' => $poId, 'reason' => 'surplus',
            'items' => [['product_id' => $productId, 'purchase_order_item_id' => $itemId, 'quantity' => $qty, 'unit_cost' => 81.82]],
        ]);
        $post(6)->assertSessionHas('success');
        $post(6)->assertSessionHas('error');
        $post(5)->assertSessionHas('success');
        $post(0.5)->assertSessionHas('error');
        $this->assertSame(2, DB::table('purchase_returns')->count());
        $this->assertEqualsWithDelta(0, $this->stockQty($companyId, $productId), 0.0001);
        $this->assertEqualsWithDelta(11, (float) DB::table('purchase_return_items')->sum('quantity'), 0.0001);
        // 900 − 300 − 11×81.8182 = −300 → the shop is Rs300 in advance, to the rupee.
        $this->assertEqualsWithDelta(-300.00, SupplierLedgerService::balanceFor($companyId, $supplierId)->balance, 0.01);
    }

    // ── 8. Adversarial: branch isolation on bill-linked reads/payments/returns ─

    /** Two-branch company; owner books the scenario bill into Main, a manager is confined to Cantt. */
    private function twoBranchScenario(): array
    {
        $companyId = $this->makeCompany();
        $b1 = (int) DB::table('branches')->insertGetId(['company_id' => $companyId, 'name' => 'Main', 'is_head_office' => true, 'created_at' => now(), 'updated_at' => now()]);
        $b2 = (int) DB::table('branches')->insertGetId(['company_id' => $companyId, 'name' => 'Cantt', 'created_at' => now(), 'updated_at' => now()]);
        $owner = $this->makeUser($companyId);
        $supplierId = $this->makeSupplier($companyId);
        $productId = $this->makeProduct($companyId);

        $this->withSession([\App\Services\BranchContextService::SESSION_KEY => $b1]);
        $this->postScenarioPurchase($owner, $supplierId, $productId, ['branch_id' => $b1])->assertRedirect()->assertSessionHas('success');
        $poId = (int) DB::table('purchase_orders')->value('id');
        $itemId = (int) DB::table('purchase_order_items')->value('id');
        $this->assertSame($b1, (int) DB::table('purchase_orders')->value('branch_id'));
        $this->assertSame($b1, (int) DB::table('inventory_movements')->where('type', 'purchase')->value('branch_id'));

        $manager = $this->makeUser($companyId, 'pos_manager');
        DB::table('users')->where('id', $manager->id)->update(['default_branch_id' => $b2]);
        DB::table('branch_user')->insert(['branch_id' => $b2, 'user_id' => $manager->id, 'created_at' => now(), 'updated_at' => now()]);
        $manager = \App\Models\User::find($manager->id);

        // Fresh actor context for the confined manager.
        \App\Services\BranchStockService::flushMemo();
        app()->forgetInstance(\App\Services\BranchContextService::class);
        $this->flushSession();
        $this->withSession([\App\Services\BranchContextService::SESSION_KEY => $b2]);

        return compact('companyId', 'b1', 'b2', 'owner', 'manager', 'supplierId', 'productId', 'poId', 'itemId');
    }

    public function test_confined_manager_cannot_read_pay_or_return_another_branch_bill(): void
    {
        extract($this->twoBranchScenario());

        // Lines of Main's bill are not visible from Cantt (404, no existence leak).
        $this->actingAs($manager, 'fbrpos')->getJson("/fbr-pos/stock/purchases/{$poId}/lines")->assertNotFound();
        // …while the owner (all branches) still can.
        $this->actingAs($owner, 'fbrpos')->getJson("/fbr-pos/stock/purchases/{$poId}/lines")->assertOk()->assertJsonPath('lines.0.remaining', 11);

        // A payment "against" Main's bill posted from Cantt is refused …
        $this->actingAs($manager, 'fbrpos')->post('/fbr-pos/stock/payments', [
            'supplier_id' => $supplierId, 'purchase_order_id' => $poId, 'amount' => 100, 'method' => 'cash',
        ])->assertSessionHas('error');
        $this->assertSame(1, DB::table('supplier_payments')->count(), 'only the paid-now row exists');

        // … and so is a return of Main's goods from Cantt: no document, no stock
        // movement, Main's balance untouched.
        $this->actingAs($manager, 'fbrpos')->post('/fbr-pos/stock/returns', [
            'purchase_order_id' => $poId, 'reason' => 'surplus', 'branch_id' => $b2,
            'items' => [['product_id' => $productId, 'purchase_order_item_id' => $itemId, 'quantity' => 2, 'unit_cost' => 81.82]],
        ])->assertSessionHas('error');
        $this->assertSame(0, DB::table('purchase_returns')->count());
        $this->assertSame(0, DB::table('inventory_movements')->where('type', 'return_out')->count());
        $this->assertEqualsWithDelta(11, $this->stockQty($companyId, $productId), 0.0001);
        $this->assertEqualsWithDelta(600.00, SupplierLedgerService::balanceFor($companyId, $supplierId, $b1)->balance, 0.001);
        $this->assertEqualsWithDelta(0.00, SupplierLedgerService::balanceFor($companyId, $supplierId, $b2)->balance, 0.001);

        // The manager CAN still pay the distributor on account from Cantt.
        $this->actingAs($manager, 'fbrpos')->post('/fbr-pos/stock/payments', [
            'supplier_id' => $supplierId, 'amount' => 100, 'method' => 'cash',
        ])->assertSessionHas('success');
        $this->assertSame($b2, (int) DB::table('supplier_payments')->orderByDesc('id')->value('branch_id'));
    }

    public function test_bill_linked_money_and_goods_stay_in_the_bill_branch_even_when_another_is_picked(): void
    {
        extract($this->twoBranchScenario());

        // Owner, viewing Cantt, pays against Main's bill: the payment is booked
        // in MAIN (the bill's branch), not the branch on screen.
        $this->actingAs($owner, 'fbrpos')->post('/fbr-pos/stock/payments', [
            'supplier_id' => $supplierId, 'purchase_order_id' => $poId, 'amount' => 100, 'method' => 'cash', 'branch_id' => $b2,
        ])->assertSessionHas('success');
        $pay = DB::table('supplier_payments')->orderByDesc('id')->first();
        $this->assertSame($b1, (int) $pay->branch_id);
        $this->assertSame($poId, (int) $pay->purchase_order_id);

        // Owner, with Cantt picked, tries to return Main's goods: refused with
        // the branch named — goods must leave the shop that received them.
        $this->actingAs($owner, 'fbrpos')->post('/fbr-pos/stock/returns', [
            'purchase_order_id' => $poId, 'reason' => 'surplus', 'branch_id' => $b2,
            'items' => [['product_id' => $productId, 'purchase_order_item_id' => $itemId, 'quantity' => 2, 'unit_cost' => 81.82]],
        ])->assertSessionHas('error');
        $this->assertSame(0, DB::table('purchase_returns')->count());

        // Same request with Main picked posts, and the stock leaves MAIN.
        $this->actingAs($owner, 'fbrpos')->post('/fbr-pos/stock/returns', [
            'purchase_order_id' => $poId, 'reason' => 'surplus', 'branch_id' => $b1,
            'items' => [['product_id' => $productId, 'purchase_order_item_id' => $itemId, 'quantity' => 2, 'unit_cost' => 81.82]],
        ])->assertSessionHas('success');
        $ret = PurchaseReturn::first();
        $this->assertSame($b1, (int) $ret->branch_id);
        $this->assertSame($b1, (int) DB::table('inventory_movements')->where('type', 'return_out')->value('branch_id'));
        $this->assertEqualsWithDelta(9, (float) DB::table('inventory_stocks')->where('product_id', $productId)->where('branch_id', $b1)->value('quantity'), 0.0001);
        $this->assertEqualsWithDelta(600 - 100 - 163.64, SupplierLedgerService::balanceFor($companyId, $supplierId, $b1)->balance, 0.001);
    }
}
