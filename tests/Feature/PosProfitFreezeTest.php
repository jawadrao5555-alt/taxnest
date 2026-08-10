<?php

namespace Tests\Feature;

use Tests\TestCase;
use App\Models\Company;
use App\Models\User;
use App\Models\PosProduct;
use App\Models\PosTransaction;
use App\Models\PosTransactionItem;
use App\Services\PosFeatureService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Carbon\Carbon;

/**
 * PRA POS PROFIT FREEZE (Task 423, owner decision Aug 2026)
 *
 * Guarantees that editing a product's purchase rate NEVER retro-rewrites
 * past profit figures — both in the range-analytics report and on the
 * dashboard BI panel. Mirrors FBR POS Task 416.
 *
 * Unit/reflection tests:
 *   1. buildReportRangeAnalytics reads frozen snapshot — not live cost.
 *   2. Range profit stays identical before and after a rate edit.
 *   3. Lines without a snapshot are excluded; coverage < 100%.
 *   4. When cost_price column is absent, range analytics falls back to
 *      live pos_products.cost_price (pre-migration PROD compatibility).
 *   5. Dashboard profitRow uses costed revenue, not all-lines revenue.
 *
 * Request-level (HTTP) tests:
 *   6. storeInvoice() freezes pos_products.cost_price onto item rows.
 *   7. storeInvoice() leaves cost_price NULL when product has no cost.
 *   8. updateTransaction() re-freezes cost_price on re-created item rows.
 *   9. Editing product rate after store does NOT change the item snapshot.
 */
class PosProfitFreezeTest extends TestCase
{
    private int $companyId;
    private Company $company;
    private User $posAdmin;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(now()->setTime(12, 0));
        PosFeatureService::flushGateCaches();
        Schema::dropAllTables();
        $this->buildSchema();
        [$this->company, $this->posAdmin] = $this->seedShop();
        $this->companyId = $this->company->id;
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    // ─────────────────────────────────────────────────────────────────────────
    // UNIT / REFLECTION TESTS
    // ─────────────────────────────────────────────────────────────────────────

    /** Range analytics profit is stable after a cost edit (frozen snapshot). */
    public function test_range_analytics_profit_unchanged_after_rate_edit(): void
    {
        $product = $this->makeProduct(cost: 50.00);
        $this->makeSaleItem($product->id, qty: 2, subtotal: 200.00, frozenCost: 50.00);

        $before = $this->runRangeAnalytics();
        // Profit = 200 - (50*2) = 100
        $this->assertEquals(100.0, $before->profit->profit);

        $product->update(['cost_price' => 90.00]);   // rate edit — must not shift past profit

        $after = $this->runRangeAnalytics();
        $this->assertEquals(
            $before->profit->profit,
            $after->profit->profit,
            'Range-analytics profit must be identical before and after a rate edit'
        );
    }

    /** Lines without a snapshot are excluded; coverage reflects that. */
    public function test_lines_without_snapshot_are_excluded_and_lower_coverage(): void
    {
        $tx = $this->makeTransaction();
        PosTransactionItem::create([
            'transaction_id' => $tx, 'item_type' => 'product',
            'item_id' => $this->makeProduct(cost: 30.00)->id,
            'item_name' => 'With Cost', 'quantity' => 1, 'unit_price' => 100,
            'subtotal' => 100, 'cost_price' => 30.00, 'is_tax_exempt' => false,
            'tax_rate' => 0, 'tax_amount' => 0,
        ]);
        PosTransactionItem::create([
            'transaction_id' => $tx, 'item_type' => 'product',
            'item_id' => $this->makeProduct(cost: 0.00)->id,
            'item_name' => 'No Cost', 'quantity' => 1, 'unit_price' => 80,
            'subtotal' => 80, 'cost_price' => null, 'is_tax_exempt' => false,
            'tax_rate' => 0, 'tax_amount' => 0,
        ]);

        $ra = $this->runRangeAnalytics();

        $this->assertNotNull($ra->profit);
        $this->assertEquals(100.0, $ra->profit->revenue, 'Only costed lines count toward costed revenue');
        $this->assertEquals(30.0,  $ra->profit->cost);
        $this->assertEquals(70.0,  $ra->profit->profit);
        $this->assertEquals(50, $ra->profit->coverage_pct); // 1 of 2 product lines
    }

