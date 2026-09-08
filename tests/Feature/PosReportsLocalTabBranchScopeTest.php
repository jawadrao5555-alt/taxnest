<?php

namespace Tests\Feature;

use App\Http\Controllers\PosController;
use App\Models\User;
use App\Services\BranchContextService;
use App\Services\PosBusinessDay;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * SALES REPORTS → LOCAL TAB — the bill list follows the branch like the totals
 * do (audit MEDIUM finding, confirmed).
 *
 * PosController::reports() funnels every aggregate (daily sales, payment
 * summary, top items, trend, range analytics) through applyReportFilters,
 * which is branch-aware (Task 1347). The Local tab's own paginated list of
 * promotable local bills was built separately — company_id + stream + cashier
 * only — so under Main Shop's branch-scoped totals the admin saw EVERY
 * branch's local bills, and could promote Gulberg's bill to PRA from Main
 * Shop's page. Locked here, for the list AND the totals on the same page:
 *
 *   1. Owner on a branch  → only that branch's local bills (+ legacy NULL rows).
 *   2. Owner "all branches" → every branch + legacy (company-wide preserved).
 *   3. Manager pivoted to ONE branch → only that branch, and a tampered session
 *      cannot move them.
 *   4. Switching the active branch switches the list.
 *   5. A branch-less shop keeps seeing everything (single-branch regression).
 *
 * Pattern: sqlite :memory: + minimal Schema::create, controller invoked
 * directly (mirrors PosMultiBranchScopeTest / PosBranchIsolationTest).
 */
class PosReportsLocalTabBranchScopeTest extends TestCase
{
    protected int $companyId;
    protected int $mainBranchId;
    protected int $cityBranchId;

    protected function setUp(): void
    {
        parent::setUp();

        Schema::dropAllTables();
        User::flushScopeColumnCache();
        \App\Services\PosFeatureService::flushGateCaches();

        Schema::create('companies', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('product_type')->nullable();
            $table->string('status')->nullable();
            $table->string('pos_business_day_cutoff')->nullable();
            $table->boolean('is_internal_account')->default(false);
            $table->integer('invoice_limit_override')->nullable();
            $table->boolean('pra_reporting_enabled')->default(false);
            $table->boolean('pos_cashier_own_sales_only')->nullable();
            $table->string('pra_connection_mode')->nullable();
            $table->boolean('pos_setup_completed')->default(true);
            $table->string('confidential_pin')->nullable();
            $table->softDeletes();
            $table->timestamps();
        });

