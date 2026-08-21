<?php

namespace Tests\Feature;

use App\Http\Controllers\PosController;
use App\Http\Controllers\RestaurantPosController;
use App\Models\User;
use App\Services\BranchContextService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * POS MULTI-BRANCH v1 — money must never cross branches (Task 1347).
 *
 * The shop owner's whole reason for creating branches is that Gulberg's
 * takings and Main Shop's takings stay apart. This locks the parts that are
 * easy to break silently later:
 *
 *   1. RETAIL dashboard headline + "Aaj ka Khaata" figures follow the active
 *      branch (both go through PosTodayKhata — a company-wide call there is
 *      invisible on screen but wrong by exactly the other branch's sale).
 *   2. RESTAURANT dashboard — a completely separate page sharing the same
 *      builder — does the same.
 *   3. The owner's "all branches" view sums every branch, and still stamps a
 *      REAL branch on new bills (never branch-less).
 *   4. Legacy pre-branch bills (branch_id NULL) stay visible in every view, so
 *      turning multi-branch on never hides yesterday's money.
 *   5. A cashier whose branch is switched OFF moves to head office instead of
 *      keeping the closed branch (and cannot switch back to it).
 *   6. A schema without the `branches` table degrades to company-wide instead
 *      of throwing (lean test schemas + deployments missing the migration).
 *
 * Pattern mirrors PosDashboardTodayKhataTest / PosRestaurantDashboardCountsTest:
 * sqlite :memory: + minimal Schema::create, controllers invoked directly.
 */
class PosMultiBranchScopeTest extends TestCase
{
    protected int $companyId;
    protected int $mainBranchId;
    protected int $cityBranchId;

    protected function setUp(): void
    {
        parent::setUp();

        Schema::dropAllTables();
        User::flushScopeColumnCache();

        Schema::create('companies', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('product_type')->nullable();
            $table->string('status')->nullable();
            $table->string('pos_dayclose_provisional_action')->nullable();
            $table->string('pos_dayclose_final_local_action')->nullable();
            $table->boolean('pos_customer_spend_persist')->default(true);
            $table->string('pos_business_day_cutoff')->nullable();
            $table->boolean('is_internal_account')->default(false);
            $table->integer('invoice_limit_override')->nullable();
            $table->boolean('pra_reporting_enabled')->default(false);
            $table->boolean('inventory_enabled')->default(false);
            $table->boolean('pos_cashier_own_sales_only')->nullable();
            $table->string('pra_connection_mode')->nullable();
            $table->boolean('pos_setup_completed')->default(true);
            $table->string('pos_dashboard_style')->nullable();
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
            $table->string('pos_billing_scope')->nullable();
            $table->unsignedBigInteger('default_branch_id')->nullable();
            $table->boolean('is_active')->default(true);
            $table->boolean('pra_reporting_enabled')->default(false);
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

        Schema::create('pos_transactions', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('company_id');
            $table->unsignedBigInteger('branch_id')->nullable();
            $table->string('invoice_number');
            $table->string('transaction_type')->nullable()->default('sale');
            $table->unsignedBigInteger('parent_transaction_id')->nullable();
            $table->string('business_date')->nullable();
            $table->string('status');
            $table->string('invoice_mode')->nullable();
            $table->string('pra_status')->nullable();
            $table->string('pra_invoice_number')->nullable();
            $table->boolean('is_archived')->default(false);
            $table->timestamp('archived_at')->nullable();
            $table->unsignedBigInteger('archived_by_report_id')->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->unsignedBigInteger('customer_id')->nullable();
            $table->string('customer_name')->nullable();
            $table->decimal('subtotal', 12, 2)->default(0);
            $table->decimal('discount_amount', 12, 2)->default(0);
            $table->decimal('tax_rate', 8, 2)->nullable();
            $table->decimal('tax_amount', 12, 2)->default(0);
            $table->decimal('exempt_amount', 12, 2)->nullable();
            $table->decimal('total_amount', 12, 2)->default(0);
            $table->string('payment_method')->nullable();
            $table->boolean('tax_inclusive')->default(false);
            $table->unsignedBigInteger('rider_id')->nullable();
            $table->string('delivery_status')->nullable();
            $table->unsignedBigInteger('rider_settlement_id')->nullable();
            $table->timestamps();
        });

        Schema::create('pos_transaction_items', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('transaction_id');
            $table->string('item_type')->nullable();
            $table->unsignedBigInteger('item_id')->nullable();
            $table->string('item_name');
            $table->decimal('quantity', 12, 3)->default(1);
            $table->decimal('unit_price', 12, 2)->default(0);
            $table->decimal('cost_price', 12, 2)->nullable();
            $table->decimal('subtotal', 12, 2)->default(0);
            $table->boolean('is_tax_exempt')->default(false);
            $table->decimal('tax_rate', 8, 2)->nullable();
            $table->decimal('tax_amount', 12, 2)->nullable();
            $table->decimal('item_discount_amount', 12, 2)->nullable();
            $table->timestamps();
        });

        Schema::create('pos_products', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('company_id');
            $table->string('name');
            $table->string('category')->nullable();
            $table->decimal('price', 12, 2)->default(0);
            $table->decimal('cost_price', 12, 2)->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('pos_day_close_reports', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('company_id');
            $table->date('report_date');
            $table->timestamps();
        });

        Schema::create('pos_day_openings', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('company_id');
            $table->date('business_date');
            $table->decimal('opening_cash', 14, 2)->default(0);
            $table->unsignedBigInteger('entered_by')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();
        });

        Schema::create('notifications', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('company_id')->nullable();
            $table->unsignedBigInteger('user_id')->nullable();
            $table->string('type')->nullable();
            $table->string('title')->nullable();
            $table->text('message')->nullable();
            $table->boolean('read')->default(false);
            $table->text('metadata')->nullable();
            $table->timestamps();
        });

        // ── restaurant dashboard tables (separate page, same khata builder) ──
        Schema::create('restaurant_orders', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('company_id');
            $table->string('order_number')->nullable();
            $table->unsignedBigInteger('table_id')->nullable();
            $table->string('order_type')->nullable();
            $table->string('status');
            $table->decimal('subtotal', 12, 2)->default(0);
            $table->decimal('discount_amount', 12, 2)->default(0);
            $table->decimal('tax_amount', 12, 2)->default(0);
            $table->decimal('total_amount', 12, 2)->default(0);
            $table->decimal('estimated_cost', 12, 2)->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->timestamp('kot_sent_at')->nullable();
            $table->timestamp('kitchen_started_at')->nullable();
            $table->timestamp('kitchen_ready_at')->nullable();
            $table->timestamp('kitchen_cleared_at')->nullable();
            $table->timestamps();
        });

        Schema::create('restaurant_order_items', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('restaurant_order_id')->nullable();
            $table->unsignedBigInteger('order_id')->nullable();
            $table->string('item_name')->nullable();
            $table->decimal('quantity', 12, 3)->default(1);
            $table->decimal('subtotal', 12, 2)->default(0);
            $table->timestamps();
        });

        Schema::create('restaurant_tables', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('company_id');
            $table->string('table_number')->nullable();
            $table->string('status')->nullable();
            $table->timestamps();
        });

        Schema::create('ingredients', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('company_id');
            $table->string('name')->nullable();
            $table->boolean('is_active')->default(true);
            $table->decimal('current_stock', 12, 3)->default(0);
            $table->decimal('min_stock_level', 12, 3)->default(0);
            $table->timestamps();
        });

        $this->companyId = (int) DB::table('companies')->insertGetId([
            'name' => 'Do Dukan Karyana',
            'product_type' => 'pos',
            'status' => 'active',
            'pos_setup_completed' => true,
            // Shared shop: branch scoping under test, not cashier isolation.
            'pos_cashier_own_sales_only' => false,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        app()->bind('currentCompanyId', fn () => $this->companyId);

        $this->mainBranchId = $this->makeBranch('Main Shop', true);
        $this->cityBranchId = $this->makeBranch('Gulberg');
    }

    protected function tearDown(): void
    {
        session()->forget(BranchContextService::SESSION_KEY);
        Auth::guard('pos')->logout();
        parent::tearDown();
    }

    // ── fixtures ─────────────────────────────────────────────────────────────

    private function makeBranch(string $name, bool $head = false, bool $active = true): int
    {
        return (int) DB::table('branches')->insertGetId([
            'company_id' => $this->companyId,
            'name' => $name,
            'is_head_office' => $head,
            'is_active' => $active,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function makeUser(string $posRole = 'pos_admin', ?int $branchId = null): User
    {
        $id = DB::table('users')->insertGetId([
            'name' => 'U' . uniqid(),
            'email' => uniqid('u') . '@taxnest.test',
            'company_id' => $this->companyId,
            'role' => 'user',
            'pos_role' => $posRole,
            'pos_billing_scope' => 'both',
            'default_branch_id' => $branchId,
            'is_active' => true,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        return User::find($id);
    }

    /** A completed PRA bill of $amount on $branchId (null = legacy pre-branch row). */
    private function makeBill(float $amount, ?int $branchId): void
    {
        DB::table('pos_transactions')->insert([
            'company_id' => $this->companyId,
            'branch_id' => $branchId,
            'invoice_number' => 'B-' . uniqid(),
            'transaction_type' => 'sale',
            'business_date' => \App\Services\PosBusinessDay::current($this->companyId),
            'status' => 'completed',
            'invoice_mode' => 'pra',
            'pra_status' => 'submitted',
            'pra_invoice_number' => 'PRA-' . uniqid(),
            'total_amount' => $amount,
            'tax_amount' => 0,
            'payment_method' => 'cash',
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    /** Main Shop 1000, Gulberg 500, plus a 200 legacy bill from before branches existed. */
    private function seedTwoBranchDay(): void
    {
        $this->makeBill(1000, $this->mainBranchId);
        $this->makeBill(500, $this->cityBranchId);
        $this->makeBill(200, null);
    }

    private function actAs(User $user, $branch = null): void
    {
        Auth::guard('pos')->setUser($user);
        if ($branch !== null) {
            session([BranchContextService::SESSION_KEY => $branch]);
        }
    }

    private function retailDashboard(): array
    {
        $view = (new PosController())->dashboard(Request::create('/pos/dashboard', 'GET'));
        $this->assertInstanceOf(\Illuminate\Contracts\View\View::class, $view, 'dashboard must not redirect');

        return $view->getData();
    }

    // ── 1. retail dashboard follows the active branch ────────────────────────

    public function test_retail_dashboard_figures_follow_the_active_branch(): void
    {
        $this->seedTwoBranchDay();
        $owner = $this->makeUser('pos_admin');

        $this->actAs($owner, $this->mainBranchId);
        $data = $this->retailDashboard();
        $this->assertSame(1200.0, $data['todayTotalSale'], 'Main Shop 1000 + legacy 200 — Gulberg must NOT be in it');
        $this->assertSame(1200.0, $data['todayKhata']['pra']['sale'], 'Khaata card must match the headline');
        $this->assertSame(2, $data['todayKhata']['pra']['bills']);

        $this->actAs($owner, $this->cityBranchId);
        $data = $this->retailDashboard();
        $this->assertSame(700.0, $data['todayTotalSale'], 'Gulberg 500 + legacy 200');
        $this->assertSame(700.0, $data['todayKhata']['pra']['sale']);
    }

    public function test_all_branches_view_sums_every_branch(): void
    {
        $this->seedTwoBranchDay();
        $this->actAs($this->makeUser('pos_admin'), BranchContextService::ALL);

        $data = $this->retailDashboard();

        $this->assertSame(1700.0, $data['todayTotalSale'], '1000 + 500 + 200 legacy');
        $this->assertSame(1700.0, $data['todayKhata']['pra']['sale']);
        $this->assertSame(3, $data['todayKhata']['pra']['bills']);
    }

    // ── 2. restaurant dashboard (separate page) does the same ────────────────

    public function test_restaurant_dashboard_figures_follow_the_active_branch(): void
    {
        $this->seedTwoBranchDay();
        $owner = $this->makeUser('pos_admin');

        $this->actAs($owner, $this->mainBranchId);
        $data = (new RestaurantPosController())->dashboard()->getData();
        $this->assertSame(1200.0, $data['todayTotalSale'], 'restaurant headline must be branch-scoped too');
        $this->assertSame(1200.0, $data['todayKhata']['pra']['sale']);

        $this->actAs($owner, $this->cityBranchId);
        $data = (new RestaurantPosController())->dashboard()->getData();
        $this->assertSame(700.0, $data['todayTotalSale']);
        $this->assertSame(700.0, $data['todayKhata']['pra']['sale']);
    }

    // ── 3. new bills always get a REAL branch ────────────────────────────────

    public function test_all_branches_view_still_stamps_new_bills_with_a_real_branch(): void
    {
        $this->actAs($this->makeUser('pos_admin'), BranchContextService::ALL);
        $svc = app(BranchContextService::class);

        $this->assertTrue($svc->isAllBranches());
        $this->assertNull($svc->getActiveBranchId(), 'company-wide view = no read filter');
        $this->assertSame($this->mainBranchId, $svc->stampBranchId(), 'a bill rung up in the company-wide view books to head office, never branch-less');
    }

    // ── 4. a switched-off branch stops taking sales ──────────────────────────

    public function test_cashier_of_a_deactivated_branch_moves_to_head_office(): void
    {
        $cashier = $this->makeUser('pos_cashier', $this->cityBranchId);
        $this->actAs($cashier);
        $svc = app(BranchContextService::class);
        $this->assertSame($this->cityBranchId, $svc->getActiveBranchId(), 'baseline: cashier sits on their own branch');

        // Owner switches Gulberg OFF.
        DB::table('branches')->where('id', $this->cityBranchId)->update(['is_active' => false]);

        session()->forget(BranchContextService::SESSION_KEY);
        Auth::guard('pos')->setUser($cashier);
        $svc = app(BranchContextService::class);

        $this->assertFalse($svc->canAccess($this->cityBranchId), 'a closed branch must not be selectable');
        $this->assertSame([$this->mainBranchId], $svc->accessibleBranches()->pluck('id')->all());
        $this->assertSame($this->mainBranchId, $svc->getActiveBranchId(), 'staff of a closed branch fall back to head office');
        $this->assertSame($this->mainBranchId, $svc->stampBranchId(), 'and their bills must never book to the closed branch');
        $this->assertFalse($svc->setActiveBranch($this->cityBranchId), 'switching back to the closed branch is refused');
    }

    public function test_owner_cannot_stay_on_a_branch_after_it_is_switched_off(): void
    {
        $this->actAs($this->makeUser('pos_admin'), $this->cityBranchId);
        DB::table('branches')->where('id', $this->cityBranchId)->update(['is_active' => false]);

        $this->assertSame($this->mainBranchId, app(BranchContextService::class)->getActiveBranchId());
    }

    // ── 5. schema without the branches table degrades, never throws ──────────

    public function test_dashboard_stays_company_wide_when_the_branches_table_is_missing(): void
    {
        $this->seedTwoBranchDay();
        $owner = $this->makeUser('pos_admin');
        $this->actAs($owner, $this->mainBranchId);

        Schema::drop('branches');
        session()->forget(BranchContextService::SESSION_KEY);
        Auth::guard('pos')->setUser($owner);

        $data = $this->retailDashboard();

        $this->assertSame(1700.0, $data['todayTotalSale'], 'no branches table = single-branch shop, everything counted');
    }
}
