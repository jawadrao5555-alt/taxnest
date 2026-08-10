<?php

namespace Tests\Feature;

use Tests\TestCase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;

/**
 * FBR POS STOCK — per-item quick edit + PROFIT-FREEZE (Aug 2026, Task 416).
 *
 * Locks the invariants of POST /fbr-pos/stock/item and the frozen-cost
 * reporting basis:
 *
 *   1. Cashiers/local viewers are 403-blocked; other companies' products 404.
 *   2. Sale price + unit persist on the products table (verified via DB read —
 *      Eloquent silently drops non-fillable writes) and move updated_at
 *      (the sale-screen boot fingerprint keys off it).
 *   3. Quantity correction books an adjustment_in/out MOVEMENT with the right
 *      delta and balance — never a raw overwrite; unchanged qty books nothing.
 *   4. Kharid-rate edit updates the inventory_stocks purchase-price fields
 *      ONLY — no sold line's stored cost_price moves; an untouched rate field
 *      (== orig) never rewrites avg_purchase_price.
 *   5. Munafa report: cost basis = per-line frozen snapshot ONLY. Lines with
 *      NULL cost are excluded from profit (no live-rate fallback), surfaced
 *      via unknownLines/unknownSaleValue; totals are IDENTICAL before and
 *      after a kharid-rate edit; a bill punched at the new rate uses it.
 *   6. Reports-page range analytics profit follows the per-line snapshot,
 *      ignoring products.cost_price entirely.
 *
 * Pattern: APP_ENV=testing + sqlite :memory: + minimal Schema::create.
 *
 * Run:
 *   env -u DATABASE_URL -u DB_CONNECTION -u PGHOST -u PGPORT -u PGUSER \
 *     -u PGPASSWORD -u PGDATABASE APP_ENV=testing DB_CONNECTION=sqlite \
 *     DB_DATABASE=':memory:' php vendor/bin/phpunit tests/Feature/FbrPosStockQuickEditTest.php
 */
