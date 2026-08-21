<?php

namespace Tests\Feature;

use App\Http\Controllers\PosInventoryController;
use App\Models\User;
use App\Services\BranchContextService;
use App\Services\BranchStockService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * PER-BRANCH STOCK — one shop's sale must never eat another shop's maal (Task 1354).
 *
 * Before this, `inventory_stocks.branch_id` was always NULL: a single company-wide
 * pile of goods. Selling 10 packets at Gulberg lowered Main Shop's stock too, and
 * "kis branch mein kitna maal para hai" had no answer. What is locked here:
 *
 *   1. A sale only deducts the stock of the branch stamped on the BILL — even when
 *      the owner is browsing another branch (or the all-branches view).
 *   2. The company mirror (`pos_products.stock_quantity`) stays the SUM of every
 *      branch, so the products page/sale screen totals never drift.
 *   3. A void restores stock to the same branch that sold it.
 *   4. Stock views are branch-scoped; the owner's all-branches view sees the lot.
 *   5. Old branch-less stock is adopted onto head office — nothing disappears —
 *      and merges (never collides) when head office already holds that product.
 *   6. Branch transfer moves goods between shops, refuses to over-send, and leaves
 *      the company total unchanged.
 *   7. A company with NO branches keeps writing branch_id = NULL, exactly as before.
 *
 * Pattern mirrors PosMultiBranchScopeTest: sqlite :memory: + minimal Schema::create,
 * controllers invoked directly.
 */
