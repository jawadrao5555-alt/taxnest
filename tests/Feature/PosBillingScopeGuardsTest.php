<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\PosFeatureService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Task #325 — POS Billing Scope Guards: server-side invariants test-locked.
 *
 * users.pos_billing_scope locks a staff account to one stream:
 *   'local' = offline/local bills only (no PRA pipeline access)
 *   'pra'   = PRA-reporting bills only (no local/provisional access)
 *   'both'  = default (no restriction)
 *
 * Locked in this suite:
 *   1. billingScopeAllowsRow — transactionShow / receipt / downloadInvoicePdf
 *      (cross-stream access = 403, own-stream access = not-403)
 *   2. retryPra / bulkRetryPra / apiRetryFailed blocked for local-scoped users
 *   3. apiTodaysBills returns only rows within the user's scope
 *   4. apiFailedBills returns empty for local-scoped users (PRA-pipeline rows hidden)
 *   5. Draft-resume never overwrites an already-frozen bill_token
 *   6. pos_billing_scope input in storeCashier/updateCashier is gated by
 *      canManageBillingScope (owner-only unless billing_scope_admin_enabled=true)
 *
 * Pattern: APP_ENV=testing + sqlite :memory: + minimal Schema::create,
 * same style as PosMonthlyBillQuotaPathsTest.
 */
