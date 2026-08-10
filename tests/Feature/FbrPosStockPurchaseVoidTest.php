<?php

namespace Tests\Feature;

use Tests\TestCase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use App\Models\PurchaseOrder;
use App\Models\InventoryMovement;
use App\Services\InventoryService;

/**
 * FBR POS STOCK — purchase void (Task 419).
 *
 * Locks the invariants of POST /fbr-pos/stock/purchase/{id}/void:
 *
 *   1. Cashiers/local viewers are 403-blocked; other companies' POs never
 *      mutate (panel isolation handles the 404 into a redirect+flash).
 *   2. Voiding a RECEIVED purchase deducts exactly the received stock, books
 *      a return_out movement referencing the PO, rolls back last/avg kharid
 *      (un-weighted average, prior-purchase fallback) and marks the PO
 *      cancelled — the row is kept for the audit trail.
 *   3. A second void is rejected and reverses nothing (no double deduction).
 *   4. Draft / ordered / partial POs (shared DI workflow rows that have not
 *      put their stock in) are rejected with ZERO mutation.
 *
 * Pattern: APP_ENV=testing + sqlite :memory: + minimal Schema::create.
 */
class FbrPosStockPurchaseVoidTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        \App\Services\PosFeatureService::flushGateCaches();

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
            $table->rememberToken();
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
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('purchase_orders', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('company_id');
            $table->unsignedBigInteger('supplier_id')->nullable();
            $table->unsignedBigInteger('branch_id')->nullable();
            $table->string('po_number');
            $table->string('status');
            $table->date('order_date')->nullable();
            $table->date('expected_date')->nullable();
            $table->date('received_date')->nullable();
            $table->decimal('total_amount', 14, 2)->default(0);
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
    }

    protected function tearDown(): void
    {
        \App\Services\PosFeatureService::flushGateCaches();
        parent::tearDown();
    }

    // ── Fixtures ─────────────────────────────────────────────────────────────

    private function makeCompany(string $name = 'Void Co'): int
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
            'email' => 'owner' . $companyId . ($posRole ?? 'admin') . '@void.test',
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

    private function makeProduct(int $companyId, string $name): int
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

    /**
     * A PO the same way storePurchase writes it (status received, stock added
     * through InventoryService so avg/last rates are real running values).
     */
    private function makeReceivedPurchase(int $companyId, int $productId, float $qty, float $price): int
    {
        $poId = (int) DB::table('purchase_orders')->insertGetId([
            'company_id' => $companyId,
            'po_number' => 'PUR-TEST-' . str_pad((string) random_int(0, 9999), 4, '0', STR_PAD_LEFT) . uniqid(),
            'status' => PurchaseOrder::STATUS_RECEIVED,
            'order_date' => now()->toDateString(),
            'received_date' => now()->toDateString(),
            'total_amount' => round($qty * $price, 2),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('purchase_order_items')->insert([
            'purchase_order_id' => $poId,
            'product_id' => $productId,
            'quantity' => $qty,
            'unit_price' => $price,
            'total_price' => round($qty * $price, 2),
            'received_quantity' => $qty,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        InventoryService::addStock(
            $companyId, $productId, $qty, $price, InventoryMovement::TYPE_PURCHASE,
            null, ['type' => 'purchase_order', 'id' => $poId, 'number' => 'PO-' . $poId]
        );

        return $poId;
    }

    private function postVoid(\App\Models\User $user, int $poId)
    {
        return $this->actingAs($user, 'fbrpos')->post("/fbr-pos/stock/purchase/{$poId}/void");
    }

    private function stock(int $productId): object
    {
        return DB::table('inventory_stocks')->where('product_id', $productId)->first();
    }

    // ── 1. Guards + scoping ──────────────────────────────────────────────────

    public function test_cashier_viewer_blocked_and_other_company_po_never_mutates(): void
    {
        $companyA = $this->makeCompany('Shop A');
        $companyB = $this->makeCompany('Shop B');
        $productB = $this->makeProduct($companyB, 'Foreign Item');
        $poB = $this->makeReceivedPurchase($companyB, $productB, 10, 50);

        foreach (['pos_cashier', 'local_viewer'] as $role) {
            $user = $this->makeUser($companyA, $role);
            $this->postVoid($user, $poB)->assertStatus(403);
        }

        // Admin of company A cannot void company B's PO — panel isolation
        // turns the 404 into a redirect+flash; nothing may mutate either way.
        $admin = $this->makeUser($companyA);
        $this->postVoid($admin, $poB)->assertSessionHas('error');
        $this->assertSame('received', DB::table('purchase_orders')->where('id', $poB)->value('status'));
        $this->assertSame(10.0, (float) $this->stock($productB)->quantity);
    }

    // ── 2. Received void: stock, movement, rates, cancellation ──────────────

    public function test_void_reverses_stock_rates_and_cancels_po(): void
    {
        $companyId = $this->makeCompany();
        $user = $this->makeUser($companyId);
        $productId = $this->makeProduct($companyId, 'Rice Bag');

        // Good purchase 10 @ 50, then the fat-finger 100 @ 80.
        $this->makeReceivedPurchase($companyId, $productId, 10, 50);
        $badPo = $this->makeReceivedPurchase($companyId, $productId, 100, 80);

        $s = $this->stock($productId);
        $this->assertSame(110.0, (float) $s->quantity);
        $this->assertSame(80.0, (float) $s->last_purchase_price);

        $this->postVoid($user, $badPo)
            ->assertRedirect('/fbr-pos/stock')
            ->assertSessionHas('success');

        $s = $this->stock($productId);
        $this->assertSame(10.0, (float) $s->quantity);
        // Last kharid rolls back to the prior purchase's 50.
        $this->assertSame(50.0, (float) $s->last_purchase_price);
        // Un-weighted average lands back at (approximately) the prior 50.
        $this->assertEqualsWithDelta(50.0, (float) $s->avg_purchase_price, 0.05);

        // Audit: PO kept as cancelled + a return_out movement referencing it.
        $this->assertSame('cancelled', DB::table('purchase_orders')->where('id', $badPo)->value('status'));
        $mv = DB::table('inventory_movements')->where('reference_type', 'purchase_void')->first();
        $this->assertNotNull($mv);
        $this->assertSame(InventoryMovement::TYPE_RETURN_OUT, $mv->type);
        $this->assertSame(100.0, (float) $mv->quantity);
        $this->assertSame(10.0, (float) $mv->balance_after);
        $this->assertSame($badPo, (int) $mv->reference_id);
    }

    // ── 2b. Sole purchase void: rates reset to 0 (no valid prior rate) ──────

    public function test_voiding_the_only_purchase_resets_rates_to_zero(): void
    {
        $companyId = $this->makeCompany();
        $user = $this->makeUser($companyId);
        $productId = $this->makeProduct($companyId, 'Atta Bag');
        $poId = $this->makeReceivedPurchase($companyId, $productId, 30, 95);

        $this->postVoid($user, $poId)->assertSessionHas('success');

        $s = $this->stock($productId);
        $this->assertSame(0.0, (float) $s->quantity);
        // No still-valid prior purchase exists — the rates must not keep the
        // voided purchase's 95; explicit "no rate" like a never-bought product.
        $this->assertSame(0.0, (float) $s->last_purchase_price, 'last kharid kept the voided rate');
        $this->assertSame(0.0, (float) $s->avg_purchase_price, 'avg kharid kept the voided rate');
    }

    // ── 2c. Fallback must skip movements whose PO was already voided ────────

    public function test_fallback_skips_previously_voided_purchases(): void
    {
        $companyId = $this->makeCompany();
        $user = $this->makeUser($companyId);
        $productId = $this->makeProduct($companyId, 'Ghee Tin');

        // Valid base purchase 10 @ 40, then A (20 @ 70) and B (5 @ 90).
        $this->makeReceivedPurchase($companyId, $productId, 10, 40);
        $poA = $this->makeReceivedPurchase($companyId, $productId, 20, 70);
        $poB = $this->makeReceivedPurchase($companyId, $productId, 5, 90);

        // Void A first (its purchase movement stays in history)…
        $this->postVoid($user, $poA)->assertSessionHas('success');
        // …then void B: fallback must resolve to the base 40, NOT A's 70.
        $this->postVoid($user, $poB)->assertSessionHas('success');

        $s = $this->stock($productId);
        $this->assertSame(10.0, (float) $s->quantity);
        $this->assertSame(40.0, (float) $s->last_purchase_price, 'fallback restored a rate from a voided purchase');
        $this->assertEqualsWithDelta(40.0, (float) $s->avg_purchase_price, 0.05);
    }

    // ── 3. Second void is rejected — no double deduction ────────────────────

    public function test_second_void_rejected_and_reverses_nothing(): void
    {
        $companyId = $this->makeCompany();
        $user = $this->makeUser($companyId);
        $productId = $this->makeProduct($companyId, 'Oil Tin');
        $poId = $this->makeReceivedPurchase($companyId, $productId, 20, 60);

        $this->postVoid($user, $poId)->assertSessionHas('success');
        $this->assertSame(0.0, (float) $this->stock($productId)->quantity);

        $this->postVoid($user, $poId)->assertSessionHas('error');
        $this->assertSame(0.0, (float) $this->stock($productId)->quantity, 'double void deducted stock twice');
        $this->assertSame(1, DB::table('inventory_movements')->where('reference_type', 'purchase_void')->count());
    }

    // ── 4. Non-received statuses: rejected with ZERO mutation ───────────────

    public function test_draft_ordered_partial_pos_are_rejected_without_mutation(): void
    {
        $companyId = $this->makeCompany();
        $user = $this->makeUser($companyId);
        $productId = $this->makeProduct($companyId, 'Sugar Bag');
        // Real stock exists from an unrelated received purchase.
        $this->makeReceivedPurchase($companyId, $productId, 5, 40);

        foreach ([PurchaseOrder::STATUS_DRAFT, PurchaseOrder::STATUS_ORDERED, PurchaseOrder::STATUS_PARTIAL] as $status) {
            // DI-workflow style PO: ordered 50, nothing (fully) received.
            $poId = (int) DB::table('purchase_orders')->insertGetId([
                'company_id' => $companyId,
                'po_number' => 'PO-' . strtoupper($status),
                'status' => $status,
                'order_date' => now()->toDateString(),
                'total_amount' => 50 * 70,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            DB::table('purchase_order_items')->insert([
                'purchase_order_id' => $poId,
                'product_id' => $productId,
                'quantity' => 50,
                'unit_price' => 70,
                'total_price' => 3500,
                'received_quantity' => 0,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $this->postVoid($user, $poId)
                ->assertRedirect('/fbr-pos/stock')
                ->assertSessionHas('error');

            $this->assertSame($status, DB::table('purchase_orders')->where('id', $poId)->value('status'), "{$status} PO was mutated");
            $this->assertSame(5.0, (float) $this->stock($productId)->quantity, "{$status} void deducted never-received stock");
            $this->assertSame(0, DB::table('inventory_movements')->where('reference_type', 'purchase_void')->count());
        }
    }
}
