<?php

namespace Tests\Feature;

use App\Http\Controllers\FbrPosController;
use App\Http\Controllers\FbrPosStockController;
use App\Models\InventoryMovement;
use App\Models\User;
use App\Services\BranchContextService;
use App\Services\BranchStockService;
use App\Services\InventoryService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * FBR POS PER-BRANCH STOCK — Gulberg ki bikri Main Shop ka maal na khaye (Task 1365).
 *
 * PRA POS already keeps stock per branch (Task 1354). FBR POS had the branch
 * switcher and stamped the branch on the bill, but EVERY stock write still
 * passed `branch_id = null`: one shared pile for the whole company. This locks
 * the FBR side onto the same BranchStockService rules:
 *
 *   1. A sale deducts ONLY the branch stamped on the BILL — never the session's.
 *   2. Received goods (purchase) land in one named shop; a void puts them back
 *      into the SAME shop even after the owner has switched branches.
 *   3. The stock page shows the branch on screen; the owner's all-branches view
 *      shows the company total (STRICT filter — nothing counted twice).
 *   4. Branch-bound edits (min level, quantity correction) are refused on the
 *      all-branches view instead of silently guessing head office.
 *   5. Old branch-less FBR stock is adopted by head office — purana maal ghayab
 *      na ho — and MERGES rather than colliding with the unique index.
 *   6. Branch transfer moves goods between shops, refuses to over-send, honours
 *      both ends of the actor's branch permissions and leaves the total alone.
 *   7. A company with NO branches keeps writing branch_id = NULL, exactly as
 *      before — single-shop users must not notice this feature exists.
 *
 * Pattern: APP_ENV=testing + sqlite :memory: + minimal Schema::create, direct
 * controller calls (same as PosBranchStockTest / FbrDealStockAndAllocationTest).
 */
class FbrPosBranchStockTest extends TestCase
{
    protected int $companyId;
    protected int $mainBranchId;
    protected int $cityBranchId;
    protected int $productId;

    protected function setUp(): void
    {
        parent::setUp();

        Schema::dropAllTables();
        User::flushScopeColumnCache();
        // Schema is rebuilt per test — a memoized "branches table missing" or a
        // stale branch list from the previous case would silently pass/fail.
        BranchStockService::flushMemo();
        \App\Services\PosFeatureService::flushGateCaches();

        Schema::create('companies', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('product_type')->nullable();
            $table->string('status')->nullable();
            $table->string('company_status')->nullable();
            $table->string('default_language')->nullable();
            $table->boolean('fbr_reporting_enabled')->default(false);
            $table->boolean('agent_enabled')->default(false);
            $table->boolean('agent_submits_pra')->nullable();
            $table->string('fbr_connection_mode')->nullable();
            $table->boolean('inventory_enabled')->default(true);
            $table->boolean('is_internal_account')->default(true);
            $table->integer('invoice_limit_override')->nullable();
            $table->decimal('cashier_discount_limit', 5, 2)->nullable();
            $table->decimal('manager_discount_limit', 5, 2)->nullable();
            $table->string('pos_invoice_prefix')->nullable();
            $table->softDeletes();
            $table->timestamps();
        });

        Schema::create('users', function (Blueprint $table) {
            $table->id();
            $table->string('name')->nullable();
            $table->string('email')->nullable()->unique();
            $table->string('password')->nullable();
            $table->unsignedBigInteger('company_id')->nullable();
            $table->string('role')->nullable();
            $table->string('pos_role')->nullable();
            $table->unsignedBigInteger('default_branch_id')->nullable();
            $table->boolean('is_active')->default(true);
            $table->string('language')->nullable();
            $table->rememberToken();
            $table->timestamps();
        });

        Schema::create('branches', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('company_id');
            $table->string('name');
            $table->string('city')->nullable();
            $table->boolean('is_head_office')->default(false);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('branch_user', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('branch_id');
            $table->unsignedBigInteger('user_id');
            $table->timestamps();
        });

        // FBR POS products live in the SHARED `products` table (not pos_products).
        Schema::create('products', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('company_id');
            $table->string('name');
            $table->string('sku')->nullable();
            $table->string('barcode')->nullable();
            $table->string('hs_code')->nullable();
            $table->string('uom')->nullable();
            $table->decimal('default_price', 12, 2)->default(0);
            $table->decimal('default_tax_rate', 8, 2)->nullable();
            $table->string('tax_type')->default('taxable');
            $table->boolean('is_third_schedule')->default(false);
            $table->boolean('is_price_editable')->default(true);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('inventory_stocks', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('company_id');
            $table->unsignedBigInteger('product_id');
            $table->unsignedBigInteger('branch_id')->nullable();
            $table->decimal('quantity', 15, 4)->default(0);
            $table->decimal('min_stock_level', 15, 4)->default(0);
            $table->decimal('avg_purchase_price', 15, 2)->default(0);
            $table->decimal('last_purchase_price', 15, 2)->default(0);
            $table->timestamps();
            // The real constraint — a blind legacy UPDATE must not be able to
            // collide with an existing head-office row (see adoptLegacyRows).
            $table->unique(['company_id', 'product_id', 'branch_id'], 'inv_stock_unique');
        });

