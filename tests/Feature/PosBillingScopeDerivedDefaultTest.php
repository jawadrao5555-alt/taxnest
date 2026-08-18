<?php

namespace Tests\Feature;

use App\Models\PosTransaction;
use App\Models\User;
use App\Services\PosFeatureService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Task #1186 — Cashier derived-default billing scope.
 *
 * Owner decision: every pos_cashier with an UNSET pos_billing_scope column
 * sees ONLY their own stream by default — praReportingEnabled → 'pra',
 * otherwise 'local'. Explicit saved values always win ('both' = owner's OFF
 * switch). The derived scope governs VISIBILITY + RETURNS only; sale-time
 * write guards and the setCashierPra/togglePra weld-locks stay on the
 * EXPLICIT column value (posBillingScopeExplicit).
 *
 * Locked in this suite:
 *   1. Derived resolution: reporting ON → 'pra', OFF → 'local'; explicit
 *      both/local/pra win; managers/owners never derive.
 *   2. Own-bill exemption (derived scope ONLY): created_by == viewer rows are
 *      always visible/printable/returnable; explicit scopes stay strict.
 *   3. Reporting flips on an unset-scope cashier never hit the weld error.
 *   4. Return guard streams: cross-stream 403, own-stream + own-bill pass.
 *   5. Team save paths: 'auto' input writes NULL (back to derived default).
 *
 * Schema/fixtures cloned from PosBillingScopeGuardsTest (same style as
 * PosMonthlyBillQuotaPathsTest — each suite owns its minimal schema).
 */
