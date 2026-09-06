<?php

namespace Tests\Feature;

use Tests\TestCase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;

/**
 * FBR POS STOCK — Recent Purchases search + pagination endpoint (Aug 2026, Task 414).
 *
 * Locks the invariants of GET /fbr-pos/stock/purchases:
 *
 *   1. Page 1 returns the newest PURCHASES_PER_PAGE (15) rows, newest first,
 *      with has_more=true when older rows exist; the next page returns the rest.
 *   2. Every row carries its item detail: product names + trimmed quantities
 *      (serializer shape: id int, po_number, date, supplier, total, items[]).
 *   3. Search `q` matches purchase number, supplier name, OR product name —
 *      server-side over the full history, never just the latest 15.
 *   4. Results are company-scoped: another company's purchases never leak,
 *      even when they match the search term.
 *   5. Cashiers/local viewers are 403-blocked (stock is owner/manager territory).
 *
 * Pattern: APP_ENV=testing + sqlite :memory: + minimal Schema::create.
 *
 * Run:
 *   env -u DATABASE_URL -u DB_CONNECTION -u PGHOST -u PGPORT -u PGUSER \
 *     -u PGPASSWORD -u PGDATABASE APP_ENV=testing DB_CONNECTION=sqlite \
 *     DB_DATABASE=':memory:' php vendor/bin/phpunit tests/Feature/FbrPosStockPurchasesSearchTest.php
 */
class FbrPosStockPurchasesSearchTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // The page-render test resolves plan gates for our throwaway company
        // ids; PosFeatureService caches verdicts statically per company id,
        // which would poison later test FILES that reuse the same ids
        // (FbrPosStoreReplayGuardTest's khata gate). Flush on both ends.
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

        // Queried on the fbrpos auth path (head-office branch resolution).
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
            $table->string('status')->nullable();
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
            $table->unsignedBigInteger('product_id')->nullable();
            $table->decimal('quantity', 12, 4)->default(0);
            $table->decimal('unit_price', 12, 2)->default(0);
            $table->decimal('total_price', 14, 2)->default(0);
            $table->decimal('received_quantity', 12, 4)->nullable();
            $table->timestamps();
        });
    }

    protected function tearDown(): void
    {
        \App\Services\PosFeatureService::flushGateCaches();
        parent::tearDown();
    }

    // ── Fixtures ─────────────────────────────────────────────────────────────

    private function makeCompany(string $name = 'Stock Test Co'): int
    {
        return (int) DB::table('companies')->insertGetId([
            'name' => $name,
            'product_type' => 'fbrpos',
            'status' => 'active',
            'inventory_enabled' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function makeUser(int $companyId, ?string $posRole = null): \App\Models\User
    {
        $id = DB::table('users')->insertGetId([
            'name' => 'Stock Owner',
            'email' => 'owner' . $companyId . ($posRole ?? 'admin') . '@stock.test',
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
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function makeSupplier(int $companyId, string $name): int
    {
        return (int) DB::table('suppliers')->insertGetId([
            'company_id' => $companyId,
            'name' => $name,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /** @param array<int, array{0:int,1:float}> $items [[productId, qty], ...] */
    private function makePurchase(int $companyId, string $poNumber, ?int $supplierId, array $items, float $total = 100.0): int
    {
        $poId = (int) DB::table('purchase_orders')->insertGetId([
            'company_id' => $companyId,
            'supplier_id' => $supplierId,
            'po_number' => $poNumber,
            'status' => 'received',
            'received_date' => now()->toDateString(),
            'total_amount' => $total,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        foreach ($items as [$productId, $qty]) {
            DB::table('purchase_order_items')->insert([
                'purchase_order_id' => $poId,
                'product_id' => $productId,
                'quantity' => $qty,
                'unit_price' => 10,
                'total_price' => $qty * 10,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        return $poId;
    }

    // ── 1+2. Pagination + item detail shape ──────────────────────────────────

    public function test_page_one_returns_newest_15_with_item_detail_and_has_more(): void
    {
        $companyId = $this->makeCompany();
        $user = $this->makeUser($companyId);
        $candi = $this->makeProduct($companyId, 'Candi Biscuit');
        $touch = $this->makeProduct($companyId, 'Fresh Touch');

        // 20 purchases → page 1 = newest 15, page 2 = remaining 5.
        for ($i = 1; $i <= 20; $i++) {
            $this->makePurchase($companyId, sprintf('PO-%03d', $i), null, [[$candi, 5.0], [$touch, 2.5]], $i * 10);
        }

        $res = $this->actingAs($user, 'fbrpos')->getJson('/fbr-pos/stock/purchases');
        $res->assertOk();
        $data = $res->json();

        $this->assertTrue($data['has_more']);
        $this->assertCount(15, $data['purchases']);
        // Newest first
        $this->assertSame('PO-020', $data['purchases'][0]['po_number']);
        $this->assertSame('PO-006', $data['purchases'][14]['po_number']);

        // Serializer shape: id int, item detail with trimmed quantities;
        // scheme fields (Task 1580) are null when the line carried none.
        $row = $data['purchases'][0];
        $this->assertIsInt($row['id']);
        $this->assertSame('200.00', $row['total']);
        $this->assertSame(
            [
                ['name' => 'Candi Biscuit', 'qty' => '5', 'bonus' => null, 'disc' => null],
                ['name' => 'Fresh Touch', 'qty' => '2.5', 'bonus' => null, 'disc' => null],
            ],
            $row['items']
        );

        // Page 2 — the older 5, no more after that.
        $res2 = $this->actingAs($user, 'fbrpos')->getJson('/fbr-pos/stock/purchases?page=2');
        $res2->assertOk();
        $data2 = $res2->json();
        $this->assertFalse($data2['has_more']);
        $this->assertCount(5, $data2['purchases']);
        $this->assertSame('PO-005', $data2['purchases'][0]['po_number']);
        $this->assertSame('PO-001', $data2['purchases'][4]['po_number']);
    }

    // ── 3. Search: po_number / supplier / product ────────────────────────────

    public function test_search_matches_number_supplier_and_product_across_full_history(): void
    {
        $companyId = $this->makeCompany();
        $user = $this->makeUser($companyId);
        $candi = $this->makeProduct($companyId, 'Candi Biscuit');
        $soap = $this->makeProduct($companyId, 'Safeguard Soap');
        $lahoreSup = $this->makeSupplier($companyId, 'Lahore Traders');
        $karachiSup = $this->makeSupplier($companyId, 'Karachi Depot');

        // Old purchase (would fall outside the latest 15) with Candi from Lahore Traders.
        $this->makePurchase($companyId, 'PO-OLD-1', $lahoreSup, [[$candi, 3.0]]);
        // 16 newer purchases with soap from Karachi Depot.
        for ($i = 1; $i <= 16; $i++) {
            $this->makePurchase($companyId, sprintf('PO-NEW-%02d', $i), $karachiSup, [[$soap, 1.0]]);
        }

        // By product name — finds the OLD purchase beyond the latest 15.
        $data = $this->actingAs($user, 'fbrpos')->getJson('/fbr-pos/stock/purchases?q=candi')->assertOk()->json();
        $this->assertCount(1, $data['purchases']);
        $this->assertSame('PO-OLD-1', $data['purchases'][0]['po_number']);
        $this->assertFalse($data['has_more']);

        // By supplier name.
        $data = $this->actingAs($user, 'fbrpos')->getJson('/fbr-pos/stock/purchases?q=lahore')->assertOk()->json();
        $this->assertCount(1, $data['purchases']);
        $this->assertSame('PO-OLD-1', $data['purchases'][0]['po_number']);

        // By purchase number.
        $data = $this->actingAs($user, 'fbrpos')->getJson('/fbr-pos/stock/purchases?q=PO-NEW-07')->assertOk()->json();
        $this->assertCount(1, $data['purchases']);
        $this->assertSame('PO-NEW-07', $data['purchases'][0]['po_number']);

        // No match.
        $data = $this->actingAs($user, 'fbrpos')->getJson('/fbr-pos/stock/purchases?q=zzz-nahi-hai')->assertOk()->json();
        $this->assertSame([], $data['purchases']);
        $this->assertFalse($data['has_more']);
    }

    // ── 4. Company scoping ───────────────────────────────────────────────────

    public function test_other_companys_purchases_never_leak_even_on_match(): void
    {
        $companyA = $this->makeCompany('Shop A');
        $companyB = $this->makeCompany('Shop B');
        $user = $this->makeUser($companyA);

        $prodB = $this->makeProduct($companyB, 'Candi Biscuit');
        $supB = $this->makeSupplier($companyB, 'Lahore Traders');
        $this->makePurchase($companyB, 'PO-B-1', $supB, [[$prodB, 4.0]]);

        foreach (['', '?q=candi', '?q=lahore', '?q=PO-B-1'] as $qs) {
            $data = $this->actingAs($user, 'fbrpos')->getJson('/fbr-pos/stock/purchases' . $qs)->assertOk()->json();
            $this->assertSame([], $data['purchases'], "Company B purchase leaked for query '{$qs}'");
        }
    }

    // ── 5. Stock page renders with baked first page ──────────────────────────

    public function test_stock_page_renders_with_baked_purchase_json(): void
    {
        // Tables the full page (controller + layout) touches beyond the endpoint.
        Schema::create('inventory_stocks', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('company_id');
            $table->unsignedBigInteger('branch_id')->nullable();
            $table->unsignedBigInteger('product_id');
            $table->decimal('quantity', 12, 4)->default(0);
            $table->decimal('min_stock_level', 12, 4)->default(0);
            $table->decimal('last_purchase_price', 12, 2)->default(0);
            $table->timestamps();
        });
        // Recent Corrections list (Task 447) queries movements; zero rows is fine.
        Schema::create('inventory_movements', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('company_id');
            $table->unsignedBigInteger('branch_id')->nullable();
            $table->unsignedBigInteger('product_id');
            $table->string('type');
            $table->decimal('quantity', 12, 4)->default(0);
            $table->decimal('balance_after', 12, 4)->nullable();
            $table->string('reference_number')->nullable();
            $table->text('notes')->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamps();
        });
        // trial-lock-modal component queries plans; zero rows is fine.
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

        $companyId = $this->makeCompany();
        $user = $this->makeUser($companyId);
        $candi = $this->makeProduct($companyId, 'Candi Biscuit');
        $sup = $this->makeSupplier($companyId, 'Lahore Traders');
        $this->makePurchase($companyId, 'PO-RENDER-1', $sup, [[$candi, 5.0]]);

        $res = $this->actingAs($user, 'fbrpos')->get('/fbr-pos/stock');
        $res->assertOk();
        $html = $res->getContent();
        $this->assertStringContainsString('PO-RENDER-1', $html, 'baked purchase JSON missing from page');
        $this->assertStringContainsString('Candi Biscuit', $html);
        $this->assertStringContainsString('fetchPurchases', $html, 'Alpine purchase search logic missing');
        $this->assertStringContainsString('/fbr-pos/stock/purchases', $html, 'relative endpoint URL missing');
    }

    // ── 6. Cashier blocked ───────────────────────────────────────────────────

    public function test_cashier_and_viewer_are_blocked(): void
    {
        $companyId = $this->makeCompany();
        foreach (['pos_cashier', 'local_viewer'] as $role) {
            $user = $this->makeUser($companyId, $role);
            $this->actingAs($user, 'fbrpos')->getJson('/fbr-pos/stock/purchases')->assertStatus(403);
        }
    }
}