    /** Pre-migration bills (all-NULL snapshots): coverage drops to 0%, no fake profit. */
    public function test_coverage_pct_is_zero_when_all_snapshots_null(): void
    {
        $tx = $this->makeTransaction();
        foreach ([['Old A', 100], ['Old B', 80]] as [$name, $price]) {
            PosTransactionItem::create([
                'transaction_id' => $tx, 'item_type' => 'product',
                'item_id' => $this->makeProduct(cost: 45.00)->id,
                'item_name' => $name, 'quantity' => 1, 'unit_price' => $price,
                'subtotal' => $price, 'cost_price' => null, 'is_tax_exempt' => false,
                'tax_rate' => 0, 'tax_amount' => 0,
            ]);
        }

        $ra = $this->runRangeAnalytics();

        $this->assertNotNull($ra->profit);
        $this->assertEquals(0, $ra->profit->coverage_pct, 'Pre-migration bills (NULL snapshots) must show 0% coverage');
        $this->assertEquals(0.0, $ra->profit->revenue, 'No costed revenue when no line has a snapshot');
        $this->assertEquals(0.0, $ra->profit->cost);
        $this->assertEquals(0.0, $ra->profit->profit, 'Must not invent profit from live product cost');
    }

    /** Pre-migration fallback: when cost_price column is absent, reads live product cost. */
    public function test_range_analytics_falls_back_to_live_cost_when_column_absent(): void
    {
        // Drop the column to simulate a PROD schema before the migration ran.
        Schema::table('pos_transaction_items', function (Blueprint $t) {
            $t->dropColumn('cost_price');
        });

        $product = $this->makeProduct(cost: 40.00);
        // Seed an item row WITHOUT cost_price (column doesn't exist)
        DB::table('pos_transaction_items')->insert([
            'transaction_id' => $this->makeTransaction(),
            'item_type' => 'product', 'item_id' => $product->id,
            'item_name' => 'Chai', 'quantity' => 2,
            'unit_price' => 100, 'subtotal' => 200,
            'is_tax_exempt' => 0, 'tax_rate' => 0, 'tax_amount' => 0,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $ra = $this->runRangeAnalytics();

        // Fallback should compute cost from live product cost_price (40 * 2 = 80)
        $this->assertNotNull($ra->profit);
        $this->assertEquals(80.0, $ra->profit->cost, 'Fallback must read live product cost_price');
        $this->assertEquals(120.0, $ra->profit->profit);

        // Re-add column so tearDown / other tests are not affected
        Schema::table('pos_transaction_items', function (Blueprint $t) {
            $t->decimal('cost_price', 12, 4)->nullable()->after('unit_price');
        });
    }

    /**
     * Dashboard profitRow uses costed-lines revenue only.
     * Exercises the actual dashboard() controller method via HTTP so the
     * production selectRaw query (not a hand-written duplicate) is verified.
     */
    public function test_dashboard_profit_uses_costed_revenue_only(): void
    {
        $product = $this->makeProduct(cost: 60.00);
        $tx = $this->makeTransaction();
        // Costed line: revenue 150, cost 60, profit 90
        PosTransactionItem::create([
            'transaction_id' => $tx, 'item_type' => 'product',
            'item_id' => $product->id, 'item_name' => 'Item A',
            'quantity' => 1, 'unit_price' => 150, 'subtotal' => 150,
            'cost_price' => 60.00, 'is_tax_exempt' => false,
            'tax_rate' => 0, 'tax_amount' => 0,
        ]);
        // Uncosted line: revenue 100, no cost — must NOT count toward costed revenue
        PosTransactionItem::create([
            'transaction_id' => $tx, 'item_type' => 'product',
            'item_id' => $this->makeProduct(cost: 0)->id, 'item_name' => 'Item B',
            'quantity' => 1, 'unit_price' => 100, 'subtotal' => 100,
            'cost_price' => null, 'is_tax_exempt' => false,
            'tax_rate' => 0, 'tax_amount' => 0,
        ]);

        $stats = $this->dashboardProfitViaHttp();

        $this->assertEquals(150.0, $stats['revenue'],
            'Dashboard revenue must be costed-lines only (150), not all-lines (250)');
        $this->assertEquals(90.0,  $stats['profit'],
            'Dashboard profit must be costed revenue minus cost (150-60=90)');
    }

    /**
     * Dashboard profit stays unchanged after a rate edit.
     * Exercises the actual dashboard() controller via HTTP.
     */
    public function test_dashboard_profit_unchanged_after_rate_edit(): void
    {
        $product = $this->makeProduct(cost: 60.00);
        $this->makeSaleItem($product->id, qty: 1, subtotal: 150.00, frozenCost: 60.00);

        $before = $this->dashboardProfitViaHttp()['profit'];
        $this->assertEquals(90.0, $before);

        $product->update(['cost_price' => 120.00]);   // rate edit

        $after = $this->dashboardProfitViaHttp()['profit'];
        $this->assertEquals($before, $after, 'Dashboard profit must be stable after a rate edit');
    }

    // ─────────────────────────────────────────────────────────────────────────
    // REQUEST-LEVEL (HTTP) TESTS
    // ─────────────────────────────────────────────────────────────────────────

    /** storeInvoice() must freeze pos_products.cost_price onto the item row. */
    public function test_store_invoice_freezes_cost_price_on_item(): void
    {
        $product = $this->makeProduct(cost: 45.00);

        $response = $this->actingAs($this->posAdmin, 'pos')
            ->postJson('/pos/invoice/store', [
                'items' => [[
                    'type' => 'product',
                    'item_id' => $product->id,
                    'name' => $product->name,
                    'quantity' => 2,
                    'unit_price' => 200,
                    'is_tax_exempt' => false,
                ]],
                'payment_method' => 'cash',
                'discount_type' => 'amount',
                'discount_value' => 0,
            ]);

        $response->assertOk()->assertJson(['success' => true]);

        $txId = $response->json('transaction_id');
        $item = PosTransactionItem::where('transaction_id', $txId)->first();

        $this->assertNotNull($item, 'Item row must exist after store');
        $this->assertNotNull($item->cost_price, 'cost_price must be frozen at sale time');
        $this->assertEquals('45.0000', $item->cost_price, 'Frozen cost must match product cost at sale time');
    }

    /** storeInvoice() must leave cost_price NULL when product has no cost set. */
    public function test_store_invoice_leaves_cost_price_null_when_product_has_none(): void
    {
        $product = $this->makeProduct(cost: 0.00);

        $response = $this->actingAs($this->posAdmin, 'pos')
            ->postJson('/pos/invoice/store', [
                'items' => [[
                    'type' => 'product',
                    'item_id' => $product->id,
                    'name' => $product->name,
                    'quantity' => 1,
                    'unit_price' => 100,
                    'is_tax_exempt' => false,
                ]],
                'payment_method' => 'cash',
                'discount_type' => 'amount',
                'discount_value' => 0,
            ]);

        $response->assertOk()->assertJson(['success' => true]);

        $item = PosTransactionItem::where('transaction_id', $response->json('transaction_id'))->first();
        $this->assertNull($item->cost_price, 'Zero-cost product must leave cost_price NULL');
    }

    /** updateTransaction() must re-freeze cost_price from the product at edit time. */
    public function test_update_transaction_freezes_cost_price_on_edit(): void
    {
        // Create an initial bill (product cost 30 at sale time)
        $product = $this->makeProduct(cost: 30.00);
        $storeResp = $this->actingAs($this->posAdmin, 'pos')
            ->postJson('/pos/invoice/store', [
                'items' => [[
                    'type' => 'product',
                    'item_id' => $product->id,
                    'name' => $product->name,
                    'quantity' => 1,
                    'unit_price' => 100,
                    'is_tax_exempt' => false,
                ]],
                'payment_method' => 'cash',
                'discount_type' => 'amount',
                'discount_value' => 0,
            ]);
        $storeResp->assertOk();
        $txId = $storeResp->json('transaction_id');

        // Product rate changes before the edit
        $product->update(['cost_price' => 70.00]);

        // Edit the bill — updateTransaction should re-freeze at the NEW rate
        $editResp = $this->actingAs($this->posAdmin, 'pos')
            ->putJson("/pos/transaction/{$txId}", [
                'items' => [[
                    'type' => 'product',
                    'item_id' => $product->id,
                    'name' => $product->name,
                    'quantity' => 1,
                    'unit_price' => 100,
                    'is_tax_exempt' => false,
                ]],
                'payment_method' => 'cash',
                'discount_type' => 'amount',
                'discount_value' => 0,
            ]);
        $editResp->assertOk()->assertJson(['success' => true]);

        $item = PosTransactionItem::where('transaction_id', $txId)->first();
        $this->assertNotNull($item->cost_price);
        $this->assertEquals('70.0000', $item->cost_price, 'Edit must re-freeze cost at current product rate');
    }

    /** Editing the product rate after a sale must not change the stored snapshot. */
    public function test_product_rate_edit_after_store_does_not_change_snapshot(): void
    {
        $product = $this->makeProduct(cost: 55.00);

        $storeResp = $this->actingAs($this->posAdmin, 'pos')
            ->postJson('/pos/invoice/store', [
                'items' => [[
                    'type' => 'product',
                    'item_id' => $product->id,
                    'name' => $product->name,
                    'quantity' => 1,
                    'unit_price' => 150,
                    'is_tax_exempt' => false,
                ]],
                'payment_method' => 'cash',
                'discount_type' => 'amount',
                'discount_value' => 0,
            ]);
        $storeResp->assertOk();
        $txId = $storeResp->json('transaction_id');

        $snapshotBefore = PosTransactionItem::where('transaction_id', $txId)->value('cost_price');

        // Shopkeeper edits the purchase rate
        $product->update(['cost_price' => 99.00]);

        // Item row must be untouched — snapshot stays 55, not 99
        $snapshotAfter = PosTransactionItem::where('transaction_id', $txId)->value('cost_price');
        $this->assertEquals($snapshotBefore, $snapshotAfter,
            'Stored cost_price snapshot must not change when product rate is edited');
    }

    // ═════════════════════════════════════════════════════════════════════════
    // Helpers
    // ═════════════════════════════════════════════════════════════════════════

    private function makeProduct(float $cost): PosProduct
    {
        return PosProduct::create([
            'company_id' => $this->companyId,
            'name' => 'Product-' . uniqid(),
            'price' => 200,
            'cost_price' => $cost > 0 ? $cost : null,
            'is_active' => true,
            'show_on_sale' => true,
            'category' => 'Test',
        ]);
    }

    private function makeTransaction(): int
    {
        return PosTransaction::create([
            'company_id'    => $this->companyId,
            'invoice_number' => 'POS-2026-' . rand(10000, 99999),
            'invoice_mode'  => 'pra',
            'status'        => 'completed',
            // applyReportFilters('pra') requires pra_status NOT NULL or
            // pra_invoice_number NOT NULL — use 'offline' (valid PRA pipeline status)
            'pra_status'    => 'offline',
            'business_date' => today()->toDateString(),
            'subtotal'      => 100,
            'discount_amount' => 0,
            'tax_amount'    => 0,
            'total_amount'  => 100,
            'payment_method' => 'cash',
        ])->id;
    }

    private function makeSaleItem(int $productId, float $qty, float $subtotal, ?float $frozenCost): void
    {
        $tx = $this->makeTransaction();
        PosTransaction::where('id', $tx)->update(['subtotal' => $subtotal, 'total_amount' => $subtotal]);
        PosTransactionItem::create([
            'transaction_id' => $tx, 'item_type' => 'product', 'item_id' => $productId,
            'item_name' => 'Product', 'quantity' => $qty,
            'unit_price' => $subtotal / $qty, 'subtotal' => $subtotal,
            'cost_price' => ($frozenCost && $frozenCost > 0) ? $frozenCost : null,
            'is_tax_exempt' => false, 'tax_rate' => 0, 'tax_amount' => 0,
        ]);
    }

    private function runRangeAnalytics(): object
    {
        $user = new class { public function isPosAdmin(): bool { return true; } };
        $ctrl = new \App\Http\Controllers\PosController();
        $ref  = new \ReflectionMethod($ctrl, 'buildReportRangeAnalytics');
        $ref->setAccessible(true);
        return $ref->invoke($ctrl, $this->companyId,
            Carbon::today()->startOfDay(), Carbon::today()->endOfDay(),
            'pra', 'all', $this->company, $user);
    }

    /**
     * Hit GET /pos/dashboard and return the profitStats array the controller
     * passes to the view. assertViewHas captures the data before Blade renders,
     * so view-layer issues don't interfere.
     */
    private function dashboardProfitViaHttp(): array
    {
        $response = $this->actingAs($this->posAdmin, 'pos')
            ->get('/pos/dashboard');
        $response->assertOk();
        $response->assertViewHas('profitStats');
        return $response->viewData('profitStats');
    }

    // ─── Schema + seed ───────────────────────────────────────────────────────

    private function seedShop(): array
    {
        $planId = DB::table('pricing_plans')->insertGetId([
            'name' => 'Business', 'product_type' => 'pos',
            'is_trial' => false, 'invoice_limit' => -1,
            'deals_enabled' => false,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $company = Company::create([
            'name' => 'Test PRA Shop', 'product_type' => 'pos',
            'status' => 'active', 'company_status' => 'active',
            'is_internal_account' => false,
            'pra_reporting_enabled' => false,
            'inventory_enabled' => false,
            'pos_setup_completed' => true, // skip the first-run setup wizard redirect
        ]);

        DB::table('subscriptions')->insert([
            'company_id' => $company->id, 'pricing_plan_id' => $planId,
            'active' => true, 'override_type' => 'none',
            'start_date' => now()->subMonth()->toDateString(),
            'end_date' => now()->addMonth()->toDateString(),
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $user = User::create([
            'name' => 'Admin', 'email' => 'admin@test.pk',
            'password' => bcrypt('secret'), 'company_id' => $company->id,
            'role' => 'company_admin', 'pos_role' => 'pos_admin',
            'is_active' => true,
        ]);

        return [$company, $user];
    }

    private function buildSchema(): void
    {
        Schema::create('companies', function (Blueprint $t) {
            $t->id();
            $t->string('name');
            $t->string('product_type')->default('pos');
            $t->string('status')->default('active');
            $t->string('company_status')->default('active');
            $t->boolean('is_internal_account')->default(false);
            $t->integer('invoice_limit_override')->nullable();
            $t->boolean('pra_reporting_enabled')->default(false);
            $t->boolean('inventory_enabled')->default(false);
            $t->text('feature_flags')->nullable();
            $t->string('business_category')->nullable();
            $t->decimal('cashier_discount_limit', 8, 2)->nullable();
            $t->decimal('pos_tax_rate_cash', 8, 2)->nullable();
            $t->decimal('pos_tax_rate_card', 8, 2)->nullable();
            $t->boolean('pos_tax_inclusive')->default(false);
            $t->string('pos_tax_pricing_mode')->nullable();
            $t->string('default_language')->nullable();
            $t->boolean('pos_setup_completed')->default(false);
            $t->string('pos_dashboard_style')->nullable();
            $t->softDeletes();
            $t->timestamps();
        });

        Schema::create('users', function (Blueprint $t) {
            $t->id();
            $t->string('name')->nullable();
            $t->string('email')->nullable()->unique();
            $t->string('password')->nullable();
            $t->unsignedBigInteger('company_id')->nullable();
            $t->string('role')->nullable();
            $t->string('pos_role')->nullable();
            $t->boolean('is_active')->default(true);
            $t->boolean('pra_reporting_enabled')->default(false);
            $t->rememberToken();
            $t->timestamps();
        });

        Schema::create('pricing_plans', function (Blueprint $t) {
            $t->id();
            $t->string('name');
            $t->string('product_type')->default('pos');
            $t->boolean('is_trial')->default(false);
            $t->boolean('deals_enabled')->default(false);
            $t->integer('invoice_limit')->nullable();
            $t->timestamps();
        });

        Schema::create('subscriptions', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('company_id');
            $t->unsignedBigInteger('pricing_plan_id')->nullable();
            $t->boolean('active')->default(true);
            $t->date('start_date')->nullable();
            $t->date('end_date')->nullable();
            $t->timestamp('trial_ends_at')->nullable();
            $t->string('override_type')->default('none');
            $t->timestamp('override_until')->nullable();
            $t->timestamp('override_granted_at')->nullable();
            $t->integer('free_invoice_limit')->nullable();
            $t->timestamps();
        });

        Schema::create('pos_products', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('company_id');
            $t->string('name');
            $t->decimal('price', 12, 2)->default(0);
            $t->decimal('cost_price', 12, 4)->nullable();
            $t->string('category')->nullable();
            $t->boolean('is_active')->default(true);
            $t->boolean('show_on_sale')->default(true);
            $t->boolean('is_tax_exempt')->default(false);
            $t->boolean('is_third_schedule')->default(false);
            $t->string('barcode')->nullable();
            $t->timestamps();
            $t->softDeletes();
        });

        Schema::create('pos_transactions', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('company_id');
            $t->unsignedBigInteger('branch_id')->nullable();
            $t->unsignedBigInteger('terminal_id')->nullable();
            $t->string('invoice_number');
            $t->string('invoice_mode')->nullable();
            $t->unsignedBigInteger('customer_id')->nullable();
            $t->string('customer_name')->nullable();
            $t->string('customer_phone')->nullable();
            $t->string('delivery_address')->nullable();
            $t->decimal('subtotal', 12, 2)->default(0);
            $t->string('discount_type')->nullable();
            $t->decimal('discount_value', 12, 2)->default(0);
            $t->decimal('discount_amount', 12, 2)->default(0);
            $t->decimal('tax_rate', 8, 2)->default(0);
            $t->decimal('tax_amount', 12, 2)->default(0);
            $t->decimal('exempt_amount', 12, 2)->nullable();
            $t->decimal('total_amount', 12, 2)->default(0);
            $t->string('payment_method')->nullable();
            $t->decimal('cash_received', 12, 2)->nullable();
            $t->decimal('change_due', 12, 2)->nullable();
            $t->string('status');
            $t->string('pra_status')->nullable();
            $t->string('pra_invoice_number')->nullable();
            $t->string('submission_hash')->nullable();
            $t->string('offline_uuid', 64)->nullable();
            $t->unsignedBigInteger('created_by')->nullable();
            $t->unsignedBigInteger('locked_by_terminal_id')->nullable();
            $t->timestamp('lock_time')->nullable();
            $t->text('notes')->nullable();
            $t->boolean('is_archived')->default(false);
            $t->timestamp('archived_at')->nullable();
            $t->string('business_date')->nullable();
            $t->boolean('tax_inclusive')->default(false);
            $t->timestamps();
            $t->unique(['company_id', 'invoice_number']);
        });

        Schema::create('pos_transaction_items', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('transaction_id');
            $t->string('item_type')->default('product');
            $t->unsignedBigInteger('item_id')->nullable();
            $t->string('item_name');
            $t->text('special_notes')->nullable();
            $t->text('deal_snapshot')->nullable();
            $t->decimal('quantity', 12, 3)->default(1);
            $t->decimal('unit_price', 12, 2)->default(0);
            $t->decimal('cost_price', 12, 4)->nullable(); // profit-freeze snapshot
            $t->decimal('subtotal', 12, 2)->default(0);
            $t->boolean('is_tax_exempt')->default(false);
            $t->boolean('is_third_schedule')->default(false);
            $t->decimal('tax_rate', 8, 2)->default(0);
            $t->decimal('tax_amount', 12, 2)->default(0);
            $t->string('item_discount_type')->nullable();
            $t->decimal('item_discount_value', 12, 2)->nullable();
            $t->decimal('item_discount_amount', 12, 2)->nullable();
            $t->timestamps();
        });

        Schema::create('pos_payments', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('transaction_id');
            $t->string('payment_method');
            $t->decimal('amount', 12, 2)->default(0);
            $t->string('reference_number')->nullable();
            $t->timestamps();
        });

        Schema::create('branches', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('company_id');
            $t->string('name')->nullable();
            $t->boolean('is_head_office')->default(false);
            $t->boolean('is_active')->default(true);
            $t->timestamps();
        });

        Schema::create('pos_tax_rules', function (Blueprint $t) {
            $t->id();
            $t->string('payment_method');
            $t->decimal('tax_rate', 8, 2)->default(0);
            $t->boolean('is_active')->default(true);
            $t->timestamps();
        });
        DB::table('pos_tax_rules')->insert([
            ['payment_method' => 'cash',       'tax_rate' => 0, 'is_active' => true, 'created_at' => now(), 'updated_at' => now()],
            ['payment_method' => 'debit_card',  'tax_rate' => 0, 'is_active' => true, 'created_at' => now(), 'updated_at' => now()],
        ]);

        // restaurant_orders required by applyReportFilters (waiter attribution query)
        Schema::create('restaurant_orders', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('company_id')->nullable();
            $t->unsignedBigInteger('transaction_id')->nullable();
            $t->unsignedBigInteger('created_by')->nullable();
            $t->timestamps();
        });

        // Required by dashboard() → Notification::where(company_id)
        Schema::create('notifications', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('company_id')->nullable();
            $t->unsignedBigInteger('user_id')->nullable();
            $t->string('type');
            $t->string('title');
            $t->text('message');
            $t->boolean('read')->default(false);
            $t->json('metadata')->nullable();
            $t->timestamps();
        });

        // Required by dashboard() → PosDayOpening::forDate()
        Schema::create('pos_day_openings', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('company_id');
            $t->date('business_date');
            $t->decimal('opening_cash', 15, 2)->default(0);
            $t->unsignedBigInteger('entered_by')->nullable();
            $t->string('notes', 500)->nullable();
            $t->timestamps();
            $t->unique(['company_id', 'business_date']);
        });

        // Required by dashboard() → PosDayCloseReport::where(company_id, report_date)
        Schema::create('pos_day_close_reports', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('company_id');
            $t->date('report_date');
            $t->string('report_number', 50)->nullable();
            $t->integer('total_invoices')->default(0);
            $t->decimal('total_amount', 15, 2)->default(0);
            $t->unsignedBigInteger('closed_by')->nullable();
            $t->timestamps();
            $t->unique(['company_id', 'report_date']);
        });
    }
}