        // Empty = no plan = analytics deep dive off (not under test here).
        Schema::create('subscriptions', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('company_id')->nullable();
            $table->unsignedBigInteger('pricing_plan_id')->nullable();
            $table->boolean('active')->default(false);
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
            $table->boolean('pra_reporting_enabled')->nullable();
            $table->timestamps();
        });

        Schema::create('branches', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('company_id');
            $table->string('name');
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
            $table->unsignedBigInteger('created_by')->nullable();
            $table->string('customer_name')->nullable();
            $table->decimal('subtotal', 12, 2)->default(0);
            $table->decimal('discount_amount', 12, 2)->default(0);
            $table->decimal('tax_rate', 8, 2)->nullable();
            $table->decimal('tax_amount', 12, 2)->default(0);
            $table->decimal('exempt_amount', 12, 2)->nullable();
            $table->decimal('total_amount', 12, 2)->default(0);
            $table->string('payment_method')->nullable();
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
            $table->decimal('subtotal', 12, 2)->default(0);
            $table->timestamps();
        });

        $this->companyId = (int) DB::table('companies')->insertGetId([
            'name' => 'Do Dukan Karyana',
            'product_type' => 'pos',
            'status' => 'active',
            'pos_setup_completed' => true,
            'pos_cashier_own_sales_only' => false,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        app()->bind('currentCompanyId', fn () => $this->companyId);

        $this->mainBranchId = $this->makeBranch('Main Shop', true);
        $this->cityBranchId = $this->makeBranch('Gulberg');

        // Local (never-reported) bills: Main 1000, Gulberg 500, legacy 200.
        $this->makeLocalBill('L-MAIN', 1000, $this->mainBranchId);
        $this->makeLocalBill('L-CITY', 500, $this->cityBranchId);
        $this->makeLocalBill('L-OLD', 200, null);
    }

    protected function tearDown(): void
    {
        session()->forget(BranchContextService::SESSION_KEY);
        session()->forget('pos_local_check');
        Auth::guard('pos')->logout();
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

    private function makeUser(string $posRole = 'pos_admin', ?int $branchId = null, string $role = 'company_admin'): User
    {
        $id = DB::table('users')->insertGetId([
            'name' => 'U' . uniqid(),
            'email' => uniqid('u') . '@taxnest.test',
            'company_id' => $this->companyId,
            'role' => $role,
            'pos_role' => $posRole,
            'pos_billing_scope' => 'both',
            'default_branch_id' => $branchId,
            'is_active' => true,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        return User::find($id);
    }

    private function makeLocalBill(string $number, float $amount, ?int $branchId): void
    {
        DB::table('pos_transactions')->insert([
            'company_id' => $this->companyId,
            'branch_id' => $branchId,
            'invoice_number' => $number,
            'transaction_type' => 'sale',
            'business_date' => PosBusinessDay::current($this->companyId),
            'status' => 'completed',
            'invoice_mode' => 'local',
            'pra_status' => 'local',
            'pra_invoice_number' => null,
            'total_amount' => $amount,
            'tax_amount' => 0,
            'payment_method' => 'cash',
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function actAs(User $user, $branch = null): void
    {
        Auth::guard('pos')->setUser($user);
        if ($branch !== null) {
            session([BranchContextService::SESSION_KEY => $branch]);
        }
    }

    /** The Local tab: ['numbers' => sorted invoice numbers, 'today' => today's daily-sales revenue]. */
    private function localTab(): array
    {
        $view = (new PosController())->reports(Request::create('/pos/reports', 'GET', ['tab' => 'local']));
        $data = $view->getData();

        $this->assertSame('local', $data['tab'], 'test precondition: the Local tab must actually be open');
        $today = $data['dailySales']->firstWhere('date', PosBusinessDay::current($this->companyId));

        return [
            'numbers' => collect($data['localBills']->items())->pluck('invoice_number')->sort()->values()->all(),
            'today' => $today ? (float) $today->revenue : 0.0,
        ];
    }

    // ── 1. owner on a branch ─────────────────────────────────────────────────

    public function test_local_bill_list_follows_the_active_branch_like_the_totals(): void
    {
        $owner = $this->makeUser('pos_admin');

        $this->actAs($owner, $this->mainBranchId);
        $tab = $this->localTab();
        $this->assertSame(['L-MAIN', 'L-OLD'], $tab['numbers'], "Gulberg's local bill must not be listed under Main Shop");
        $this->assertSame(1200.0, $tab['today'], 'the totals on the same page: 1000 + 200 legacy');

        $this->actAs($owner, $this->cityBranchId);
        $tab = $this->localTab();
        $this->assertSame(['L-CITY', 'L-OLD'], $tab['numbers'], "Main Shop's local bill must not be listed under Gulberg");
        $this->assertSame(700.0, $tab['today']);
    }

    // ── 2. owner company-wide view ───────────────────────────────────────────

    public function test_owner_all_branches_view_lists_every_branch_and_legacy_rows(): void
    {
        $this->actAs($this->makeUser('pos_admin'), BranchContextService::ALL);

        $tab = $this->localTab();

        $this->assertSame(['L-CITY', 'L-MAIN', 'L-OLD'], $tab['numbers'], 'company-wide reporting must stay company-wide');
        $this->assertSame(1700.0, $tab['today'], '1000 + 500 + 200');
    }

    // ── 3. manager confined to one branch ────────────────────────────────────

    public function test_manager_pivoted_to_one_branch_sees_only_that_branchs_local_bills(): void
    {
        $manager = $this->makeUser('pos_manager', null, 'user');
        DB::table('branch_user')->insert(['branch_id' => $this->mainBranchId, 'user_id' => $manager->id, 'created_at' => now(), 'updated_at' => now()]);
        // Task 705: a manager sees the Local tab only in local-check mode.
        session(['pos_local_check' => true]);

        $this->actAs($manager);
        $tab = $this->localTab();
        $this->assertSame(['L-MAIN', 'L-OLD'], $tab['numbers']);
        $this->assertSame(1200.0, $tab['today']);

        // A hand-edited session pointing at Gulberg must not move them.
        $this->actAs($manager, $this->cityBranchId);
        $tab = $this->localTab();
        $this->assertSame(['L-MAIN', 'L-OLD'], $tab['numbers'], 'a manager cannot reach a branch outside their pivot');
        $this->assertSame(1200.0, $tab['today']);

        // ...nor can they take the owner-only company-wide view.
        $this->assertFalse(app(BranchContextService::class)->setActiveBranch(BranchContextService::ALL));
    }

    // ── 4. switching branches switches the list ──────────────────────────────

    public function test_switching_the_active_branch_switches_the_list(): void
    {
        $owner = $this->makeUser('pos_admin');
        $this->actAs($owner, $this->mainBranchId);
        $this->assertSame(['L-MAIN', 'L-OLD'], $this->localTab()['numbers']);

        $this->assertTrue(app(BranchContextService::class)->setActiveBranch($this->cityBranchId));

        $this->assertSame(['L-CITY', 'L-OLD'], $this->localTab()['numbers']);
    }

    // ── 5. single-branch shop regression ─────────────────────────────────────

    public function test_a_shop_without_branches_still_lists_every_local_bill(): void
    {
        DB::table('branches')->delete();
        DB::table('pos_transactions')->update(['branch_id' => null]);
        $this->actAs($this->makeUser('pos_admin'));

        $tab = $this->localTab();

        $this->assertSame(['L-CITY', 'L-MAIN', 'L-OLD'], $tab['numbers']);
        $this->assertSame(1700.0, $tab['today']);
    }
}
