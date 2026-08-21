<?php

namespace Tests\Feature;

use App\Http\Controllers\PosController;
use App\Models\User;
use App\Services\BranchContextService;
use App\Services\PlanLimitService;
use App\Services\PosBusinessDay;
use App\Services\PosFeatureService;
use Carbon\Carbon;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * BRANCH ISOLATION LOCK (Task 1355) — one branch's money must never leak into
 * another branch's figures.
 *
 * Multi-branch v1 shipped with manual (curl + dev DB) verification only. The
 * expensive failure is SILENT: someone adds a new report query or a new
 * bill-creating route and forgets the branch filter / branch stamp. From that
 * day on, Gulberg's bills quietly join Main Shop's reports, day-close and tax
 * figures — the shopkeeper reconciles a drawer against the wrong number and
 * files PRA/FBR returns with the wrong sales.
 *
 * PosMultiBranchScopeTest already locks the two DASHBOARDS. This suite locks
 * everything downstream of them:
 *
 *   1. READS — the four money surfaces that funnel through applyReportFilters
 *      / the day-close preview: Transactions list, Sales Reports, Tax Reports
 *      and the Day Close preview. A branch A bill is invisible on branch B.
 *   2. ALL-BRANCHES — the owner's company-wide view sums BOTH branches plus
 *      legacy pre-branch (branch_id NULL) rows on every one of those surfaces.
 *   3. WRITES — every bill-creating route stamps a branch: the normal sale
 *      (storeInvoice), the restaurant pay-order (payOrder), and the offline
 *      replay, which must book on the branch it was RUNG UP on, not the branch
 *      that happens to be syncing. A foreign company's branch id is rejected.
 *   4. LOCK + QUOTA — a cashier is welded to their own branch (no switch, and
 *      a hand-edited session cannot move their reports), and a branch beyond
 *      the package limit is refused.
 *
 * Pattern: APP_ENV=testing + sqlite :memory: + minimal Schema::create. Reads
 * go through the controllers directly (PosDayCloseReturnNettingTest style);
 * the write/switch/quota paths go over real HTTP so their middleware chain is
 * exercised too (PosMonthlyBillQuotaPathsTest style). Companies stay
 * reporting-OFF — no network.
 */
class PosBranchIsolationTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Schema::dropAllTables();
        User::flushScopeColumnCache();
        // planAllows/restaurantAllowed cache per company id statically — ids
        // restart at 1 after dropAllTables, so stale verdicts would leak.
        PosFeatureService::flushGateCaches();

        Schema::create('companies', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('product_type')->nullable();
            $table->string('status')->nullable();
            $table->string('default_language')->nullable();
            $table->string('pos_dayclose_provisional_action')->nullable();
            $table->string('pos_dayclose_final_local_action')->nullable();
            $table->boolean('pos_customer_spend_persist')->default(true);
            $table->string('pos_business_day_cutoff')->nullable();
            $table->boolean('is_internal_account')->default(false);
            $table->integer('invoice_limit_override')->nullable();
            $table->integer('user_limit_override')->nullable();
            // Admin bypass for the branch quota under test.
            $table->integer('branch_limit_override')->nullable();
            $table->boolean('pra_reporting_enabled')->default(false);
            $table->boolean('agent_enabled')->default(false);
            $table->boolean('agent_submits_pra')->nullable();
            $table->string('pra_connection_mode')->nullable();
            $table->string('pra_environment')->nullable();
            $table->text('pra_production_token')->nullable();
            $table->string('pra_proxy_url')->nullable();
            $table->string('pra_pos_id')->nullable();
            $table->decimal('pos_tax_rate_cash', 8, 2)->nullable();
            $table->decimal('pos_tax_rate_card', 8, 2)->nullable();
            $table->decimal('cashier_discount_limit', 8, 2)->nullable();
            $table->boolean('inventory_enabled')->default(false);
            $table->string('pos_tax_pricing_mode')->nullable();
            $table->boolean('pos_tax_inclusive')->default(false);
            // Branch scoping is what's under test, not cashier isolation.
            $table->boolean('pos_cashier_own_sales_only')->nullable();
            $table->boolean('pos_setup_completed')->default(true);
            // Opt-in 6 AM sweep (per-branch since Task 1360).
            $table->boolean('pos_auto_dayclose_24h')->default(false);
            $table->text('feature_flags')->nullable();
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
            $table->string('language')->nullable();
            // NULLABLE on purpose: NULL = inherit the company flag.
            $table->boolean('pra_reporting_enabled')->nullable();
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

        Schema::create('pos_transactions', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('company_id');
            $table->unsignedBigInteger('branch_id')->nullable();
            $table->unsignedBigInteger('terminal_id')->nullable();
            $table->unsignedBigInteger('customer_id')->nullable();
            $table->string('invoice_number');
            $table->string('transaction_type')->nullable()->default('sale');
            $table->unsignedBigInteger('parent_transaction_id')->nullable();
            $table->string('business_date')->nullable();
            $table->string('status');
            $table->string('invoice_mode')->nullable();
            $table->string('pra_status')->nullable();
            $table->string('pra_invoice_number')->nullable();
            $table->string('pra_response_code')->nullable();
            $table->text('pra_qr_code')->nullable();
            $table->boolean('is_archived')->default(false);
            $table->timestamp('archived_at')->nullable();
            $table->unsignedBigInteger('archived_by_report_id')->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->string('customer_name')->nullable();
            $table->string('customer_phone')->nullable();
            $table->string('delivery_address')->nullable();
            $table->decimal('subtotal', 12, 2)->default(0);
            $table->string('discount_type')->nullable();
            $table->decimal('discount_value', 12, 2)->nullable();
            $table->decimal('discount_amount', 12, 2)->default(0);
            $table->decimal('tax_rate', 8, 2)->nullable();
            $table->decimal('tax_amount', 12, 2)->default(0);
            $table->decimal('exempt_amount', 12, 2)->nullable();
            $table->decimal('total_amount', 12, 2)->default(0);
            $table->string('payment_method')->nullable();
            $table->string('order_type')->nullable();
            $table->decimal('cash_received', 12, 2)->nullable();
            $table->decimal('change_due', 12, 2)->nullable();
            $table->string('submission_hash')->nullable();
            // storeInvoice's insert always carries this key (only the replay
            // lookup is hasColumn-guarded) — and offline_branch_id is only
            // honoured while it exists, so the column must be here.
            $table->string('offline_uuid')->nullable()->unique();
            $table->boolean('tax_inclusive')->default(false);
            $table->decimal('tax_menu_rate', 8, 2)->nullable();
            $table->text('notes')->nullable();
            $table->unsignedBigInteger('rider_id')->nullable();
            $table->string('delivery_status')->nullable();
            $table->unsignedBigInteger('rider_settlement_id')->nullable();
            $table->timestamp('rider_settled_at')->nullable();
            $table->timestamps();
        });

        Schema::create('pos_transaction_items', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('transaction_id');
            $table->string('item_type')->nullable();
            $table->unsignedBigInteger('item_id')->nullable();
            $table->string('item_name');
            $table->text('special_notes')->nullable();
            $table->text('deal_snapshot')->nullable();
            $table->decimal('quantity', 12, 3)->default(1);
            $table->decimal('unit_price', 12, 2)->default(0);
            $table->decimal('cost_price', 12, 2)->nullable();
            $table->decimal('subtotal', 12, 2)->default(0);
            $table->boolean('is_tax_exempt')->default(false);
            $table->decimal('tax_rate', 8, 2)->nullable();
            $table->decimal('tax_amount', 12, 2)->nullable();
            $table->string('item_discount_type')->nullable();
            $table->decimal('item_discount_value', 12, 2)->nullable();
            $table->decimal('item_discount_amount', 12, 2)->nullable();
            $table->timestamps();
        });

        Schema::create('pos_payments', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('transaction_id');
            $table->string('payment_method')->nullable();
            $table->decimal('amount', 12, 2)->default(0);
            $table->string('reference_number')->nullable();
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
            // Task 1360: 0 = company-wide (branch-less shop / pre-branch history).
            $table->unsignedBigInteger('branch_id')->default(0);
            $table->date('report_date');
            $table->string('report_number')->nullable();
            $table->integer('deleted_final_count')->default(0);
            $table->integer('deleted_provisional_count')->default(0);
            $table->text('local_summary')->nullable();
            $table->text('rider_summary')->nullable();
            $table->text('returns_detail')->nullable();
            $table->integer('total_invoices')->default(0);
            $table->integer('pra_invoices')->default(0);
            $table->integer('local_invoices')->default(0);
            $table->integer('offline_invoices')->default(0);
            $table->decimal('gross_sales', 14, 2)->default(0);
            $table->decimal('total_discount', 14, 2)->default(0);
            $table->decimal('net_sales', 14, 2)->default(0);
            $table->decimal('total_tax', 14, 2)->default(0);
            $table->decimal('total_amount', 14, 2)->default(0);
            $table->decimal('cash_amount', 14, 2)->default(0);
            $table->decimal('card_amount', 14, 2)->default(0);
            $table->decimal('other_amount', 14, 2)->default(0);
            $table->integer('returns_count')->default(0);
            $table->decimal('returns_amount', 14, 2)->default(0);
            $table->string('first_invoice_number')->nullable();
            $table->string('last_invoice_number')->nullable();
            $table->timestamp('first_invoice_time')->nullable();
            $table->timestamp('last_invoice_time')->nullable();
            $table->unsignedBigInteger('closed_by')->nullable();
            $table->text('notes')->nullable();
            $table->string('hash')->nullable();
            $table->decimal('opening_float', 14, 2)->nullable();
            $table->decimal('counted_cash', 14, 2)->nullable();
            $table->decimal('expected_cash', 14, 2)->nullable();
            $table->decimal('cash_variance', 14, 2)->nullable();
            $table->timestamps();
        });

        Schema::create('pos_day_openings', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('company_id');
            $table->unsignedBigInteger('branch_id')->default(0); // Task 1360
            $table->date('business_date');
            $table->decimal('opening_cash', 14, 2)->default(0);
            $table->unsignedBigInteger('entered_by')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();
        });

        Schema::create('pos_riders', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('company_id');
            $table->string('name');
            $table->timestamps();
        });

        Schema::create('restaurant_orders', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('company_id');
            $table->string('order_number')->nullable();
            $table->unsignedBigInteger('table_id')->nullable();
            $table->string('order_type')->nullable();
            $table->string('status')->nullable();
            $table->string('source')->nullable();
            $table->unsignedBigInteger('customer_id')->nullable();
            $table->string('customer_name')->nullable();
            $table->string('customer_phone')->nullable();
            $table->string('delivery_address')->nullable();
            $table->decimal('subtotal', 12, 2)->default(0);
            $table->string('discount_type')->nullable();
            $table->decimal('discount_value', 12, 2)->nullable();
            $table->decimal('discount_amount', 12, 2)->default(0);
            $table->decimal('tax_amount', 12, 2)->default(0);
            $table->decimal('total_amount', 12, 2)->default(0);
            $table->string('payment_method')->nullable();
            $table->text('kitchen_notes')->nullable();
            $table->unsignedBigInteger('pos_transaction_id')->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamps();
        });

        Schema::create('restaurant_order_items', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('order_id');
            $table->string('item_type')->nullable();
            $table->unsignedBigInteger('item_id')->nullable();
            $table->string('item_name');
            $table->decimal('quantity', 12, 3)->default(1);
            $table->decimal('unit_price', 12, 2)->default(0);
            $table->decimal('subtotal', 12, 2)->default(0);
            $table->boolean('is_tax_exempt')->default(false);
            $table->string('item_discount_type')->nullable();
            $table->decimal('item_discount_value', 12, 2)->nullable();
            $table->decimal('item_discount_amount', 12, 2)->nullable();
            $table->timestamps();
        });

        // payOrder's stock validation queries recipes for product lines even
        // when inventory is OFF — the table must exist (and stays empty here).
        Schema::create('product_recipes', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('company_id');
            $table->unsignedBigInteger('product_id');
            $table->unsignedBigInteger('ingredient_id');
            $table->decimal('quantity_needed', 12, 4)->default(0);
            $table->timestamps();
        });

        Schema::create('pricing_plans', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('product_type')->default('pos');
            $table->boolean('is_trial')->default(false);
            $table->integer('invoice_limit')->nullable();
            $table->integer('user_limit')->nullable();
            // The package's branch allowance (NULL/-1 = unlimited).
            $table->integer('branch_limit')->nullable();
            $table->boolean('restaurant_enabled')->default(true);
            $table->boolean('deals_enabled')->default(false);
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
            $table->string('override_type')->default('none');
            $table->timestamp('override_until')->nullable();
            $table->timestamp('override_granted_at')->nullable();
            $table->integer('free_invoice_limit')->nullable();
            $table->timestamps();
        });

        // effectiveRules() reads this table before applying the company
        // overrides, so it must exist even when the overrides win.
        Schema::create('pos_tax_rules', function (Blueprint $table) {
            $table->id();
            $table->string('payment_method');
            $table->decimal('tax_rate', 8, 2)->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('pra_logs', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('company_id')->nullable();
            $table->unsignedBigInteger('transaction_id')->nullable();
            $table->text('request_payload')->nullable();
            $table->text('response_payload')->nullable();
            $table->string('response_code')->nullable();
            $table->string('status')->nullable();
            $table->timestamps();
        });
    }

    protected function tearDown(): void
    {
        session()->forget(BranchContextService::SESSION_KEY);
        Auth::guard('pos')->logout();
        parent::tearDown();
    }

    // ── fixtures ─────────────────────────────────────────────────────────────

    /** Reporting-OFF POS company that survives PosAuth + company.approval. */
    private function makeCompany(array $attrs = []): int
    {
        return (int) DB::table('companies')->insertGetId(array_merge([
            'name' => 'Do Dukan Karyana',
            'product_type' => 'pos',
            'status' => 'active',
            'pos_dayclose_provisional_action' => 'save',
            'pos_dayclose_final_local_action' => 'save',
            'pos_setup_completed' => true,
            'is_internal_account' => false,
            'pra_reporting_enabled' => false,
            'agent_enabled' => false,
            'pra_connection_mode' => 'cloud',
            'pra_environment' => 'sandbox',
            'inventory_enabled' => false,
            // Company-level rate overrides → PosTaxRule table never consulted.
            'pos_tax_rate_cash' => 16.00,
            'pos_tax_rate_card' => 16.00,
            // Branch scoping under test, NOT per-cashier isolation.
            'pos_cashier_own_sales_only' => false,
            'created_at' => now(), 'updated_at' => now(),
        ], $attrs));
    }

    private function subscribe(int $companyId, array $planAttrs = []): int
    {
        $planId = (int) DB::table('pricing_plans')->insertGetId(array_merge([
            'name' => 'Business',
            'product_type' => 'pos',
            'is_trial' => false,
            'invoice_limit' => -1,
            'user_limit' => null,
            'branch_limit' => -1,
            'restaurant_enabled' => true,
            'created_at' => now(), 'updated_at' => now(),
        ], $planAttrs));

        DB::table('subscriptions')->insert([
            'company_id' => $companyId,
            'pricing_plan_id' => $planId,
            'active' => true,
            'start_date' => now()->subMonth()->toDateString(),
            'end_date' => now()->addMonth()->toDateString(),
            'override_type' => 'none',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        return $planId;
    }

    private function makeBranch(int $companyId, string $name, bool $head = false): int
    {
        return (int) DB::table('branches')->insertGetId([
            'company_id' => $companyId,
            'name' => $name,
            'is_head_office' => $head,
            'is_active' => true,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function makeUser(int $companyId, array $attrs = []): User
    {
        static $seq = 0;
        $id = DB::table('users')->insertGetId(array_merge([
            'name' => 'POS Owner',
            'email' => 'u' . $companyId . '-' . (++$seq) . '@taxnest.test',
            'password' => bcrypt('Secret@12345'),
            'company_id' => $companyId,
            'role' => 'company_admin',
            'pos_role' => 'pos_admin',
            'pos_billing_scope' => 'both',
            'is_active' => true,
            'language' => 'en',
            'pra_reporting_enabled' => null,
            'created_at' => now(), 'updated_at' => now(),
        ], $attrs));

        return User::find($id);
    }

    /** A completed PRA bill of $subtotal (+10% tax) on $branchId (null = legacy row). */
    private function makeBill(int $companyId, string $number, float $subtotal, ?int $branchId): int
    {
        $tax = round($subtotal * 0.1, 2);
        $id = (int) DB::table('pos_transactions')->insertGetId([
            'company_id' => $companyId,
            'branch_id' => $branchId,
            'invoice_number' => $number,
            'transaction_type' => 'sale',
            'business_date' => PosBusinessDay::current($companyId),
            'status' => 'completed',
            'invoice_mode' => 'pra',
            'pra_status' => 'submitted',
            'pra_invoice_number' => 'PRA-' . $number,
            'subtotal' => $subtotal,
            'discount_amount' => 0,
            'tax_rate' => 10,
            'tax_amount' => $tax,
            'total_amount' => $subtotal + $tax,
            'payment_method' => 'cash',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        DB::table('pos_transaction_items')->insert([
            'transaction_id' => $id,
            'item_type' => 'product',
            'item_name' => 'Item ' . $number,
            'quantity' => 1,
            'unit_price' => $subtotal,
            'subtotal' => $subtotal,
            'is_tax_exempt' => false,
            'tax_rate' => 10,
            'tax_amount' => $tax,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        return $id;
    }

    /** A completed LOCAL (reporting-off) bill — the kind the day-close wash sweeps. */
    private function makeLocalBill(int $companyId, string $number, float $subtotal, ?int $branchId): int
    {
        $tax = round($subtotal * 0.1, 2);

        return (int) DB::table('pos_transactions')->insertGetId([
            'company_id' => $companyId,
            'branch_id' => $branchId,
            'invoice_number' => $number,
            'transaction_type' => 'sale',
            'business_date' => PosBusinessDay::current($companyId),
            'status' => 'completed',
            // The "local" triple (completed + local + local) — anything else is
            // a different stream and the wash must leave it alone.
            'invoice_mode' => 'local',
            'pra_status' => 'local',
            'is_archived' => false,
            'subtotal' => $subtotal,
            'discount_amount' => 0,
            'tax_rate' => 10,
            'tax_amount' => $tax,
            'total_amount' => $subtotal + $tax,
            'payment_method' => 'cash',
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    /**
     * The canonical two-branch shop:
     *   Main Shop  P-MAIN  1000 + 100 tax = 1100
     *   Gulberg    P-CITY   500 +  50 tax =  550
     *   legacy     P-OLD    200 +  20 tax =  220  (rung up before branches existed)
     *
     * @return array{0:int,1:int,2:int} [companyId, mainBranchId, cityBranchId]
     */
    private function seedTwoBranchShop(array $companyAttrs = [], array $planAttrs = []): array
    {
        $companyId = $this->makeCompany($companyAttrs);
        $this->subscribe($companyId, $planAttrs);
        $mainId = $this->makeBranch($companyId, 'Main Shop', true);
        $cityId = $this->makeBranch($companyId, 'Gulberg');

        $this->makeBill($companyId, 'P-MAIN', 1000, $mainId);
        $this->makeBill($companyId, 'P-CITY', 500, $cityId);
        $this->makeBill($companyId, 'P-OLD', 200, null);

        return [$companyId, $mainId, $cityId];
    }

    // ── surface helpers (reads) ──────────────────────────────────────────────

    /**
     * Look at the shop's books as $user, standing on $branch
     * (a branch id, or BranchContextService::ALL for the company-wide view).
     */
    private function standOn(int $companyId, User $user, $branch): void
    {
        Auth::guard('pos')->setUser($user);
        app()->instance('currentCompanyId', $companyId);
        session([BranchContextService::SESSION_KEY => $branch]);
    }

    /** Invoice numbers on the Transactions page, sorted. */
    private function transactionNumbers(): array
    {
        $data = (new PosController())->transactions(Request::create('/pos/transactions', 'GET'))->getData();

        return collect($data['transactions']->items())->pluck('invoice_number')->sort()->values()->all();
    }

    /** Today's row of the Sales Reports daily table (null when the branch had no sales). */
    private function reportsToday(int $companyId)
    {
        $data = (new PosController())->reports(Request::create('/pos/reports', 'GET'))->getData();

        return $data['dailySales']->firstWhere('date', PosBusinessDay::current($companyId));
    }

    /** Tax Reports screen data (summary + paginated rows). */
    private function taxReportData(): array
    {
        return (new PosController())->taxReports(Request::create('/pos/tax-reports', 'GET'))->getData();
    }

    /** Day Close preview stats for the open business day. */
    private function dayCloseStats(int $companyId)
    {
        $request = Request::create('/pos/day-close', 'GET', ['date' => PosBusinessDay::current($companyId)]);

        return (new PosController())->dayCloseReport($request)->getData()['stats'];
    }

    // ── surface helpers (writes) ─────────────────────────────────────────────

    private function storePayload(array $overrides = []): array
    {
        return array_merge([
            'items' => [[
                'type' => 'product',
                'name' => 'Chai',
                'quantity' => 1,
                'unit_price' => 100,
                'is_tax_exempt' => false,
                '_manual' => 1, // ad-hoc line — no product master involved
            ]],
            'payment_method' => 'cash',
            'discount_type' => 'amount',
            'discount_value' => 0,
        ], $overrides);
    }

    /** Ring up a sale over real HTTP while standing on $branchId; returns the new bill's branch_id. */
    private function sellFromBranch(User $user, int $branchId, array $payloadOverrides = []): ?int
    {
        $response = $this->actingAs($user, 'pos')
            ->withSession([BranchContextService::SESSION_KEY => $branchId])
            ->postJson('/pos/invoice/store', $this->storePayload($payloadOverrides));
        $response->assertStatus(200)->assertJson(['success' => true]);
        $branch = DB::table('pos_transactions')->where('id', $response->json('transaction_id'))->value('branch_id');

        return $branch === null ? null : (int) $branch;
    }

    /** Close the open business day over real HTTP while standing on $branch. */
    private function closeDayFrom(User $user, $branch, array $payload = [])
    {
        return $this->actingAs($user, 'pos')
            ->withSession([BranchContextService::SESSION_KEY => $branch])
            ->post('/pos/day-close', $payload);
    }

    /** The saved Z-report row for a branch (null branch = the company-wide row). */
    private function savedReport(int $companyId, ?int $branchId)
    {
        return $this->reportOn($companyId, $branchId, PosBusinessDay::current($companyId));
    }

    /** The saved Z-report row for a branch on a given date. */
    private function reportOn(int $companyId, ?int $branchId, string $date)
    {
        return DB::table('pos_day_close_reports')
            ->where('company_id', $companyId)
            ->where('branch_id', $branchId ?: 0)
            ->whereDate('report_date', $date)
            ->first();
    }

    /** Restaurant-featured company (RestaurantOnly middleware + plan module). */
    private function makeRestaurantOrder(int $companyId): int
    {
        $orderId = (int) DB::table('restaurant_orders')->insertGetId([
            'company_id' => $companyId,
            'order_number' => 'ORD-001',
            'order_type' => 'takeaway',
            'status' => 'pending',
            'subtotal' => 100,
            'discount_type' => 'amount',
            'discount_value' => 0,
            'discount_amount' => 0,
            'total_amount' => 100,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        DB::table('restaurant_order_items')->insert([
            'order_id' => $orderId,
            'item_type' => 'product',
            'item_id' => 9001, // no recipe rows → stock validation is a no-op
            'item_name' => 'Karahi',
            'quantity' => 1,
            'unit_price' => 100,
            'subtotal' => 100,
            'is_tax_exempt' => false,
            'item_discount_type' => 'percentage',
            'item_discount_value' => 0,
            'item_discount_amount' => 0,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        return $orderId;
    }

    // ════════════════════════════════════════════════════════════════════════
    // 1. READS — branch A's bill is invisible from branch B
    // ════════════════════════════════════════════════════════════════════════

    public function test_transactions_list_hides_the_other_branch(): void
    {
        [$companyId, $mainId, $cityId] = $this->seedTwoBranchShop();
        $owner = $this->makeUser($companyId);

        $this->standOn($companyId, $owner, $mainId);
        $this->assertSame(['P-MAIN', 'P-OLD'], $this->transactionNumbers(),
            "Gulberg's bill must never appear in Main Shop's Transactions list");

        $this->standOn($companyId, $owner, $cityId);
        $this->assertSame(['P-CITY', 'P-OLD'], $this->transactionNumbers(),
            "Main Shop's bill must never appear in Gulberg's Transactions list");
    }

    public function test_sales_reports_figures_exclude_the_other_branch(): void
    {
        [$companyId, $mainId, $cityId] = $this->seedTwoBranchShop();
        $owner = $this->makeUser($companyId);

        $this->standOn($companyId, $owner, $mainId);
        $today = $this->reportsToday($companyId);
        $this->assertNotNull($today);
        $this->assertSame(2, (int) $today->count, 'Main Shop bill + legacy bill');
        $this->assertSame(1320.0, (float) $today->revenue, '1100 + 220 legacy — never Gulberg 550');

        $this->standOn($companyId, $owner, $cityId);
        $today = $this->reportsToday($companyId);
        $this->assertSame(2, (int) $today->count);
        $this->assertSame(770.0, (float) $today->revenue, '550 + 220 legacy — never Main Shop 1100');
    }

    public function test_tax_report_figures_exclude_the_other_branch(): void
    {
        [$companyId, $mainId, $cityId] = $this->seedTwoBranchShop();
        $owner = $this->makeUser($companyId);

        // Tax figures are the most expensive to get wrong — they are filed.
        $this->standOn($companyId, $owner, $mainId);
        $data = $this->taxReportData();
        $this->assertSame(['P-MAIN', 'P-OLD'],
            collect($data['transactions']->items())->pluck('invoice_number')->sort()->values()->all());
        $this->assertSame(2, (int) $data['summary']->total_invoices);
        $this->assertSame(1320.0, (float) $data['summary']->total_sales);
        $this->assertSame(120.0, (float) $data['summary']->total_tax, 'Gulberg tax must never be filed under Main Shop');

        $this->standOn($companyId, $owner, $cityId);
        $data = $this->taxReportData();
        $this->assertSame(['P-CITY', 'P-OLD'],
            collect($data['transactions']->items())->pluck('invoice_number')->sort()->values()->all());
        $this->assertSame(770.0, (float) $data['summary']->total_sales);
        $this->assertSame(70.0, (float) $data['summary']->total_tax);
    }

    public function test_day_close_preview_excludes_the_other_branch(): void
    {
        [$companyId, $mainId, $cityId] = $this->seedTwoBranchShop();
        $owner = $this->makeUser($companyId);

        // The drawer is counted per branch — a foreign bill here means the
        // cashier is short (or over) by exactly the other shop's takings.
        $this->standOn($companyId, $owner, $mainId);
        $stats = $this->dayCloseStats($companyId);
        $this->assertSame(2, (int) $stats->total_invoices);
        $this->assertSame(1320.0, (float) $stats->total_amount);
        $this->assertSame(1320.0, (float) $stats->cash_amount, 'expected drawer cash is per branch');

        $this->standOn($companyId, $owner, $cityId);
        $stats = $this->dayCloseStats($companyId);
        $this->assertSame(2, (int) $stats->total_invoices);
        $this->assertSame(770.0, (float) $stats->total_amount);
        $this->assertSame(770.0, (float) $stats->cash_amount);
    }

    // ════════════════════════════════════════════════════════════════════════
    // 1b. DAY CLOSE — what the branch previewed is what the branch freezes
    // ════════════════════════════════════════════════════════════════════════

    public function test_saved_day_close_report_matches_the_branch_preview(): void
    {
        // THE bug (Task 1360): Gulberg's cashier previewed Rs 770 and the
        // saved Z-report froze Rs 1,870 — both branches plus legacy history.
        [$companyId, $mainId, $cityId] = $this->seedTwoBranchShop();
        $owner = $this->makeUser($companyId);

        $this->standOn($companyId, $owner, $cityId);
        $preview = $this->dayCloseStats($companyId);

        $this->closeDayFrom($owner, $cityId)->assertSessionHas('success');

        $saved = $this->savedReport($companyId, $cityId);
        $this->assertNotNull($saved, "Gulberg's close must save a report stamped with Gulberg");
        $this->assertSame((float) $preview->total_amount, (float) $saved->total_amount,
            'the frozen total must be the previewed total, never the company-wide one');
        $this->assertSame(770.0, (float) $saved->total_amount);
        $this->assertSame(770.0, (float) $saved->cash_amount, 'the drawer is reconciled against this branch only');
        $this->assertSame(2, (int) $saved->total_invoices, 'Gulberg bill + legacy bill');
        $this->assertNull($this->savedReport($companyId, null),
            'a branch close must not also mint a company-wide report');
    }

    public function test_closing_one_branch_leaves_the_other_branch_open(): void
    {
        [$companyId, $mainId, $cityId] = $this->seedTwoBranchShop();
        $owner = $this->makeUser($companyId);

        $this->closeDayFrom($owner, $cityId)->assertSessionHas('success');

        // Main Shop's day is untouched: no report, page still shows its figures.
        $this->assertNull($this->savedReport($companyId, $mainId));
        $this->standOn($companyId, $owner, $mainId);
        $data = (new PosController())->dayCloseReport(
            Request::create('/pos/day-close', 'GET', ['date' => PosBusinessDay::current($companyId)])
        )->getData();
        $this->assertNull($data['existingReport'], "Gulberg's close must not close Main Shop's day");
        $this->assertSame(1320.0, (float) $data['stats']->total_amount);

        // ...and Main Shop can still close its own day afterwards.
        $this->closeDayFrom($owner, $mainId)->assertSessionHas('success');
        $this->assertSame(1320.0, (float) $this->savedReport($companyId, $mainId)->total_amount);
    }

    public function test_day_close_wash_only_touches_its_own_branchs_local_bills(): void
    {
        // Standing policy = archive local bills at close. One branch's close
        // must not sweep the other branch's still-open local bills away.
        [$companyId, $mainId, $cityId] = $this->seedTwoBranchShop();
        $owner = $this->makeUser($companyId);
        $mainLocal = $this->makeLocalBill($companyId, 'L-MAIN', 300, $mainId);
        $cityLocal = $this->makeLocalBill($companyId, 'L-CITY', 400, $cityId);

        $this->closeDayFrom($owner, $cityId)->assertSessionHas('success');

        $this->assertTrue((bool) DB::table('pos_transactions')->where('id', $cityLocal)->value('is_archived'),
            "Gulberg's own local bill is washed by Gulberg's close");
        $this->assertFalse((bool) DB::table('pos_transactions')->where('id', $mainLocal)->value('is_archived'),
            "Main Shop's local bill must survive Gulberg's close — it is still a live, uncounted bill");
    }

    public function test_report_number_and_opening_cash_are_per_branch(): void
    {
        [$companyId, $mainId, $cityId] = $this->seedTwoBranchShop();
        $owner = $this->makeUser($companyId);

        // Each branch counts its own drawer float at day start.
        $this->actingAs($owner, 'pos')->withSession([BranchContextService::SESSION_KEY => $mainId])
            ->post('/pos/day-opening', ['opening_cash' => 5000])->assertSessionHas('success');
        $this->actingAs($owner, 'pos')->withSession([BranchContextService::SESSION_KEY => $cityId])
            ->post('/pos/day-opening', ['opening_cash' => 2000])->assertSessionHas('success');

        $this->closeDayFrom($owner, $cityId)->assertSessionHas('success');
        $this->closeDayFrom($owner, $mainId)->assertSessionHas('success');

        $city = $this->savedReport($companyId, $cityId);
        $main = $this->savedReport($companyId, $mainId);

        $this->assertSame(2000.0, (float) $city->opening_float, "Gulberg's Z-report opens with Gulberg's float");
        $this->assertSame(5000.0, (float) $main->opening_float);

        // Each branch keeps its OWN Z sequence — Main Shop's first close is #1
        // even though Gulberg closed first.
        $this->assertSame('ZRPT-POS-B' . $cityId . '-00001', $city->report_number);
        $this->assertSame('ZRPT-POS-B' . $mainId . '-00001', $main->report_number);
    }

    public function test_all_branches_view_cannot_close_the_day(): void
    {
        // Half-branch-aware is the dangerous state: a company-wide close from
        // the reporting view would belong to no branch while every branch's
        // own page still said "not closed".
        [$companyId, , ] = $this->seedTwoBranchShop();
        $owner = $this->makeUser($companyId);

        $this->closeDayFrom($owner, BranchContextService::ALL)->assertSessionHas('error');

        $this->assertSame(0, DB::table('pos_day_close_reports')->where('company_id', $companyId)->count(),
            'no report may be saved from the company-wide view');

        // Opening cash has no single drawer there either.
        $this->actingAs($owner, 'pos')->withSession([BranchContextService::SESSION_KEY => BranchContextService::ALL])
            ->post('/pos/day-opening', ['opening_cash' => 1000])->assertSessionHas('error');
        $this->assertSame(0, DB::table('pos_day_openings')->where('company_id', $companyId)->count());
    }

    public function test_a_branch_less_shop_still_closes_company_wide(): void
    {
        // Regression guard: the per-branch rule must be invisible to the
        // (majority) single-branch shops — one report, old number format.
        $companyId = $this->makeCompany();
        $this->subscribe($companyId);
        $this->makeBill($companyId, 'P-1', 1000, null);
        $owner = $this->makeUser($companyId);

        $this->actingAs($owner, 'pos')->post('/pos/day-close')->assertSessionHas('success');

        $report = $this->savedReport($companyId, null);
        $this->assertNotNull($report);
        $this->assertSame('ZRPT-POS-00001', $report->report_number, 'branch-less shops keep the original numbering');
        $this->assertSame(1100.0, (float) $report->total_amount);
    }

    public function test_auto_close_closes_each_branch_separately(): void
    {
        // The 6 AM sweep follows the same rule — one Z-report per branch, each
        // with its own figures, instead of a single company-wide report.
        [$companyId, $mainId, $cityId] = $this->seedTwoBranchShop(['pos_auto_dayclose_24h' => true]);
        $this->makeUser($companyId); // the sweep records the company admin as closer
        $yesterday = Carbon::parse(PosBusinessDay::current($companyId))->subDay()->toDateString();
        DB::table('pos_transactions')->where('company_id', $companyId)
            ->update(['business_date' => $yesterday, 'created_at' => Carbon::parse($yesterday)->setTime(13, 0)]);

        $this->artisan('pos:auto-dayclose')->assertExitCode(0);

        $main = $this->reportOn($companyId, $mainId, $yesterday);
        $city = $this->reportOn($companyId, $cityId, $yesterday);
        $this->assertNotNull($main, 'Main Shop gets its own auto-close report');
        $this->assertNotNull($city, 'Gulberg gets its own auto-close report');
        $this->assertSame(1320.0, (float) $main->total_amount);
        $this->assertSame(770.0, (float) $city->total_amount);
        $this->assertNull($this->reportOn($companyId, null, $yesterday),
            'the sweep must not also write a company-wide report for a branched shop');
    }

    // ════════════════════════════════════════════════════════════════════════
    // 2. ALL-BRANCHES — the owner sees both branches AND legacy rows
    // ════════════════════════════════════════════════════════════════════════

    public function test_owner_all_branches_view_shows_both_branches_and_legacy_rows(): void
    {
        [$companyId, , ] = $this->seedTwoBranchShop();
        $owner = $this->makeUser($companyId);

        $this->standOn($companyId, $owner, BranchContextService::ALL);

        $this->assertSame(['P-CITY', 'P-MAIN', 'P-OLD'], $this->transactionNumbers(),
            'company-wide view = both branches + pre-branch history');

        $today = $this->reportsToday($companyId);
        $this->assertSame(3, (int) $today->count);
        $this->assertSame(1870.0, (float) $today->revenue, '1100 + 550 + 220');

        $tax = $this->taxReportData();
        $this->assertSame(3, (int) $tax['summary']->total_invoices);
        $this->assertSame(1870.0, (float) $tax['summary']->total_sales);
        $this->assertSame(170.0, (float) $tax['summary']->total_tax, '100 + 50 + 20');

        $stats = $this->dayCloseStats($companyId);
        $this->assertSame(3, (int) $stats->total_invoices);
        $this->assertSame(1870.0, (float) $stats->total_amount);
    }

    public function test_a_shop_that_never_made_branches_still_sees_everything(): void
    {
        // The upgrade path: multi-branch code now runs for EVERY shop. A
        // single-branch (branch-less) company must keep seeing all its money.
        $companyId = $this->makeCompany();
        $this->subscribe($companyId);
        $this->makeBill($companyId, 'P-1', 1000, null);
        $this->makeBill($companyId, 'P-2', 500, null);

        Auth::guard('pos')->setUser($this->makeUser($companyId));
        app()->instance('currentCompanyId', $companyId);

        $this->assertSame(['P-1', 'P-2'], $this->transactionNumbers());
        $this->assertSame(1650.0, (float) $this->reportsToday($companyId)->revenue);
    }

    // ════════════════════════════════════════════════════════════════════════
    // 3. WRITES — every bill-creating route stamps a branch
    // ════════════════════════════════════════════════════════════════════════

    public function test_normal_sale_books_on_the_branch_it_was_rung_up_on(): void
    {
        [$companyId, , $cityId] = $this->seedTwoBranchShop();
        $owner = $this->makeUser($companyId);

        $this->assertSame($cityId, $this->sellFromBranch($owner, $cityId),
            'a sale rung up in Gulberg must book to Gulberg');
    }

    public function test_sale_in_the_company_wide_view_still_books_on_a_real_branch(): void
    {
        [$companyId, $mainId, ] = $this->seedTwoBranchShop();
        $owner = $this->makeUser($companyId);

        // "All branches" is a REPORTING view. A bill rung up while it is
        // selected must not become branch-less (invisible to every branch).
        $response = $this->actingAs($owner, 'pos')
            ->withSession([BranchContextService::SESSION_KEY => BranchContextService::ALL])
            ->postJson('/pos/invoice/store', $this->storePayload());
        $response->assertStatus(200);
        $this->assertSame($mainId, (int) DB::table('pos_transactions')
            ->where('id', $response->json('transaction_id'))->value('branch_id'),
            'company-wide view falls back to head office, never NULL');
    }

    public function test_restaurant_pay_order_books_on_the_active_branch(): void
    {
        [$companyId, , $cityId] = $this->seedTwoBranchShop([
            'feature_flags' => json_encode(['tables' => true, 'kot' => true, 'kitchen' => true]),
        ]);
        $user = $this->makeUser($companyId);
        $orderId = $this->makeRestaurantOrder($companyId);

        $response = $this->actingAs($user, 'pos')
            ->withSession([BranchContextService::SESSION_KEY => $cityId])
            ->postJson("/pos/restaurant/orders/{$orderId}/pay", ['payment_method' => 'cash']);
        $response->assertStatus(200)->assertJson(['success' => true]);
        $this->assertSame($cityId, (int) DB::table('pos_transactions')
            ->where('id', $response->json('transaction_id'))->value('branch_id'),
            'dine-in / takeaway bills must not fall out of their branch reports');
    }

    public function test_offline_bill_syncs_onto_its_own_branch_not_the_syncing_one(): void
    {
        [$companyId, $mainId, $cityId] = $this->seedTwoBranchShop();
        $owner = $this->makeUser($companyId);

        // Rung up offline at Main Shop; the queue drains later while the
        // session is standing in Gulberg. The bill belongs to Main Shop.
        $branch = $this->sellFromBranch($owner, $cityId, [
            'offline_uuid' => 'uuid-offline-main-1',
            'offline_branch_id' => $mainId,
        ]);

        $this->assertSame($mainId, $branch, 'an offline bill must replay onto the branch that sold it');
    }

    public function test_offline_branch_id_from_another_company_is_ignored(): void
    {
        [$companyId, , $cityId] = $this->seedTwoBranchShop();
        $owner = $this->makeUser($companyId);

        // A branch id belonging to a DIFFERENT shop is not a branch of ours —
        // accepting it would post our money into somebody else's books. Two
        // layers stop it today (Branch's CompanyScope + the explicit
        // company_id filter on the lookup); this locks the OUTCOME, so losing
        // either layer alone stays safe but losing both is caught here.
        $otherCompanyId = $this->makeCompany(['name' => 'Padosi Store']);
        $foreignBranchId = $this->makeBranch($otherCompanyId, 'Padosi Main', true);

        $branch = $this->sellFromBranch($owner, $cityId, [
            'offline_uuid' => 'uuid-offline-foreign-1',
            'offline_branch_id' => $foreignBranchId,
        ]);

        $this->assertSame($cityId, $branch, 'a foreign branch id must fall back to the session branch');
    }

    // ════════════════════════════════════════════════════════════════════════
    // 4. CASHIER LOCK + PACKAGE QUOTA
    // ════════════════════════════════════════════════════════════════════════

    public function test_cashier_is_locked_to_their_own_branch(): void
    {
        [$companyId, $mainId, $cityId] = $this->seedTwoBranchShop();
        $cashier = $this->makeUser($companyId, [
            'role' => 'user',
            'pos_role' => 'pos_cashier',
            'default_branch_id' => $cityId,
        ]);

        Auth::guard('pos')->setUser($cashier);
        app()->instance('currentCompanyId', $companyId);
        $svc = app(BranchContextService::class);

        $this->assertSame([$cityId], $svc->accessibleBranches()->pluck('id')->all(), 'one branch only');
        $this->assertFalse($svc->canSwitch(), 'the switcher must not even offer a second branch');
        $this->assertFalse($svc->canAccess($mainId));
        $this->assertFalse($svc->setActiveBranch($mainId), 'switching to Main Shop must be refused');
        $this->assertFalse($svc->setActiveBranch(BranchContextService::ALL), 'the company-wide view is owner-only');
        $this->assertSame($cityId, $svc->getActiveBranchId(), 'the cashier stays on Gulberg');
        $this->assertSame($cityId, $svc->stampBranchId(), 'and their bills stay on Gulberg');
    }

    public function test_branch_switch_endpoint_refuses_a_cashier(): void
    {
        [$companyId, $mainId, $cityId] = $this->seedTwoBranchShop();
        $cashier = $this->makeUser($companyId, [
            'role' => 'user',
            'pos_role' => 'pos_cashier',
            'default_branch_id' => $cityId,
        ]);

        $response = $this->actingAs($cashier, 'pos')->post('/branch/switch', ['branch_id' => $mainId]);

        $response->assertSessionHas('error');
        $this->assertNotSame($mainId, session(BranchContextService::SESSION_KEY),
            'a refused switch must not leave the cashier standing in another branch');
    }

    public function test_hand_edited_session_cannot_move_a_cashier_to_another_branch(): void
    {
        [$companyId, $mainId, $cityId] = $this->seedTwoBranchShop();
        $cashier = $this->makeUser($companyId, [
            'role' => 'user',
            'pos_role' => 'pos_cashier',
            'default_branch_id' => $cityId,
        ]);

        // Session tampered with Main Shop's id: the context service must throw
        // it away and the cashier's reports must stay on their own branch.
        $this->standOn($companyId, $cashier, $mainId);

        $this->assertSame($cityId, app(BranchContextService::class)->getActiveBranchId());
        $this->assertSame(['P-CITY', 'P-OLD'], $this->transactionNumbers());
    }

    public function test_branch_beyond_the_package_limit_is_refused(): void
    {
        // Package allows 2 branches; the shop already has Main Shop + Gulberg.
        [$companyId, , ] = $this->seedTwoBranchShop([], ['branch_limit' => 2]);
        $owner = $this->makeUser($companyId);

        $this->assertFalse(PlanLimitService::canAddBranch($companyId)['allowed']);

        $response = $this->actingAs($owner, 'pos')->post('/pos/branches', ['name' => 'Johar Town']);
        $response->assertSessionHas('error');
        $this->assertSame(2, DB::table('branches')->where('company_id', $companyId)->count(),
            'a third branch must not be created beyond the package limit');
    }

    public function test_branch_within_the_package_limit_is_created(): void
    {
        [$companyId, , ] = $this->seedTwoBranchShop([], ['branch_limit' => 3]);
        $owner = $this->makeUser($companyId);

        $response = $this->actingAs($owner, 'pos')->post('/pos/branches', ['name' => 'Johar Town']);

        $response->assertSessionHas('success');
        $this->assertSame(3, DB::table('branches')->where('company_id', $companyId)->count());
        $this->assertTrue((bool) DB::table('branches')
            ->where('company_id', $companyId)->where('name', 'Johar Town')->value('is_active'));
    }
}