class PosBillingScopeGuardsTest extends TestCase
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
            $table->boolean('pos_cashier_own_sales_only')->nullable();
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
        Schema::create('pos_products', function (Blueprint $table) {
            $table->id(); $table->unsignedBigInteger('company_id'); $table->string('name');
            $table->decimal('price', 12, 2); $table->decimal('stock_quantity', 12, 3)->nullable();
            $table->boolean('is_active')->default(true); $table->boolean('is_tax_exempt')->default(false);
            $table->boolean('is_third_schedule')->default(false); $table->timestamps();
        });
        Schema::create('pos_deals', function (Blueprint $table) {
            $table->id(); $table->unsignedBigInteger('company_id'); $table->string('name'); $table->decimal('price', 12, 2);
            $table->string('deal_type')->default('regular'); $table->boolean('is_active')->default(true);
            $table->date('starts_on')->nullable(); $table->date('ends_on')->nullable();
            $table->time('special_start_time')->nullable(); $table->time('special_end_time')->nullable();
            $table->json('active_days')->nullable(); $table->unsignedInteger('total_deal_units_limit')->nullable();
            $table->unsignedInteger('daily_deal_units_limit')->nullable(); $table->timestamps();
        });
        Schema::create('pos_deal_items', function (Blueprint $table) {
            $table->id(); $table->unsignedBigInteger('deal_id'); $table->unsignedBigInteger('pos_product_id');
            $table->unsignedInteger('quantity')->default(1); $table->timestamps();
        });
        Schema::create('pos_deal_usages', function (Blueprint $table) {
            $table->id(); $table->unsignedBigInteger('company_id'); $table->unsignedBigInteger('deal_id');
            $table->date('usage_date'); $table->unsignedInteger('units_used')->default(0); $table->timestamps();
            $table->unique(['company_id', 'deal_id', 'usage_date']);
        });
        Schema::create('inventory_stocks', function (Blueprint $table) {
            $table->id(); $table->unsignedBigInteger('company_id'); $table->unsignedBigInteger('product_id');
            $table->unsignedBigInteger('branch_id')->nullable(); $table->decimal('quantity', 12, 3);
            $table->timestamps();
        });
        Schema::create('ingredients', function (Blueprint $table) {
            $table->id(); $table->unsignedBigInteger('company_id'); $table->string('name'); $table->string('unit')->default('kg');
            $table->decimal('current_stock', 12, 4)->default(0); $table->decimal('cost_per_unit', 12, 2)->default(0);
            $table->boolean('is_active')->default(true); $table->timestamps();
        });
        Schema::create('product_recipes', function (Blueprint $table) {
            $table->id(); $table->unsignedBigInteger('company_id'); $table->unsignedBigInteger('product_id');
            $table->unsignedBigInteger('ingredient_id'); $table->decimal('quantity_needed', 12, 4);
            $table->unsignedInteger('recipe_version')->default(1); $table->boolean('is_active')->default(true); $table->timestamps();
        });
        Schema::create('inventory_movements', function (Blueprint $table) {
            $table->id(); $table->unsignedBigInteger('company_id'); $table->unsignedBigInteger('product_id');
            $table->unsignedBigInteger('branch_id')->nullable(); $table->string('type'); $table->decimal('quantity', 12, 4);
            $table->decimal('unit_price', 12, 2)->default(0); $table->decimal('total_price', 12, 2)->default(0);
            $table->decimal('balance_after', 12, 4); $table->string('reference_type')->nullable();
            $table->unsignedBigInteger('reference_id')->nullable(); $table->string('reference_number')->nullable();
            $table->text('notes')->nullable(); $table->unsignedBigInteger('created_by')->nullable(); $table->timestamps();
        });
        Schema::create('recipe_consumptions', function (Blueprint $table) {
            $table->id(); $table->unsignedBigInteger('company_id'); $table->unsignedBigInteger('transaction_id');
            $table->unsignedBigInteger('ingredient_id'); $table->unsignedBigInteger('branch_id')->nullable();
            $table->decimal('quantity', 12, 4); $table->json('components'); $table->json('snapshot');
            $table->string('invoice_number')->nullable(); $table->timestamps();
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
            'name'                         => 'Scope Test Co',
            // Shared shop: this suite pins billing-SCOPE semantics; cashier
            // sales isolation (default ON when column missing) must stay out.
            'pos_cashier_own_sales_only'   => false,
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
            'invoice_limit_override'       => -1, // unlimited bills
            'user_limit_override'          => -1, // unlimited users
            'billing_scope_admin_enabled'  => false,
            'local_number_style'           => 'serial',
            'pra_number_style'             => 'serial',
            'bill_token_counter_local'     => 0,
            'bill_token_date_local'        => null,
            'bill_token_counter_pra'       => 0,
            'bill_token_date_pra'          => null,
            'created_at'                   => now(),
            'updated_at'                   => now(),
        ], $attrs));
    }

    private function makeUser(int $companyId, array $attrs = []): User
    {
        $seq = ++self::$userSeq;
        $id = DB::table('users')->insertGetId(array_merge([
            'name'                  => 'Test User',
            'email'                 => "user{$seq}@scope.test",
            'password'              => bcrypt('Secret@12345'),
            'company_id'            => $companyId,
            'role'                  => 'employee',
            'pos_role'              => 'pos_cashier',
            'is_active'             => true,
            'language'              => 'en',
            'pra_reporting_enabled' => null, // inherit company flag
            'pos_billing_scope'     => null,  // 'both' by default
            'created_at'            => now(),
            'updated_at'            => now(),
        ], $attrs));

        return User::find($id);
    }

    private function makeOwner(int $companyId): User
    {
        return $this->makeUser($companyId, [
            'role'     => 'company_admin',
            'pos_role' => null,
        ]);
    }

    /** Active unlimited subscription so plan.limit:invoices never blocks. */
    private function subscribe(int $companyId): void
    {
        $planId = (int) DB::table('pricing_plans')->insertGetId([
            'name'         => 'Business',
            'product_type' => 'pos',
            'is_trial'     => false,
            'invoice_limit' => -1,
            'user_limit'   => null,
            'deals_enabled' => true,
            'created_at'   => now(),
            'updated_at'   => now(),
        ]);
        DB::table('subscriptions')->insert([
            'company_id'       => $companyId,
            'pricing_plan_id'  => $planId,
            'active'           => true,
            'start_date'       => now()->subMonth()->toDateString(),
            'end_date'         => now()->addMonth()->toDateString(),
            'override_type'    => 'none',
            'created_at'       => now(),
            'updated_at'       => now(),
        ]);
    }

    public function test_owner_can_choose_automatic_daily_local_number_without_rewinding_the_serial(): void
    {
        $companyId = $this->makeCompany([
            'local_number_style' => 'serial',
            'bill_token_counter_local' => 42,
        ]);
        $owner = $this->makeOwner($companyId);

        $this->actingAs($owner, 'pos')
            ->postJson('/pos/settings/local-billing/number-style', ['style' => 'daily'])
            ->assertOk()
            ->assertJson([
                'success' => true,
                'style' => 'daily',
            ]);

        $company = DB::table('companies')->where('id', $companyId)->first();
        $this->assertSame('daily', $company->local_number_style);
        $this->assertSame(42, (int) $company->bill_token_counter_local, 'Changing display style must not rewind a counter');
    }

    public function test_cashier_cannot_change_local_number_style_and_invalid_styles_are_rejected(): void
    {
        $companyId = $this->makeCompany();
        $cashier = $this->makeUser($companyId);
        $owner = $this->makeOwner($companyId);

        $this->actingAs($cashier, 'pos')
            ->postJson('/pos/settings/local-billing/number-style', ['style' => 'daily'])
            ->assertForbidden();

        $this->actingAs($owner, 'pos')
            ->postJson('/pos/settings/local-billing/number-style', ['style' => 'reset'])
            ->assertUnprocessable();

        $this->assertSame('serial', DB::table('companies')->where('id', $companyId)->value('local_number_style'));
    }

    /**
     * Insert a completed POS transaction.
     * 'local' stream: invoice_mode='local', pra_status='local' (provisional)
     * 'pra' stream:   invoice_mode='pra',   pra_status='pending' (PRA pipeline)
     */
    private function makeTxn(int $companyId, array $attrs = []): int
    {
        return (int) DB::table('pos_transactions')->insertGetId(array_merge([
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
        ], $attrs));
    }

    private function makePraTxn(int $companyId, array $overrides = []): int
    {
        return $this->makeTxn($companyId, array_merge([
            'invoice_number' => 'POS-' . rand(1000, 9999),
            'invoice_mode'   => 'pra',
            'pra_status'     => 'pending',
            'tax_rate'       => 16,
            'tax_amount'     => 16,
            'total_amount'   => 116,
        ], $overrides));
    }

    // ════════════════════════════════════════════════════════════════════════
    // Coverage 1 — billingScopeAllowsRow
    // Routes: GET /pos/transaction/{id}   (transactionShow)
    //         GET /pos/transaction/{id}/receipt
    //         GET /pos/transaction/{id}/pdf
    // ════════════════════════════════════════════════════════════════════════

    public function test_local_scoped_user_403_on_pra_transaction_show(): void
    {
        $cid  = $this->makeCompany();
        $user = $this->makeUser($cid, ['pos_billing_scope' => 'local']);
        $txId = $this->makePraTxn($cid);

        $this->actingAs($user, 'pos')
            ->get("/pos/transaction/{$txId}")
            ->assertStatus(403);
    }

    public function test_local_scoped_user_403_on_pra_receipt(): void
    {
        $cid  = $this->makeCompany();
        $user = $this->makeUser($cid, ['pos_billing_scope' => 'local']);
        $txId = $this->makePraTxn($cid);

        $this->actingAs($user, 'pos')
            ->get("/pos/transaction/{$txId}/receipt")
            ->assertStatus(403);
    }

    public function test_local_scoped_user_403_on_pra_pdf(): void
    {
        $cid  = $this->makeCompany();
        $user = $this->makeUser($cid, ['pos_billing_scope' => 'local']);
        $txId = $this->makePraTxn($cid);

        $this->actingAs($user, 'pos')
            ->get("/pos/transaction/{$txId}/pdf")
            ->assertStatus(403);
    }

    public function test_pra_scoped_user_403_on_local_transaction_show(): void
    {
        $cid  = $this->makeCompany();
        $user = $this->makeUser($cid, ['pos_billing_scope' => 'pra']);
        $txId = $this->makeTxn($cid); // local bill

        $this->actingAs($user, 'pos')
            ->get("/pos/transaction/{$txId}")
            ->assertStatus(403);
    }

    public function test_pra_scoped_user_403_on_local_receipt(): void
    {
        $cid  = $this->makeCompany();
        $user = $this->makeUser($cid, ['pos_billing_scope' => 'pra']);
        $txId = $this->makeTxn($cid);

        $this->actingAs($user, 'pos')
            ->get("/pos/transaction/{$txId}/receipt")
            ->assertStatus(403);
    }

    public function test_pra_scoped_user_403_on_local_pdf(): void
    {
        $cid  = $this->makeCompany();
        $user = $this->makeUser($cid, ['pos_billing_scope' => 'pra']);
        $txId = $this->makeTxn($cid);

        $this->actingAs($user, 'pos')
            ->get("/pos/transaction/{$txId}/pdf")
            ->assertStatus(403);
    }

    /** Local-scoped user must NOT be 403-blocked on their own stream's bill. */
    public function test_local_scoped_user_can_access_local_bill(): void
    {
        $cid  = $this->makeCompany();
        $user = $this->makeUser($cid, ['pos_billing_scope' => 'local']);
        $txId = $this->makeTxn($cid); // local bill

        // The scope guard passes; whatever happens next (view render etc.) is
        // not a 403. We only verify the guard did NOT fire.
        $resp = $this->actingAs($user, 'pos')->get("/pos/transaction/{$txId}");
        $this->assertNotEquals(403, $resp->getStatusCode(),
            'local-scoped user must not be 403-blocked on a local bill');
    }

    /** PRA-scoped user must NOT be 403-blocked on a PRA stream bill. */
    public function test_pra_scoped_user_can_access_pra_bill(): void
    {
        $cid  = $this->makeCompany();
        $user = $this->makeUser($cid, ['pos_billing_scope' => 'pra']);
        $txId = $this->makePraTxn($cid);

        $resp = $this->actingAs($user, 'pos')->get("/pos/transaction/{$txId}");
        $this->assertNotEquals(403, $resp->getStatusCode(),
            'pra-scoped user must not be 403-blocked on a PRA bill');
    }

    /** Explicit both-scoped user can access bills of either stream.
     *  Task 1186: NULL scope now DERIVES from reporting status — explicit
     *  'both' is the owner's OFF switch that keeps the unrestricted view. */
    public function test_both_scoped_user_can_access_any_bill(): void
    {
        $cid      = $this->makeCompany();
        $user     = $this->makeUser($cid, ['pos_billing_scope' => 'both']);
        $localId  = $this->makeTxn($cid);
        $praId    = $this->makePraTxn($cid);

        $this->assertNotEquals(403,
            $this->actingAs($user, 'pos')->get("/pos/transaction/{$localId}")->getStatusCode(),
            'both-scoped user must not be blocked on a local bill');

        $this->assertNotEquals(403,
            $this->actingAs($user, 'pos')->get("/pos/transaction/{$praId}")->getStatusCode(),
            'both-scoped user must not be blocked on a PRA bill');
    }

    // ════════════════════════════════════════════════════════════════════════
    // Coverage 2 — retryPra / bulkRetryPra / apiRetryFailed
    // A local-scoped user must never touch the PRA submission pipeline.
    // We set pra_reporting_enabled=true on the user so the *first* check in
    // bulkRetryPra / apiRetryFailed (reporting OFF → 422/back) is bypassed,
    // and we confirm it is the *billing scope* guard that fires.
    // ════════════════════════════════════════════════════════════════════════

    public function test_local_scoped_user_blocked_on_retry_pra(): void
    {
        $cid  = $this->makeCompany();
        // retryPra checks billing scope FIRST (before PRA reporting check)
        $user = $this->makeUser($cid, [
            'pos_billing_scope'     => 'local',
            'pra_reporting_enabled' => true,
        ]);
        $txId = $this->makeTxn($cid, [
            'invoice_mode' => 'local',
            'pra_status'   => 'local',
        ]);

        $response = $this->actingAs($user, 'pos')
            ->post("/pos/transaction/{$txId}/retry-pra");

        // retryPra returns back()->with('error', ...) for a local-scope block
        $response->assertRedirect();
        $response->assertSessionHas('error');
    }

    public function test_local_scoped_user_blocked_on_bulk_retry_pra(): void
    {
        $cid  = $this->makeCompany();
        // bulkRetryPra checks praReportingEnabled FIRST; give it true so only
        // the billing scope check remains.
        $user = $this->makeUser($cid, [
            'pos_billing_scope'     => 'local',
            'pra_reporting_enabled' => true,
        ]);
        // Create a bill that would normally be retried
        $this->makePraTxn($cid, ['pra_status' => 'failed', 'pra_invoice_number' => null]);

        $response = $this->actingAs($user, 'pos')
            ->post('/pos/transactions/bulk-retry-pra');

        $response->assertRedirect();
        $response->assertSessionHas('error');
    }

    public function test_local_scoped_user_blocked_on_api_retry_failed(): void
    {
        $cid  = $this->makeCompany(['pra_reporting_enabled' => true]);
        // apiRetryFailed checks praReportingEnabled FIRST; user inherits company ON flag.
        $user = $this->makeUser($cid, [
            'pos_billing_scope'     => 'local',
            'pra_reporting_enabled' => true,
        ]);
        $txId = $this->makePraTxn($cid, ['pra_status' => 'failed', 'pra_invoice_number' => null]);

        $this->actingAs($user, 'pos')
            ->postJson("/pos/api/failed-bills/{$txId}/retry")
            ->assertStatus(403)
            ->assertJson(['success' => false]);
    }

    // ════════════════════════════════════════════════════════════════════════
    // Coverage 3 — apiTodaysBills scope filtering
    // GET /pos/api/todays-bills
    // ════════════════════════════════════════════════════════════════════════

    private function seedTodayBills(int $cid): array
    {
        // Use PosBusinessDay::current() — NOT now()->toDateString() — so the seeded
        // business_date matches exactly what apiTodaysBills queries. The controller
        // filters on PosBusinessDay::current($companyId), which returns yesterday
        // when the Karachi clock is before the 06:00 cutoff and yesterday isn't
        // day-closed. Using now()->toDateString() caused a time-of-day mismatch
        // (pre-existing failure on origin/main; proved by 0-line git diff on all
        // three affected files between fd6aadc and HEAD).
        $today = \App\Services\PosBusinessDay::current($cid);
        // Local bill: invoice_mode='local', pra_status='local'
        $localId = $this->makeTxn($cid, ['business_date' => $today]);
        // PRA bill: invoice_mode='pra', pra_status='pending'
        $praId = $this->makePraTxn($cid, ['business_date' => $today]);
        return [$localId, $praId];
    }

    public function test_api_todays_bills_local_scope_excludes_pra_bills(): void
    {
        $cid  = $this->makeCompany();
        [$localId, $praId] = $this->seedTodayBills($cid);
        $user = $this->makeUser($cid, ['pos_billing_scope' => 'local']);

        $response = $this->actingAs($user, 'pos')
            ->getJson('/pos/api/todays-bills')
            ->assertOk()
            ->assertJsonStructure(['success', 'count', 'bills']);

        $ids = collect($response->json('bills'))->pluck('id')->all();
        $this->assertContains($localId, $ids, 'local bill must appear for local-scoped user');
        $this->assertNotContains($praId, $ids, 'PRA bill must not appear for local-scoped user');
    }

    public function test_api_todays_bills_pra_scope_excludes_local_bills(): void
    {
        $cid  = $this->makeCompany();
        [$localId, $praId] = $this->seedTodayBills($cid);
        $user = $this->makeUser($cid, ['pos_billing_scope' => 'pra']);

        $response = $this->actingAs($user, 'pos')
            ->getJson('/pos/api/todays-bills')
            ->assertOk();

        $ids = collect($response->json('bills'))->pluck('id')->all();
        $this->assertNotContains($localId, $ids, 'local bill must not appear for pra-scoped user');
        $this->assertContains($praId, $ids, 'PRA bill must appear for pra-scoped user');
    }

    public function test_api_todays_bills_both_scope_returns_all(): void
    {
        $cid  = $this->makeCompany();
        [$localId, $praId] = $this->seedTodayBills($cid);
        // Task 1186: explicit 'both' (NULL now derives from reporting status)
        $user = $this->makeUser($cid, ['pos_billing_scope' => 'both']);

        $response = $this->actingAs($user, 'pos')
            ->getJson('/pos/api/todays-bills')
            ->assertOk();

        $ids = collect($response->json('bills'))->pluck('id')->all();
        $this->assertContains($localId, $ids, 'both-scoped user should see local bill');
        $this->assertContains($praId, $ids, 'both-scoped user should see PRA bill');
    }

    // ════════════════════════════════════════════════════════════════════════
    // Coverage 4 — apiFailedBills scope filtering
    // GET /pos/api/failed-bills
    // ════════════════════════════════════════════════════════════════════════

    public function test_api_failed_bills_local_scope_returns_empty(): void
    {
        $cid  = $this->makeCompany();
        // Seed a failed PRA bill
        $this->makePraTxn($cid, ['pra_status' => 'failed', 'pra_invoice_number' => null]);
        $user = $this->makeUser($cid, ['pos_billing_scope' => 'local']);

        $this->actingAs($user, 'pos')
            ->getJson('/pos/api/failed-bills')
            ->assertOk()
            ->assertJson(['success' => true, 'count' => 0, 'bills' => []]);
    }

    public function test_api_failed_bills_pra_scope_returns_pra_bills(): void
    {
        $cid  = $this->makeCompany();
        $this->makePraTxn($cid, ['pra_status' => 'failed', 'pra_invoice_number' => null]);
        $user = $this->makeUser($cid, ['pos_billing_scope' => 'pra']);

        $response = $this->actingAs($user, 'pos')
            ->getJson('/pos/api/failed-bills')
            ->assertOk();

        $this->assertGreaterThan(0, $response->json('count'),
            'pra-scoped user must see failed PRA bills');
    }

    public function test_api_failed_bills_both_scope_returns_pra_bills(): void
    {
        $cid  = $this->makeCompany();
        $this->makePraTxn($cid, ['pra_status' => 'offline', 'pra_invoice_number' => null]);
        // Task 1186: explicit 'both' (NULL now derives from reporting status)
        $user = $this->makeUser($cid, ['pos_billing_scope' => 'both']);

        $response = $this->actingAs($user, 'pos')
            ->getJson('/pos/api/failed-bills')
            ->assertOk();

        $this->assertGreaterThan(0, $response->json('count'),
            'both-scoped user must see failed/offline PRA bills');
    }

    // ════════════════════════════════════════════════════════════════════════
    // Coverage 5 — Draft-resume must not overwrite an existing bill_token
    // POST /pos/invoice/store (with draft_id)
    //
    // When local_number_style='token' and the bill_token counters exist, a
    // fresh storeInvoice would normally allocate the next token. When resuming
    // a draft that already has a bill_token frozen, the existing token MUST be
    // preserved — reprints must always show the same number that was issued at
    // sale time.
    // ════════════════════════════════════════════════════════════════════════

    public function test_draft_resume_does_not_overwrite_existing_bill_token(): void
    {
        $cid = $this->makeCompany([
            'pra_reporting_enabled'    => false,
            'local_number_style'       => 'token', // triggers nextBillToken allocation
            'bill_token_counter_local' => 0,        // counter starts at 0 → next = 1
            'bill_token_date_local'    => null,
        ]);
        $this->subscribe($cid);
        $owner = $this->makeOwner($cid);

        // Create a draft transaction with bill_token=42 already frozen
        $draftId = (int) DB::table('pos_transactions')->insertGetId([
            'company_id'     => $cid,
            'invoice_number' => 'L-DRAFT',
            'business_date'  => now()->toDateString(),
            'status'         => 'draft',
            'invoice_mode'   => 'local',
            'pra_status'     => null,
            'bill_token'     => 42, // frozen at draft-save time
            'subtotal'       => 0,
            'discount_type'  => 'amount',
            'discount_value' => 0,
            'discount_amount'=> 0,
            'tax_rate'       => 0,
            'tax_amount'     => 0,
            'total_amount'   => 0,
            'payment_method' => null,
            'created_at'     => now(),
            'updated_at'     => now(),
        ]);

        $response = $this->actingAs($owner, 'pos')
            ->postJson('/pos/invoice/store', [
                'draft_id'       => $draftId,
                'items'          => [[
                    'type'         => 'product',
                    'name'         => 'Chai',
                    'quantity'     => 1,
                    'unit_price'   => 100,
                    'is_tax_exempt'=> false,
                    '_manual'      => 1,
                ]],
                'payment_method' => 'cash',
                'discount_type'  => 'amount',
                'discount_value' => 0,
            ]);

        $response->assertStatus(200)->assertJson(['success' => true]);

        $stored = DB::table('pos_transactions')->where('id', $draftId)->first();
        $this->assertSame(42, (int) $stored->bill_token,
            'draft-resume must preserve the originally frozen bill_token=42, not overwrite with counter token 1');
    }

    public function test_special_deal_stock_failure_returns_422_and_rolls_back_bill_quota_and_stock(): void
    {
        $cid = $this->makeCompany(['inventory_enabled' => true]);
        $this->subscribe($cid);
        $owner = $this->makeOwner($cid);
        $productId = (int) DB::table('pos_products')->insertGetId([
            'company_id' => $cid, 'name' => 'Limited Burger', 'price' => 100,
            'stock_quantity' => 99, 'is_active' => true, 'is_tax_exempt' => false,
            'is_third_schedule' => false, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $dealId = (int) DB::table('pos_deals')->insertGetId([
            'company_id' => $cid, 'name' => 'Lunch Special', 'price' => 100,
            'deal_type' => 'special', 'is_active' => true, 'starts_on' => now()->toDateString(),
            'ends_on' => now()->toDateString(), 'special_start_time' => '00:00',
            'special_end_time' => '23:59', 'total_deal_units_limit' => 1,
            'daily_deal_units_limit' => 1, 'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('pos_deal_items')->insert([
            'deal_id' => $dealId, 'pos_product_id' => $productId, 'quantity' => 1,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('inventory_stocks')->insert([
            'company_id' => $cid, 'product_id' => $productId, 'branch_id' => null,
            'quantity' => 0, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $payload = [
            'items' => [[
                'type' => 'deal', 'item_id' => $dealId, 'name' => 'Lunch Special',
                'quantity' => 1, 'unit_price' => 100, 'is_tax_exempt' => false,
                'deal_snapshot' => [[
                    'product_id' => $productId, 'name' => 'Limited Burger', 'qty' => 1,
                ]],
            ]],
            'payment_method' => 'cash', 'discount_type' => 'amount', 'discount_value' => 0,
        ];

        $this->actingAs($owner, 'pos')->postJson('/pos/invoice/store', $payload)
            ->assertUnprocessable()->assertJsonValidationErrors('items');

        $this->assertSame(0, DB::table('pos_transactions')->count());
        $this->assertSame(0, DB::table('pos_transaction_items')->count());
        $this->assertSame(0, DB::table('pos_payments')->count());
        $this->assertSame(0, DB::table('pos_deal_usages')->count());
        $this->assertSame(0.0, (float) DB::table('inventory_stocks')->value('quantity'));
        $this->assertSame(99.0, (float) DB::table('pos_products')->where('id', $productId)->value('stock_quantity'));

        DB::table('pos_deals')->where('id', $dealId)->update(['is_active' => false]);
        $this->actingAs($owner, 'pos')->postJson('/pos/invoice/store', $payload)
            ->assertUnprocessable()->assertJsonValidationErrors('items');
        $this->assertSame(0, DB::table('pos_transactions')->count());
        $this->assertSame(0, DB::table('pos_deal_usages')->count());
    }

    public function test_special_deal_commits_frozen_recipe_when_live_recipe_changes_after_quota_reservation(): void
    {
        $cid = $this->makeCompany(['inventory_enabled' => true]);
        $this->subscribe($cid);
        $owner = $this->makeOwner($cid);
        $productId = (int) DB::table('pos_products')->insertGetId([
            'company_id' => $cid, 'name' => 'Recipe Meal', 'price' => 200,
            'stock_quantity' => null, 'is_active' => true, 'is_tax_exempt' => false,
            'is_third_schedule' => false, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $a = (int) DB::table('ingredients')->insertGetId([
            'company_id' => $cid, 'name' => 'Ingredient A', 'unit' => 'kg',
            'current_stock' => 10, 'is_active' => true, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $b = (int) DB::table('ingredients')->insertGetId([
            'company_id' => $cid, 'name' => 'Ingredient B', 'unit' => 'kg',
            'current_stock' => 10, 'is_active' => true, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $recipeId = (int) DB::table('product_recipes')->insertGetId([
            'company_id' => $cid, 'product_id' => $productId, 'ingredient_id' => $a,
            'quantity_needed' => 2, 'recipe_version' => 1, 'is_active' => true,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $dealId = (int) DB::table('pos_deals')->insertGetId([
            'company_id' => $cid, 'name' => 'Frozen Recipe Special', 'price' => 200,
            'deal_type' => 'special', 'is_active' => true, 'starts_on' => now()->toDateString(),
            'ends_on' => now()->toDateString(), 'special_start_time' => '00:00', 'special_end_time' => '23:59',
            'total_deal_units_limit' => 1, 'daily_deal_units_limit' => 1,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('pos_deal_items')->insert([
            'deal_id' => $dealId, 'pos_product_id' => $productId, 'quantity' => 1,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        \Illuminate\Support\Facades\Event::listen(
            'eloquent.created: App\Models\PosDealUsage',
            function ($usage) use ($dealId, $recipeId, $b): void {
                if ((int) $usage->deal_id === $dealId) {
                    DB::table('product_recipes')->where('id', $recipeId)->update([
                        'ingredient_id' => $b, 'quantity_needed' => 3,
                        'recipe_version' => 2, 'updated_at' => now(),
                    ]);
                }
            }
        );

        $this->actingAs($owner, 'pos')->postJson('/pos/invoice/store', [
            'items' => [[
                'type' => 'deal', 'item_id' => $dealId, 'name' => 'Frozen Recipe Special',
                'quantity' => 1, 'unit_price' => 200, 'is_tax_exempt' => false,
                // Legacy/simple browser snapshot is accepted and upgraded.
                'deal_snapshot' => [['product_id' => $productId, 'name' => 'Recipe Meal', 'qty' => 1]],
            ]],
            'payment_method' => 'cash', 'discount_type' => 'amount', 'discount_value' => 0,
        ])->assertOk()->assertJson(['success' => true]);

        $this->assertSame(8.0, (float) DB::table('ingredients')->where('id', $a)->value('current_stock'));
        $this->assertSame(10.0, (float) DB::table('ingredients')->where('id', $b)->value('current_stock'));
        $snapshot = json_decode((string) DB::table('recipe_consumptions')->value('snapshot'), true);
        $this->assertSame($a, $snapshot[0]['ingredient_id']);
        $this->assertSame(1, DB::table('pos_deal_usages')->value('units_used'));
        $this->assertSame(1, DB::table('pos_transactions')->count());
        $this->assertSame(1, DB::table('pos_transaction_items')->count());
        $this->assertSame(1, DB::table('pos_payments')->count());
    }

    public function test_special_deal_keeps_frozen_direct_mode_when_recipe_is_added_after_quota_reservation(): void
    {
        $cid = $this->makeCompany(['inventory_enabled' => true]);
        $this->subscribe($cid);
        $owner = $this->makeOwner($cid);
        $productId = (int) DB::table('pos_products')->insertGetId([
            'company_id' => $cid, 'name' => 'Ready Snack', 'price' => 150,
            'stock_quantity' => 5, 'is_active' => true, 'is_tax_exempt' => false,
            'is_third_schedule' => false, 'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('inventory_stocks')->insert([
            'company_id' => $cid, 'product_id' => $productId, 'branch_id' => null,
            'quantity' => 5, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $ingredientId = (int) DB::table('ingredients')->insertGetId([
            'company_id' => $cid, 'name' => 'Late Recipe Ingredient', 'unit' => 'kg',
            'current_stock' => 10, 'is_active' => true, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $dealId = (int) DB::table('pos_deals')->insertGetId([
            'company_id' => $cid, 'name' => 'Frozen Direct Special', 'price' => 150,
            'deal_type' => 'special', 'is_active' => true, 'starts_on' => now()->toDateString(),
            'ends_on' => now()->toDateString(), 'special_start_time' => '00:00', 'special_end_time' => '23:59',
            'total_deal_units_limit' => 1, 'daily_deal_units_limit' => 1,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('pos_deal_items')->insert([
            'deal_id' => $dealId, 'pos_product_id' => $productId, 'quantity' => 1,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        \Illuminate\Support\Facades\Event::listen(
            'eloquent.created: App\Models\PosDealUsage',
            function ($usage) use ($dealId, $cid, $productId, $ingredientId): void {
                if ((int) $usage->deal_id === $dealId) {
                    DB::table('product_recipes')->insert([
                        'company_id' => $cid, 'product_id' => $productId,
                        'ingredient_id' => $ingredientId, 'quantity_needed' => 2,
                        'recipe_version' => 1, 'is_active' => true,
                        'created_at' => now(), 'updated_at' => now(),
                    ]);
                }
            }
        );

        $this->actingAs($owner, 'pos')->postJson('/pos/invoice/store', [
            'items' => [[
                'type' => 'deal', 'item_id' => $dealId, 'name' => 'Frozen Direct Special',
                'quantity' => 1, 'unit_price' => 150, 'is_tax_exempt' => false,
                'deal_snapshot' => [['product_id' => $productId, 'name' => 'Ready Snack', 'qty' => 1]],
            ]],
            'payment_method' => 'cash', 'discount_type' => 'amount', 'discount_value' => 0,
        ])->assertOk()->assertJson(['success' => true]);

        $this->assertSame(4.0, (float) DB::table('inventory_stocks')->where('product_id', $productId)->value('quantity'));
        $this->assertSame(4.0, (float) DB::table('pos_products')->where('id', $productId)->value('stock_quantity'));
        $this->assertSame(10.0, (float) DB::table('ingredients')->where('id', $ingredientId)->value('current_stock'));
        $this->assertSame(0, DB::table('recipe_consumptions')->count());
        $this->assertSame(1, DB::table('inventory_movements')->where('type', 'sale')->count());
        $this->assertSame(1, DB::table('pos_deal_usages')->value('units_used'));
    }

    // ════════════════════════════════════════════════════════════════════════
    // Coverage 6 — storeCashier / updateCashier billing_scope input gating
    // POST /pos/team/cashier
    // PUT  /pos/team/cashier/{id}
    //
    // Owner-only rule (07 Aug 2026): pos_billing_scope is only accepted when
    // the submitter canManageBillingScope() — meaning the user is company_admin
    // OR (isPosAdmin + billing_scope_admin_enabled=true on the company).
    // Non-owner managers (isPosAdmin but not company_admin) have their input
    // silently ignored when billing_scope_admin_enabled=false.
    // ════════════════════════════════════════════════════════════════════════

    private function storeCashierPayload(array $overrides = []): array
    {
        static $seq = 0;
        return array_merge([
            'name'              => 'New Cashier',
            'email'             => 'cashier' . (++$seq) . '@scope.test',
            'phone'             => '',
            'password'          => 'Secret@12345',
            'pos_role'          => 'pos_cashier',
            'pos_billing_scope' => 'local',
        ], $overrides);
    }

    /**
     * A pos_manager (isPosAdmin=true but role!='company_admin') with
     * billing_scope_admin_enabled=false cannot set pos_billing_scope.
     * The request succeeds (cashier is created) but the scope is silently
     * ignored — the created user must have pos_billing_scope=NULL (='both').
     */
    public function test_store_cashier_scope_ignored_when_admin_perm_off(): void
    {
        $cid     = $this->makeCompany(['billing_scope_admin_enabled' => false]);
        $manager = $this->makeUser($cid, ['pos_role' => 'pos_manager', 'role' => 'employee']);

        $this->actingAs($manager, 'pos')
            ->post('/pos/team/cashier', $this->storeCashierPayload());

        $created = DB::table('users')->where('company_id', $cid)
            ->where('pos_role', 'pos_cashier')
            ->orderByDesc('id')
            ->first();

        $this->assertNotNull($created, 'cashier row must be created');
        $this->assertNull($created->pos_billing_scope,
            'manager without billing_scope_admin_enabled must not set pos_billing_scope on storeCashier');
    }

    /**
     * A pos_manager with billing_scope_admin_enabled=true CAN set pos_billing_scope.
     * The scope must be persisted on the new cashier row.
     *
     * NOTE: This test will FAIL if pos_billing_scope is not added to
     * User::$fillable, because User::create() silently drops non-fillable columns.
     * That is a genuine guard hole that must be fixed alongside this test.
     */
    public function test_store_cashier_scope_saved_when_admin_perm_on(): void
    {
        $cid     = $this->makeCompany(['billing_scope_admin_enabled' => true]);
        $manager = $this->makeUser($cid, ['pos_role' => 'pos_manager', 'role' => 'employee']);

        $this->actingAs($manager, 'pos')
            ->post('/pos/team/cashier', $this->storeCashierPayload(['pos_billing_scope' => 'local']));

        $created = DB::table('users')->where('company_id', $cid)
            ->where('pos_role', 'pos_cashier')
            ->orderByDesc('id')
            ->first();

        $this->assertNotNull($created, 'cashier row must be created');
        $this->assertSame('local', $created->pos_billing_scope,
            'manager with billing_scope_admin_enabled=true must be able to set pos_billing_scope on storeCashier');
    }

    /**
     * The company owner (role='company_admin') can always set pos_billing_scope
     * regardless of billing_scope_admin_enabled (canManageBillingScope always true).
     *
     * NOTE: Same fillable caveat as above applies.
     */
    public function test_store_cashier_scope_always_saved_for_owner(): void
    {
        $cid   = $this->makeCompany(['billing_scope_admin_enabled' => false]);
        $owner = $this->makeOwner($cid);

        $this->actingAs($owner, 'pos')
            ->post('/pos/team/cashier', $this->storeCashierPayload([
                'pos_billing_scope' => 'pra',
            ]));

        $created = DB::table('users')->where('company_id', $cid)
            ->where('pos_role', 'pos_cashier')
            ->orderByDesc('id')
            ->first();

        $this->assertNotNull($created, 'cashier row must be created');
        $this->assertSame('pra', $created->pos_billing_scope,
            'owner must always be able to set pos_billing_scope on storeCashier');
    }

    /**
     * updateCashier: manager with billing_scope_admin_enabled=false cannot
     * change an existing cashier's scope. The PUT succeeds (name/email updated)
     * but pos_billing_scope stays unchanged.
     */
    public function test_update_cashier_scope_ignored_when_admin_perm_off(): void
    {
        $cid     = $this->makeCompany(['billing_scope_admin_enabled' => false]);
        $manager = $this->makeUser($cid, ['pos_role' => 'pos_manager', 'role' => 'employee']);
        $cashier = $this->makeUser($cid, [
            'pos_role'          => 'pos_cashier',
            'pos_billing_scope' => null, // starts as 'both'
        ]);

        $this->actingAs($manager, 'pos')
            ->put("/pos/team/cashier/{$cashier->id}", [
                'name'              => 'Updated Name',
                'email'             => $cashier->email,
                'phone'             => '',
                'pos_billing_scope' => 'local', // manager tries to set scope
            ]);

        $fresh = DB::table('users')->where('id', $cashier->id)->first();
        $this->assertNull($fresh->pos_billing_scope,
            'manager without billing_scope_admin_enabled must not change pos_billing_scope on updateCashier');
    }

    /**
     * updateCashier: manager with billing_scope_admin_enabled=true CAN change scope.
     * updateCashier uses direct assignment ($cashier->pos_billing_scope = ...)
     * + save(), bypassing $fillable — so this should work correctly.
     */
    public function test_update_cashier_scope_saved_when_admin_perm_on(): void
    {
        $cid     = $this->makeCompany(['billing_scope_admin_enabled' => true]);
        $manager = $this->makeUser($cid, ['pos_role' => 'pos_manager', 'role' => 'employee']);
        $cashier = $this->makeUser($cid, [
            'pos_role'          => 'pos_cashier',
            'pos_billing_scope' => null,
        ]);

        $this->actingAs($manager, 'pos')
            ->put("/pos/team/cashier/{$cashier->id}", [
                'name'              => $cashier->name,
                'email'             => $cashier->email,
                'phone'             => '',
                'pos_billing_scope' => 'local',
            ]);

        $fresh = DB::table('users')->where('id', $cashier->id)->first();
        $this->assertSame('local', $fresh->pos_billing_scope,
            'manager with billing_scope_admin_enabled=true must be able to set pos_billing_scope on updateCashier');
    }

    /**
     * updateCashier: owner always saves pos_billing_scope.
     * Also verifies reporting alignment: 'local' scope forces pra_reporting_enabled=false.
     */
    public function test_update_cashier_scope_by_owner_also_aligns_reporting(): void
    {
        $cid     = $this->makeCompany(['billing_scope_admin_enabled' => false]);
        $owner   = $this->makeOwner($cid);
        $cashier = $this->makeUser($cid, [
            'pos_role'              => 'pos_cashier',
            'pos_billing_scope'     => null,
            'pra_reporting_enabled' => true, // currently PRA-reporting ON
        ]);

        $this->actingAs($owner, 'pos')
            ->put("/pos/team/cashier/{$cashier->id}", [
                'name'              => $cashier->name,
                'email'             => $cashier->email,
                'phone'             => '',
                'pos_billing_scope' => 'local', // lock to local stream
            ]);

        $fresh = DB::table('users')->where('id', $cashier->id)->first();
        $this->assertSame('local', $fresh->pos_billing_scope,
            'owner must be able to set pos_billing_scope to local');
        $this->assertSame(0, (int) $fresh->pra_reporting_enabled,
            "'local' scope lock must force pra_reporting_enabled=false (reporting alignment)");
    }

    // ════════════════════════════════════════════════════════════════════════
    // Coverage — Exempt stream isolation (Task 647)
    // exempt_internal bills are their OWN stream: excluded from the Local
    // Reports "Local Invoices" list even when invoice_mode='local' (data
    // drift), and never promotable via the Local-to-PRA retry action.
    // ════════════════════════════════════════════════════════════════════════

    public function test_reports_local_invoices_list_excludes_exempt_bills_regardless_of_mode(): void
    {
        $cid   = $this->makeCompany();
        $owner = $this->makeOwner($cid);
        $this->subscribe($cid);

        $provId = $this->makeTxn($cid, ['invoice_number' => 'L-PROV-1']);
        // The precedence/data-drift case: exempt status must win over local mode.
        $this->makeTxn($cid, [
            'invoice_number' => 'EX-LOCALMODE',
            'invoice_mode'   => 'local',
            'pra_status'     => 'exempt_internal',
        ]);
        $this->makeTxn($cid, [
            'invoice_number' => 'EX-PRAMODE',
            'invoice_mode'   => 'pra',
            'pra_status'     => 'exempt_internal',
        ]);

        $response = $this->actingAs($owner, 'pos')->get('/pos/reports?tab=local');
        $response->assertOk();

        $localBills = $response->viewData('localBills');
        $this->assertNotNull($localBills, 'local tab must expose the localBills list');
        $numbers = collect($localBills->items())->pluck('invoice_number')->all();

        $this->assertContains('L-PROV-1', $numbers, 'provisional stays in the Local Invoices list');
        $this->assertNotContains('EX-LOCALMODE', $numbers,
            'exempt_internal bill must NOT appear in Local Invoices even with invoice_mode=local');
        $this->assertNotContains('EX-PRAMODE', $numbers,
            'exempt_internal bill must NOT appear in Local Invoices');
    }

    public function test_retry_pra_refuses_exempt_bills_in_both_modes(): void
    {
        $cid   = $this->makeCompany();
        $owner = $this->makeOwner($cid);
        $this->subscribe($cid);

        foreach (['local', 'pra'] as $mode) {
            $billId = $this->makeTxn($cid, [
                'invoice_number' => "EX-RETRY-{$mode}",
                'invoice_mode'   => $mode,
                'pra_status'     => 'exempt_internal',
            ]);

            $response = $this->actingAs($owner, 'pos')
                ->from('/pos/reports?tab=local')
                ->post("/pos/transaction/{$billId}/retry-pra");

            $response->assertRedirect();
            $response->assertSessionHas('error');

            $fresh = DB::table('pos_transactions')->where('id', $billId)->first();
            $this->assertSame('exempt_internal', $fresh->pra_status,
                "retry-pra must never touch an exempt bill (mode={$mode})");
            $this->assertNull($fresh->pra_invoice_number,
                "exempt bill must never gain a fiscal number (mode={$mode})");
        }
    }
}
