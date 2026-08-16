<?php

namespace Tests\Feature;

use App\Services\PosFeatureService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Task 994 — pay_uuid REPLAY GUARD on the restaurant hold -> pay path.
 *
 * Owner report (16 Aug 2026): a slow Takeaway pay request errored LATE
 * client-side but had SUCCEEDED server-side; the retry re-held the cart and
 * paid a brand-new order -> duplicate bill + duplicate KOT. The client now
 * rides one pay_uuid per sale attempt on BOTH the hold and pay POSTs, and the
 * server must replay the original success payload instead of billing twice.
 *
 * Locked here (incl. the completion-review overlap fixes):
 *   1. Retry pay with a known uuid (different ghost order) -> replayed:true,
 *      ORIGINAL transaction data, ghost order cancelled, exactly ONE bill.
 *   2. Retry pay against the SAME already-completed order -> replay, not the
 *      dead "Order already paid" 400 (post-lock/pre-txn re-check).
 *   3. Retry hold with a known uuid -> already_paid:true short-circuit, no
 *      ghost order row is created at all.
 *   4. OVERLAP: loser of two same-uuid pays hits the UNIQUE(company_id,
 *      offline_uuid) index at insert time -> recovered into the winner's
 *      canonical replay payload (200), never a 500, still ONE bill.
 *   5. A DIFFERENT uuid never replays (fresh sale bills normally).
 *
 * Pattern: APP_ENV=testing + sqlite :memory: + minimal Schema::create,
 * HTTP through the real routes/middleware (same as PosMonthlyBillQuotaPathsTest,
 * whose payOrder schema this reuses). Companies stay reporting-OFF (no network).
 */
