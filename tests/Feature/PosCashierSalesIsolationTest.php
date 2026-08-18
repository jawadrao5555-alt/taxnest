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
 * Task #1197 — Per-cashier COMPLETE sales isolation (owner-approved strict
 * rule, DEFAULT ON): every pos_cashier sees ONLY bills they created — in the
 * Transactions list, the Reprint (todays-bills) list, the F10 provisional and
 * F11 failed lists, and every bill read guard. Managers and the owner keep
 * company-wide visibility plus a per-cashier filter. Owner-only Team-page
 * switch companies.pos_cashier_own_sales_only turns it OFF for a shop.
 *
 * Locked in this suite:
 *   1. Verdict: NULL column = ON (no backfill), switch OFF = shared, only
 *      pos_cashier isolates — managers/owners never.
 *   2. Row predicate: own row passes, other's blocked, NULL created_by is
 *      NEVER own (strict), non-isolated viewers always pass.
 *   3. Transactions page: isolated cashier sees own rows only; switch OFF
 *      restores shared; admin ?cashier=ID inspects one team member.
 *   4. Read guards: detail + receipt 403 on another cashier's bill (direct
 *      URL), own bill opens; return form follows the same rule.
 *   5. APIs: todays-bills / provisional-bills / failed-bills own-only.
 *   6. Toggle: owner-only (manager 403), persists the flip.
 *   7. Isolation ANDs with Billing Scope — never replaces it.
 *
 * Schema/fixtures cloned from PosBillingScopeDerivedDefaultTest (each suite
 * owns its minimal schema).
 */
class PosCashierSalesIsolationTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        PosFeatureService::flushGateCaches();
        User::flushScopeColumnCache();

        Schema::dropAllTables();

        // ── companies ────────────────────────────────────────────────────────
        Schema::create('companies', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('product_type')->nullable();
            $table->string('status')->nullable();
            $table->boolean('is_internal_account')->default(false);
            $table->boolean('pos_setup_completed')->default(true);
            $table->text('pos_printer_settings')->nullable();
            $table->timestamp('agent_last_seen')->nullable();
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
            $table->boolean('billing_scope_admin_enabled')->default(false);
            // The column under test — NULLABLE: a NULL row must read as ON
            // (default-ON without backfill, mirrors the real migration).
            $table->boolean('pos_cashier_own_sales_only')->nullable();
            $table->string('ntn')->nullable();
            $table->string('pos_integration_mode')->default('pra');
            $table->boolean('pos_cashier_dayclose')->default(false);
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
            $table->string('phone')->nullable();
            $table->string('password')->nullable();
            $table->unsignedBigInteger('company_id')->nullable();
            $table->string('role')->nullable();
            $table->string('pos_role')->nullable();
            $table->boolean('is_active')->default(true);
            $table->string('language')->nullable();
            $table->boolean('pra_reporting_enabled')->nullable();
            $table->string('pos_billing_scope', 10)->nullable();
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
            $table->text('pra_error_message')->nullable(); // apiFailedBills select
            $table->text('pra_qr_code')->nullable();
            $table->boolean('is_archived')->default(false);
            $table->timestamp('archived_at')->nullable();
            $table->unsignedBigInteger('archived_by_report_id')->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->string('customer_name')->nullable();
            $table->string('customer_phone')->nullable();
            $table->string('order_type')->nullable(); // todays-bills/provisional select
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
            $table->unsignedBigInteger('locked_by_terminal_id')->nullable();
            $table->timestamp('lock_time')->nullable();
            $table->integer('bill_token')->nullable();
            $table->string('transaction_type')->default('sale');
            $table->unsignedBigInteger('return_of_transaction_id')->nullable();
            $table->timestamp('receipt_printed_at')->nullable(); // receipt-printed write guard
            $table->integer('reprint_count')->default(0);
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
            $table->decimal('returned_quantity', 12, 3)->nullable();
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

        Schema::create('restaurant_orders', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('company_id');
            $table->unsignedBigInteger('pos_transaction_id')->nullable();
            $table->unsignedBigInteger('customer_id')->nullable();
            $table->string('order_number')->nullable();
            $table->unsignedInteger('token_no')->nullable();
            $table->string('source')->nullable();
            $table->string('status')->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamps();
        });

        Schema::create('pos_day_close_reports', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('company_id');
            $table->date('report_date');
            $table->integer('deleted_final_count')->default(0);
            $table->timestamps();
        });

        // Dashboard KPI coverage: the controller-invoke pattern touches these.
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

        Schema::create('pos_print_jobs', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('company_id');
            $table->string('type');
            $table->string('target_printer')->nullable();
            $table->string('device_uid')->nullable();
            $table->unsignedBigInteger('transaction_id')->nullable();
            $table->unsignedBigInteger('restaurant_order_id')->nullable();
            $table->text('render_query')->nullable();
            $table->string('status')->default('pending');
            $table->string('claim_token')->nullable();
            $table->text('printed_item_ids')->nullable();
            $table->text('error')->nullable();
            $table->integer('attempts')->default(0);
            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamps();
        });

        Schema::create('pos_customers', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('company_id');
            $table->string('name')->nullable();
            $table->string('phone')->nullable();
            $table->timestamps();
        });

        // setCashierOwnSales audits the flip.
        Schema::create('audit_logs', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('company_id')->nullable();
            $table->unsignedBigInteger('user_id')->nullable();
            $table->string('action')->nullable();
            $table->string('entity_type')->nullable();
            $table->unsignedBigInteger('entity_id')->nullable();
            $table->text('old_values')->nullable();
            $table->text('new_values')->nullable();
            $table->string('ip_address')->nullable();
            $table->string('sha256_hash')->nullable();
            $table->timestamps();
        });
    }

    // ════════════════════════════════════════════════════════════════════════
    // Fixtures
    // ════════════════════════════════════════════════════════════════════════

    private static int $userSeq = 0;

    private function makeCompany(array $attrs = []): int
    {
        return (int) DB::table('companies')->insertGetId(array_merge([
            'name'                        => 'Isolation Test Co',
            'product_type'                => 'pos',
            'status'                      => 'active',
            'is_internal_account'         => false,
            'pra_reporting_enabled'       => false,
            'agent_enabled'               => false,
            'pra_connection_mode'         => 'cloud',
            'pra_environment'             => 'sandbox',
            'inventory_enabled'           => false,
            'pos_tax_rate_cash'           => 16.00,
            'pos_tax_rate_card'           => 16.00,
            'invoice_limit_override'      => -1,
            'user_limit_override'         => -1,
            'billing_scope_admin_enabled' => false,
            // pos_cashier_own_sales_only deliberately ABSENT → NULL = default ON
            'created_at'                  => now(),
            'updated_at'                  => now(),
        ], $attrs));
    }

    private function makeUser(int $companyId, array $attrs = []): User
    {
        $seq = ++self::$userSeq;
        $id = DB::table('users')->insertGetId(array_merge([
            'name'                  => "Cashier {$seq}",
            'email'                 => "user{$seq}@iso.test",
            'password'              => bcrypt('Secret@12345'),
            'company_id'            => $companyId,
            'role'                  => 'employee',
            'pos_role'              => 'pos_cashier',
            'is_active'             => true,
            'language'              => 'en',
            'pra_reporting_enabled' => null,
            'pos_billing_scope'     => null,
            'created_at'            => now(),
            'updated_at'            => now(),
        ], $attrs));

        return User::find($id);
    }

    private function makeOwner(int $companyId): User
    {
        return $this->makeUser($companyId, ['role' => 'company_admin', 'pos_role' => null]);
    }

    private function makeManager(int $companyId): User
    {
        return $this->makeUser($companyId, ['pos_role' => 'pos_manager']);
    }

    /** Completed provisional bill (local stream) — the shared default fixture. */
    private function makeTxn(int $companyId, array $attrs = []): int
    {
        return (int) DB::table('pos_transactions')->insertGetId(array_merge([
            'company_id'     => $companyId,
            'invoice_number' => 'L-' . str_pad((string) rand(0, 99999), 5, '0', STR_PAD_LEFT),
            // Business day, not calendar day: 00:00–05:59 counts in YESTERDAY —
            // todays-bills filters on PosBusinessDay::current, so a plain
            // toDateString() fixture goes empty during that window (flake).
            'business_date'  => \App\Services\PosBusinessDay::current($companyId),
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
            'invoice_number' => 'POS-' . rand(10000, 99999),
            'invoice_mode'   => 'pra',
            'pra_status'     => 'pending',
            'tax_rate'       => 16,
            'tax_amount'     => 16,
            'total_amount'   => 116,
        ], $overrides));
    }

    // ════════════════════════════════════════════════════════════════════════
    // Coverage 1 — verdict (User::posSalesIsolated)
    // ════════════════════════════════════════════════════════════════════════

    public function test_null_column_means_default_on_for_cashiers_only(): void
    {
        $cid = $this->makeCompany(); // pos_cashier_own_sales_only = NULL

        $this->assertTrue($this->makeUser($cid)->posSalesIsolated(),
            'NULL switch = default ON — cashier must be isolated without backfill');
        $this->assertFalse($this->makeManager($cid)->posSalesIsolated(),
            'managers are never isolated');
        $this->assertFalse($this->makeOwner($cid)->posSalesIsolated(),
            'the owner is never isolated');
        $this->assertFalse($this->makeUser($cid, ['pos_role' => 'pos_admin'])->posSalesIsolated(),
            'pos_admin is never isolated');
    }

    public function test_switch_off_and_on_flip_the_verdict(): void
    {
        $off = $this->makeCompany(['pos_cashier_own_sales_only' => false]);
        $this->assertFalse($this->makeUser($off)->posSalesIsolated(),
            'switch OFF = shared visibility (purana behavior)');

        $on = $this->makeCompany(['pos_cashier_own_sales_only' => true]);
        $this->assertTrue($this->makeUser($on)->posSalesIsolated());
    }

    // ════════════════════════════════════════════════════════════════════════
    // Coverage 2 — row predicate (allowedForCashierIsolationOf)
    // ════════════════════════════════════════════════════════════════════════

    public function test_row_predicate_own_other_null_and_shared(): void
    {
        $cid     = $this->makeCompany();
        $cashier = $this->makeUser($cid);
        $peer    = $this->makeUser($cid);

        $own    = PosTransaction::withoutGlobalScope('hide_archived')->find($this->makeTxn($cid, ['created_by' => $cashier->id]));
        $others = PosTransaction::withoutGlobalScope('hide_archived')->find($this->makeTxn($cid, ['created_by' => $peer->id]));
        $orphan = PosTransaction::withoutGlobalScope('hide_archived')->find($this->makeTxn($cid, ['created_by' => null]));

        $this->assertTrue($own->allowedForCashierIsolationOf($cashier));
        $this->assertFalse($others->allowedForCashierIsolationOf($cashier));
        $this->assertFalse($orphan->allowedForCashierIsolationOf($cashier),
            'NULL created_by is NEVER own — an unattributed bill is no loophole');

        // Non-isolated viewers (manager / switch-OFF cashier) always pass.
        $this->assertTrue($others->allowedForCashierIsolationOf($this->makeManager($cid)));
        $offCid = $this->makeCompany(['pos_cashier_own_sales_only' => false]);
        $offRow = PosTransaction::withoutGlobalScope('hide_archived')->find($this->makeTxn($offCid, ['created_by' => 999]));
        $this->assertTrue($offRow->allowedForCashierIsolationOf($this->makeUser($offCid)));
    }

    // ════════════════════════════════════════════════════════════════════════
    // Coverage 3 — Transactions page
    // ════════════════════════════════════════════════════════════════════════

    public function test_transactions_page_isolated_cashier_sees_only_own(): void
    {
        $cid     = $this->makeCompany();
        $this->subscribe($cid);
        $cashier = $this->makeUser($cid);
        $peer    = $this->makeUser($cid);
        $this->makeTxn($cid, ['created_by' => $cashier->id, 'invoice_number' => 'L-MINE1']);
        $this->makeTxn($cid, ['created_by' => $peer->id, 'invoice_number' => 'L-PEER1']);

        $resp = $this->actingAs($cashier, 'pos')->get('/pos/transactions?tab=local');
        $resp->assertStatus(200);
        $resp->assertSee('L-MINE1');
        $resp->assertDontSee('L-PEER1');
    }

    public function test_transactions_page_switch_off_restores_shared(): void
    {
        $cid     = $this->makeCompany(['pos_cashier_own_sales_only' => false]);
        $this->subscribe($cid);
        $cashier = $this->makeUser($cid);
        $peer    = $this->makeUser($cid);
        $this->makeTxn($cid, ['created_by' => $cashier->id, 'invoice_number' => 'L-MINE2']);
        $this->makeTxn($cid, ['created_by' => $peer->id, 'invoice_number' => 'L-PEER2']);

        $resp = $this->actingAs($cashier, 'pos')->get('/pos/transactions?tab=local');
        $resp->assertStatus(200);
        $resp->assertSee('L-MINE2');
        $resp->assertSee('L-PEER2');
    }

    public function test_transactions_page_admin_cashier_filter(): void
    {
        $cid     = $this->makeCompany();
        $this->subscribe($cid);
        $owner   = $this->makeOwner($cid);
        $cashier = $this->makeUser($cid);
        $peer    = $this->makeUser($cid);
        $this->makeTxn($cid, ['created_by' => $cashier->id, 'invoice_number' => 'L-CASH3']);
        $this->makeTxn($cid, ['created_by' => $peer->id, 'invoice_number' => 'L-PEER3']);

        // Merged view (default) — owner sees everything.
        $all = $this->actingAs($owner, 'pos')->get('/pos/transactions?tab=local');
        $all->assertStatus(200);
        $all->assertSee('L-CASH3');
        $all->assertSee('L-PEER3');

        // Per-cashier inspection.
        $one = $this->actingAs($owner, 'pos')->get("/pos/transactions?tab=local&cashier={$cashier->id}");
        $one->assertStatus(200);
        $one->assertSee('L-CASH3');
        $one->assertDontSee('L-PEER3');
    }

    // ════════════════════════════════════════════════════════════════════════
    // Coverage 4 — read guards (detail / receipt / return, direct URL)
    // ════════════════════════════════════════════════════════════════════════

    public function test_read_guards_block_other_cashiers_bill(): void
    {
        $cid     = $this->makeCompany();
        $this->subscribe($cid);
        $cashier = $this->makeUser($cid);
        $peer    = $this->makeUser($cid);
        $ownId   = $this->makeTxn($cid, ['created_by' => $cashier->id]);
        $peerId  = $this->makeTxn($cid, ['created_by' => $peer->id]);

        $this->actingAs($cashier, 'pos')->get("/pos/transaction/{$peerId}")->assertStatus(403);
        $this->actingAs($cashier, 'pos')->get("/pos/transaction/{$peerId}/receipt")->assertStatus(403);
        $this->actingAs($cashier, 'pos')->get("/pos/transaction/{$peerId}/return")->assertStatus(403);

        $this->assertNotEquals(403,
            $this->actingAs($cashier, 'pos')->get("/pos/transaction/{$ownId}")->getStatusCode(),
            'own bill must still open');
        $this->assertNotEquals(403,
            $this->actingAs($cashier, 'pos')->get("/pos/transaction/{$ownId}/receipt")->getStatusCode(),
            'own bill must still print');
        $this->assertNotEquals(403,
            $this->actingAs($cashier, 'pos')->get("/pos/transaction/{$ownId}/return")->getStatusCode(),
            'own bill must still be returnable');
    }

    public function test_read_guards_shared_when_switch_off(): void
    {
        $cid     = $this->makeCompany(['pos_cashier_own_sales_only' => false]);
        $this->subscribe($cid);
        $cashier = $this->makeUser($cid);
        $peer    = $this->makeUser($cid);
        $peerId  = $this->makeTxn($cid, ['created_by' => $peer->id]);

        $this->assertNotEquals(403,
            $this->actingAs($cashier, 'pos')->get("/pos/transaction/{$peerId}")->getStatusCode(),
            "switch OFF restores the old shared behavior — peer's bill opens");
    }

    // ════════════════════════════════════════════════════════════════════════
    // Coverage 5 — sale-screen list APIs
    // ════════════════════════════════════════════════════════════════════════

    public function test_api_todays_bills_isolated_to_own(): void
    {
        $cid     = $this->makeCompany();
        $this->subscribe($cid);
        $cashier = $this->makeUser($cid);
        $peer    = $this->makeUser($cid);
        $ownId   = $this->makeTxn($cid, ['created_by' => $cashier->id]);
        $peerId  = $this->makeTxn($cid, ['created_by' => $peer->id]);

        $resp = $this->actingAs($cashier, 'pos')->get('/pos/api/todays-bills');
        $resp->assertStatus(200);
        $ids = collect($resp->json('bills'))->pluck('id')->all();
        $this->assertContains($ownId, $ids, 'own bill must appear in the Reprint list');
        $this->assertNotContains($peerId, $ids, "peer's bill must NOT appear in the Reprint list");
    }

    public function test_api_provisional_and_failed_bills_isolated_to_own(): void
    {
        $cid     = $this->makeCompany();
        $this->subscribe($cid);
        // Reporting ON: a reporting-OFF cashier derives the 'local' scope and
        // the F11 failed list is empty for them BY DESIGN (pre-existing scope
        // rule) — this test needs a derived-'pra' cashier to reach the list.
        $cashier = $this->makeUser($cid, ['pra_reporting_enabled' => true]);
        $peer    = $this->makeUser($cid, ['pra_reporting_enabled' => true]);

        // F10 provisional list (completed + local + local).
        $ownProv  = $this->makeTxn($cid, ['created_by' => $cashier->id]);
        $peerProv = $this->makeTxn($cid, ['created_by' => $peer->id]);
        $resp = $this->actingAs($cashier, 'pos')->get('/pos/api/provisional-bills');
        $resp->assertStatus(200);
        $ids = collect($resp->json('bills'))->pluck('id')->all();
        $this->assertContains($ownProv, $ids);
        $this->assertNotContains($peerProv, $ids);

        // F11 failed/queued list (pra_status failed/offline/pending, no fiscal no).
        $ownFail  = $this->makePraTxn($cid, ['created_by' => $cashier->id, 'pra_status' => 'failed']);
        $peerFail = $this->makePraTxn($cid, ['created_by' => $peer->id, 'pra_status' => 'failed']);
        $resp = $this->actingAs($cashier, 'pos')->get('/pos/api/failed-bills');
        $resp->assertStatus(200);
        $ids = collect($resp->json('bills'))->pluck('id')->all();
        $this->assertContains($ownFail, $ids);
        $this->assertNotContains($peerFail, $ids);
    }

    // ════════════════════════════════════════════════════════════════════════
    // Coverage 6 — owner-only toggle
    // ════════════════════════════════════════════════════════════════════════

    public function test_toggle_is_owner_only_and_persists(): void
    {
        $cid     = $this->makeCompany();
        $this->subscribe($cid);
        $owner   = $this->makeOwner($cid);
        $manager = $this->makeManager($cid);

        // Manager (isPosAdmin but not owner) → 403.
        $this->actingAs($manager, 'pos')
            ->post('/pos/team/own-sales', ['enabled' => 0])
            ->assertStatus(403);

        // Owner flips OFF.
        $this->actingAs($owner, 'pos')
            ->post('/pos/team/own-sales', ['enabled' => 0])
            ->assertRedirect();
        $this->assertSame(0, (int) DB::table('companies')->where('id', $cid)->value('pos_cashier_own_sales_only'));

        // Owner flips back ON.
        $this->actingAs($owner, 'pos')->post('/pos/team/own-sales', ['enabled' => 1]);
        $this->assertSame(1, (int) DB::table('companies')->where('id', $cid)->value('pos_cashier_own_sales_only'));
    }

    // ════════════════════════════════════════════════════════════════════════
    // Coverage 7 — isolation ANDs with Billing Scope
    // ════════════════════════════════════════════════════════════════════════

    public function test_isolation_composes_with_billing_scope(): void
    {
        $cid = $this->makeCompany();
        $this->subscribe($cid);
        // Derived-'pra' cashier (reporting ON, scope unset): stream shows PRA
        // bills + own cross-stream bills. Isolation must narrow BOTH halves
        // to own rows only — never widen the stream.
        $cashier = $this->makeUser($cid, ['pra_reporting_enabled' => true]);
        $peer    = $this->makeUser($cid, ['pra_reporting_enabled' => true]);

        $ownLocal = $this->makeTxn($cid, ['created_by' => $cashier->id, 'invoice_number' => 'L-OWNX']);
        $peerPra  = $this->makePraTxn($cid, ['created_by' => $peer->id, 'invoice_number' => 'POS-PEERX']);
        $ownPra   = $this->makePraTxn($cid, ['created_by' => $cashier->id, 'invoice_number' => 'POS-OWNX']);

        $resp = $this->actingAs($cashier->fresh(), 'pos')->get('/pos/transactions?tab=pra');
        $resp->assertStatus(200);
        $resp->assertSee('POS-OWNX');
        $resp->assertSee('L-OWNX');   // own-bill union (Task 1186) survives
        $resp->assertDontSee('POS-PEERX'); // stream-allowed but NOT own → hidden
    }

    // ════════════════════════════════════════════════════════════════════════
    // Coverage 8 — restaurant surfaces (shared workflows stay shared; company-
    // wide figures stay admin/manager-only; direct writes still guarded)
    // ════════════════════════════════════════════════════════════════════════

    /** Restaurant-module company: flags ON + internal (plan gate always passes). */
    private function makeRestaurantCompany(array $attrs = []): int
    {
        return $this->makeCompany(array_merge([
            'is_internal_account' => true,
            'feature_flags'       => json_encode(['kot' => true, 'kitchen' => true, 'tables' => true]),
        ], $attrs));
    }

    public function test_restaurant_dashboard_unreachable_for_cashier(): void
    {
        $cid = $this->makeRestaurantCompany();
        $this->subscribe($cid);
        $cashier = $this->makeUser($cid);

        // The restaurant dashboard aggregates COMPANY-WIDE figures and is
        // admin/manager/owner-only by construction: every cashier is bounced
        // to the sale screen before any query runs — that's why its queries
        // need no per-cashier filtering under isolation.
        $this->actingAs($cashier, 'pos')
            ->get('/pos/restaurant/dashboard')
            ->assertRedirect('/pos/invoice/create');
    }

    public function test_receipt_printed_write_guarded_for_isolated_cashier(): void
    {
        $cid = $this->makeRestaurantCompany(); // switch NULL = isolation ON
        $this->subscribe($cid);
        $cashier = $this->makeUser($cid);
        $peer    = $this->makeUser($cid);

        $ownId  = $this->makeTxn($cid, ['created_by' => $cashier->id]);
        $peerId = $this->makeTxn($cid, ['created_by' => $peer->id]);

        // Another cashier's bill: the printed/reprint status POST is a
        // transaction WRITE — blocked just like viewing its receipt.
        $this->actingAs($cashier, 'pos')
            ->post("/pos/restaurant/api/receipt-printed/{$peerId}")
            ->assertStatus(403);
        $this->assertNull(DB::table('pos_transactions')->where('id', $peerId)->value('receipt_printed_at'),
            "peer's bill must stay untouched");

        // Own bill: first print stamps, reprint increments.
        $this->actingAs($cashier, 'pos')
            ->post("/pos/restaurant/api/receipt-printed/{$ownId}")
            ->assertOk()
            ->assertJson(['success' => true, 'reprint' => false]);
        $this->actingAs($cashier, 'pos')
            ->post("/pos/restaurant/api/receipt-printed/{$ownId}")
            ->assertOk()
            ->assertJson(['success' => true, 'reprint' => true]);

        // Switch OFF → shared shop: colleague's bill printable again.
        DB::table('companies')->where('id', $cid)->update(['pos_cashier_own_sales_only' => false]);
        $this->actingAs($cashier->fresh(), 'pos')
            ->post("/pos/restaurant/api/receipt-printed/{$peerId}")
            ->assertOk()
            ->assertJson(['success' => true]);
    }

    // ════════════════════════════════════════════════════════════════════════
    // Coverage 9 — day-close preview, X-report and stored Z-report exposure
    // ════════════════════════════════════════════════════════════════════════

    public function test_day_close_preview_isolated_to_own_bills(): void
    {
        $cid = $this->makeCompany(['pos_cashier_dayclose' => true]); // switch NULL = isolation ON
        $this->subscribe($cid);
        $cashier = $this->makeUser($cid);
        $peer    = $this->makeUser($cid);

        $own      = $this->makeTxn($cid, ['created_by' => $cashier->id, 'invoice_number' => 'L-DCISO-OWN']);
        $peerLoc  = $this->makeTxn($cid, ['created_by' => $peer->id, 'invoice_number' => 'L-DCISO-PEER']);
        $peerPra  = $this->makePraTxn($cid, ['created_by' => $peer->id, 'invoice_number' => 'POS-DCISO-PEER']);

        $resp = $this->actingAs($cashier, 'pos')->get('/pos/day-close');
        $resp->assertStatus(200);

        $ids = collect($resp->viewData('transactions'))->pluck('id')->all();
        $this->assertContains($own, $ids, 'own bill must join the preview set');
        $this->assertNotContains($peerLoc, $ids);
        $this->assertNotContains($peerPra, $ids);
        // The page must not leak peer figures through ANY section (streamSplit
        // merge, wash list, returns audit…).
        $resp->assertDontSee('L-DCISO-PEER');
        $resp->assertDontSee('POS-DCISO-PEER');
        // Stored Z-reports are company-wide — no history list for isolated cashiers.
        $this->assertCount(0, $resp->viewData('previousReports'));
    }

    public function test_x_report_isolated_to_own_bills(): void
    {
        $cid = $this->makeCompany(['pos_cashier_dayclose' => true]);
        $this->subscribe($cid);
        $cashier = $this->makeUser($cid);
        $peer    = $this->makeUser($cid);

        // X-report base set = PRA-mode bills only (locals merge in via the
        // stream split) — the cashier needs an own PRA bill to render the page.
        $ownPra  = $this->makePraTxn($cid, ['created_by' => $cashier->id, 'invoice_number' => 'POS-XISO-OWN']);
        $ownLoc  = $this->makeTxn($cid, ['created_by' => $cashier->id, 'invoice_number' => 'L-XISO-OWN']);
        $peerLoc = $this->makeTxn($cid, ['created_by' => $peer->id, 'invoice_number' => 'L-XISO-PEER']);
        $peerPra = $this->makePraTxn($cid, ['created_by' => $peer->id, 'invoice_number' => 'POS-XISO-PEER']);

        $resp = $this->actingAs($cashier, 'pos')->get('/pos/day-close/x-report/thermal');
        $resp->assertStatus(200);

        $ids = collect($resp->viewData('transactions'))->pluck('id')->all();
        $this->assertContains($ownPra, $ids);
        $this->assertNotContains($peerLoc, $ids);
        $this->assertNotContains($peerPra, $ids);
        // streamSplit is rebuilt from the SAME own-bills merge — the local box
        // must count exactly the cashier's own local bill, not the shop's two.
        $split = $resp->viewData('streamSplit');
        $this->assertSame(1, (int) ($split['local']['count'] ?? -1),
            'X-report local stream box must hold ONLY the own local bill');
        $resp->assertDontSee('L-XISO-PEER');
        $resp->assertDontSee('POS-XISO-PEER');
    }

    public function test_stored_z_report_views_blocked_for_isolated_cashier(): void
    {
        $cid = $this->makeCompany(['pos_cashier_dayclose' => true]);
        $this->subscribe($cid);
        $cashier = $this->makeUser($cid);

        $reportId = (int) DB::table('pos_day_close_reports')->insertGetId([
            'company_id'  => $cid,
            'report_date' => \App\Services\PosBusinessDay::current($cid),
            'created_at'  => now(),
            'updated_at'  => now(),
        ]);

        // The stored Z is a COMPANY-WIDE shop document — preview access never
        // extends to it for an isolated cashier.
        $this->actingAs($cashier, 'pos')
            ->get("/pos/day-close/{$reportId}/pdf")
            ->assertRedirect(route('pos.dashboard'));
        $this->actingAs($cashier, 'pos')
            ->get("/pos/day-close/{$reportId}/thermal")
            ->assertRedirect(route('pos.dashboard'));
    }

    // ════════════════════════════════════════════════════════════════════════
    // Coverage 11 — dashboard KPI isolation (incl. New Customers attribution)
    // ════════════════════════════════════════════════════════════════════════

    /** Invoke the dashboard directly (khata-suite pattern) and return its view data. */
    private function dashboardData(User $user, array $query = []): array
    {
        \Illuminate\Support\Facades\Auth::guard('pos')->setUser($user);
        // Direct controller invoke skips the route middleware that binds the
        // company container key — bind a closure (null-safe convention).
        app()->bind('currentCompanyId', fn () => (int) $user->company_id);
        $view = (new \App\Http\Controllers\PosController())->dashboard(
            \Illuminate\Http\Request::create('/pos/dashboard', 'GET', $query)
        );

        return $view->getData();
    }

    public function test_dashboard_kpis_isolated_and_staff_filtered(): void
    {
        $cid = $this->makeCompany(['pra_reporting_enabled' => true]);
        $this->subscribe($cid);
        $admin   = $this->makeUser($cid, ['pos_role' => 'pos_admin', 'pra_reporting_enabled' => true]);
        $cashier = $this->makeUser($cid, ['pra_reporting_enabled' => true]);
        $peer    = $this->makeUser($cid, ['pra_reporting_enabled' => true]);

        // One brand-new customer per cashier, linked through their bills.
        $custOwn  = (int) DB::table('pos_customers')->insertGetId(['company_id' => $cid, 'name' => 'Own Cust', 'created_at' => now(), 'updated_at' => now()]);
        $custPeer = (int) DB::table('pos_customers')->insertGetId(['company_id' => $cid, 'name' => 'Peer Cust', 'created_at' => now(), 'updated_at' => now()]);

        // PRA bills on both sides — reporting-ON staff derive the 'pra' stream,
        // so a local fixture bill would vanish from combinedSale (scope AND).
        $this->makePraTxn($cid, ['created_by' => $cashier->id, 'customer_id' => $custOwn, 'pra_status' => 'submitted', 'pra_invoice_number' => 'PRA-DASH-OWN', 'total_amount' => 100]);
        $this->makePraTxn($cid, ['created_by' => $peer->id, 'customer_id' => $custPeer, 'pra_status' => 'submitted', 'pra_invoice_number' => 'PRA-DASH-PEER', 'total_amount' => 116]);

        // Isolated cashier: revenue + new-customers reflect ONLY their own bills.
        $own = $this->dashboardData($cashier);
        $this->assertEquals(100.0, (float) $own['todayTotalSale'], 'isolated cashier revenue = own bills only');
        $this->assertSame(1, (int) $own['newCustomersToday'], 'isolated cashier sees only customers on own bills');

        // Admin merged view: everything.
        $merged = $this->dashboardData($admin);
        $this->assertEquals(216.0, (float) $merged['todayTotalSale']);
        $this->assertSame(2, (int) $merged['newCustomersToday']);

        // Admin per-cashier view: exactly the selected cashier's day.
        $filtered = $this->dashboardData($admin, ['cashier' => (string) $peer->id]);
        $this->assertEquals(116.0, (float) $filtered['todayTotalSale']);
        $this->assertSame(1, (int) $filtered['newCustomersToday'], 'staff filter narrows New Customers to that cashier');

        // Switch OFF: cashier sees the shared shop again.
        DB::table('companies')->where('id', $cid)->update(['pos_cashier_own_sales_only' => false]);
        $shared = $this->dashboardData(User::find($cashier->id)); // fresh instance — verdict is memoized
        $this->assertEquals(216.0, (float) $shared['todayTotalSale']);
        $this->assertSame(2, (int) $shared['newCustomersToday']);
    }

    // ════════════════════════════════════════════════════════════════════════
    // Coverage 12 — silent print jobs, edit-locks, and the Sync-all badge
    // ════════════════════════════════════════════════════════════════════════

    public function test_print_jobs_and_locks_blocked_on_peer_bills(): void
    {
        $cid = $this->makeCompany([
            'agent_enabled'        => true,
            'agent_last_seen'      => now(),
            'pos_printer_settings' => json_encode(['silent_print_enabled' => true, 'receipt_printer' => 'Counter-1']),
        ]);
        $this->subscribe($cid);
        DB::table('pos_terminals')->insert([
            'company_id' => $cid, 'name' => 'T1',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $cashier = $this->makeUser($cid);
        $peer    = $this->makeUser($cid);
        $own     = $this->makeTxn($cid, ['created_by' => $cashier->id]);
        $peerTxn = $this->makeTxn($cid, ['created_by' => $peer->id]);

        // Silent receipt print: peer bill mirrors not_found; own bill enqueues.
        $this->actingAs($cashier, 'pos')
            ->postJson('/pos/api/print-jobs', ['type' => 'bill', 'transaction_id' => $peerTxn])
            ->assertStatus(404);
        $this->assertSame(0, (int) DB::table('pos_print_jobs')->count(), 'no job row for a peer bill');
        $this->actingAs($cashier, 'pos')
            ->postJson('/pos/api/print-jobs', ['type' => 'bill', 'transaction_id' => $own])
            ->assertStatus(200)->assertJsonPath('success', true);

        // Edit-locks: cannot claim or release a peer's bill.
        $this->actingAs($cashier, 'pos')
            ->postJson("/pos/api/invoice/{$peerTxn}/lock", ['terminal_id' => 1])
            ->assertStatus(403);
        $this->actingAs($cashier, 'pos')
            ->postJson("/pos/api/invoice/{$peerTxn}/unlock")
            ->assertStatus(403);
        $this->assertNull(DB::table('pos_transactions')->where('id', $peerTxn)->value('locked_by_terminal_id'));

        // Own bill locks fine.
        $this->actingAs($cashier, 'pos')
            ->postJson("/pos/api/invoice/{$own}/lock", ['terminal_id' => 1])
            ->assertStatus(200)->assertJsonPath('success', true);

        // Switch OFF → peer print + lock shared again.
        DB::table('companies')->where('id', $cid)->update(['pos_cashier_own_sales_only' => false]);
        $fresh = User::find($cashier->id); // verdict memoized per instance
        $this->actingAs($fresh, 'pos')
            ->postJson('/pos/api/print-jobs', ['type' => 'bill', 'transaction_id' => $peerTxn])
            ->assertStatus(200)->assertJsonPath('success', true);
        $this->actingAs($fresh, 'pos')
            ->postJson("/pos/api/invoice/{$peerTxn}/lock", ['terminal_id' => 1])
            ->assertStatus(200)->assertJsonPath('success', true);
    }

    public function test_sync_all_badge_counts_only_own_failed_bills(): void
    {
        $cid = $this->makeCompany(['pra_reporting_enabled' => true]);
        $this->subscribe($cid);
        $cashier = $this->makeUser($cid, ['pra_reporting_enabled' => true]);
        $peer    = $this->makeUser($cid, ['pra_reporting_enabled' => true]);
        $this->makePraTxn($cid, ['created_by' => $cashier->id, 'pra_status' => 'failed', 'pra_invoice_number' => null]);
        $this->makePraTxn($cid, ['created_by' => $peer->id, 'pra_status' => 'failed', 'pra_invoice_number' => null, 'invoice_number' => 'P-BADGE']);

        $resp = $this->actingAs($cashier, 'pos')->get('/pos/transactions');
        $resp->assertStatus(200);
        $resp->assertSee(__('pos.sync_all_count', ['count' => 1]));

        // Switch OFF → badge counts the whole shop again.
        DB::table('companies')->where('id', $cid)->update(['pos_cashier_own_sales_only' => false]);
        $resp = $this->actingAs(User::find($cashier->id), 'pos')->get('/pos/transactions');
        $resp->assertSee(__('pos.sync_all_count', ['count' => 2]));
    }

    // ════════════════════════════════════════════════════════════════════════
    // Coverage 10 — adversarial direct peer-ID hits on mutation/status endpoints
    // ════════════════════════════════════════════════════════════════════════

    public function test_mutation_endpoints_block_peer_bills_for_isolated_cashier(): void
    {
        $cid = $this->makeCompany(['pra_reporting_enabled' => true, 'pra_connection_mode' => 'fiscal_device']);
        $this->subscribe($cid);
        $cashier = $this->makeUser($cid, ['pra_reporting_enabled' => true]);
        $peer    = $this->makeUser($cid, ['pra_reporting_enabled' => true]);

        // Peer provisional (editable/promotable) + peer failed PRA bill.
        $prov   = $this->makeTxn($cid, ['created_by' => $peer->id]);
        $failed = $this->makePraTxn($cid, ['created_by' => $peer->id, 'pra_status' => 'failed']);

        // Edit screen + update — 403 even via direct URL.
        $this->actingAs($cashier, 'pos')->get("/pos/transaction/{$prov}/edit")->assertStatus(403);
        $this->actingAs($cashier, 'pos')->put("/pos/transaction/{$prov}", [])->assertStatus(403);

        // Promote/finalize (guard covers BOTH branches) — 403, row untouched.
        $this->actingAs($cashier, 'pos')
            ->postJson("/pos/api/provisional-bills/{$prov}/promote", ['send_to_pra' => false])
            ->assertStatus(403);
        $this->assertSame('local', DB::table('pos_transactions')->where('id', $prov)->value('pra_status'));

        // F11 retry — 403; the atomic claim must NOT have flipped the status.
        $this->actingAs($cashier, 'pos')
            ->postJson("/pos/api/failed-bills/{$failed}/retry")
            ->assertStatus(403);
        $this->assertSame('failed', DB::table('pos_transactions')->where('id', $failed)->value('pra_status'));

        // Web retry — bounced back with an error, row untouched.
        $this->actingAs($cashier, 'pos')
            ->post("/pos/transaction/{$failed}/retry-pra")
            ->assertStatus(302)
            ->assertSessionHas('error');
        $this->assertSame('failed', DB::table('pos_transactions')->where('id', $failed)->value('pra_status'));

        // Fiscal-status probe — peer bill mirrors not-found (no existence oracle).
        $this->actingAs($cashier, 'pos')
            ->getJson("/pos/transaction/{$failed}/pra-status")
            ->assertStatus(404);
    }

    public function test_mutation_endpoints_shared_again_when_switch_off(): void
    {
        // fiscal_device = agent mode: retry returns "queued" JSON without any
        // network call — proves the WRITE path is genuinely shared again.
        $cid = $this->makeCompany([
            'pos_cashier_own_sales_only' => false,
            'pra_reporting_enabled'      => true,
            'pra_connection_mode'        => 'fiscal_device',
        ]);
        $this->subscribe($cid);
        $cashier = $this->makeUser($cid, ['pra_reporting_enabled' => true]);
        $peer    = $this->makeUser($cid, ['pra_reporting_enabled' => true]);
        $failed  = $this->makePraTxn($cid, ['created_by' => $peer->id, 'pra_status' => 'failed']);

        // Old shared behavior: peer bill's fiscal state visible again…
        $this->actingAs($cashier, 'pos')
            ->getJson("/pos/transaction/{$failed}/pra-status")
            ->assertStatus(200)
            ->assertJsonPath('pra_status', 'failed');

        // …and the peer's failed bill is retryable by any colleague.
        $this->actingAs($cashier, 'pos')
            ->postJson("/pos/api/failed-bills/{$failed}/retry")
            ->assertStatus(200)
            ->assertJsonPath('queued', true);
        $this->assertSame('pending', DB::table('pos_transactions')->where('id', $failed)->value('pra_status'));
    }

    /** Active unlimited subscription so plan.limit / subscription gates never block. */
    private function subscribe(int $companyId): void
    {
        $planId = (int) DB::table('pricing_plans')->insertGetId([
            'name'          => 'Business',
            'product_type'  => 'pos',
            'is_trial'      => false,
            'invoice_limit' => -1,
            'user_limit'    => null,
            'created_at'    => now(),
            'updated_at'    => now(),
        ]);
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
}