class PosBranchStockTest extends TestCase
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

        Schema::create('companies', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('product_type')->nullable();
            $table->string('status')->nullable();
            $table->boolean('inventory_enabled')->default(true);
            $table->boolean('pos_setup_completed')->default(true);
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

        Schema::create('pos_products', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('company_id');
            $table->string('name');
            $table->string('sku')->nullable();
            $table->string('uom')->nullable();
            $table->decimal('price', 12, 2)->default(0);
            $table->integer('stock_quantity')->nullable();
            $table->integer('low_stock_threshold')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('inventory_stocks', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('company_id');
            $table->unsignedBigInteger('product_id');
            $table->unsignedBigInteger('branch_id')->nullable();
            $table->decimal('quantity', 15, 2)->default(0);
            $table->decimal('min_stock_level', 15, 2)->default(0);
            $table->decimal('max_stock_level', 15, 2)->nullable();
            $table->decimal('avg_purchase_price', 15, 2)->default(0);
            $table->decimal('last_purchase_price', 15, 2)->default(0);
            $table->timestamps();
            $table->unique(['company_id', 'product_id', 'branch_id'], 'inv_stock_unique');
        });

        Schema::create('inventory_movements', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('company_id');
            $table->unsignedBigInteger('product_id');
            $table->unsignedBigInteger('branch_id')->nullable();
            $table->string('type', 30);
            $table->decimal('quantity', 15, 2);
            $table->decimal('unit_price', 15, 2)->default(0);
            $table->decimal('total_price', 15, 2)->default(0);
            $table->decimal('balance_after', 15, 2)->default(0);
            $table->string('reference_type', 50)->nullable();
            $table->unsignedBigInteger('reference_id')->nullable();
            $table->string('reference_number')->nullable();
            $table->text('notes')->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamps();
        });

        $this->companyId = (int) DB::table('companies')->insertGetId([
            'name' => 'Do Dukan Karyana',
            'product_type' => 'pos',
            'status' => 'active',
            'inventory_enabled' => true,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        app()->bind('currentCompanyId', fn () => $this->companyId);

        $this->mainBranchId = $this->makeBranch('Main Shop', true);
        $this->cityBranchId = $this->makeBranch('Gulberg');
        $this->productId = $this->makeProduct('Chai Patti', 100);
    }

    protected function tearDown(): void
    {
        session()->forget(BranchContextService::SESSION_KEY);
        Auth::guard('pos')->logout();
        BranchStockService::flushMemo();
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

    private function makeProduct(string $name, ?int $mirror = null): int
    {
        return (int) DB::table('pos_products')->insertGetId([
            'company_id' => $this->companyId,
            'name' => $name,
            'price' => 250,
            'stock_quantity' => $mirror,
            'is_active' => true,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    /** Opening stock straight into the table, bypassing the code under test. */
    private function seedStock(?int $branchId, float $qty, ?int $productId = null, float $cost = 200): int
    {
        return (int) DB::table('inventory_stocks')->insertGetId([
            'company_id' => $this->companyId,
            'product_id' => $productId ?? $this->productId,
            'branch_id' => $branchId,
            'quantity' => $qty,
            'min_stock_level' => 5,
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

    private function mirror(?int $productId = null): ?int
    {
        $v = DB::table('pos_products')->where('id', $productId ?? $this->productId)->value('stock_quantity');

        return $v === null ? null : (int) $v;
    }

    private function makeUser(string $posRole = 'pos_admin', ?int $branchId = null): User
    {
        $id = DB::table('users')->insertGetId([
            'name' => 'U' . uniqid(),
            'email' => uniqid('u') . '@taxnest.test',
            'company_id' => $this->companyId,
            'role' => 'user',
            'pos_role' => $posRole,
            'default_branch_id' => $branchId,
            'is_active' => true,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        return User::find($id);
    }

    private function actAs(User $user, $branch = null): void
    {
        Auth::guard('pos')->setUser($user);
        // Accessible branches are memoized per actor — swapping user mid-test
        // must not inherit the previous one's reach.
        BranchStockService::flushMemo();
        if ($branch !== null) {
            session([BranchContextService::SESSION_KEY => $branch]);
        }
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

    private function saleItems(float $qty): array
    {
        return [[
            'type' => 'product',
            'item_id' => $this->productId,
            'quantity' => $qty,
            'unit_price' => 250,
        ]];
    }

    // ── 1. a sale only touches the billing branch ────────────────────────────

    public function test_sale_deducts_only_the_branch_the_bill_was_made_on(): void
    {
        $this->seedStock($this->mainBranchId, 60);
        $this->seedStock($this->cityBranchId, 40);

        PosInventoryController::deductStockForInvoice(
            $this->companyId, $this->saleItems(10), 1, 'INV-1', null, $this->cityBranchId
        );

        $this->assertSame(60.0, $this->qty($this->mainBranchId), 'Main Shop stock must not move for a Gulberg bill');
        $this->assertSame(30.0, $this->qty($this->cityBranchId), 'Gulberg sold 10 of its 40');
        $this->assertSame(90, $this->mirror(), 'company mirror = sum of both branches');
    }

    public function test_owner_browsing_another_branch_cannot_move_the_wrong_shops_stock(): void
    {
        $this->seedStock($this->mainBranchId, 60);
        $this->seedStock($this->cityBranchId, 40);

        // Owner is standing on Main Shop but the BILL belongs to Gulberg.
        $this->actAs($this->makeUser('pos_admin'), $this->mainBranchId);

        PosInventoryController::deductStockForInvoice(
            $this->companyId, $this->saleItems(5), 2, 'INV-2', null, $this->cityBranchId
        );

        $this->assertSame(60.0, $this->qty($this->mainBranchId), 'the session branch must never win over the bill');
        $this->assertSame(35.0, $this->qty($this->cityBranchId));
    }

    public function test_void_restores_stock_to_the_same_branch_that_sold_it(): void
    {
        $this->seedStock($this->mainBranchId, 60);
        $this->seedStock($this->cityBranchId, 40);

        PosInventoryController::deductStockForInvoice(
            $this->companyId, $this->saleItems(10), 3, 'INV-3', null, $this->cityBranchId
        );
        PosInventoryController::restoreStockForInvoice(
            $this->companyId, $this->saleItems(10), 3, 'INV-3', null, 'pos_void', $this->cityBranchId
        );

        $this->assertSame(60.0, $this->qty($this->mainBranchId));
        $this->assertSame(40.0, $this->qty($this->cityBranchId), 'void puts the maal back where it came from');
        $this->assertSame(100, $this->mirror(), 'mirror is whole again');
    }

    public function test_sale_movement_row_is_stamped_with_the_bill_branch(): void
    {
        $this->seedStock($this->cityBranchId, 40);

        PosInventoryController::deductStockForInvoice(
            $this->companyId, $this->saleItems(4), 4, 'INV-4', null, $this->cityBranchId
        );

        $movement = DB::table('inventory_movements')->where('reference_number', 'INV-4')->first();
        $this->assertNotNull($movement, 'a sale must leave a ledger row');
        $this->assertSame($this->cityBranchId, (int) $movement->branch_id, 'the ledger must say WHICH shop it left');
    }

    // ── 2. untracked products stay untracked ─────────────────────────────────

    public function test_untracked_product_mirror_is_never_forced_to_zero(): void
    {
        $untracked = $this->makeProduct('Loose Namak', null);
        $this->seedStock($this->mainBranchId, 25, $untracked);

        BranchStockService::syncProductMirror($this->companyId, $untracked);

        $this->assertNull($this->mirror($untracked), 'NULL means untracked — never 0');
    }

    // ── 3. branch-scoped reads ───────────────────────────────────────────────

    public function test_stock_page_shows_only_the_active_branch_and_all_for_the_owner(): void
    {
        $this->seedStock($this->mainBranchId, 60);
        $this->seedStock($this->cityBranchId, 40);
        $owner = $this->makeUser('pos_admin');

        $this->actAs($owner, $this->cityBranchId);
        $data = (new PosInventoryController())->stock(Request::create('/pos/inventory/stock', 'GET'))->getData();
        $this->assertSame([40.0], $data['stocks']->pluck('quantity')->map(fn ($q) => (float) $q)->all(), 'Gulberg sees Gulberg only');
        $this->assertFalse($data['allBranches']);
        $this->assertSame('Gulberg', $data['activeBranchName']);

        $this->actAs($owner, BranchContextService::ALL);
        $data = (new PosInventoryController())->stock(Request::create('/pos/inventory/stock', 'GET'))->getData();
        $this->assertSame(2, $data['stocks']->count(), 'the owner sees both shops');
        $this->assertTrue($data['allBranches']);
    }

    public function test_dashboard_breaks_the_total_down_per_branch_for_the_owner(): void
    {
        $this->seedStock($this->mainBranchId, 60);
        $this->seedStock($this->cityBranchId, 40);
        $this->actAs($this->makeUser('pos_admin'), BranchContextService::ALL);

        $data = (new PosInventoryController())->dashboard()->getData();

        $totals = collect($data['branchTotals'])->keyBy('name');
        $this->assertSame(60.0, (float) $totals['Main Shop']->qty);
        $this->assertSame(40.0, (float) $totals['Gulberg']->qty);
        $this->assertSame(20000.0, (float) $data['totalStockValue'], '(60 + 40) x 200 avg cost');
    }

    // ── 4. legacy pre-branch stock is adopted, not lost ──────────────────────

    public function test_legacy_branchless_stock_is_adopted_by_head_office(): void
    {
        $this->seedStock(null, 75);

        BranchStockService::healLegacyRows($this->companyId);

        $this->assertNull($this->qty(null), 'no branch-less rows may survive');
        $this->assertSame(75.0, $this->qty($this->mainBranchId), 'purana maal head office ke khaate mein');
    }

    public function test_adopting_legacy_stock_merges_instead_of_colliding(): void
    {
        // Head office already holds this product — a blind UPDATE would violate
        // the (company, product, branch) unique index and lose the legacy row.
        $this->seedStock($this->mainBranchId, 30);
        $this->seedStock(null, 45);

        $moved = BranchStockService::adoptLegacyRows($this->companyId);

        $this->assertSame(1, $moved);
        $this->assertSame(75.0, $this->qty($this->mainBranchId), '30 + 45, nothing dropped');
        $this->assertSame(1, DB::table('inventory_stocks')->where('product_id', $this->productId)->count());
    }

    public function test_a_sale_on_a_company_with_legacy_stock_still_finds_the_goods(): void
    {
        // No branch rows at all in inventory_stocks yet — everything is legacy.
        $this->seedStock(null, 50);

        PosInventoryController::deductStockForInvoice(
            $this->companyId, $this->saleItems(20), 5, 'INV-5', null, $this->mainBranchId
        );

        $this->assertSame(30.0, $this->qty($this->mainBranchId), 'legacy stock healed first, then sold from');
        $this->assertNull($this->qty(null));
    }

    public function test_legacy_movement_history_follows_the_goods(): void
    {
        $this->seedStock(null, 10);
        DB::table('inventory_movements')->insert([
            'company_id' => $this->companyId,
            'product_id' => $this->productId,
            'branch_id' => null,
            'type' => 'opening',
            'quantity' => 10,
            'balance_after' => 10,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        BranchStockService::healLegacyRows($this->companyId);

        $this->assertSame(
            $this->mainBranchId,
            (int) DB::table('inventory_movements')->where('type', 'opening')->value('branch_id'),
            'a branch ledger must not open empty'
        );
    }

    // ── 5. branch → branch transfer ──────────────────────────────────────────

    private function transfer(int $from, int $to, float $qty)
    {
        return (new PosInventoryController())->storeTransfer(Request::create('/pos/inventory/transfer', 'POST', [
            'product_id' => $this->productId,
            'from_branch_id' => $from,
            'to_branch_id' => $to,
            'quantity' => $qty,
        ]));
    }

    public function test_transfer_moves_stock_between_branches_without_changing_the_total(): void
    {
        $this->seedStock($this->mainBranchId, 60);
        $this->seedStock($this->cityBranchId, 40);
        $this->actAs($this->makeUser('pos_admin'), $this->mainBranchId);

        $this->transfer($this->mainBranchId, $this->cityBranchId, 15);

        $this->assertSame(45.0, $this->qty($this->mainBranchId));
        $this->assertSame(55.0, $this->qty($this->cityBranchId));
        $this->assertSame(100, $this->mirror(), 'a transfer never creates or destroys maal');
    }

    public function test_transfer_reweights_the_cost_of_a_destination_that_already_has_stock(): void
    {
        // Same rule as the FBR panel (Task 1365): 10 units at Rs.100 plus 10
        // arriving at Rs.200 leaves the shelf at Rs.150. Keeping the old rate
        // would undervalue the maal and inflate that branch's munafa.
        $this->seedStock($this->mainBranchId, 10, null, 200);
        $this->seedStock($this->cityBranchId, 10, null, 100);
        $this->actAs($this->makeUser('pos_admin'), $this->mainBranchId);

        $this->transfer($this->mainBranchId, $this->cityBranchId, 10);

        $dest = $this->costRow($this->cityBranchId);
        $this->assertSame(20.0, (float) $dest->quantity);
        $this->assertSame(150.0, (float) $dest->avg_purchase_price, 'weighted across old and arriving maal');
        $this->assertSame(200.0, (float) $dest->last_purchase_price, 'the arriving units are the latest rate');
    }

    public function test_transfer_of_rateless_goods_does_not_wipe_the_destination_rate(): void
    {
        $this->seedStock($this->mainBranchId, 10, null, 0);
        $this->seedStock($this->cityBranchId, 10, null, 120);
        $this->actAs($this->makeUser('pos_admin'), $this->mainBranchId);

        $this->transfer($this->mainBranchId, $this->cityBranchId, 5);

        $dest = $this->costRow($this->cityBranchId);
        $this->assertSame(15.0, (float) $dest->quantity, 'the goods still moved');
        $this->assertSame(120.0, (float) $dest->avg_purchase_price, 'known rate survives an unknown one');
    }

    public function test_transfer_writes_a_paired_out_and_in_ledger_row(): void
    {
        $this->seedStock($this->mainBranchId, 60);
        $this->seedStock($this->cityBranchId, 40);
        $this->actAs($this->makeUser('pos_admin'), $this->mainBranchId);

        $this->transfer($this->mainBranchId, $this->cityBranchId, 15);

        $rows = DB::table('inventory_movements')->where('reference_type', 'branch_transfer')->get();
        $this->assertCount(2, $rows, 'both shops must be able to explain the change');
        $this->assertSame(1, $rows->where('type', 'transfer_out')->count());
        $this->assertSame(1, $rows->where('type', 'transfer_in')->count());
        $this->assertSame(1, $rows->pluck('reference_number')->unique()->count(), 'the pair shares one reference');
        $this->assertSame($this->mainBranchId, (int) $rows->firstWhere('type', 'transfer_out')->branch_id);
        $this->assertSame($this->cityBranchId, (int) $rows->firstWhere('type', 'transfer_in')->branch_id);
    }

    public function test_transfer_refuses_to_send_more_than_the_source_branch_has(): void
    {
        $this->seedStock($this->mainBranchId, 5);
        $this->seedStock($this->cityBranchId, 40);
        $this->actAs($this->makeUser('pos_admin'), $this->mainBranchId);

        $this->transfer($this->mainBranchId, $this->cityBranchId, 20);

        $this->assertSame(5.0, $this->qty($this->mainBranchId), 'nothing left the source');
        $this->assertSame(40.0, $this->qty($this->cityBranchId), 'and nothing arrived');
        $this->assertSame(0, DB::table('inventory_movements')->where('reference_type', 'branch_transfer')->count());
    }

    public function test_transfer_rejects_a_branch_from_another_company(): void
    {
        $otherCompany = (int) DB::table('companies')->insertGetId([
            'name' => 'Dusri Company', 'product_type' => 'pos', 'status' => 'active',
            'inventory_enabled' => true, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $foreignBranch = (int) DB::table('branches')->insertGetId([
            'company_id' => $otherCompany, 'name' => 'Unki Shop',
            'is_head_office' => true, 'is_active' => true,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->seedStock($this->mainBranchId, 60);
        $this->actAs($this->makeUser('pos_admin'), $this->mainBranchId);

        $this->transfer($this->mainBranchId, $foreignBranch, 10);

        $this->assertSame(60.0, $this->qty($this->mainBranchId), 'maal must not leave the company');
        $this->assertSame(0, DB::table('inventory_stocks')->where('branch_id', $foreignBranch)->count());
    }

    public function test_cashier_cannot_transfer_stock(): void
    {
        $this->seedStock($this->mainBranchId, 60);
        $this->actAs($this->makeUser('pos_cashier', $this->mainBranchId), $this->mainBranchId);

        try {
            (new PosInventoryController())->transfers(Request::create('/pos/inventory/transfer', 'GET'));
            $this->fail('a cashier must not reach the transfer page');
        } catch (\Symfony\Component\HttpKernel\Exception\HttpException $e) {
            $this->assertSame(403, $e->getStatusCode());
        }
    }

    // ── 5b. a manager may only touch the branches that are theirs ────────────

    public function test_manager_cannot_transfer_out_of_a_branch_that_is_not_theirs(): void
    {
        $this->seedStock($this->mainBranchId, 60);
        $this->seedStock($this->cityBranchId, 40);
        // Assigned to Gulberg only — Main Shop's maal is none of their business.
        $this->actAs($this->makeManagerFor([$this->cityBranchId]), $this->cityBranchId);

        $this->transfer($this->mainBranchId, $this->cityBranchId, 25);

        $this->assertSame(60.0, $this->qty($this->mainBranchId), 'nothing may leave a branch they cannot access');
        $this->assertSame(40.0, $this->qty($this->cityBranchId), 'and nothing may arrive from one');
        $this->assertSame(0, DB::table('inventory_movements')->where('reference_type', 'branch_transfer')->count());
    }

    public function test_manager_cannot_push_stock_into_a_branch_that_is_not_theirs(): void
    {
        $this->seedStock($this->mainBranchId, 60);
        $this->seedStock($this->cityBranchId, 40);
        $this->actAs($this->makeManagerFor([$this->mainBranchId]), $this->mainBranchId);

        $this->transfer($this->mainBranchId, $this->cityBranchId, 25);

        $this->assertSame(60.0, $this->qty($this->mainBranchId), 'the send must be refused, not half-applied');
        $this->assertSame(40.0, $this->qty($this->cityBranchId));
    }

    public function test_manager_with_a_single_branch_cannot_open_the_transfer_page(): void
    {
        $this->actAs($this->makeManagerFor([$this->cityBranchId]), $this->cityBranchId);

        try {
            (new PosInventoryController())->transfers(Request::create('/pos/inventory/transfer', 'GET'));
            $this->fail('one branch = nowhere to send maal');
        } catch (\Symfony\Component\HttpKernel\Exception\HttpException $e) {
            $this->assertSame(403, $e->getStatusCode());
        }
    }

    public function test_transfer_page_hides_the_holdings_of_branches_the_manager_cannot_access(): void
    {
        $thirdBranch = (int) DB::table('branches')->insertGetId([
            'company_id' => $this->companyId, 'name' => 'Johar Town',
            'is_head_office' => false, 'is_active' => true,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->seedStock($this->mainBranchId, 60);
        $this->seedStock($this->cityBranchId, 40);
        $this->seedStock($thirdBranch, 25);
        $this->actAs($this->makeManagerFor([$this->mainBranchId, $thirdBranch]), $this->mainBranchId);

        $view = (new PosInventoryController())->transfers(Request::create('/pos/inventory/transfer', 'GET'));
        $data = $view->getData();

        $offered = collect($data['branches'])->pluck('id')->map(fn ($id) => (int) $id)->all();
        sort($offered);
        $this->assertSame([$this->mainBranchId, $thirdBranch], $offered, 'only their own shops are selectable');
        $this->assertArrayNotHasKey($this->cityBranchId, $data['stockMap'], 'no peeking at another branch stock');
        $this->assertArrayHasKey($thirdBranch, $data['stockMap']);
    }

    public function test_manager_cannot_adjust_stock_of_another_branch(): void
    {
        $this->seedStock($this->mainBranchId, 60);
        $this->actAs($this->makeManagerFor([$this->cityBranchId]), $this->cityBranchId);

        try {
            (new PosInventoryController())->adjustStock(Request::create('/pos/inventory/adjust', 'POST', [
                'product_id' => $this->productId,
                'type' => 'remove',
                'quantity' => 50,
                'reason' => 'shrinkage',
                'branch_id' => $this->mainBranchId,
            ]));
            $this->fail('a confined manager must not adjust another branch');
        } catch (\Symfony\Component\HttpKernel\Exception\HttpException $e) {
            $this->assertSame(403, $e->getStatusCode());
        }

        $this->assertSame(60.0, $this->qty($this->mainBranchId), 'the other branch stock is untouched');
    }

    public function test_manager_cannot_set_min_stock_for_another_branch(): void
    {
        $this->seedStock($this->mainBranchId, 60);
        $this->actAs($this->makeManagerFor([$this->cityBranchId]), $this->cityBranchId);

        try {
            (new PosInventoryController())->updateMinStock(Request::create('/pos/inventory/min-stock', 'POST', [
                'product_id' => $this->productId,
                'min_stock_level' => 99,
                'branch_id' => $this->mainBranchId,
            ]));
            $this->fail('thresholds belong to the branch that owns them');
        } catch (\Symfony\Component\HttpKernel\Exception\HttpException $e) {
            $this->assertSame(403, $e->getStatusCode());
        }
    }

    public function test_manager_stock_view_stays_on_their_own_branch(): void
    {
        // Session points at Main Shop, but the pivot only grants Gulberg —
        // falling through to "no filter" would show the whole company.
        $this->actAs($this->makeManagerFor([$this->cityBranchId]), $this->mainBranchId);

        $this->assertSame($this->cityBranchId, BranchStockService::viewBranchId($this->companyId));
        $this->assertFalse(BranchStockService::viewingAllBranches($this->companyId));
    }

    public function test_manager_cannot_hold_the_all_branches_view(): void
    {
        $this->actAs($this->makeManagerFor([$this->cityBranchId]), BranchContextService::ALL);

        $this->assertFalse(BranchStockService::viewingAllBranches($this->companyId), 'all-branches is owner-only');
        $this->assertSame($this->cityBranchId, BranchStockService::viewBranchId($this->companyId));
    }

    // ── 6. single-shop companies keep the old behaviour ──────────────────────

    public function test_company_without_branches_still_writes_branchless_rows(): void
    {
        DB::table('branches')->where('company_id', $this->companyId)->delete();
        BranchStockService::flushMemo();
        $this->seedStock(null, 50);

        $this->assertNull(BranchStockService::writeBranchId($this->companyId), 'no branches = no branch key');

        PosInventoryController::deductStockForInvoice(
            $this->companyId, $this->saleItems(20), 6, 'INV-6', null, null
        );

        $this->assertSame(30.0, $this->qty(null), 'single-shop behaviour is byte-for-byte unchanged');
        $this->assertSame(1, DB::table('inventory_stocks')->count(), 'and no extra branch rows appear');
    }

    public function test_missing_branches_table_degrades_instead_of_throwing(): void
    {
        $this->seedStock(null, 50);
        Schema::drop('branches');
        BranchStockService::flushMemo();

        $this->assertFalse(BranchStockService::ready());
        $this->assertNull(BranchStockService::writeBranchId($this->companyId));

        PosInventoryController::deductStockForInvoice(
            $this->companyId, $this->saleItems(5), 7, 'INV-7', null, null
        );

        $this->assertSame(45.0, $this->qty(null));
    }
}
