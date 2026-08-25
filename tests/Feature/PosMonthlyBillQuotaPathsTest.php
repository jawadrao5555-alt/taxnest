<?php

namespace Tests\Feature;

use App\Services\PlanLimitService;
use App\Services\PosFeatureService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Task 203 — MONTHLY FINAL-BILL QUOTA: all creation paths test-locked.
 *
 * PRA POS plans carry a bills-per-calendar-month quota (PlanLimitService::
 * canCreatePosBill). A FINAL bill can come into existence through FOUR
 * HTTP paths, and each one must keep its gate forever:
 *
 *   1. PosController::storeInvoice            — normal sale finalize
 *   2. RestaurantPosController::payOrder      — restaurant hold → pay
 *   3. PosController::retryPra                — provisional 'local' promote
 *      (plain retries of already-final failed/offline/pending bills and
 *      NULL-final per-bill submits are NOT re-charged — locked too)
 *   4. PosController::apiPromoteProvisional   — F10 promote (JSON)
 *      (send_to_pra=false local-final bypass stays quota-free — locked)
 *
 * (A 5th call site — the day-close auto-finalize sweep — is already locked
 * by PosDayCloseAutoFinalizeTest; not duplicated here.)
 *
 * The task title's "cashier reactivation flow" is the sibling TEAM-account
 * quota (canAddPosUser) on PosController::toggleCashier — reactivating a
 * deactivated cashier/manager re-consumes a slot. Locked here as well.
 *
 * Also locked: what COUNTS toward the monthly quota (provisionals/drafts
 * free; NULL-status and archived finals counted; day-close deleted finals
 * added back; prior months excluded; trial plans exempt).
 *
 * Pattern: APP_ENV=testing + sqlite :memory: + minimal Schema::create,
 * HTTP through the real routes/middleware (same as PosDayCloseAutoFinalizeTest).
 * No network: companies stay reporting-OFF, or are Agent-Sync (re-queue only).
 */
class PosMonthlyBillQuotaPathsTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // planAllows/restaurantAllowed cache per company id statically — ids
        // restart at 1 after dropAllTables, so stale verdicts would leak.
        PosFeatureService::flushGateCaches();

        Schema::dropAllTables();

        Schema::create('companies', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('product_type')->nullable();
            $table->string('status')->nullable();
            $table->string('default_language')->nullable();
            $table->boolean('is_internal_account')->default(false);
            $table->integer('invoice_limit_override')->nullable();
            $table->integer('user_limit_override')->nullable();
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
            $table->string('pos_business_day_cutoff')->nullable();
            $table->boolean('inventory_enabled')->default(false);
            $table->decimal('cashier_discount_limit', 8, 2)->nullable();
            $table->string('pos_tax_pricing_mode')->nullable();
            $table->boolean('pos_tax_inclusive')->default(false);
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
            $table->boolean('is_active')->default(true);
            $table->string('language')->nullable();
            // NULLABLE on purpose: NULL = inherit the company flag
            // (User::praReportingEnabled) — a false default would override it.
            $table->boolean('pra_reporting_enabled')->nullable();
            $table->rememberToken();
            $table->timestamps();
        });

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
            // storeInvoice's insert always carries this key (only the replay
            // lookup is hasColumn-guarded) — the column must exist.
            $table->string('offline_uuid')->nullable()->unique();
            $table->boolean('tax_inclusive')->default(false);
            $table->decimal('tax_menu_rate', 8, 2)->nullable();
            $table->text('notes')->nullable();
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

        Schema::create('branches', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('company_id');
            $table->string('name');
            $table->boolean('is_active')->default(true);
            $table->boolean('is_head_office')->default(false);
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

        // Monthly quota adds back finals hard-deleted by day-close DELETE policy.
        Schema::create('pos_day_close_reports', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('company_id');
            $table->date('report_date');
            $table->integer('deleted_final_count')->default(0);
            $table->timestamps();
        });

        // ...and finals hard-deleted one by one from a bill page (Task 1372).
        Schema::create('pos_bill_deletions', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('company_id');
            $table->unsignedBigInteger('transaction_id')->nullable();
            $table->string('invoice_number')->nullable();
            $table->timestamp('sold_at')->nullable();
            $table->date('business_date')->nullable();
            $table->decimal('total_amount', 12, 2)->default(0);
            $table->unsignedBigInteger('deleted_by')->nullable();
            $table->timestamps();
            $table->unique(['company_id', 'transaction_id']);
        });

        Schema::create('pricing_plans', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('product_type')->default('pos');
            $table->boolean('is_trial')->default(false);
            $table->integer('invoice_limit')->nullable();
            $table->integer('user_limit')->nullable();
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

        Schema::create('restaurant_orders', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('company_id');
            $table->string('order_number')->nullable();
            $table->unsignedBigInteger('table_id')->nullable();
            $table->string('order_type')->nullable();
            $table->string('status')->nullable();
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
    }

    // ── fixtures ────────────────────────────────────────────────────────────

    /** Reporting-OFF POS company that survives PosAuth + company.approval. */
    private function makeCompany(array $attrs = []): int
    {
        return (int) DB::table('companies')->insertGetId(array_merge([
            'name' => 'Quota Test Co',
            'product_type' => 'pos',
            'status' => 'active',
            'is_internal_account' => false,
            'invoice_limit_override' => null,
            'user_limit_override' => null,
            'pra_reporting_enabled' => false,
            'agent_enabled' => false,
            'pra_connection_mode' => 'cloud',
            'pra_environment' => 'sandbox',
            'inventory_enabled' => false,
            // Company-level rate overrides → PosTaxRule table never consulted.
            'pos_tax_rate_cash' => 16.00,
            'pos_tax_rate_card' => 16.00,
            'created_at' => now(),
            'updated_at' => now(),
        ], $attrs));
    }

    /**
     * Active paid subscription. storeInvoice's route middleware
     * (plan.limit:invoices → SubscriptionAccessService::hasAccess) fails
     * closed without one, so EVERY company that posts /pos/invoice/store
     * needs a subscription row even when an admin override decides the quota.
     */
    private function subscribe(int $companyId, array $planAttrs = [], array $subAttrs = []): int
    {
        $planId = (int) DB::table('pricing_plans')->insertGetId(array_merge([
            'name' => 'Business',
            'product_type' => 'pos',
            'is_trial' => false,
            'invoice_limit' => -1,
            'user_limit' => null,
            'restaurant_enabled' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ], $planAttrs));

        DB::table('subscriptions')->insert(array_merge([
            'company_id' => $companyId,
            'pricing_plan_id' => $planId,
            'active' => true,
            'start_date' => now()->subMonth()->toDateString(),
            'end_date' => now()->addMonth()->toDateString(),
            'override_type' => 'none',
            'created_at' => now(),
            'updated_at' => now(),
        ], $subAttrs));

        return $planId;
    }

    private function makeUser(int $companyId, array $attrs = []): \App\Models\User
    {
        static $seq = 0;
        $id = DB::table('users')->insertGetId(array_merge([
            'name' => 'POS Owner',
            'email' => 'owner' . $companyId . '-' . (++$seq) . '@taxnest.test',
            'password' => bcrypt('Secret@12345'),
            'company_id' => $companyId,
            'role' => 'company_admin',
            'pos_role' => null,
            'is_active' => true,
            'language' => 'en',
            'pra_reporting_enabled' => null, // inherit company flag
            'created_at' => now(),
            'updated_at' => now(),
        ], $attrs));

        return \App\Models\User::find($id);
    }

    /** A quota-consuming FINAL bill this month (completed, mode 'pra'). */
    private function makeFinal(int $companyId, string $number, array $attrs = []): int
    {
        return (int) DB::table('pos_transactions')->insertGetId(array_merge([
            'company_id' => $companyId,
            'invoice_number' => $number,
            'business_date' => now()->toDateString(),
            'status' => 'completed',
            'invoice_mode' => 'pra',
            'pra_status' => null,
            'subtotal' => 100,
            'tax_rate' => 16,
            'tax_amount' => 16,
            'total_amount' => 116,
            'payment_method' => 'cash',
            'created_at' => now(),
            'updated_at' => now(),
        ], $attrs));
    }

    /** A deliberate provisional (quota-free until promoted). */
    private function makeProvisional(int $companyId, string $number, array $attrs = []): int
    {
        $subtotal = (float) ($attrs['subtotal'] ?? 100.00);
        $id = $this->makeFinal($companyId, $number, array_merge([
            'invoice_mode' => 'local',
            'pra_status' => 'local',
            'subtotal' => $subtotal,
            'tax_rate' => 0,
            'tax_amount' => 0,
            'total_amount' => $subtotal,
        ], $attrs));

        DB::table('pos_transaction_items')->insert([
            'transaction_id' => $id,
            'item_type' => 'product',
            'item_name' => 'Test Item',
            'quantity' => 1,
            'unit_price' => $subtotal,
            'subtotal' => $subtotal,
            'is_tax_exempt' => false,
            'tax_rate' => 0,
            'tax_amount' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $id;
    }

    private function makeOrder(int $companyId, array $attrs = []): int
    {
        $orderId = (int) DB::table('restaurant_orders')->insertGetId(array_merge([
            'company_id' => $companyId,
            'order_number' => 'ORD-001',
            'order_type' => 'dine_in',
            'status' => 'pending',
            'subtotal' => 100,
            'discount_type' => 'amount',
            'discount_value' => 0,
            'discount_amount' => 0,
            'total_amount' => 100,
            'created_at' => now(),
            'updated_at' => now(),
        ], $attrs));

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
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $orderId;
    }

    /** Restaurant-featured company (RestaurantOnly middleware + plan module). */
    private function makeRestaurantCompany(array $attrs = []): int
    {
        $companyId = $this->makeCompany(array_merge([
            'feature_flags' => json_encode(['tables' => true, 'kot' => true, 'kitchen' => true]),
        ], $attrs));
        $this->subscribe($companyId, ['restaurant_enabled' => true]);

        return $companyId;
    }

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

    private function finalsCount(int $companyId): int
    {
        return DB::table('pos_transactions')
            ->where('company_id', $companyId)
            ->where('status', 'completed')
            ->where(fn ($q) => $q->whereNull('invoice_mode')->orWhere('invoice_mode', '!=', 'local'))
            ->count();
    }

    private const OVERRIDE_FULL_1 = 'Monthly bill limit reached (1/1 this month). Please contact admin.';

    // ════════════════════════════════════════════════════════════════════════
    // PATH 1 — PosController::storeInvoice (normal sale finalize)
    // ════════════════════════════════════════════════════════════════════════

    public function test_store_invoice_blocked_at_quota_full_admin_override(): void
    {
        $companyId = $this->makeCompany(['invoice_limit_override' => 1]);
        $this->subscribe($companyId);
        $this->makeFinal($companyId, 'L050');

        $response = $this->actingAs($this->makeUser($companyId), 'pos')
            ->postJson('/pos/invoice/store', $this->storePayload());

        $response->assertStatus(403)->assertJson([
            'success' => false,
            'error' => self::OVERRIDE_FULL_1,
            'message' => self::OVERRIDE_FULL_1,
            // Task 216: plain-retail quota 403 must advertise the provisional escape hatch.
            'quota_full' => true,
            'provisional_allowed' => true,
        ]);
        $this->assertSame(1, DB::table('pos_transactions')->where('company_id', $companyId)->count(), 'no bill row may be created when blocked');
    }

    public function test_store_invoice_quota_403_dine_in_restaurantish_disallows_provisional(): void
    {
        // Task 216: restaurant-ish company + non-delivery order_type → the quota 403
        // must NOT offer a provisional retry (dine-in/takeaway settle as finals only).
        $companyId = $this->makeRestaurantCompany(['invoice_limit_override' => 1]);
        $this->makeFinal($companyId, 'L050');

        $response = $this->actingAs($this->makeUser($companyId), 'pos')
            ->postJson('/pos/invoice/store', $this->storePayload(['order_type' => 'dine_in']));

        $response->assertStatus(403)->assertJson([
            'success' => false,
            'quota_full' => true,
            'provisional_allowed' => false,
        ]);
    }

    public function test_store_invoice_quota_403_delivery_restaurantish_allows_provisional(): void
    {
        $companyId = $this->makeRestaurantCompany(['invoice_limit_override' => 1]);
        $this->makeFinal($companyId, 'L050');

        $response = $this->actingAs($this->makeUser($companyId), 'pos')
            ->postJson('/pos/invoice/store', $this->storePayload(['order_type' => 'delivery']));

        $response->assertStatus(403)->assertJson([
            'success' => false,
            'quota_full' => true,
            'provisional_allowed' => true,
        ]);
    }

    public function test_store_invoice_blocked_at_plan_limit_names_the_plan(): void
    {
        $companyId = $this->makeCompany(); // no admin override → plan decides
        $this->subscribe($companyId, ['name' => 'Starter', 'invoice_limit' => 1]);
        $this->makeFinal($companyId, 'L050');

        $expected = 'Monthly bill limit reached (1/1 bills this month on the Starter plan). Please upgrade your plan to keep billing.';

        $response = $this->actingAs($this->makeUser($companyId), 'pos')
            ->postJson('/pos/invoice/store', $this->storePayload());

        $response->assertStatus(403)->assertJson(['success' => false, 'message' => $expected]);
        $this->assertSame(1, DB::table('pos_transactions')->where('company_id', $companyId)->count());
    }

    public function test_store_invoice_allowed_within_quota_and_new_final_consumes_it(): void
    {
        $companyId = $this->makeCompany(['invoice_limit_override' => 2]);
        $this->subscribe($companyId);
        $this->makeFinal($companyId, 'L050');

        $response = $this->actingAs($this->makeUser($companyId), 'pos')
            ->postJson('/pos/invoice/store', $this->storePayload());

        $response->assertStatus(200)->assertJson(['success' => true]);

        $tx = DB::table('pos_transactions')->where('company_id', $companyId)->orderByDesc('id')->first();
        // Reporting-OFF final = 'pra' mode + NULL status on the L-series.
        $this->assertSame('completed', $tx->status);
        $this->assertSame('pra', $tx->invoice_mode);
        $this->assertNull($tx->pra_status);
        $this->assertMatchesRegularExpression('/^L\d+$/', $tx->invoice_number);
        $this->assertSame(116.0, (float) $tx->total_amount); // 100 + 16% whole-rupee

        // The new final CONSUMED the last slot — quota is now closed (2/2).
        $this->assertSame(2, $this->finalsCount($companyId));
        $quota = PlanLimitService::canCreatePosBill($companyId);
        $this->assertFalse($quota['allowed']);
        $this->assertSame('Monthly bill limit reached (2/2 this month). Please contact admin.', $quota['reason']);
    }

    public function test_store_invoice_provisional_allowed_at_quota_full_and_stays_quota_free(): void
    {
        $companyId = $this->makeCompany(['invoice_limit_override' => 1]);
        $this->subscribe($companyId);
        $this->makeFinal($companyId, 'L050');

        $response = $this->actingAs($this->makeUser($companyId), 'pos')
            ->postJson('/pos/invoice/store', $this->storePayload(['save_as_provisional' => 1]));

        $response->assertStatus(200)->assertJson(['success' => true]);

        $tx = DB::table('pos_transactions')->where('company_id', $companyId)->orderByDesc('id')->first();
        $this->assertSame('local', $tx->invoice_mode, 'provisional must be stored as local/local');
        $this->assertSame('local', $tx->pra_status);

        // Still exactly ONE quota-counted final — the provisional charged nothing.
        $this->assertSame(1, $this->finalsCount($companyId));
        $this->assertFalse(PlanLimitService::canCreatePosBill($companyId)['allowed']);
    }

    // ════════════════════════════════════════════════════════════════════════
    // PATH 2 — RestaurantPosController::payOrder (hold → pay)
    // ════════════════════════════════════════════════════════════════════════

    public function test_pay_order_blocked_at_quota_full(): void
    {
        $companyId = $this->makeRestaurantCompany(['invoice_limit_override' => 1]);
        $this->makeFinal($companyId, 'L050');
        $orderId = $this->makeOrder($companyId);

        $response = $this->actingAs($this->makeUser($companyId), 'pos')
            ->postJson("/pos/restaurant/orders/{$orderId}/pay", ['payment_method' => 'cash']);

        $response->assertStatus(403)->assertJson([
            'success' => false,
            'message' => self::OVERRIDE_FULL_1,
            // Task 216: dine-in on a restaurant-ish company → no provisional escape hatch.
            'quota_full' => true,
            'provisional_allowed' => false,
        ]);

        $this->assertSame('pending', DB::table('restaurant_orders')->where('id', $orderId)->value('status'), 'blocked order must stay open');
        $this->assertSame(1, DB::table('pos_transactions')->where('company_id', $companyId)->count(), 'no bill row may be created when blocked');
    }

    public function test_pay_order_quota_403_delivery_order_allows_provisional(): void
    {
        // Task 216: delivery held order at quota-full → 403 must advertise the
        // provisional retry so the sale screen can offer the one-click save.
        $companyId = $this->makeRestaurantCompany(['invoice_limit_override' => 1]);
        $this->makeFinal($companyId, 'L050');
        $orderId = $this->makeOrder($companyId, ['order_type' => 'delivery']);

        $response = $this->actingAs($this->makeUser($companyId), 'pos')
            ->postJson("/pos/restaurant/orders/{$orderId}/pay", ['payment_method' => 'cash']);

        $response->assertStatus(403)->assertJson([
            'success' => false,
            'quota_full' => true,
            'provisional_allowed' => true,
        ]);
        $this->assertSame('pending', DB::table('restaurant_orders')->where('id', $orderId)->value('status'));
    }

    public function test_pay_order_allowed_within_quota_creates_final(): void
    {
        $companyId = $this->makeRestaurantCompany(['invoice_limit_override' => 1]);
        $orderId = $this->makeOrder($companyId);

        $response = $this->actingAs($this->makeUser($companyId), 'pos')
            ->postJson("/pos/restaurant/orders/{$orderId}/pay", ['payment_method' => 'cash']);

        $response->assertStatus(200)->assertJson(['success' => true]);

        $order = DB::table('restaurant_orders')->where('id', $orderId)->first();
        $this->assertSame('completed', $order->status);

        $tx = DB::table('pos_transactions')->where('id', $order->pos_transaction_id)->first();
        $this->assertSame('completed', $tx->status);
        $this->assertSame('pra', $tx->invoice_mode);
        $this->assertNull($tx->pra_status); // reporting-OFF final
        $this->assertMatchesRegularExpression('/^L\d+$/', $tx->invoice_number);

        // Settle consumed the only slot — next final is blocked.
        $this->assertFalse(PlanLimitService::canCreatePosBill($companyId)['allowed']);
    }

    public function test_pay_order_provisional_settle_allowed_at_quota_full_and_stays_quota_free(): void
    {
        // Task 215 — quota-full must NOT deadlock the shop: a provisional settle
        // (delivery-only on restaurant-ish companies) skips the quota gate exactly
        // like storeInvoice's save_as_provisional path, and charges nothing.
        $companyId = $this->makeRestaurantCompany(['invoice_limit_override' => 1]);
        $this->makeFinal($companyId, 'L050');
        $orderId = $this->makeOrder($companyId, ['order_type' => 'delivery']);

        $response = $this->actingAs($this->makeUser($companyId), 'pos')
            ->postJson("/pos/restaurant/orders/{$orderId}/pay", [
                'payment_method' => 'cash',
                'save_as_provisional' => 1,
            ]);

        $response->assertStatus(200)->assertJson(['success' => true]);

        $order = DB::table('restaurant_orders')->where('id', $orderId)->first();
        $this->assertSame('completed', $order->status);

        $tx = DB::table('pos_transactions')->where('id', $order->pos_transaction_id)->first();
        $this->assertSame('local', $tx->invoice_mode, 'provisional must be stored as local/local');
        $this->assertSame('local', $tx->pra_status);

        // Still exactly ONE quota-counted final — the provisional charged nothing,
        // and the quota stays closed for FINAL settles.
        $this->assertSame(1, $this->finalsCount($companyId));
        $this->assertFalse(PlanLimitService::canCreatePosBill($companyId)['allowed']);
    }

    // ════════════════════════════════════════════════════════════════════════
    // PATH 2b — Task 407: prepaid delivery (qr_payment) on payOrder
    //
    // The Delivery Prepaid toggle (Task 287) overrides the payment method to
    // 'qr_payment' on EVERY submit path. payOrder's validation only allowed
    // cash/card/online/split → live shop ZFC Pizza Point (10 Aug 2026) got a
    // 422 on every Cash/Card/PAY/Provisional press for prepaid delivery bills.
    // payOrder must mirror storeInvoice's accepted set + alias normalization.
    // ════════════════════════════════════════════════════════════════════════

    public function test_pay_order_accepts_qr_payment_prepaid_delivery(): void
    {
        $companyId = $this->makeRestaurantCompany();
        $orderId = $this->makeOrder($companyId, ['order_type' => 'delivery']);

        $response = $this->actingAs($this->makeUser($companyId), 'pos')
            ->postJson("/pos/restaurant/orders/{$orderId}/pay", ['payment_method' => 'qr_payment']);

        $response->assertStatus(200)->assertJson(['success' => true]);

        $order = DB::table('restaurant_orders')->where('id', $orderId)->first();
        $this->assertSame('completed', $order->status);
        $this->assertSame('qr_payment', $order->payment_method, 'prepaid bill must stay qr_payment — never cash (rider khata rule)');

        $tx = DB::table('pos_transactions')->where('id', $order->pos_transaction_id)->first();
        $this->assertSame('qr_payment', $tx->payment_method);
        $this->assertNull($tx->cash_received, 'non-cash bill must not record cash_received');
    }

    public function test_pay_order_accepts_qr_payment_provisional_delivery(): void
    {
        // Provisional + prepaid — the exact combination that also errored live.
        $companyId = $this->makeRestaurantCompany();
        $orderId = $this->makeOrder($companyId, ['order_type' => 'delivery']);

        $response = $this->actingAs($this->makeUser($companyId), 'pos')
            ->postJson("/pos/restaurant/orders/{$orderId}/pay", [
                'payment_method' => 'qr_payment',
                'save_as_provisional' => 1,
            ]);

        $response->assertStatus(200)->assertJson(['success' => true]);

        $order = DB::table('restaurant_orders')->where('id', $orderId)->first();
        $tx = DB::table('pos_transactions')->where('id', $order->pos_transaction_id)->first();
        $this->assertSame('qr_payment', $tx->payment_method);
        $this->assertSame('local', $tx->invoice_mode);
        $this->assertSame('local', $tx->pra_status);
    }

    public function test_pay_order_normalizes_card_alias_to_debit_card(): void
    {
        // Parity with storeInvoice: 'card' (front-end alias) must be stored as
        // 'debit_card' so tax rules, PRA PaymentMode, and cash/card aggregations
        // all see the canonical bucket.
        $companyId = $this->makeRestaurantCompany();
        $orderId = $this->makeOrder($companyId, ['order_type' => 'delivery']);

        $response = $this->actingAs($this->makeUser($companyId), 'pos')
            ->postJson("/pos/restaurant/orders/{$orderId}/pay", ['payment_method' => 'card']);

        $response->assertStatus(200)->assertJson(['success' => true]);

        $order = DB::table('restaurant_orders')->where('id', $orderId)->first();
        $tx = DB::table('pos_transactions')->where('id', $order->pos_transaction_id)->first();
        $this->assertSame('debit_card', $tx->payment_method);
    }

    public function test_pay_order_rejects_unknown_payment_method(): void
    {
        $companyId = $this->makeRestaurantCompany();
        $orderId = $this->makeOrder($companyId, ['order_type' => 'delivery']);

        $response = $this->actingAs($this->makeUser($companyId), 'pos')
            ->postJson("/pos/restaurant/orders/{$orderId}/pay", ['payment_method' => 'bitcoin']);

        $response->assertStatus(422);
        $this->assertSame('pending', DB::table('restaurant_orders')->where('id', $orderId)->value('status'));
    }

    // ════════════════════════════════════════════════════════════════════════
    // PATH 3 — PosController::retryPra (provisional promote / plain retry)
    // ════════════════════════════════════════════════════════════════════════

    public function test_retry_pra_local_promote_blocked_at_quota_full(): void
    {
        $companyId = $this->makeCompany(['invoice_limit_override' => 1]);
        $this->makeFinal($companyId, 'F-001');
        $billId = $this->makeProvisional($companyId, 'L001');

        $response = $this->actingAs($this->makeUser($companyId), 'pos')
            ->from('/pos/transactions')
            ->post("/pos/transaction/{$billId}/retry-pra");

        $response->assertRedirect('/pos/transactions')
            ->assertSessionHas('error', self::OVERRIDE_FULL_1);

        $tx = DB::table('pos_transactions')->where('id', $billId)->first();
        $this->assertSame('local', $tx->invoice_mode, 'blocked provisional must stay provisional');
        $this->assertSame('local', $tx->pra_status);
        $this->assertSame(1, $this->finalsCount($companyId));
    }

    public function test_retry_pra_local_promote_allowed_within_quota_reporting_off(): void
    {
        $companyId = $this->makeCompany(['invoice_limit_override' => 1]);
        $billId = $this->makeProvisional($companyId, 'L001');

        $response = $this->actingAs($this->makeUser($companyId), 'pos')
            ->from('/pos/transactions')
            ->post("/pos/transaction/{$billId}/retry-pra");

        $response->assertSessionHas('success', __('pos.bill_now_final_pra_off', ['number' => 'L001']));

        $tx = DB::table('pos_transactions')->where('id', $billId)->first();
        $this->assertSame('pra', $tx->invoice_mode);
        $this->assertNull($tx->pra_status);
        $this->assertSame('L001', $tx->invoice_number, 'reporting-OFF promote keeps the L number');

        // Promotion consumed the slot.
        $this->assertFalse(PlanLimitService::canCreatePosBill($companyId)['allowed']);
    }

    public function test_retry_pra_plain_retry_of_failed_final_is_not_quota_charged(): void
    {
        // Agent-Sync company → retry just re-queues 'pending', zero network.
        $companyId = $this->makeCompany([
            'invoice_limit_override' => 1,
            'pra_reporting_enabled' => true,
            'agent_enabled' => true,
            'agent_submits_pra' => true,
        ]);
        // The failed FINAL itself already fills the quota (1/1).
        $billId = $this->makeFinal($companyId, 'POS-' . now()->format('Y') . '-00001', ['pra_status' => 'failed']);

        $response = $this->actingAs($this->makeUser($companyId), 'pos')
            ->from('/pos/transactions')
            ->post("/pos/transaction/{$billId}/retry-pra");

        // Quota is FULL, yet the plain retry must pass — it charged at creation.
        $response->assertSessionHas('success', __('pos.requeued_desktop_agent'));
        $response->assertSessionMissing('error');

        $this->assertSame('pending', DB::table('pos_transactions')->where('id', $billId)->value('pra_status'));
        $this->assertSame(1, $this->finalsCount($companyId), 'retry must not mint a second final');
    }

    public function test_retry_pra_null_final_submit_is_not_quota_charged(): void
    {
        $companyId = $this->makeCompany([
            'invoice_limit_override' => 1,
            'pra_reporting_enabled' => true,
            'agent_enabled' => true,
            'agent_submits_pra' => true,
        ]);
        // Reporting-OFF final (pra/NULL) fills the quota by itself.
        $billId = $this->makeFinal($companyId, 'L777');

        $response = $this->actingAs($this->makeUser($companyId), 'pos')
            ->from('/pos/transactions')
            ->post("/pos/transaction/{$billId}/retry-pra");

        // Per-bill "Submit to PRA" on an existing final is NOT a new bill.
        $response->assertSessionHas('success', __('pos.requeued_desktop_agent'));
        $response->assertSessionMissing('error');

        $tx = DB::table('pos_transactions')->where('id', $billId)->first();
        $this->assertSame('pending', $tx->pra_status);
        $this->assertSame('P001', $tx->invoice_number, 'PRA-bound submit allots the fiscal serial');
        $this->assertSame(1, $this->finalsCount($companyId));
    }

    // ════════════════════════════════════════════════════════════════════════
    // PATH 4 — PosController::apiPromoteProvisional (F10 promote, JSON)
    // ════════════════════════════════════════════════════════════════════════

    public function test_api_promote_blocked_at_quota_full(): void
    {
        $companyId = $this->makeCompany(['invoice_limit_override' => 1]);
        $this->makeFinal($companyId, 'F-001');
        $billId = $this->makeProvisional($companyId, 'L001');

        $response = $this->actingAs($this->makeUser($companyId), 'pos')
            ->postJson("/pos/api/provisional-bills/{$billId}/promote", ['payment_method' => 'cash']);

        $response->assertStatus(403)->assertJson([
            'success' => false,
            'message' => self::OVERRIDE_FULL_1,
        ]);

        $tx = DB::table('pos_transactions')->where('id', $billId)->first();
        $this->assertSame('local', $tx->invoice_mode);
        $this->assertSame('local', $tx->pra_status);
        $this->assertSame(1, $this->finalsCount($companyId));
    }

    public function test_api_promote_allowed_within_quota_reporting_off(): void
    {
        $companyId = $this->makeCompany(['invoice_limit_override' => 1]);
        $billId = $this->makeProvisional($companyId, 'L001');

        $response = $this->actingAs($this->makeUser($companyId), 'pos')
            ->postJson("/pos/api/provisional-bills/{$billId}/promote", ['payment_method' => 'cash']);

        $response->assertStatus(200)->assertJson(['success' => true]);

        $tx = DB::table('pos_transactions')->where('id', $billId)->first();
        $this->assertSame('completed', $tx->status);
        $this->assertSame('pra', $tx->invoice_mode);
        $this->assertNull($tx->pra_status); // reporting-OFF finalize
        $this->assertSame('L001', $tx->invoice_number);

        $this->assertFalse(PlanLimitService::canCreatePosBill($companyId)['allowed']);
    }

    public function test_api_promote_local_final_bypass_stays_quota_free(): void
    {
        // send_to_pra=false = deliberate LOCAL FINAL — archived, stays local/local,
        // and must remain reachable + quota-free even when the quota is FULL.
        $companyId = $this->makeCompany(['invoice_limit_override' => 1]);
        $this->makeFinal($companyId, 'F-001');
        $billId = $this->makeProvisional($companyId, 'L001');

        $response = $this->actingAs($this->makeUser($companyId), 'pos')
            ->postJson("/pos/api/provisional-bills/{$billId}/promote", ['send_to_pra' => false]);

        $response->assertStatus(200)->assertJson(['success' => true, 'local_final' => true]);

        $tx = DB::table('pos_transactions')->where('id', $billId)->first();
        $this->assertSame('local', $tx->invoice_mode, 'local final must STAY local — never a quota-charged promote');
        $this->assertSame('local', $tx->pra_status);
        $this->assertTrue((bool) $tx->is_archived);

        $this->assertSame(1, $this->finalsCount($companyId), 'local finals never consume monthly quota');
    }

    // ════════════════════════════════════════════════════════════════════════
    // TEAM-ACCOUNT QUOTA — PosController::toggleCashier (reactivation)
    // ════════════════════════════════════════════════════════════════════════

    public function test_reactivate_cashier_blocked_at_team_quota_full(): void
    {
        $companyId = $this->makeCompany(['user_limit_override' => 1]);
        $owner = $this->makeUser($companyId); // company_admin — quota-exempt
        $this->makeUser($companyId, ['pos_role' => 'pos_cashier', 'role' => null, 'is_active' => true]);
        $inactive = $this->makeUser($companyId, ['pos_role' => 'pos_cashier', 'role' => null, 'is_active' => false]);

        $response = $this->actingAs($owner, 'pos')
            ->from('/pos/team')
            ->post("/pos/team/cashier/{$inactive->id}/toggle");

        $response->assertSessionHas('error', 'Team account limit reached (1/1). Please contact admin.');
        $this->assertFalse((bool) DB::table('users')->where('id', $inactive->id)->value('is_active'), 'blocked account must stay deactivated');
    }

    public function test_reactivate_cashier_allowed_within_team_quota(): void
    {
        $companyId = $this->makeCompany(['user_limit_override' => 2]);
        $owner = $this->makeUser($companyId);
        $this->makeUser($companyId, ['pos_role' => 'pos_cashier', 'role' => null, 'is_active' => true]);
        $inactive = $this->makeUser($companyId, ['pos_role' => 'pos_cashier', 'role' => null, 'is_active' => false]);

        $response = $this->actingAs($owner, 'pos')
            ->from('/pos/team')
            ->post("/pos/team/cashier/{$inactive->id}/toggle");

        $response->assertSessionHas('success');
        $response->assertSessionMissing('error');
        $this->assertTrue((bool) DB::table('users')->where('id', $inactive->id)->value('is_active'));
    }

    public function test_reactivate_kitchen_account_is_exempt_from_team_quota(): void
    {
        // Confined roles (kitchen/waiter/delivery) never consume a slot.
        $companyId = $this->makeCompany(['user_limit_override' => 1]);
        $owner = $this->makeUser($companyId);
        $this->makeUser($companyId, ['pos_role' => 'pos_cashier', 'role' => null, 'is_active' => true]); // quota full
        $kitchen = $this->makeUser($companyId, ['pos_role' => 'pos_kitchen', 'role' => null, 'is_active' => false]);

        $response = $this->actingAs($owner, 'pos')
            ->from('/pos/team')
            ->post("/pos/team/cashier/{$kitchen->id}/toggle");

        $response->assertSessionHas('success');
        $this->assertTrue((bool) DB::table('users')->where('id', $kitchen->id)->value('is_active'));
    }

    // ════════════════════════════════════════════════════════════════════════
    // COUNTING INVARIANTS — PlanLimitService::canCreatePosBill
    // ════════════════════════════════════════════════════════════════════════

    public function test_counting_provisionals_and_drafts_are_free_finals_and_archived_count(): void
    {
        $companyId = $this->makeCompany(['invoice_limit_override' => 3]);

        $this->makeProvisional($companyId, 'L001');                                  // free
        $this->makeFinal($companyId, 'D-001', ['status' => 'draft']);                 // free
        $this->makeFinal($companyId, 'L050');                                        // counts (NULL-status final)
        $this->makeFinal($companyId, 'POS-2026-00001', [                              // counts (archived final)
            'pra_status' => 'submitted', 'is_archived' => true, 'archived_at' => now(),
        ]);
        $this->makeFinal($companyId, 'POS-2026-00002', [                              // counts (NULL invoice_mode legacy row)
            'invoice_mode' => null, 'pra_status' => 'submitted',
        ]);

        $quota = PlanLimitService::canCreatePosBill($companyId);
        // Exactly 3 counted → at limit 3 the next final is blocked; the (3/3)
        // message proves provisionals + drafts stayed out of the count.
        $this->assertFalse($quota['allowed']);
        $this->assertSame('Monthly bill limit reached (3/3 this month). Please contact admin.', $quota['reason']);
    }

    public function test_counting_day_close_deleted_finals_are_added_back(): void
    {
        $companyId = $this->makeCompany(['invoice_limit_override' => 2]);
        $this->makeFinal($companyId, 'L050');
        // Day-close DELETE policy hard-deleted one final earlier this month.
        DB::table('pos_day_close_reports')->insert([
            'company_id' => $companyId,
            'report_date' => now()->toDateString(),
            'deleted_final_count' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $quota = PlanLimitService::canCreatePosBill($companyId);
        $this->assertFalse($quota['allowed'], 'deleted finals must still occupy quota');
        $this->assertSame('Monthly bill limit reached (2/2 this month). Please contact admin.', $quota['reason']);
    }

    public function test_counting_prior_month_finals_are_excluded(): void
    {
        $companyId = $this->makeCompany(['invoice_limit_override' => 1]);
        $lastMonth = now()->subMonthNoOverflow();
        $this->makeFinal($companyId, 'L050', [
            'business_date' => $lastMonth->toDateString(),
            'created_at' => $lastMonth,
            'updated_at' => $lastMonth,
        ]);

        $quota = PlanLimitService::canCreatePosBill($companyId);
        $this->assertTrue($quota['allowed'], 'monthly quota resets each calendar month');
        $this->assertSame(1, $quota['remaining']);
    }

    public function test_counting_trial_plan_is_exempt_from_monthly_quota(): void
    {
        $companyId = $this->makeCompany();
        $this->subscribe($companyId, ['name' => 'Trial', 'is_trial' => true, 'invoice_limit' => 1], ['trial_ends_at' => now()->addDays(5)]);
        $this->makeFinal($companyId, 'L050');
        $this->makeFinal($companyId, 'L051');

        // 2 finals against invoice_limit 1 — trial plans skip the monthly gate
        // (their 20-bill total cap lives in SubscriptionAccessService).
        $this->assertTrue(PlanLimitService::canCreatePosBill($companyId)['allowed']);
    }

    // ════════════════════════════════════════════════════════════════════════
    // DELETE PATH — PosController::deleteTransaction (per-bill admin delete)
    //
    // The count is taken off the rows still in the table, so a hard delete used
    // to hand the slot straight back: delete a reporting-OFF final, bill again
    // for free, repeat past the package limit (Task 1372). The delete now banks
    // the bill in pos_bill_deletions and PlanLimitService adds it back — same
    // contract as the day-close DELETE policy.
    // ════════════════════════════════════════════════════════════════════════

    public function test_deleting_a_final_bill_does_not_give_back_monthly_quota(): void
    {
        $companyId = $this->makeCompany(['invoice_limit_override' => 1]);
        $billId = $this->makeFinal($companyId, 'L050');
        $this->assertFalse(PlanLimitService::canCreatePosBill($companyId)['allowed'], 'quota should be full before the delete');

        $response = $this->actingAs($this->makeUser($companyId), 'pos')
            ->from("/pos/transaction/{$billId}")
            ->delete("/pos/transaction/{$billId}");

        $response->assertRedirect('/pos/transactions')->assertSessionHas('success');
        $this->assertSame(0, $this->finalsCount($companyId), 'the bill really is gone');

        $quota = PlanLimitService::canCreatePosBill($companyId);
        $this->assertFalse($quota['allowed'], 'a deleted bill must keep occupying its slot this month');
        $this->assertSame(self::OVERRIDE_FULL_1, $quota['reason']);

        $this->assertDatabaseHas('pos_bill_deletions', [
            'company_id' => $companyId,
            'transaction_id' => $billId,
            'invoice_number' => 'L050',
        ]);
    }

    public function test_deleting_a_provisional_bill_is_never_banked(): void
    {
        // A deliberate provisional never consumed a slot, so there is nothing to
        // add back — deleting one must leave the allowance exactly as it was.
        $companyId = $this->makeCompany(['invoice_limit_override' => 1]);
        $billId = $this->makeProvisional($companyId, 'L001');

        $this->actingAs($this->makeUser($companyId), 'pos')
            ->from("/pos/transaction/{$billId}")
            ->delete("/pos/transaction/{$billId}")
            ->assertRedirect('/pos/transactions');

        $this->assertSame(0, DB::table('pos_bill_deletions')->count(), 'provisionals must leave no ledger row');

        $quota = PlanLimitService::canCreatePosBill($companyId);
        $this->assertTrue($quota['allowed']);
        $this->assertSame(1, $quota['remaining'], 'the full month allowance must still be there');
    }

    public function test_deleting_a_previous_month_bill_never_touches_this_months_count(): void
    {
        // Banked on the BILL's own created_at, so last month's delete lands in
        // last month — this month's allowance stays untouched.
        $companyId = $this->makeCompany(['invoice_limit_override' => 1]);
        $lastMonth = now()->subMonthNoOverflow();
        $billId = $this->makeFinal($companyId, 'L050', [
            'business_date' => $lastMonth->toDateString(),
            'created_at' => $lastMonth,
            'updated_at' => $lastMonth,
        ]);

        $this->actingAs($this->makeUser($companyId), 'pos')
            ->from("/pos/transaction/{$billId}")
            ->delete("/pos/transaction/{$billId}")
            ->assertRedirect('/pos/transactions');

        $this->assertDatabaseHas('pos_bill_deletions', ['transaction_id' => $billId]);

        $quota = PlanLimitService::canCreatePosBill($companyId);
        $this->assertTrue($quota['allowed'], "an old month's delete must not eat this month's allowance");
        $this->assertSame(1, $quota['remaining']);
    }

    public function test_counting_internal_account_bypasses_quota(): void
    {
        $companyId = $this->makeCompany(['is_internal_account' => true, 'invoice_limit_override' => 1]);
        $this->makeFinal($companyId, 'L050');
        $this->makeFinal($companyId, 'L051');

        $quota = PlanLimitService::canCreatePosBill($companyId);
        $this->assertTrue($quota['allowed']);
        $this->assertTrue($quota['internal'] ?? false);
    }
}