class PosPayUuidReplayGuardTest extends TestCase
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


    // ── helpers ──────────────────────────────────────────────────────────────

    private function payJson(int $orderId, \App\Models\User $user, array $body)
    {
        return $this->actingAs($user, 'pos')
            ->postJson("/pos/restaurant/orders/{$orderId}/pay", $body);
    }

    private function billCountForUuid(int $companyId, string $uuid): int
    {
        return DB::table('pos_transactions')
            ->where('company_id', $companyId)
            ->where('offline_uuid', $uuid)
            ->count();
    }

    // ── tests ────────────────────────────────────────────────────────────────

    public function test_retry_pay_on_ghost_order_replays_original_and_cancels_ghost(): void
    {
        $companyId = $this->makeRestaurantCompany();
        $user = $this->makeUser($companyId);
        $uuid = 'uuid-lost-response-1';

        $originalId = $this->makeOrder($companyId, ['order_type' => 'takeaway']);
        $first = $this->payJson($originalId, $user, ['payment_method' => 'cash', 'pay_uuid' => $uuid]);
        $first->assertStatus(200)->assertJson(['success' => true]);
        $txnId = $first->json('transaction_id');
        $invoice = $first->json('invoice_number');

        // Lost response -> cashier re-held the cart (ghost) and retries the pay.
        $ghostId = $this->makeOrder($companyId, ['order_type' => 'takeaway', 'status' => 'held']);
        $retry = $this->payJson($ghostId, $user, ['payment_method' => 'cash', 'pay_uuid' => $uuid]);

        $retry->assertStatus(200)->assertJson([
            'success' => true,
            'replayed' => true,
            'order_id' => $originalId,          // client reprints the ORIGINAL order's KOT
            'transaction_id' => $txnId,
            'invoice_number' => $invoice,
        ]);
        $this->assertSame(1, $this->billCountForUuid($companyId, $uuid), 'retry must never create a second bill');
        $this->assertSame('cancelled', DB::table('restaurant_orders')->where('id', $ghostId)->value('status'), 'ghost retry order must be tidied');
        $this->assertSame('completed', DB::table('restaurant_orders')->where('id', $originalId)->value('status'));
    }

    public function test_retry_pay_on_same_completed_order_replays_instead_of_400(): void
    {
        // Review fix lock: the first pay committed while the retry was already
        // past the early uuid lookup — the completed-order branch must ALSO
        // re-check the uuid and replay, never return the dead "already paid" 400.
        $companyId = $this->makeRestaurantCompany();
        $user = $this->makeUser($companyId);
        $uuid = 'uuid-same-order-retry';

        $orderId = $this->makeOrder($companyId, ['order_type' => 'takeaway']);
        $first = $this->payJson($orderId, $user, ['payment_method' => 'cash', 'pay_uuid' => $uuid]);
        $first->assertStatus(200);

        $retry = $this->payJson($orderId, $user, ['payment_method' => 'cash', 'pay_uuid' => $uuid]);
        $retry->assertStatus(200)->assertJson([
            'success' => true,
            'replayed' => true,
            'order_id' => $orderId,
            'transaction_id' => $first->json('transaction_id'),
        ]);
        $this->assertSame(1, $this->billCountForUuid($companyId, $uuid));
    }

    public function test_completed_order_without_matching_uuid_still_gets_400(): void
    {
        $companyId = $this->makeRestaurantCompany();
        $user = $this->makeUser($companyId);

        $orderId = $this->makeOrder($companyId, ['order_type' => 'takeaway']);
        $this->payJson($orderId, $user, ['payment_method' => 'cash', 'pay_uuid' => 'uuid-a'])->assertStatus(200);

        // A DIFFERENT uuid is a different sale attempt — no replay for it.
        $other = $this->payJson($orderId, $user, ['payment_method' => 'cash', 'pay_uuid' => 'uuid-b']);
        $other->assertStatus(400)->assertJson(['success' => false]);
        $this->assertSame(0, $this->billCountForUuid($companyId, 'uuid-b'));
    }

    public function test_retry_hold_with_known_uuid_short_circuits_already_paid(): void
    {
        $companyId = $this->makeRestaurantCompany();
        $user = $this->makeUser($companyId);
        $uuid = 'uuid-hold-retry';

        $originalId = $this->makeOrder($companyId, ['order_type' => 'takeaway']);
        $first = $this->payJson($originalId, $user, ['payment_method' => 'cash', 'pay_uuid' => $uuid]);
        $first->assertStatus(200);
        $ordersBefore = DB::table('restaurant_orders')->count();

        // Lost response -> the client's retry re-POSTs the HOLD with the same uuid.
        $hold = $this->actingAs($user, 'pos')->postJson('/pos/restaurant/orders/hold', [
            'order_type' => 'takeaway',
            'billing_flow' => true,
            'pay_uuid' => $uuid,
            'items' => [[
                'item_type' => 'product', 'item_id' => 9001, 'product_id' => 9001,
                'name' => 'Karahi', 'quantity' => 1, 'unit_price' => 100,
            ]],
        ]);

        $hold->assertStatus(200)->assertJson([
            'success' => true,
            'already_paid' => true,
            'order_id' => $originalId,
            'transaction_id' => $first->json('transaction_id'),
            'invoice_number' => $first->json('invoice_number'),
        ]);
        $this->assertSame($ordersBefore, DB::table('restaurant_orders')->count(), 'already_paid short-circuit must not create a ghost order');
        $this->assertSame(1, $this->billCountForUuid($companyId, $uuid));
    }

    public function test_duplicate_key_insert_recovers_into_replay_not_500(): void
    {
        // OVERLAP window (review fix lock): the retry's EARLY uuid lookup misses
        // because the winner has not committed yet; by the time the retry INSERTs
        // its transaction the winner IS committed, so the loser trips the unique
        // offline_uuid index and must be recovered into the winner's canonical
        // replay payload — never a 500, still exactly ONE bill.
        //
        // Race injection: DB::listen fires after every query. The FIRST
        // restaurant_orders select of the pay request runs AFTER the early
        // pos_transactions uuid lookup — committing the winner row at that
        // moment lands it exactly inside the lost-response overlap window
        // (post-early-guard, pre-INSERT; autocommit, so the loser's rollback
        // cannot undo it).
        $companyId = $this->makeRestaurantCompany();
        $user = $this->makeUser($companyId);
        $uuid = 'uuid-overlap-insert';

        $orderB = $this->makeOrder($companyId, ['order_type' => 'takeaway', 'status' => 'held']);

        $injected = false;
        $winnerId = null;
        DB::listen(function ($q) use (&$injected, &$winnerId, $companyId, $uuid) {
            if ($injected || !str_contains($q->sql, 'restaurant_orders')) {
                return;
            }
            $injected = true; // guard BEFORE inserting — the insert fires this listener again
            $winnerId = DB::table('pos_transactions')->insertGetId([
                'company_id' => $companyId,
                'invoice_number' => 'L-WINNER-1',
                'status' => 'completed',
                'invoice_mode' => 'pra',
                'pra_status' => null,
                'offline_uuid' => $uuid,
                'subtotal' => 100,
                'tax_amount' => 16,
                'total_amount' => 116,
                'payment_method' => 'cash',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        });

        $retry = $this->payJson($orderB, $user, ['payment_method' => 'cash', 'pay_uuid' => $uuid]);

        $this->assertTrue($injected, 'race injection must have fired');
        $retry->assertStatus(200)->assertJson([
            'success' => true,
            'replayed' => true,
            'transaction_id' => $winnerId,
            'invoice_number' => 'L-WINNER-1',
        ]);
        $this->assertSame(1, $this->billCountForUuid($companyId, $uuid), 'loser insert must not survive');
        // The loser's order was tidied by the recovery (ghost cancel) — the
        // winner txn has no linked order here, so orderB counts as a ghost.
        $this->assertSame('cancelled', DB::table('restaurant_orders')->where('id', $orderB)->value('status'));
    }
}