class FbrPosStockQuickEditTest extends TestCase
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
            $table->decimal('cost_price', 12, 2)->nullable();
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

        Schema::create('fbr_pos_transactions', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('company_id');
            $table->string('status')->nullable();
            $table->string('transaction_type')->nullable();
            $table->string('fbr_status')->nullable();
            $table->string('payment_method')->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->unsignedBigInteger('customer_id')->nullable();
            $table->string('customer_name')->nullable();
            $table->string('customer_phone')->nullable();
            $table->decimal('subtotal', 14, 2)->default(0);
            $table->decimal('total_amount', 14, 2)->default(0);
            $table->decimal('tax_amount', 14, 2)->default(0);
            $table->decimal('discount_amount', 14, 2)->default(0);
            $table->decimal('loyalty_redemption_amount', 14, 2)->default(0);
            $table->timestamps();
        });

        Schema::create('fbr_pos_transaction_items', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('transaction_id');
            $table->unsignedBigInteger('product_id')->nullable();
            $table->string('item_name')->nullable();
            $table->decimal('quantity', 12, 4)->default(0);
            $table->decimal('subtotal', 14, 2)->default(0);
            $table->decimal('tax_amount', 14, 2)->default(0);
            $table->decimal('item_discount', 14, 2)->default(0);
            $table->decimal('promotion_discount', 14, 2)->default(0);
            $table->decimal('cost_price', 12, 4)->nullable();
            $table->timestamps();
        });

        // Layout components on rendered pages (munafa) query these.
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

    private function makeCompany(string $name = 'QuickEdit Co'): int
    {
        $id = (int) DB::table('companies')->insertGetId([
            'name' => $name,
            'product_type' => 'fbrpos',
            'status' => 'active',
            'inventory_enabled' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        // plan.limit:inventory fails closed without an active subscription —
        // a lifetime override bypasses per-resource caps (like live QA cos).
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
            'email' => 'owner' . $companyId . ($posRole ?? 'admin') . '@quickedit.test',
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

    private function makeProduct(int $companyId, string $name, float $price = 100, ?float $productCost = null): int
    {
        return (int) DB::table('products')->insertGetId([
            'company_id' => $companyId,
            'name' => $name,
            'uom' => 'U',
            'default_price' => $price,
            'cost_price' => $productCost,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function makeStock(int $companyId, int $productId, float $qty, float $avg, float $last): int
    {
        return (int) DB::table('inventory_stocks')->insertGetId([
            'company_id' => $companyId,
            'product_id' => $productId,
            'branch_id' => null,
            'quantity' => $qty,
            'min_stock_level' => 0,
            'avg_purchase_price' => $avg,
            'last_purchase_price' => $last,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /** @param array<int, array{0:?int,1:string,2:float,3:float,4:?float}> $lines [[productId, name, qty, subtotal, costPrice|null]] */
    private function makeSale(int $companyId, array $lines, string $type = 'sale'): int
    {
        $txId = (int) DB::table('fbr_pos_transactions')->insertGetId([
            'company_id' => $companyId,
            'status' => 'completed',
            'transaction_type' => $type,
            'subtotal' => array_sum(array_column($lines, 3)),
            'total_amount' => array_sum(array_column($lines, 3)),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        foreach ($lines as [$productId, $name, $qty, $subtotal, $cost]) {
            DB::table('fbr_pos_transaction_items')->insert([
                'transaction_id' => $txId,
                'product_id' => $productId,
                'item_name' => $name,
                'quantity' => $qty,
                'subtotal' => $subtotal,
                'cost_price' => $cost,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        return $txId;
    }

    private function postItem(\App\Models\User $user, array $payload)
    {
        return $this->actingAs($user, 'fbrpos')->post('/fbr-pos/stock/item', $payload);
    }

    // ── 1. Guards + scoping ──────────────────────────────────────────────────

    public function test_cashier_viewer_blocked_and_other_company_product_404(): void
    {
        $companyA = $this->makeCompany('Shop A');
        $companyB = $this->makeCompany('Shop B');
        $productB = $this->makeProduct($companyB, 'Foreign Item');

        foreach (['pos_cashier', 'local_viewer'] as $role) {
            $user = $this->makeUser($companyA, $role);
            $this->postItem($user, ['product_id' => $productB, 'default_price' => 50])->assertStatus(403);
        }

        // Admin of company A cannot touch company B's product. The app's
        // panel-isolation handler turns the 404 into an in-panel redirect
        // with an error flash — the write must never land either way.
        $admin = $this->makeUser($companyA);
        $this->postItem($admin, ['product_id' => $productB, 'default_price' => 50])
            ->assertRedirect('/fbr-pos/dashboard')
            ->assertSessionHas('error');
        $this->assertSame(100.0, (float) DB::table('products')->where('id', $productB)->value('default_price'));
    }

    // ── 2. Price + unit persist (DB read) and dirty updated_at ──────────────

    public function test_price_and_uom_persist_and_move_updated_at(): void
    {
        $companyId = $this->makeCompany();
        $user = $this->makeUser($companyId);
        $productId = $this->makeProduct($companyId, 'Candi Biscuit', 100);
        DB::table('products')->where('id', $productId)->update(['updated_at' => now()->subDay()]);
        $before = DB::table('products')->where('id', $productId)->value('updated_at');

        $this->postItem($user, [
            'product_id' => $productId,
            'default_price' => 120.50,
            'uom' => 'pkt', // lowercased on purpose — must normalize
        ])->assertRedirect('/fbr-pos/stock');

        $row = DB::table('products')->where('id', $productId)->first();
        $this->assertSame(120.5, (float) $row->default_price);
        $this->assertSame('PKT', $row->uom);
        // Boot fingerprint keys off max(updated_at) — the edit must move it.
        $this->assertNotEquals($before, $row->updated_at, 'updated_at did not move — sale screen would stay stale');
    }

    // ── 3. Quantity correction = adjustment movement, never overwrite ───────

    public function test_qty_correction_books_adjustment_movement_with_delta(): void
    {
        $companyId = $this->makeCompany();
        $user = $this->makeUser($companyId);
        $productId = $this->makeProduct($companyId, 'Rice Bag');
        $this->makeStock($companyId, $productId, 10, 80, 90);

        // Correct 10 → 7.5 (shrink: adjustment_out of 2.5).
        $this->postItem($user, [
            'product_id' => $productId,
            'default_price' => 100,
            'new_quantity' => 7.5,
            'quantity_orig' => 10,
            'qty_reason' => 'ginti ke baad',
        ])->assertRedirect('/fbr-pos/stock');

        $stock = DB::table('inventory_stocks')->where('product_id', $productId)->first();
        $this->assertSame(7.5, (float) $stock->quantity);

        $mv = DB::table('inventory_movements')->where('product_id', $productId)->orderByDesc('id')->first();
        $this->assertNotNull($mv, 'quantity change without a movement row = raw overwrite');
        $this->assertSame(\App\Models\InventoryMovement::TYPE_ADJUSTMENT_OUT, $mv->type);
        $this->assertSame(2.5, (float) $mv->quantity);
        $this->assertSame(7.5, (float) $mv->balance_after);
        $this->assertStringContainsString('ginti ke baad', (string) $mv->notes);
        // Adjustments must NOT touch purchase rates.
        $this->assertSame(80.0, (float) $stock->avg_purchase_price);
        $this->assertSame(90.0, (float) $stock->last_purchase_price);

        // Grow 7.5 → 9 (adjustment_in of 1.5).
        $this->postItem($user, [
            'product_id' => $productId,
            'default_price' => 100,
            'new_quantity' => 9,
            'quantity_orig' => 7.5,
        ]);
        $mv2 = DB::table('inventory_movements')->where('product_id', $productId)->orderByDesc('id')->first();
        $this->assertSame(\App\Models\InventoryMovement::TYPE_ADJUSTMENT_IN, $mv2->type);
        $this->assertSame(1.5, (float) $mv2->quantity);
        $this->assertSame(9.0, (float) DB::table('inventory_stocks')->where('product_id', $productId)->value('quantity'));

        // Unchanged qty (== orig) books nothing.
        $countBefore = DB::table('inventory_movements')->count();
        $this->postItem($user, [
            'product_id' => $productId,
            'default_price' => 100,
            'new_quantity' => 9,
            'quantity_orig' => 9,
        ]);
        $this->assertSame($countBefore, DB::table('inventory_movements')->count());
    }

    // ── 4. Kharid-rate edit: stock fields only, sold lines untouched ────────

    public function test_rate_edit_updates_stock_rates_only_and_respects_orig(): void
    {
        $companyId = $this->makeCompany();
        $user = $this->makeUser($companyId);
        $productId = $this->makeProduct($companyId, 'Cooking Oil');
        $this->makeStock($companyId, $productId, 20, 70, 75); // avg ≠ last on purpose
        $this->makeSale($companyId, [[$productId, 'Cooking Oil', 2, 200, 70]]);

        // Untouched field: submitted == orig → avg must NOT be rewritten to 75.
        $this->postItem($user, [
            'product_id' => $productId,
            'default_price' => 100,
            'kharid_rate' => 75,
            'kharid_rate_orig' => 75,
        ]);
        $stock = DB::table('inventory_stocks')->where('product_id', $productId)->first();
        $this->assertSame(70.0, (float) $stock->avg_purchase_price, 'untouched rate field silently rewrote avg');

        // Real edit → BOTH avg and last become the new rate (future snapshots read avg first).
        $this->postItem($user, [
            'product_id' => $productId,
            'default_price' => 100,
            'kharid_rate' => 82,
            'kharid_rate_orig' => 75,
        ]);
        $stock = DB::table('inventory_stocks')->where('product_id', $productId)->first();
        $this->assertSame(82.0, (float) $stock->avg_purchase_price);
        $this->assertSame(82.0, (float) $stock->last_purchase_price);

        // PROFIT FREEZE: the sold line's stored cost snapshot never moves.
        $this->assertSame(70.0, (float) DB::table('fbr_pos_transaction_items')->where('product_id', $productId)->value('cost_price'));
        // No movement row for a pure rate edit.
        $this->assertSame(0, DB::table('inventory_movements')->count());
    }

    // ── 5. Munafa: frozen cost only, stable across rate edits ───────────────

    public function test_munafa_excludes_null_cost_lines_and_is_frozen_across_rate_edit(): void
    {
        $companyId = $this->makeCompany();
        $user = $this->makeUser($companyId);
        $productId = $this->makeProduct($companyId, 'Chai Patti');
        $this->makeStock($companyId, $productId, 50, 60, 60);

        // One costed line (snapshot 60) + one PRE-SNAPSHOT line (NULL cost).
        $this->makeSale($companyId, [[$productId, 'Chai Patti', 2, 200, 60.0]]);
        $this->makeSale($companyId, [[$productId, 'Chai Patti', 1, 100, null]]);

        $get = fn () => $this->actingAs($user, 'fbrpos')->get('/fbr-pos/munafa')->assertOk();

        $res = $get();
        // Revenue = full sales; profit only over the costed line: 200 − 2×60 = 80.
        $this->assertSame(300.0, (float) $res->viewData('revenue'));
        $this->assertSame(200.0, (float) $res->viewData('costedRevenue'));
        $this->assertSame(120.0, (float) $res->viewData('cost'));
        $this->assertSame(80.0, (float) $res->viewData('grossProfit'));
        $this->assertSame(1, $res->viewData('unknownLines'));
        $this->assertSame(100.0, (float) $res->viewData('unknownSaleValue'));

        // Kharid-rate edit 60 → 90…
        $this->postItem($user, [
            'product_id' => $productId,
            'default_price' => 100,
            'kharid_rate' => 90,
            'kharid_rate_orig' => 60,
        ]);

        // …past munafa numbers must be EXACTLY the same (no live-rate fallback).
        $res2 = $get();
        foreach (['revenue', 'costedRevenue', 'cost', 'grossProfit', 'netProfit', 'unknownLines', 'unknownSaleValue'] as $key) {
            $this->assertEquals($res->viewData($key), $res2->viewData($key), "munafa '{$key}' changed after a rate edit — profit history not frozen");
        }

        // A bill punched AFTER the edit freezes the new rate → only it uses 90.
        $this->makeSale($companyId, [[$productId, 'Chai Patti', 1, 150, 90.0]]);
        $res3 = $get();
        $this->assertSame(450.0, (float) $res3->viewData('revenue'));
        $this->assertSame(80.0 + (150.0 - 90.0), (float) $res3->viewData('grossProfit'));
    }

    // ── 6. Reports range analytics: per-line snapshot, not product cost ─────

    public function test_reports_analytics_profit_uses_line_snapshot_not_product_cost(): void
    {
        $companyId = $this->makeCompany();
        $user = $this->makeUser($companyId);
        // Decoy: product's CURRENT cost_price = 999 — must be ignored.
        $productId = $this->makeProduct($companyId, 'Surf Excel', 100, 999);

        $this->makeSale($companyId, [[$productId, 'Surf Excel', 2, 200, 40.0]]); // frozen cost 40
        $this->makeSale($companyId, [[$productId, 'Surf Excel', 1, 100, null]]); // pre-snapshot

        $controller = app(\App\Http\Controllers\FbrPosController::class);
        $method = new \ReflectionMethod($controller, 'buildFbrReportRangeAnalytics');
        $method->setAccessible(true);
        $analytics = $method->invoke($controller, $companyId, now()->subDay(), now()->addDay(), $user);

        // Cost = 2 × 40 (line snapshot). If it were product cost: 2×999 or 3×999.
        $this->assertSame(80.0, (float) $analytics->profit->cost);
        $this->assertSame(200.0, (float) $analytics->profit->revenue); // costed lines only
        $this->assertSame(120.0, (float) $analytics->profit->profit); // 200 − 80
        // Coverage: 2 of 3 units costed → 67%.
        $this->assertSame(67, (int) $analytics->profit->coverage_pct);
    }
}
