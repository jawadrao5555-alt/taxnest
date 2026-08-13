<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\PosFeatureService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Task 664 review — Sales Analytics dashboard is a PLAN ENTITLEMENT
 * (pricing_plans.analytics_enabled, Business+ since Aug 2026), not just the
 * PDF export:
 *
 *   1. Business subscriber GET /pos/reports → range analytics built and
 *      rendered (rangeAnalytics view data present, no locked card).
 *   2. Starter subscriber GET /pos/reports → rangeAnalytics is NULL (the
 *      data is never even built — no leak), locked upgrade card renders.
 *   3. Starter subscriber GET /pos/reports/analytics-pdf → redirected away
 *      (planGate), never receives the PDF.
 *
 * Pattern: APP_ENV=testing + sqlite :memory: + minimal Schema::create,
 * schema copied from PosBillingScopeGuardsTest (which exercises the same
 * /pos/reports route).
 */
class PosAnalyticsPlanGateTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Flush static caches that persist across tests in the same PHP process.
        PosFeatureService::flushGateCaches();
        User::flushScopeColumnCache(); // User::posBillingScope() caches column existence; reset so fresh schema is re-probed

        Schema::dropAllTables();

        // ── companies ────────────────────────────────────────────────────────
        Schema::create('companies', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('product_type')->nullable();
            $table->string('status')->nullable();
            $table->boolean('is_internal_account')->default(false);
            $table->boolean('pra_reporting_enabled')->default(false);
            $table->boolean('agent_enabled')->default(false);
            $table->string('pra_connection_mode')->nullable();
            $table->string('pra_environment')->nullable();
            $table->string('pra_pos_id')->nullable();
            $table->text('pra_production_token')->nullable();
            $table->string('pra_proxy_url')->nullable();
            $table->decimal('pos_tax_rate_cash', 8, 2)->nullable();
            $table->decimal('pos_tax_rate_card', 8, 2)->nullable();
            $table->string('pos_business_day_cutoff')->nullable();
            $table->boolean('inventory_enabled')->default(false);
            $table->boolean('pos_tax_inclusive')->default(false);
            $table->string('pos_tax_pricing_mode')->nullable();
            $table->text('feature_flags')->nullable();
            $table->integer('invoice_limit_override')->nullable();
            $table->integer('user_limit_override')->nullable();
            // Billing scope feature columns
            $table->boolean('billing_scope_admin_enabled')->default(false);
            // Bill Number Style columns (for bill_token tests)
            $table->string('local_number_style', 10)->default('serial');
            $table->string('pra_number_style', 10)->default('serial');
            $table->integer('bill_token_counter_local')->default(0);
            $table->date('bill_token_date_local')->nullable();
            $table->integer('bill_token_counter_pra')->default(0);
            $table->date('bill_token_date_pra')->nullable();
            $table->softDeletes();
            $table->timestamps();
        });

        // ── users ────────────────────────────────────────────────────────────
        Schema::create('users', function (Blueprint $table) {
            $table->id();
            $table->string('name')->nullable();
            $table->string('email')->nullable()->unique();
            $table->string('phone')->nullable(); // updateCashier updates phone
            $table->string('password')->nullable();
            $table->unsignedBigInteger('company_id')->nullable();
            $table->string('role')->nullable();
            $table->string('pos_role')->nullable();
            $table->boolean('is_active')->default(true);
            $table->string('language')->nullable();
            $table->boolean('pra_reporting_enabled')->nullable(); // NULL = inherit company flag
            $table->string('pos_billing_scope', 10)->nullable();  // the column under test
            $table->text('pos_team_password_enc')->nullable();
            $table->rememberToken();
            $table->timestamps();
        });

        // ── pos_transactions ─────────────────────────────────────────────────
        Schema::create('pos_transactions', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('company_id');
            $table->unsignedBigInteger('branch_id')->nullable();
            $table->unsignedBigInteger('terminal_id')->nullable();
            $table->unsignedBigInteger('customer_id')->nullable();
            $table->string('invoice_number');
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
            $table->decimal('cash_received', 12, 2)->nullable();
            $table->decimal('change_due', 12, 2)->nullable();
            $table->string('submission_hash')->nullable();
            $table->string('offline_uuid')->nullable()->unique();
            $table->boolean('tax_inclusive')->default(false);
            $table->decimal('tax_menu_rate', 8, 2)->nullable();
            $table->text('notes')->nullable();
            // Draft lock columns (updateCashier / storeInvoice draft-resume clears these)
            $table->unsignedBigInteger('locked_by_terminal_id')->nullable();
            $table->timestamp('lock_time')->nullable();
            // Bill Number Style
            $table->integer('bill_token')->nullable();
            $table->timestamps();
        });

        // ── pos_transaction_items ────────────────────────────────────────────
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
            $table->decimal('subtotal', 12, 2)->default(0);
            $table->boolean('is_tax_exempt')->default(false);
            $table->decimal('tax_rate', 8, 2)->nullable();
            $table->decimal('tax_amount', 12, 2)->nullable();
            $table->string('item_discount_type')->nullable();
            $table->decimal('item_discount_value', 12, 2)->nullable();
            $table->decimal('item_discount_amount', 12, 2)->nullable();
            $table->timestamps();
        });

        // ── pos_payments ─────────────────────────────────────────────────────
        Schema::create('pos_payments', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('transaction_id');
            $table->string('payment_method')->nullable();
            $table->decimal('amount', 12, 2)->default(0);
            $table->string('reference_number')->nullable();
            $table->timestamps();
        });

        // ── pra_logs ─────────────────────────────────────────────────────────
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

        // ── pos_terminals ─────────────────────────────────────────────────────
        // Required for the with('terminal') eager-load in transactionShow / receipt
        Schema::create('pos_terminals', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('company_id');
            $table->string('name');
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        // ── branches ─────────────────────────────────────────────────────────
        Schema::create('branches', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('company_id');
            $table->string('name');
            $table->boolean('is_active')->default(true);
            $table->boolean('is_head_office')->default(false);
            $table->timestamps();
        });

        // ── pricing_plans + subscriptions ────────────────────────────────────
        // Needed for plan.limit:invoices middleware (storeInvoice) and
        // PlanLimitService::canAddPosUser (storeCashier quota check).
        Schema::create('pricing_plans', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('product_type')->default('pos');
            $table->boolean('is_trial')->default(false);
            $table->integer('invoice_limit')->nullable();
            $table->integer('user_limit')->nullable();
            $table->boolean('restaurant_enabled')->default(false);
            $table->boolean('analytics_enabled')->default(false); // the gate under test
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

        // Needed by the reports() waiter-attribution lookup (Task 647 tests).
        Schema::create('restaurant_orders', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('company_id');
            $table->unsignedBigInteger('pos_transaction_id')->nullable();
            $table->string('order_number')->nullable();
            $table->unsignedInteger('token_no')->nullable();
            $table->string('source')->nullable();
            $table->string('status')->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamps();
        });

        // Needed by PlanLimitService::canCreatePosBill (day-close deleted finals).
        Schema::create('pos_day_close_reports', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('company_id');
            $table->date('report_date');
            $table->integer('deleted_final_count')->default(0);
            $table->timestamps();
        });
    }

    // ════════════════════════════════════════════════════════════════════════
    // Fixtures
    // ════════════════════════════════════════════════════════════════════════

    private static int $userSeq = 0;

    /** Reporting-OFF POS company that passes PosAuth + company.approval checks. */
    private function makeCompany(array $attrs = []): int
    {
        return (int) DB::table('companies')->insertGetId(array_merge([
            'name'                         => 'Analytics Gate Co',
            'product_type'                 => 'pos',
            'status'                       => 'active',
            'is_internal_account'          => false,
            'pra_reporting_enabled'        => false,
            'agent_enabled'                => false,
            'pra_connection_mode'          => 'cloud',
            'pra_environment'              => 'sandbox',
            'inventory_enabled'            => false,
            'pos_tax_rate_cash'            => 16.00,
            'pos_tax_rate_card'            => 16.00,
            'invoice_limit_override'       => -1,
            'user_limit_override'          => -1,
            'billing_scope_admin_enabled'  => false,
            'local_number_style'           => 'serial',
            'pra_number_style'             => 'serial',
            'bill_token_counter_local'     => 0,
            'bill_token_counter_pra'       => 0,
            'created_at'                   => now(),
            'updated_at'                   => now(),
        ], $attrs));
    }

    private function makeOwner(int $companyId): User
    {
        $seq = ++self::$userSeq;
        $id = DB::table('users')->insertGetId([
            'name'       => 'Owner',
            'email'      => "owner{$seq}@analyticsgate.test",
            'password'   => bcrypt('Secret@12345'),
            'company_id' => $companyId,
            'role'       => 'user',
            'pos_role'   => 'pos_admin',
            'is_active'  => true,
            'language'   => 'en',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return User::find($id);
    }

    /** Active paid subscription on a plan with the given gate columns. */
    private function subscribe(int $companyId, array $planAttrs = []): void
    {
        $planId = (int) DB::table('pricing_plans')->insertGetId(array_merge([
            'name'          => 'Business',
            'product_type'  => 'pos',
            'is_trial'      => false,
            'invoice_limit' => -1,
            'created_at'    => now(),
            'updated_at'    => now(),
        ], $planAttrs));
        DB::table('subscriptions')->insert([
            'company_id'      => $companyId,
            'pricing_plan_id' => $planId,
            'active'          => true,
            'start_date'      => now()->subMonth()->toDateString(),
            'end_date'        => now()->addMonth()->toDateString(),
            'override_type'   => 'none',
            'created_at'      => now(),
            'updated_at'      => now(),
        ]);
    }

    private function makeTxn(int $companyId): void
    {
        DB::table('pos_transactions')->insert([
            'company_id'     => $companyId,
            'invoice_number' => 'L-' . rand(100, 999),
            'business_date'  => now()->toDateString(),
            'status'         => 'completed',
            'invoice_mode'   => 'local',
            'pra_status'     => 'local',
            'subtotal'       => 100,
            'tax_rate'       => 0,
            'tax_amount'     => 0,
            'total_amount'   => 100,
            'payment_method' => 'cash',
            'created_at'     => now(),
            'updated_at'     => now(),
        ]);
    }

    // ════════════════════════════════════════════════════════════════════════
    // Tests
    // ════════════════════════════════════════════════════════════════════════

    public function test_business_subscriber_receives_the_analytics_dashboard(): void
    {
        $cid   = $this->makeCompany();
        $owner = $this->makeOwner($cid);
        $this->subscribe($cid, ['name' => 'Business', 'analytics_enabled' => 1]);
        $this->makeTxn($cid);

        $response = $this->actingAs($owner, 'pos')->get('/pos/reports');
        $response->assertOk();

        $this->assertNotNull($response->viewData('rangeAnalytics'),
            'Business plan must receive the built range analytics');
        $response->assertDontSee(__('pos.plan_locked_feature'));
    }

    public function test_starter_subscriber_gets_locked_card_and_no_analytics_data(): void
    {
        $cid   = $this->makeCompany();
        $owner = $this->makeOwner($cid);
        $this->subscribe($cid, ['name' => 'Starter', 'analytics_enabled' => 0]);
        $this->makeTxn($cid);

        $response = $this->actingAs($owner, 'pos')->get('/pos/reports');
        $response->assertOk();

        $this->assertNull($response->viewData('rangeAnalytics'),
            'Starter plan must NEVER receive analytics data — not even hidden in the view');
        $response->assertSee(__('pos.plan_locked_feature'));
        $response->assertSee(__('pos.upgrade_plan_btn'));
    }

    public function test_starter_subscriber_blocked_from_analytics_pdf(): void
    {
        $cid   = $this->makeCompany();
        $owner = $this->makeOwner($cid);
        $this->subscribe($cid, ['name' => 'Starter', 'analytics_enabled' => 0]);

        $response = $this->actingAs($owner, 'pos')->get('/pos/reports/analytics-pdf');

        $response->assertRedirect(route('pos.billing'));
        $response->assertSessionHas('error');
    }

    public function test_active_trial_receives_the_analytics_dashboard(): void
    {
        $cid   = $this->makeCompany();
        $owner = $this->makeOwner($cid);
        // Trial plan columns stay false by convention; isTrialActive unlocks.
        $planId = (int) DB::table('pricing_plans')->insertGetId([
            'name' => 'Trial', 'product_type' => 'pos', 'is_trial' => true,
            'analytics_enabled' => 0,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('subscriptions')->insert([
            'company_id' => $cid, 'pricing_plan_id' => $planId, 'active' => true,
            'start_date' => now()->subDay()->toDateString(),
            'end_date' => now()->addMonth()->toDateString(),
            'trial_ends_at' => now()->addDays(5), 'override_type' => 'none',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $response = $this->actingAs($owner, 'pos')->get('/pos/reports');
        $response->assertOk();
        $this->assertNotNull($response->viewData('rangeAnalytics'),
            'active trial must evaluate the analytics dashboard');
    }
}