        Schema::create('inventory_movements', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('company_id');
            $table->unsignedBigInteger('product_id');
            $table->unsignedBigInteger('branch_id')->nullable();
            $table->string('type')->nullable();
            $table->decimal('quantity', 15, 4)->default(0);
            $table->decimal('unit_price', 15, 2)->default(0);
            $table->decimal('total_price', 15, 2)->default(0);
            $table->decimal('balance_after', 15, 4)->default(0);
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
            $table->string('po_number');
            $table->string('status');
            $table->date('order_date')->nullable();
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

        // ── sale path (store()) tables ──────────────────────────────────────
        Schema::create('fbr_pos_transactions', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('company_id');
            $table->unsignedBigInteger('branch_id')->nullable();
            $table->unsignedBigInteger('terminal_id')->nullable();
            $table->unsignedBigInteger('shift_id')->nullable();
            $table->string('invoice_number');
            $table->string('invoice_mode')->nullable();
            $table->string('transaction_type')->nullable();
            $table->string('status')->nullable();
            $table->string('fbr_status')->nullable();
            $table->string('fbr_invoice_number')->nullable();
            $table->string('fbr_response_code')->nullable();
            $table->text('fbr_response')->nullable();
            $table->string('fbr_submission_hash')->nullable();
            $table->unsignedBigInteger('customer_id')->nullable();
            $table->string('customer_name')->nullable();
            $table->string('customer_phone')->nullable();
            $table->string('customer_ntn')->nullable();
            $table->decimal('subtotal', 12, 2)->default(0);
            $table->string('discount_type')->nullable();
            $table->decimal('discount_value', 12, 2)->nullable();
            $table->decimal('discount_amount', 12, 2)->default(0);
            $table->decimal('tax_rate', 8, 2)->nullable();
            $table->decimal('tax_amount', 12, 2)->default(0);
            $table->decimal('fbr_service_charge', 12, 2)->nullable();
            $table->decimal('loyalty_points_earned', 12, 2)->nullable();
            $table->decimal('loyalty_points_redeemed', 12, 2)->nullable();
            $table->decimal('loyalty_redemption_amount', 12, 2)->nullable();
            $table->decimal('total_amount', 12, 2)->default(0);
            $table->string('payment_method')->nullable();
            $table->text('payment_breakdown')->nullable();
            $table->decimal('cash_received', 12, 2)->nullable();
            $table->decimal('change_due', 12, 2)->nullable();
            $table->unsignedBigInteger('promotion_id')->nullable();
            $table->string('promotion_code')->nullable();
            $table->unsignedBigInteger('parent_transaction_id')->nullable();
            $table->string('order_type')->nullable();
            $table->string('delivery_address')->nullable();
            $table->string('offline_uuid', 64)->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamps();
            $table->unique(['company_id', 'offline_uuid'], 'fbr_txn_offline_uuid_unique');
        });