class PosBillingScopeDerivedDefaultTest extends TestCase
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

        // ── Task 1186 additions on top of the guards-test schema ────────────
        // setCashierPra's enable path checks company ntn + integration mode;
        // the return flow gate needs transaction_type / returned_quantity.
        Schema::table('companies', function (Blueprint $table) {
            $table->string('ntn')->nullable();
            $table->string('pos_integration_mode')->default('pra');
        });
        Schema::table('pos_transactions', function (Blueprint $table) {
            $table->string('transaction_type')->default('sale');
            $table->unsignedBigInteger('return_of_transaction_id')->nullable();
        });
        Schema::table('pos_transaction_items', function (Blueprint $table) {
            $table->decimal('returned_quantity', 12, 3)->nullable();
        });

        // ── List-surface additions (Transactions / Reports / Day Close render) ──
        // Day Close for a cashier needs the company switch column; the page
        // itself needs opening-cash, tax-rule, product and notification tables
        // (mirrors PosDayCloseStrandedBannerTest's minimal schema).
        Schema::table('companies', function (Blueprint $table) {
            $table->boolean('pos_cashier_dayclose')->default(false);
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
        Schema::create('pos_tax_rules', function (Blueprint $t) {
            $t->id();
            $t->string('payment_method');
            $t->decimal('tax_rate', 8, 2)->default(0);
            $t->boolean('is_active')->default(true);
            $t->timestamps();
        });
        DB::table('pos_tax_rules')->insert([
            ['payment_method' => 'cash',       'tax_rate' => 0, 'is_active' => true, 'created_at' => now(), 'updated_at' => now()],
            ['payment_method' => 'debit_card', 'tax_rate' => 0, 'is_active' => true, 'created_at' => now(), 'updated_at' => now()],
        ]);
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
            // Mirror the PosTransaction creating hook: 00:00–cutoff belongs to
            // YESTERDAY's business day. Stamping plain today made this suite
            // fail whenever it ran between midnight and 06:00 (day-close and
            // report pages resolve the business day via PosBusinessDay too).
            'business_date'  => \App\Services\PosBusinessDay::forMoment($companyId, now()),
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
    // Coverage 1 — derived resolution (User::posBillingScope effective value)
    // ════════════════════════════════════════════════════════════════════════

    public function test_unset_cashier_derives_local_when_reporting_off(): void
    {
        $cid  = $this->makeCompany(); // company reporting OFF
        $user = $this->makeUser($cid); // pos_billing_scope NULL, own flag NULL

        $this->assertSame('local', $user->posBillingScope(),
            'unset cashier with reporting OFF must derive the local stream');
        $this->assertTrue($user->posBillingScopeIsDerived());
        $this->assertSame('both', $user->posBillingScopeExplicit(),
            'explicit scope must stay both — write/weld guards see no lock');
    }

    public function test_unset_cashier_derives_pra_when_reporting_on(): void
    {
        // Own flag ON
        $cid  = $this->makeCompany();
        $user = $this->makeUser($cid, ['pra_reporting_enabled' => true]);
        $this->assertSame('pra', $user->posBillingScope(),
            'unset cashier with own reporting ON must derive the PRA stream');

        // Company flag inherited (own flag NULL)
        $cid2  = $this->makeCompany(['pra_reporting_enabled' => true]);
        $user2 = $this->makeUser($cid2);
        $this->assertSame('pra', $user2->posBillingScope(),
            'unset cashier inheriting company reporting ON must derive pra');
    }

    public function test_explicit_values_always_win(): void
    {
        $cid = $this->makeCompany(['pra_reporting_enabled' => true]);

        $both = $this->makeUser($cid, ['pos_billing_scope' => 'both']);
        $this->assertSame('both', $both->posBillingScope(),
            "explicit 'both' = owner's OFF switch — never derived");
        $this->assertFalse($both->posBillingScopeIsDerived());

        $local = $this->makeUser($cid, ['pos_billing_scope' => 'local', 'pra_reporting_enabled' => true]);
        $this->assertSame('local', $local->posBillingScope(),
            'explicit local wins over reporting ON');

        $pra = $this->makeUser($cid, ['pos_billing_scope' => 'pra', 'pra_reporting_enabled' => false]);
        $this->assertSame('pra', $pra->posBillingScope(),
            'explicit pra wins over reporting OFF');
    }

    public function test_managers_and_owners_never_derive(): void
    {
        $cid = $this->makeCompany(['pra_reporting_enabled' => true]);

        $manager = $this->makeUser($cid, ['pos_role' => 'pos_manager']);
        $this->assertSame('both', $manager->posBillingScope(),
            'unset manager keeps both (Task-705 default rides posHidesLocalStream, not scope)');
        $this->assertFalse($manager->posBillingScopeIsDerived());

        $owner = $this->makeOwner($cid);
        $this->assertSame('both', $owner->posBillingScope());
        $this->assertFalse($owner->posBillingScopeIsDerived());
    }

    // ════════════════════════════════════════════════════════════════════════
    // Coverage 2 — own-bill exemption (model + transactionShow/receipt HTTP)
    // ════════════════════════════════════════════════════════════════════════

    public function test_own_bill_exemption_applies_only_to_derived_scope(): void
    {
        $cid = $this->makeCompany();

        // Derived-'pra' cashier: own local bill allowed, someone else's not.
        $derived = $this->makeUser($cid, ['pra_reporting_enabled' => true]);
        $ownLocal   = PosTransaction::withoutGlobalScope('hide_archived')->find($this->makeTxn($cid, ['created_by' => $derived->id]));
        $otherLocal = PosTransaction::withoutGlobalScope('hide_archived')->find($this->makeTxn($cid, ['created_by' => $derived->id + 900]));
        $this->assertTrue($ownLocal->allowedForBillingScopeOf($derived),
            'derived-pra cashier must always see their OWN local bill');
        $this->assertFalse($otherLocal->allowedForBillingScopeOf($derived),
            "someone else's local bill stays hidden from a derived-pra cashier");

        // Explicit 'pra' lock: strict — even the cashier's own local bill is blocked.
        $locked = $this->makeUser($cid, ['pos_billing_scope' => 'pra', 'pra_reporting_enabled' => true]);
        $ownLockedLocal = PosTransaction::withoutGlobalScope('hide_archived')->find($this->makeTxn($cid, ['created_by' => $locked->id]));
        $this->assertFalse($ownLockedLocal->allowedForBillingScopeOf($locked),
            'explicit pra lock keeps its strict behavior — no own-bill exemption');
    }

    public function test_derived_cashier_can_view_and_print_own_cross_stream_bill(): void
    {
        $cid  = $this->makeCompany();
        $user = $this->makeUser($cid, ['pra_reporting_enabled' => true]); // derived 'pra'
        $ownLocalId = $this->makeTxn($cid, ['created_by' => $user->id]);

        $this->assertNotEquals(403,
            $this->actingAs($user, 'pos')->get("/pos/transaction/{$ownLocalId}")->getStatusCode(),
            'own cross-stream bill must open (receipt popup right after an F10 save)');
        $this->assertNotEquals(403,
            $this->actingAs($user, 'pos')->get("/pos/transaction/{$ownLocalId}/receipt")->getStatusCode(),
            'own cross-stream bill must print');
    }

    public function test_derived_cashier_403_on_others_cross_stream_bill(): void
    {
        $cid  = $this->makeCompany(); // reporting OFF → derived 'local'
        $user = $this->makeUser($cid);
        $praId = $this->makePraTxn($cid); // created_by NULL — not theirs

        $this->actingAs($user, 'pos')
            ->get("/pos/transaction/{$praId}")
            ->assertStatus(403);
    }

    // ════════════════════════════════════════════════════════════════════════
    // Coverage 3 — apiTodaysBills under the derived scope
    // ════════════════════════════════════════════════════════════════════════

    public function test_api_todays_bills_derived_scope_filters_with_own_bill_exemption(): void
    {
        $cid   = $this->makeCompany();
        $user  = $this->makeUser($cid, ['pra_reporting_enabled' => true]); // derived 'pra'
        $today = \App\Services\PosBusinessDay::current($cid);

        $praId        = $this->makePraTxn($cid, ['business_date' => $today]);
        $othersLocal  = $this->makeTxn($cid, ['business_date' => $today]);
        $ownLocal     = $this->makeTxn($cid, ['business_date' => $today, 'created_by' => $user->id]);

        $response = $this->actingAs($user, 'pos')
            ->getJson('/pos/api/todays-bills')
            ->assertOk();

        $ids = collect($response->json('bills'))->pluck('id')->all();
        $this->assertContains($praId, $ids, 'derived-pra cashier sees the PRA stream');
        $this->assertNotContains($othersLocal, $ids, "someone else's local bill stays hidden");
        $this->assertContains($ownLocal, $ids, 'own local bill (F10 provisional) must stay in the Reprint list');
    }

    // ════════════════════════════════════════════════════════════════════════
    // Coverage 4 — return guard streams (returnForm)
    // Cashiers may return by default (owner rule 18 Aug 2026 — no Custom
    // Access set → returnsAllowed true), so only the scope gate is under test.
    // ════════════════════════════════════════════════════════════════════════

    public function test_return_form_derived_scope_blocks_cross_stream(): void
    {
        $cid  = $this->makeCompany(); // derived 'local'
        $user = $this->makeUser($cid);
        $praId = $this->makePraTxn($cid);

        $this->actingAs($user, 'pos')
            ->get("/pos/transaction/{$praId}/return")
            ->assertStatus(403);
    }

    public function test_return_form_derived_scope_allows_own_stream_and_own_bill(): void
    {
        $cid  = $this->makeCompany(); // derived 'local'
        $user = $this->makeUser($cid);

        // Own-stream bill (someone else's local bill) — scope allows.
        $localId = $this->makeTxn($cid);
        $this->assertNotEquals(403,
            $this->actingAs($user, 'pos')->get("/pos/transaction/{$localId}/return")->getStatusCode(),
            'derived-local cashier must reach the return form for a local bill');

        // Own cross-stream bill — own-bill exemption lets the return through.
        $ownPraId = $this->makePraTxn($cid, ['created_by' => $user->id]);
        $this->assertNotEquals(403,
            $this->actingAs($user, 'pos')->get("/pos/transaction/{$ownPraId}/return")->getStatusCode(),
            'own cross-stream bill must stay returnable under the derived scope');
    }

    // ════════════════════════════════════════════════════════════════════════
    // Coverage 5 — reporting flips never weld-403 an unset-scope cashier
    // ════════════════════════════════════════════════════════════════════════

    public function test_set_cashier_pra_flip_free_for_unset_scope(): void
    {
        $cid     = $this->makeCompany(['ntn' => '1234567-8']);
        $owner   = $this->makeOwner($cid);
        $cashier = $this->makeUser($cid); // NULL scope → derived

        // OFF → ON
        $this->actingAs($owner, 'pos')
            ->post("/pos/team/cashier/{$cashier->id}/pra", ['enabled' => 1])
            ->assertSessionHas('success');
        $this->assertTrue((bool) DB::table('users')->where('id', $cashier->id)->value('pra_reporting_enabled'));

        // ON → OFF
        $this->actingAs($owner, 'pos')
            ->post("/pos/team/cashier/{$cashier->id}/pra", ['enabled' => 0])
            ->assertSessionHas('success');
        $this->assertFalse((bool) DB::table('users')->where('id', $cashier->id)->value('pra_reporting_enabled'));
    }

    public function test_set_cashier_pra_weld_still_blocks_explicit_lock(): void
    {
        $cid     = $this->makeCompany(['ntn' => '1234567-8']);
        $owner   = $this->makeOwner($cid);
        $cashier = $this->makeUser($cid, ['pos_billing_scope' => 'local', 'pra_reporting_enabled' => false]);

        $this->actingAs($owner, 'pos')
            ->post("/pos/team/cashier/{$cashier->id}/pra", ['enabled' => 1])
            ->assertSessionHas('error');
        $this->assertFalse((bool) DB::table('users')->where('id', $cashier->id)->value('pra_reporting_enabled'),
            'explicit local lock must keep the weld — reporting stays OFF');
    }

    // ════════════════════════════════════════════════════════════════════════
    // Coverage 6 — Team save paths: 'auto' writes NULL (back to derived)
    // ════════════════════════════════════════════════════════════════════════

    public function test_update_cashier_auto_writes_null(): void
    {
        $cid     = $this->makeCompany();
        $owner   = $this->makeOwner($cid);
        $cashier = $this->makeUser($cid, ['pos_billing_scope' => 'local', 'pra_reporting_enabled' => false]);

        $this->actingAs($owner, 'pos')->put("/pos/team/cashier/{$cashier->id}", [
            'name'              => 'Back To Auto',
            'email'             => $cashier->email,
            'phone'             => '',
            'pos_billing_scope' => 'auto',
        ]);

        $fresh = DB::table('users')->where('id', $cashier->id)->first();
        $this->assertNull($fresh->pos_billing_scope,
            "'auto' must clear the column — cashier returns to the derived default");
        $this->assertSame(0, (int) $fresh->pra_reporting_enabled,
            "'auto' must never weld-flip the reporting flag");
    }

    public function test_store_cashier_auto_leaves_column_null(): void
    {
        $cid   = $this->makeCompany();
        $owner = $this->makeOwner($cid);

        $this->actingAs($owner, 'pos')->post('/pos/team/cashier', [
            'name'              => 'Auto Cashier',
            'email'             => 'auto-cashier@scope.test',
            'phone'             => '',
            'password'          => 'Secret@12345',
            'pos_role'          => 'pos_cashier',
            'pos_billing_scope' => 'auto',
        ]);

        $created = DB::table('users')->where('company_id', $cid)
            ->where('pos_role', 'pos_cashier')->orderByDesc('id')->first();
        $this->assertNotNull($created, 'cashier row must be created');
        $this->assertNull($created->pos_billing_scope,
            "'auto' at creation must leave the column NULL (derived default)");
    }

    // ════════════════════════════════════════════════════════════════════════
    // Coverage 7 — own-bill union on the LIST surfaces (Transactions /
    // Reports / Day Close): a derived viewer's own cross-stream rows stay
    // visible; other users' cross-stream rows never leak; explicit scopes
    // stay strict.
    // ════════════════════════════════════════════════════════════════════════

    public function test_transactions_list_unions_own_cross_stream_bill_for_derived_cashier(): void
    {
        $cid = $this->makeCompany(['pra_reporting_enabled' => true]);
        $this->subscribe($cid);
        $cashier = $this->makeUser($cid); // unset scope + reporting ON → derived 'pra'
        $other   = $this->makeUser($cid);

        $this->makeTxn($cid, ['invoice_number' => 'L-OWN-UNION-77', 'created_by' => $cashier->id]);
        $this->makeTxn($cid, ['invoice_number' => 'L-OTHER-UNION-88', 'created_by' => $other->id]);
        $this->makePraTxn($cid, ['invoice_number' => 'POS-OWN-UNION-99', 'created_by' => $cashier->id]);

        $resp = $this->actingAs($cashier->fresh(), 'pos')->get('/pos/transactions');
        $resp->assertStatus(200);
        // Own local (cross-stream) bill joins the forced PRA tab…
        $resp->assertSee('L-OWN-UNION-77');
        // …own in-stream bill unchanged…
        $resp->assertSee('POS-OWN-UNION-99');
        // …but another user's local bill never leaks into the PRA tab.
        $resp->assertDontSee('L-OTHER-UNION-88');
    }

    public function test_transactions_list_explicit_scope_stays_strict(): void
    {
        $cid = $this->makeCompany(['pra_reporting_enabled' => true]);
        $this->subscribe($cid);
        // Owner-locked explicit 'pra' — the old strict behavior must survive.
        $cashier = $this->makeUser($cid, ['pos_billing_scope' => 'pra']);

        $this->makeTxn($cid, ['invoice_number' => 'L-OWN-STRICT-55', 'created_by' => $cashier->id]);
        $this->makePraTxn($cid, ['invoice_number' => 'POS-OWN-STRICT-66', 'created_by' => $cashier->id]);

        $resp = $this->actingAs($cashier->fresh(), 'pos')->get('/pos/transactions');
        $resp->assertStatus(200);
        $resp->assertSee('POS-OWN-STRICT-66');
        // Explicit lock = strict: even the cashier's OWN cross-stream bill stays out.
        $resp->assertDontSee('L-OWN-STRICT-55');
    }

    public function test_reports_daily_sales_include_own_cross_stream_revenue_for_derived_cashier(): void
    {
        $cid = $this->makeCompany(['pra_reporting_enabled' => true]);
        $this->subscribe($cid);
        $cashier = $this->makeUser($cid); // derived 'pra'
        $other   = $this->makeUser($cid);

        // Own PRA bill 116 + own local bill 50 → derived union counts BOTH.
        $this->makePraTxn($cid, ['created_by' => $cashier->id]); // total 116
        $this->makeTxn($cid, ['created_by' => $cashier->id, 'total_amount' => 50]);
        // Another user's local bill must never join this cashier's figures
        // (cashier reports force created_by = self anyway — belt and braces).
        $this->makeTxn($cid, ['created_by' => $other->id, 'total_amount' => 999]);

        $resp = $this->actingAs($cashier->fresh(), 'pos')->get('/pos/reports');
        $resp->assertStatus(200);
        $revenue = (float) collect($resp->viewData('dailySales'))->sum('revenue');
        $this->assertSame(166.0, $revenue,
            'derived cashier reports must total own PRA (116) + own local (50) rows only');
    }

    public function test_day_close_includes_own_cross_stream_bill_and_return_for_derived_cashier(): void
    {
        $cid = $this->makeCompany([
            'pra_reporting_enabled' => true,
            'pos_cashier_dayclose'  => true, // company switch: cashier may open Day Close
        ]);
        $this->subscribe($cid);
        $cashier = $this->makeUser($cid); // derived 'pra'
        $other   = $this->makeUser($cid);

        $ownLocal   = $this->makeTxn($cid, ['invoice_number' => 'L-DC-OWN-1', 'created_by' => $cashier->id]);
        $otherLocal = $this->makeTxn($cid, ['invoice_number' => 'L-DC-OTHER-2', 'created_by' => $other->id]);
        $ownPra     = $this->makePraTxn($cid, ['invoice_number' => 'POS-DC-OWN-3', 'created_by' => $cashier->id]);
        // Own local RETURN row — must survive the return-detail audit filter.
        $ownReturn  = $this->makeTxn($cid, [
            'invoice_number'   => 'RET-DC-OWN-4',
            'created_by'       => $cashier->id,
            'transaction_type' => 'return',
            'total_amount'     => 20,
        ]);

        $resp = $this->actingAs($cashier->fresh(), 'pos')->get('/pos/day-close');
        $resp->assertStatus(200);

        $ids = collect($resp->viewData('transactions'))->pluck('id')->all();
        $this->assertContains($ownLocal, $ids, 'own cross-stream bill must join the day-close set');
        $this->assertContains($ownPra, $ids, 'own in-stream bill unchanged');
        $this->assertNotContains($otherLocal, $ids, "another user's local bill must stay out of a derived-pra day close");

        $returnIds = collect($resp->viewData('dcReturnDetail'))->pluck('id')->all();
        $this->assertContains($ownReturn, $returnIds, 'own cross-stream return must stay in the audit detail');
    }
}