        Schema::create('fbr_pos_transaction_items', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('transaction_id');
            $table->unsignedBigInteger('product_id')->nullable();
            $table->string('item_name');
            $table->string('hs_code')->nullable();
            $table->string('uom')->nullable();
            $table->decimal('quantity', 12, 4)->default(1);
            $table->decimal('unit_price', 12, 2)->default(0);
            $table->decimal('cost_price', 12, 4)->nullable();
            $table->decimal('discount', 12, 2)->nullable();
            $table->decimal('item_discount', 12, 2)->nullable();
            $table->boolean('is_tax_exempt')->default(false);
            $table->boolean('is_third_schedule')->default(false);
            $table->decimal('tax_rate', 8, 2)->nullable();
            $table->decimal('tax_amount', 12, 2)->nullable();
            $table->decimal('subtotal', 12, 2)->nullable();
            $table->decimal('total', 12, 2)->nullable();
            $table->string('deal_group', 40)->nullable();
            $table->unsignedBigInteger('deal_id')->nullable();
            $table->string('deal_name')->nullable();
            $table->unsignedInteger('deal_quantity')->nullable();
            $table->decimal('deal_unit_price', 10, 2)->nullable();
            $table->timestamps();
        });

        Schema::create('fbr_pos_loyalty_settings', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('company_id')->unique();
            $table->boolean('is_enabled')->default(false);
            $table->decimal('rs_per_point', 8, 2)->default(100);
            $table->decimal('point_value', 8, 2)->default(1);
            $table->integer('min_redeem_points')->default(50);
            $table->integer('points_expiry_days')->nullable();
            $table->timestamps();
        });

        Schema::create('fbr_pos_shifts', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('company_id');
            $table->unsignedBigInteger('user_id');
            $table->string('status')->default('open');
            $table->decimal('sales_count', 12, 2)->default(0);
            $table->decimal('total_sales', 12, 2)->default(0);
            $table->decimal('total_cash', 12, 2)->default(0);
            $table->decimal('total_card', 12, 2)->default(0);
            $table->decimal('total_other', 12, 2)->default(0);
            $table->timestamps();
        });

        Schema::create('pos_customers', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('company_id');
            $table->string('name');
            $table->string('phone')->nullable();
            $table->decimal('khata_balance', 12, 2)->default(0);
            $table->integer('loyalty_points')->default(0);
            $table->decimal('total_spent', 12, 2)->default(0);
            $table->integer('total_orders')->default(0);
            $table->timestamps();
        });

        Schema::create('fbr_customer_ledgers', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('company_id');
            $table->unsignedBigInteger('customer_id');
            $table->unsignedBigInteger('transaction_id')->nullable();
            $table->string('entry_type');
            $table->decimal('amount', 12, 2);
            $table->decimal('balance_after', 12, 2);
            $table->string('note')->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamps();
        });

        Schema::create('pricing_plans', function (Blueprint $table) {
            $table->id();
            $table->string('name')->nullable();
            $table->string('product_type')->default('fbrpos');
            $table->boolean('is_trial')->default(false);
            $table->decimal('price', 12, 2)->default(0);
            $table->timestamps();
        });

        Schema::create('subscriptions', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('company_id');
            $table->unsignedBigInteger('pricing_plan_id')->nullable();
            $table->boolean('active')->default(true);
            $table->date('start_date')->nullable();
            $table->date('end_date')->nullable();
            $table->timestamp('trial_ends_at')->nullable();
            $table->string('override_type')->default('lifetime');
            $table->timestamp('override_until')->nullable();
            $table->timestamp('override_granted_at')->nullable();
            $table->integer('free_invoice_limit')->nullable();
            $table->timestamps();
        });

        $this->companyId = (int) DB::table('companies')->insertGetId([
            'name' => 'Do Dukan Karyana (FBR)',
            'product_type' => 'fbrpos',
            'status' => 'active',
            'fbr_reporting_enabled' => false,
            'inventory_enabled' => true,
            'is_internal_account' => true,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('subscriptions')->insert([
            'company_id' => $this->companyId, 'active' => true, 'override_type' => 'lifetime',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('fbr_pos_loyalty_settings')->insert([
            'company_id' => $this->companyId, 'is_enabled' => false,
            'rs_per_point' => 100, 'point_value' => 1, 'min_redeem_points' => 50,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        app()->bind('currentCompanyId', fn () => $this->companyId);
        app()->bind('currentBranchId', fn () => null);

        $this->mainBranchId = $this->makeBranch('Main Shop', true);
        $this->cityBranchId = $this->makeBranch('Gulberg');
        $this->productId = $this->makeProduct('Chai Patti');
    }

    protected function tearDown(): void
    {
        session()->forget(BranchContextService::SESSION_KEY);
        Auth::guard('fbrpos')->logout();
        BranchStockService::flushMemo();
        \App\Services\PosFeatureService::flushGateCaches();
        parent::tearDown();
    }

    // ── fixtures ─────────────────────────────────────────────────────────────

    private function makeBranch(string $name, bool $head = false): int
    {
        return (int) DB::table('branches')->insertGetId([
            'company_id' => $this->companyId,
            'name' => $name,
            'is_head_office' => $head,
            'is_active' => true,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function makeProduct(string $name): int
    {
        return (int) DB::table('products')->insertGetId([
            'company_id' => $this->companyId,
            'name' => $name,
            'uom' => 'U',
            'default_price' => 250,
            'default_tax_rate' => 18,
            'tax_type' => 'taxable',
            'is_active' => true,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    /** Opening stock straight into the table, bypassing the code under test. */
    private function seedStock(?int $branchId, float $qty, ?int $productId = null, float $min = 5, float $cost = 200): int
    {
        return (int) DB::table('inventory_stocks')->insertGetId([
            'company_id' => $this->companyId,
            'product_id' => $productId ?? $this->productId,
            'branch_id' => $branchId,
            'quantity' => $qty,
            'min_stock_level' => $min,
            'avg_purchase_price' => $cost,
            'last_purchase_price' => $cost,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    /** The receiving shelf's stock row, for cost assertions. */
    private function costRow(int $branchId, ?int $productId = null)
    {
        return DB::table('inventory_stocks')
            ->where('company_id', $this->companyId)
            ->where('product_id', $productId ?? $this->productId)
            ->where('branch_id', $branchId)
            ->first();
    }

    private function qty(?int $branchId, ?int $productId = null): ?float
    {
        $row = DB::table('inventory_stocks')
            ->where('company_id', $this->companyId)
            ->where('product_id', $productId ?? $this->productId)
            ->when($branchId, fn ($q) => $q->where('branch_id', $branchId))
            ->when(!$branchId, fn ($q) => $q->whereNull('branch_id'))
            ->first();

        return $row ? (float) $row->quantity : null;
    }

    private function makeUser(string $posRole = 'pos_admin', ?int $branchId = null): User
    {
        $id = DB::table('users')->insertGetId([
            'name' => 'U' . uniqid(),
            'email' => uniqid('u') . '@taxnest.test',
            'password' => bcrypt('Secret@12345'),
            'company_id' => $this->companyId,
            'role' => $posRole === 'pos_admin' ? 'company_admin' : 'user',
            'pos_role' => $posRole,
            'default_branch_id' => $branchId,
            'is_active' => true,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        return User::find($id);
    }

    /** A manager is confined to the branches in their `branch_user` pivot. */
    private function makeManagerFor(array $branchIds): User
    {
        $user = $this->makeUser('pos_manager', $branchIds[0] ?? null);
        foreach ($branchIds as $branchId) {
            DB::table('branch_user')->insert([
                'branch_id' => $branchId, 'user_id' => $user->id,
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }

        return $user;
    }

    /** Sign in on the fbrpos guard and (optionally) put a branch on screen. */
    private function actAs(User $user, $branch = null): void
    {
        Auth::guard('fbrpos')->setUser($user);
        // Accessible branches are memoized per actor — swapping user mid-test
        // must not inherit the previous one's reach.
        BranchStockService::flushMemo();
        app()->forgetInstance(BranchContextService::class);
        if ($branch !== null) {
            session([BranchContextService::SESSION_KEY => $branch]);
        }
    }

    private function stockController(): FbrPosStockController
    {
        return new FbrPosStockController();
    }

    /** POST /fbr-pos/stock/purchase — receive $qty of the product. */
    private function receiveStock(float $qty, ?int $branchId, float $price = 200)
    {
        $payload = [
            'items' => [[
                'product_id' => $this->productId,
                'quantity' => $qty,
                'unit_price' => $price,
            ]],
        ];
        if ($branchId !== null) {
            $payload['branch_id'] = $branchId;
        }

        return $this->stockController()->storePurchase(
            Request::create('/fbr-pos/stock/purchase', 'POST', $payload)
        );
    }

    /** The stock page's view data for whoever is signed in right now. */
    private function stockPage(): array
    {
        return $this->stockController()->index()->getData();
    }

    private function pageRow(array $data, ?int $productId = null)
    {
        return collect($data['rows'])->firstWhere('product_id', $productId ?? $this->productId);
    }

    /** A one-line cash sale through the real FBR sale path. */
    private function sell(float $qty, ?int $branchId, User $user)
    {
        app()->bind('currentBranchId', fn () => $branchId);
        Auth::guard('fbrpos')->setUser($user);
        BranchStockService::flushMemo();

        $req = Request::create('/fbr-pos/store', 'POST', [
            'items' => [[
                'product_id' => $this->productId,
                'item_name' => 'Chai Patti',
                'quantity' => $qty,
                'unit_price' => 250,
                'uom' => 'U',
                'tax_rate' => 18,
                'is_tax_exempt' => false,
                'item_discount' => 0,
            ]],
            'payment_method' => 'cash',
            'cash_received' => 250 * $qty * 2,
            'discount_type' => 'percentage',
            'discount_value' => 0,
            'tax_inclusive' => false,
            'offline_uuid' => 'branch-stock-' . uniqid(),
        ]);
        $req->headers->set('Accept', 'application/json');

        return (new FbrPosController())->store($req);
    }

    // ── 1. a sale only touches the branch on the bill ────────────────────────

    public function test_sale_deducts_only_the_branch_the_bill_was_made_on(): void
    {
        $this->seedStock($this->mainBranchId, 10);
        $this->seedStock($this->cityBranchId, 10);
        $owner = $this->makeUser();

        $res = $this->sell(3, $this->cityBranchId, $owner);
        $this->assertTrue($res->getData(true)['success'] ?? false, 'sale went through');

        $this->assertSame(10.0, $this->qty($this->mainBranchId), 'Main Shop untouched');
        $this->assertSame(7.0, $this->qty($this->cityBranchId), 'only Gulberg sold 3');
    }

    public function test_sale_ignores_the_branch_selector_and_follows_the_bill(): void
    {
        $this->seedStock($this->mainBranchId, 10);
        $this->seedStock($this->cityBranchId, 10);
        $owner = $this->makeUser();
        // Owner is BROWSING Main Shop but the bill is rung up on Gulberg.
        session([BranchContextService::SESSION_KEY => $this->mainBranchId]);

        $this->sell(2, $this->cityBranchId, $owner);

        $this->assertSame(10.0, $this->qty($this->mainBranchId), 'browsed branch keeps its maal');
        $this->assertSame(8.0, $this->qty($this->cityBranchId), 'billing branch pays for the sale');
    }

    public function test_sale_movement_row_is_stamped_with_the_bill_branch(): void
    {
        $this->seedStock($this->cityBranchId, 10);
        $this->sell(4, $this->cityBranchId, $this->makeUser());

        $movement = DB::table('inventory_movements')
            ->where('company_id', $this->companyId)
            ->where('type', InventoryMovement::TYPE_SALE)
            ->orderByDesc('id')
            ->first();

        $this->assertNotNull($movement, 'sale writes a ledger row');
        $this->assertSame($this->cityBranchId, (int) $movement->branch_id, 'ledger row belongs to the selling branch');
    }

    public function test_all_branches_sale_stamps_the_resolved_head_office_on_bill_and_stock_movement(): void
    {
        $this->seedStock($this->mainBranchId, 10);
        $this->seedStock($this->cityBranchId, 10);
        $owner = $this->makeUser();
        $this->actAs($owner, BranchContextService::ALL);

        // The all-branches selector is a valid owner view, but a sale still
        // needs one concrete branch. writeBranchId() resolves the head office;
        // the bill, deduction ledger and later return must share that choice.
        $response = $this->sell(3, null, $owner);
        $this->assertTrue($response->getData(true)['success'] ?? false, 'sale went through');

        $bill = DB::table('fbr_pos_transactions')
            ->where('company_id', $this->companyId)
            ->orderByDesc('id')
            ->first();
        $this->assertSame($this->mainBranchId, (int) $bill->branch_id, 'all-branches sale is booked at head office');

        $movement = DB::table('inventory_movements')
            ->where('reference_type', 'fbr_pos_transaction')
            ->where('reference_id', $bill->id)
            ->where('type', InventoryMovement::TYPE_SALE)
            ->first();
        $this->assertNotNull($movement);
        $this->assertSame($this->mainBranchId, (int) $movement->branch_id, 'stock deduction follows the bill');
        $this->assertSame(7.0, $this->qty($this->mainBranchId), 'head-office stock paid for the sale');
        $this->assertSame(10.0, $this->qty($this->cityBranchId), 'other branch was untouched');
    }

    // ── 2. purchases land — and unwind — in one named shop ───────────────────

    public function test_received_stock_lands_only_in_the_picked_branch(): void
    {
        $this->actAs($this->makeUser(), $this->mainBranchId);

        $this->receiveStock(12, $this->cityBranchId);

        $this->assertNull($this->qty($this->mainBranchId), 'browsed branch gets nothing');
        $this->assertSame(12.0, $this->qty($this->cityBranchId), 'goods land where they were received');
    }

    public function test_received_stock_falls_back_to_the_branch_on_screen(): void
    {
        $this->actAs($this->makeUser(), $this->cityBranchId);

        $this->receiveStock(6, null);

        $this->assertSame(6.0, $this->qty($this->cityBranchId));
        $this->assertNull($this->qty($this->mainBranchId));
    }

    public function test_purchase_from_the_all_branches_view_is_refused(): void
    {
        $this->actAs($this->makeUser(), BranchContextService::ALL);

        $response = $this->receiveStock(5, null);

        $this->assertSame(__('pos.stock_edit_pick_branch'), session('error'), 'owner is asked which shop received it');
        $this->assertSame(0, DB::table('purchase_orders')->count(), 'nothing was written');
        $this->assertNull($this->qty($this->mainBranchId));
        $this->assertNull($this->qty($this->cityBranchId));
        $this->assertNotNull($response);
    }

    public function test_void_returns_the_goods_to_the_branch_that_received_them(): void
    {
        $owner = $this->makeUser();
        $this->actAs($owner, $this->cityBranchId);
        $this->receiveStock(10, $this->cityBranchId);
        $poId = (int) DB::table('purchase_orders')->orderByDesc('id')->value('id');

        // Owner has since switched to Main Shop — the void must NOT invent
        // stock there and leave a hole in Gulberg.
        $this->actAs($owner, $this->mainBranchId);
        $this->stockController()->voidPurchase($poId);

        $this->assertSame(0.0, $this->qty($this->cityBranchId), 'reversed out of Gulberg');
        $this->assertNull($this->qty($this->mainBranchId), 'Main Shop never saw these goods');
    }

    // ── 3. the stock page is branch-scoped ───────────────────────────────────

    public function test_stock_page_shows_only_the_branch_on_screen(): void
    {
        $this->seedStock($this->mainBranchId, 40);
        $this->seedStock($this->cityBranchId, 7);
        $this->actAs($this->makeUser(), $this->cityBranchId);

        $data = $this->stockPage();

        $this->assertFalse($data['allBranches'], 'a named branch is on screen');
        $this->assertSame('Gulberg', $data['activeBranchName']);
        $this->assertSame(7.0, (float) $this->pageRow($data)->quantity);
    }

    public function test_owner_all_branches_view_shows_the_company_total_once(): void
    {
        $this->seedStock($this->mainBranchId, 40);
        $this->seedStock($this->cityBranchId, 7);
        $this->actAs($this->makeUser(), BranchContextService::ALL);

        $data = $this->stockPage();

        $this->assertTrue($data['allBranches']);
        // STRICT filter: 47, never 47 + a duplicated branch-less row.
        $this->assertSame(47.0, (float) $this->pageRow($data)->quantity);
    }

    public function test_manager_stock_view_stays_on_their_own_branch(): void
    {
        $this->seedStock($this->mainBranchId, 40);
        $this->seedStock($this->cityBranchId, 7);
        // A confined manager asking for "all branches" must not get it.
        $this->actAs($this->makeManagerFor([$this->cityBranchId]), BranchContextService::ALL);

        $data = $this->stockPage();

        $this->assertFalse($data['allBranches'], 'manager never holds the all-branches view');
        $this->assertSame(7.0, (float) $this->pageRow($data)->quantity);
    }

    // ── 4. branch-bound edits refuse the all-branches view ───────────────────

    public function test_min_level_is_saved_against_the_branch_on_screen(): void
    {
        $this->seedStock($this->mainBranchId, 10, null, 0);
        $this->seedStock($this->cityBranchId, 10, null, 0);
        $this->actAs($this->makeUser(), $this->cityBranchId);

        $this->stockController()->updateMinLevel(Request::create('/fbr-pos/stock/min-level', 'POST', [
            'product_id' => $this->productId,
            'min_stock_level' => 4,
        ]));

        $this->assertSame(4.0, (float) DB::table('inventory_stocks')
            ->where('branch_id', $this->cityBranchId)->value('min_stock_level'));
        $this->assertSame(0.0, (float) DB::table('inventory_stocks')
            ->where('branch_id', $this->mainBranchId)->value('min_stock_level'), 'other shop untouched');
    }

    public function test_min_level_is_refused_on_the_all_branches_view(): void
    {
        $this->seedStock($this->mainBranchId, 10, null, 0);
        $this->actAs($this->makeUser(), BranchContextService::ALL);

        $response = $this->stockController()->updateMinLevel(Request::create('/fbr-pos/stock/min-level', 'POST', [
            'product_id' => $this->productId,
            'min_stock_level' => 4,
        ]));

        $this->assertSame(422, $response->getStatusCode(), 'refused, not silently written to head office');
        $this->assertSame(0.0, (float) DB::table('inventory_stocks')
            ->where('branch_id', $this->mainBranchId)->value('min_stock_level'));
    }

    public function test_quantity_correction_is_refused_on_the_all_branches_view(): void
    {
        $this->seedStock($this->mainBranchId, 10);
        $this->seedStock($this->cityBranchId, 10);
        $this->actAs($this->makeUser(), BranchContextService::ALL);

        $this->stockController()->updateItem(Request::create('/fbr-pos/stock/item', 'POST', [
            'product_id' => $this->productId,
            'default_price' => 250,
            'new_quantity' => 99,
        ]));

        $this->assertSame(10.0, $this->qty($this->mainBranchId), 'no shop was corrected');
        $this->assertSame(10.0, $this->qty($this->cityBranchId));
    }

    public function test_sale_price_edit_still_works_on_the_all_branches_view(): void
    {
        // Price and unit are the same item everywhere — only the branch-bound
        // fields are locked on the company-wide view.
        $this->seedStock($this->mainBranchId, 10);
        $this->actAs($this->makeUser(), BranchContextService::ALL);

        $this->stockController()->updateItem(Request::create('/fbr-pos/stock/item', 'POST', [
            'product_id' => $this->productId,
            'default_price' => 320,
        ]));

        $this->assertSame(320.0, (float) DB::table('products')->where('id', $this->productId)->value('default_price'));
    }

    // ── 5. purana maal ghayab na ho ──────────────────────────────────────────

    public function test_legacy_branchless_stock_is_adopted_by_head_office(): void
    {
        $this->seedStock(null, 25);
        $this->actAs($this->makeUser(), $this->mainBranchId);

        $data = $this->stockPage();

        $this->assertNull($this->qty(null), 'no branch-less row survives');
        $this->assertSame(25.0, $this->qty($this->mainBranchId), 'head office inherited the maal');
        $this->assertSame(25.0, (float) $this->pageRow($data)->quantity);
    }

    public function test_legacy_adoption_merges_instead_of_colliding(): void
    {
        // Head office already holds this product — a blind UPDATE would break
        // the (company_id, product_id, branch_id) unique index.
        $this->seedStock($this->mainBranchId, 4);
        $this->seedStock(null, 6);

        BranchStockService::healLegacyRows($this->companyId);

        $this->assertNull($this->qty(null));
        $this->assertSame(10.0, $this->qty($this->mainBranchId), '4 + 6 merged into one row');
        $this->assertSame(1, DB::table('inventory_stocks')
            ->where('product_id', $this->productId)->where('branch_id', $this->mainBranchId)->count());
    }

    public function test_a_sale_on_a_company_with_legacy_stock_still_finds_the_goods(): void
    {
        $this->seedStock(null, 20);

        $this->sell(5, $this->cityBranchId, $this->makeUser());

        $this->assertNull($this->qty(null));
        $this->assertSame(20.0, $this->qty($this->mainBranchId), 'legacy maal is head office stock');
        $this->assertSame(-5.0, $this->qty($this->cityBranchId), 'Gulberg oversold from its own (empty) shelf');
    }

    public function test_legacy_movement_history_follows_the_goods(): void
    {
        $this->seedStock(null, 5);
        DB::table('inventory_movements')->insert([
            'company_id' => $this->companyId, 'product_id' => $this->productId, 'branch_id' => null,
            'type' => InventoryMovement::TYPE_PURCHASE, 'quantity' => 5, 'unit_price' => 200,
            'total_price' => 1000, 'balance_after' => 5,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        BranchStockService::healLegacyRows($this->companyId);

        $this->assertSame(0, DB::table('inventory_movements')->whereNull('branch_id')->count());
        $this->assertSame(1, DB::table('inventory_movements')->where('branch_id', $this->mainBranchId)->count());
    }

    // ── 6. branch transfer ───────────────────────────────────────────────────

    private function transfer(int $from, int $to, float $qty)
    {
        return $this->stockController()->storeTransfer(
            Request::create('/fbr-pos/stock/transfer', 'POST', [
                'product_id' => $this->productId,
                'from_branch_id' => $from,
                'to_branch_id' => $to,
                'quantity' => $qty,
            ])
        );
    }

    public function test_transfer_moves_goods_without_changing_the_company_total(): void
    {
        $this->seedStock($this->mainBranchId, 30);
        $this->seedStock($this->cityBranchId, 2);
        $this->actAs($this->makeUser(), $this->mainBranchId);

        $this->transfer($this->mainBranchId, $this->cityBranchId, 8);

        $this->assertSame(22.0, $this->qty($this->mainBranchId));
        $this->assertSame(10.0, $this->qty($this->cityBranchId));
        $this->assertSame(32.0, $this->qty($this->mainBranchId) + $this->qty($this->cityBranchId), 'total unchanged');
    }

    public function test_transfer_reweights_the_cost_of_a_destination_that_already_has_stock(): void
    {
        // Gulberg holds 10 units bought at Rs.100; Main Shop sends 10 more that
        // cost Rs.200. The shelf is now worth Rs.150 a unit — keeping Rs.100
        // would undervalue the maal and every later Gulberg sale would report
        // too much munafa.
        $this->seedStock($this->mainBranchId, 10, null, 5, 200);
        $this->seedStock($this->cityBranchId, 10, null, 5, 100);
        $this->actAs($this->makeUser(), $this->mainBranchId);

        $this->transfer($this->mainBranchId, $this->cityBranchId, 10);

        $dest = $this->costRow($this->cityBranchId);
        $this->assertSame(20.0, (float) $dest->quantity);
        $this->assertSame(150.0, (float) $dest->avg_purchase_price, 'weighted across old and arriving maal');
        $this->assertSame(200.0, (float) $dest->last_purchase_price, 'the arriving units are the latest rate');

        $source = $this->costRow($this->mainBranchId);
        $this->assertSame(200.0, (float) $source->avg_purchase_price, 'sending at its own average leaves it unchanged');
    }

    public function test_a_sale_after_a_transfer_snapshots_the_reweighted_cost(): void
    {
        $this->seedStock($this->mainBranchId, 10, null, 5, 200);
        $this->seedStock($this->cityBranchId, 10, null, 5, 100);
        $owner = $this->makeUser();
        $this->actAs($owner, $this->mainBranchId);
        $this->transfer($this->mainBranchId, $this->cityBranchId, 10);

        $this->sell(1, $this->cityBranchId, $owner);

        $this->assertSame(
            150.0,
            (float) DB::table('fbr_pos_transaction_items')->orderByDesc('id')->value('cost_price'),
            'the bill freezes the shelf\'s real cost, not the pre-transfer one'
        );
    }

    public function test_transfer_into_an_empty_shelf_simply_takes_the_arriving_cost(): void
    {
        $this->seedStock($this->mainBranchId, 10, null, 5, 200);
        $this->actAs($this->makeUser(), $this->mainBranchId);

        $this->transfer($this->mainBranchId, $this->cityBranchId, 4);

        $dest = $this->costRow($this->cityBranchId);
        $this->assertSame(200.0, (float) $dest->avg_purchase_price);
        $this->assertSame(200.0, (float) $dest->last_purchase_price);
    }

    public function test_transfer_of_rateless_goods_does_not_wipe_the_destination_rate(): void
    {
        // Rs.0 means "no rate recorded", never "free" — it must not dilute a
        // shelf that does know what its maal cost.
        $this->seedStock($this->mainBranchId, 10, null, 5, 0);
        $this->seedStock($this->cityBranchId, 10, null, 5, 120);
        $this->actAs($this->makeUser(), $this->mainBranchId);

        $this->transfer($this->mainBranchId, $this->cityBranchId, 5);

        $dest = $this->costRow($this->cityBranchId);
        $this->assertSame(15.0, (float) $dest->quantity, 'the goods still moved');
        $this->assertSame(120.0, (float) $dest->avg_purchase_price, 'known rate survives an unknown one');
        $this->assertSame(120.0, (float) $dest->last_purchase_price);
    }

    public function test_transfer_writes_a_paired_out_and_in_ledger_row(): void
    {
        $this->seedStock($this->mainBranchId, 30);
        $this->actAs($this->makeUser(), $this->mainBranchId);

        $this->transfer($this->mainBranchId, $this->cityBranchId, 5);

        $rows = DB::table('inventory_movements')
            ->where('reference_type', 'branch_transfer')->orderBy('id')->get();
        $this->assertCount(2, $rows, 'both shops get their side of the story');

        $out = $rows->firstWhere('type', InventoryMovement::TYPE_TRANSFER_OUT);
        $in = $rows->firstWhere('type', InventoryMovement::TYPE_TRANSFER_IN);
        $this->assertSame($this->mainBranchId, (int) $out->branch_id);
        $this->assertSame($this->cityBranchId, (int) $out->reference_id, 'out row points at the receiver');
        $this->assertSame($this->cityBranchId, (int) $in->branch_id);
        $this->assertSame($this->mainBranchId, (int) $in->reference_id, 'in row points at the sender');
        $this->assertSame($out->reference_number, $in->reference_number, 'one transfer, one reference');
    }

    public function test_transfer_carries_the_cost_to_the_receiving_branch(): void
    {
        $this->seedStock($this->mainBranchId, 30);
        $this->actAs($this->makeUser(), $this->mainBranchId);

        $this->transfer($this->mainBranchId, $this->cityBranchId, 5);

        $dest = DB::table('inventory_stocks')->where('branch_id', $this->cityBranchId)->first();
        $this->assertSame(200.0, (float) $dest->avg_purchase_price, 'receiver values its stock, not at zero');
    }

    public function test_transfer_refuses_to_send_more_than_the_source_has(): void
    {
        $this->seedStock($this->mainBranchId, 3);
        $this->actAs($this->makeUser(), $this->mainBranchId);

        $this->transfer($this->mainBranchId, $this->cityBranchId, 9);

        $this->assertNotNull(session('error'), 'over-send is refused');
        $this->assertSame(3.0, $this->qty($this->mainBranchId), 'source untouched');
        $this->assertNull($this->qty($this->cityBranchId), 'nothing invented at the destination');
    }

    public function test_manager_cannot_push_stock_into_a_branch_that_is_not_theirs(): void
    {
        $this->seedStock($this->cityBranchId, 10);
        $this->actAs($this->makeManagerFor([$this->cityBranchId]), $this->cityBranchId);

        $this->transfer($this->cityBranchId, $this->mainBranchId, 4);

        $this->assertSame(__('pos.transfer_branch_invalid'), session('error'));
        $this->assertSame(10.0, $this->qty($this->cityBranchId));
        $this->assertNull($this->qty($this->mainBranchId));
    }

    public function test_manager_with_a_single_branch_cannot_open_the_transfer_page(): void
    {
        $this->actAs($this->makeManagerFor([$this->cityBranchId]), $this->cityBranchId);

        $this->expectException(\Symfony\Component\HttpKernel\Exception\HttpException::class);
        $this->stockController()->transfers();
    }

    public function test_cashier_cannot_transfer_stock(): void
    {
        $this->seedStock($this->mainBranchId, 10);
        $this->actAs($this->makeUser('pos_cashier'), $this->mainBranchId);

        try {
            $this->transfer($this->mainBranchId, $this->cityBranchId, 4);
            $this->fail('cashier should be blocked');
        } catch (\Symfony\Component\HttpKernel\Exception\HttpException $e) {
            $this->assertSame(403, $e->getStatusCode());
        }
        $this->assertSame(10.0, $this->qty($this->mainBranchId));
    }

    public function test_transfer_page_hides_the_holdings_of_branches_the_manager_cannot_access(): void
    {
        $third = $this->makeBranch('Johar Town');
        $this->seedStock($this->mainBranchId, 40);
        $this->seedStock($this->cityBranchId, 7);
        $this->seedStock($third, 9);
        $this->actAs($this->makeManagerFor([$this->cityBranchId, $third]), $this->cityBranchId);

        $data = $this->stockController()->transfers()->getData();

        $this->assertSame([$this->cityBranchId, $third], collect($data['branches'])->pluck('id')->map(fn ($i) => (int) $i)->sort()->values()->all());
        $this->assertArrayNotHasKey($this->mainBranchId, $data['stockMap'], 'Main Shop holdings stay hidden');
    }

    // ── 7. single-shop companies are untouched ───────────────────────────────

    public function test_company_without_branches_still_writes_branchless_rows(): void
    {
        DB::table('branch_user')->delete();
        DB::table('branches')->where('company_id', $this->companyId)->delete();
        BranchStockService::flushMemo();

        $owner = $this->makeUser();
        $this->actAs($owner);
        $this->receiveStock(15, null);

        $this->assertSame(15.0, $this->qty(null), 'stock stays branch-less');
        $this->assertSame(0, DB::table('inventory_stocks')->whereNotNull('branch_id')->count());

        $this->sell(5, null, $owner);
        $this->assertSame(10.0, $this->qty(null), 'the sale finds it exactly as before');
    }

    public function test_first_branch_created_adopts_the_shops_existing_stock(): void
    {
        DB::table('branches')->where('company_id', $this->companyId)->delete();
        BranchStockService::flushMemo();
        $this->seedStock(null, 18);

        $this->actAs($this->makeUser());
        (new \App\Http\Controllers\FbrPosBranchController())->store(
            Request::create('/fbr-pos/branches', 'POST', ['name' => 'Main Shop'])
        );

        $newBranchId = (int) DB::table('branches')->where('company_id', $this->companyId)->value('id');
        $this->assertNull($this->qty(null), 'nothing left stranded');
        $this->assertSame(18.0, $this->qty($newBranchId), 'the shop keeps its maal');
    }

    // ── 8. a purchase belongs to ONE shop — see it, void it, only there ──────

    /** GET /fbr-pos/stock/purchases — the searchable history, as the user sees it. */
    private function purchaseList(): array
    {
        $json = $this->stockController()
            ->purchases(Request::create('/fbr-pos/stock/purchases', 'GET'))
            ->getData(true);

        return array_column($json['purchases'], 'id');
    }

    /** Two received purchases, one per shop. Returns [mainPoId, cityPoId]. */
    private function seedPurchasePerBranch(User $owner): array
    {
        $this->actAs($owner, $this->mainBranchId);
        $this->receiveStock(10, $this->mainBranchId);
        $mainPo = (int) DB::table('purchase_orders')->orderByDesc('id')->value('id');

        $this->actAs($owner, $this->cityBranchId);
        $this->receiveStock(4, $this->cityBranchId);
        $cityPo = (int) DB::table('purchase_orders')->orderByDesc('id')->value('id');

        return [$mainPo, $cityPo];
    }

    public function test_purchase_history_hides_other_branches_from_a_confined_manager(): void
    {
        [$mainPo, $cityPo] = $this->seedPurchasePerBranch($this->makeUser());

        $this->actAs($this->makeManagerFor([$this->cityBranchId]), $this->cityBranchId);

        $ids = $this->purchaseList();
        $this->assertContains($cityPo, $ids, 'his own shop\'s purchase is there');
        $this->assertNotContains($mainPo, $ids, 'Main Shop\'s purchase is not his to see');
    }

    public function test_owner_all_branches_view_still_sees_every_purchase(): void
    {
        [$mainPo, $cityPo] = $this->seedPurchasePerBranch($this->makeUser());

        $this->actAs($this->makeUser(), BranchContextService::ALL);

        $ids = $this->purchaseList();
        $this->assertContains($mainPo, $ids);
        $this->assertContains($cityPo, $ids);
    }

    public function test_confined_manager_cannot_void_another_branchs_purchase(): void
    {
        [$mainPo, ] = $this->seedPurchasePerBranch($this->makeUser());

        $this->actAs($this->makeManagerFor([$this->cityBranchId]), $this->cityBranchId);
        $this->stockController()->voidPurchase($mainPo);

        $this->assertSame(__('pos.branch_switch_denied'), session('error'), 'refused, with a reason');
        $this->assertSame(10.0, $this->qty($this->mainBranchId), 'Main Shop keeps its maal');
        $this->assertSame(4.0, $this->qty($this->cityBranchId), 'and nothing came out of Gulberg either');
        $this->assertSame(
            \App\Models\PurchaseOrder::STATUS_RECEIVED,
            DB::table('purchase_orders')->where('id', $mainPo)->value('status'),
            'the purchase stays received'
        );
    }

    public function test_confined_manager_can_still_void_his_own_branchs_purchase(): void
    {
        [, $cityPo] = $this->seedPurchasePerBranch($this->makeUser());

        $this->actAs($this->makeManagerFor([$this->cityBranchId]), $this->cityBranchId);
        $this->stockController()->voidPurchase($cityPo);

        $this->assertSame(0.0, $this->qty($this->cityBranchId), 'his own purchase unwinds');
        $this->assertSame(
            \App\Models\PurchaseOrder::STATUS_CANCELLED,
            DB::table('purchase_orders')->where('id', $cityPo)->value('status')
        );
    }

    public function test_void_rate_fallback_never_borrows_another_branchs_kharid(): void
    {
        $owner = $this->makeUser();

        // Gulberg bought the same item at a very different rate. Voiding Main
        // Shop's only purchase must reset ITS rate to "no rate" — never inherit
        // Gulberg's 999.
        $this->actAs($owner, $this->cityBranchId);
        $this->receiveStock(5, $this->cityBranchId, 999);

        $this->actAs($owner, $this->mainBranchId);
        $this->receiveStock(10, $this->mainBranchId, 200);
        $mainPo = (int) DB::table('purchase_orders')->orderByDesc('id')->value('id');

        $this->stockController()->voidPurchase($mainPo);

        $row = DB::table('inventory_stocks')
            ->where('company_id', $this->companyId)
            ->where('product_id', $this->productId)
            ->where('branch_id', $this->mainBranchId)
            ->first();

        $this->assertSame(0.0, (float) $row->last_purchase_price, 'no rate, not Gulberg\'s rate');
        $this->assertSame(0.0, (float) $row->avg_purchase_price);
        $this->assertSame(
            999.0,
            (float) DB::table('inventory_stocks')
                ->where('branch_id', $this->cityBranchId)
                ->where('product_id', $this->productId)
                ->value('last_purchase_price'),
            'Gulberg keeps its own rate'
        );
    }

    // ── 9. the product form never guesses a shelf ────────────────────────────

    /** The private guard both product-form entry points call first. */
    private function productFormGuard(array $payload): void
    {
        $controller = new FbrPosController();
        $method = new \ReflectionMethod($controller, 'assertPanelStockBranch');
        $method->setAccessible(true);
        $method->invoke($controller, Request::create('/fbr-pos/products', 'POST', $payload), $this->companyId);
    }

    public function test_product_form_refuses_opening_stock_from_the_all_branches_view(): void
    {
        $this->actAs($this->makeUser(), BranchContextService::ALL);

        $this->expectException(\Illuminate\Validation\ValidationException::class);
        $this->productFormGuard(['name' => 'Chai Patti', 'opening_stock' => '12']);
    }

    public function test_product_form_refuses_a_min_level_from_the_all_branches_view(): void
    {
        $this->actAs($this->makeUser(), BranchContextService::ALL);

        $this->expectException(\Illuminate\Validation\ValidationException::class);
        $this->productFormGuard(['name' => 'Chai Patti', 'min_stock_level' => '5']);
    }

    public function test_product_form_refuses_a_stock_adjustment_from_the_all_branches_view(): void
    {
        $this->actAs($this->makeUser(), BranchContextService::ALL);

        $this->expectException(\Illuminate\Validation\ValidationException::class);
        $this->productFormGuard(['name' => 'Chai Patti', 'stock_action' => 'correct', 'new_qty' => '3']);
    }

    public function test_product_form_refuses_a_multi_row_opening_stock_from_the_all_branches_view(): void
    {
        $this->actAs($this->makeUser(), BranchContextService::ALL);

        $this->expectException(\Illuminate\Validation\ValidationException::class);
        $this->productFormGuard([
            'entry_mode' => 'multi',
            'rows' => [
                ['name' => 'A', 'default_price' => '10', 'opening_stock' => ''],
                ['name' => 'B', 'default_price' => '20', 'opening_stock' => '7'],
            ],
        ]);
    }

    public function test_company_wide_product_fields_still_save_from_the_all_branches_view(): void
    {
        $this->actAs($this->makeUser(), BranchContextService::ALL);

        // Renaming / repricing touches no shelf, so the guard must stay quiet.
        $this->productFormGuard(['name' => 'Chai Patti Gold', 'default_price' => '450']);

        $this->assertTrue(true, 'a company-wide edit is not refused');
    }

    public function test_product_form_allows_stock_writes_once_a_branch_is_picked(): void
    {
        $this->actAs($this->makeUser(), $this->cityBranchId);

        $this->productFormGuard(['name' => 'Chai Patti', 'opening_stock' => '12', 'min_stock_level' => '5']);

        $this->assertTrue(true, 'a named branch is a real shelf');
    }

    public function test_panel_stock_branch_backstop_refuses_the_all_branches_view(): void
    {
        $this->actAs($this->makeUser(), BranchContextService::ALL);

        $controller = new FbrPosController();
        $method = new \ReflectionMethod($controller, 'panelStockBranchId');
        $method->setAccessible(true);

        // Even if a future caller skips the entry guard, the resolver itself
        // must refuse rather than silently hand back head office.
        $this->expectException(\Illuminate\Validation\ValidationException::class);
        $method->invoke($controller, $this->companyId);
    }

    public function test_panel_stock_branch_returns_the_shop_on_screen(): void
    {
        $this->actAs($this->makeUser(), $this->cityBranchId);

        $controller = new FbrPosController();
        $method = new \ReflectionMethod($controller, 'panelStockBranchId');
        $method->setAccessible(true);

        $this->assertSame($this->cityBranchId, $method->invoke($controller, $this->companyId));
    }

    public function test_untracked_products_are_not_invented_by_the_branch_filter(): void
    {
        // No inventory_stocks row at all — the page must show it as untracked
        // rather than a phantom zero-stock branch row.
        $this->actAs($this->makeUser(), $this->mainBranchId);

        $row = $this->pageRow($this->stockPage());

        $this->assertFalse((bool) $row->tracked);
        $this->assertSame(0, DB::table('inventory_stocks')->count());
    }
}
