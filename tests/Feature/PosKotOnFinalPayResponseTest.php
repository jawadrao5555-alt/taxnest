<?php

namespace Tests\Feature;

use App\Services\PosBusinessDay;
use App\Services\PosFeatureService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Task 1369 — the JOINT between Task 1356's two halves.
 *
 * The "bill final ho to KOT zaroor jaye" safety net is two pieces bolted
 * together: the server tells the sale screen a ticket is still owed through
 * two response keys (`kot_pending` + `kot_order_id`), and the sale screen's
 * auto-print chain prints the delta slip when it sees them.
 *
 * Both halves already had their own guard — KotPrintService has
 * PosKotOnFinalSafetyNetTest, the print chain has scripts/kot-on-final-check.mjs
 * — but NOTHING tested the wire between them: the actual JSON the payment
 * endpoints return. A refactor that dropped those two keys from a response, or
 * renamed them, would sail through every existing test and only surface in a
 * real shop, at the worst possible moment: the kitchen silently stops getting
 * orders. That is the exact silence that hid the bug the first time.
 *
 * So this file drives the REAL endpoints over HTTP and reads the response body:
 *
 *   • POST /pos/restaurant/orders/{id}/pay   — dine-in cart straight to CASH,
 *     the already-printed held order, the shop toggle, and the lost-response
 *     replay (same pay_uuid).
 *   • POST /pos/invoice/store                — the cashier settling a waiter's
 *     order (the only restaurant order that endpoint ever finalises).
 *
 * Every assertion checks the KEYS EXIST (assertJsonStructure) before checking
 * their values, so a rename fails loudly here instead of in a kitchen.
 *
 * NEVER "fix" a failure here by deleting the assertion or by reading the signal
 * off restaurant_orders.kot_sent_at — hold stamps kot_sent_at on every held
 * order, which is precisely why the original bug was invisible. The orders
 * seeded below carry kot_sent_at on purpose so that shortcut fails.
 *
 * Pattern: APP_ENV=testing + sqlite :memory: + minimal Schema::create, real
 * routes and middleware (same shape as PosPayUuidReplayGuardTest, whose
 * payOrder schema this extends). Companies stay reporting-OFF (no network).
 *
 * Run:
 *   php vendor/bin/phpunit tests/Feature/PosKotOnFinalPayResponseTest.php --testdox
 */
class PosKotOnFinalPayResponseTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // planAllows/restaurantAllowed cache per company id statically — ids
        // restart at 1 after dropAllTables, so stale verdicts would leak.
        PosFeatureService::flushGateCaches();

        Schema::dropAllTables();

        Schema::create('companies', function (Blueprint $t) {
            $t->id();
            $t->string('name');
            $t->string('product_type')->nullable();
            $t->string('status')->nullable();
            $t->string('company_status')->nullable();
            $t->string('default_language')->nullable();
            $t->boolean('is_internal_account')->default(false);
            $t->integer('invoice_limit_override')->nullable();
            $t->integer('user_limit_override')->nullable();
            $t->boolean('pra_reporting_enabled')->default(false);
            $t->boolean('agent_enabled')->default(false);
            $t->boolean('agent_submits_pra')->nullable();
            $t->string('pra_connection_mode')->nullable();
            $t->string('pra_environment')->nullable();
            $t->text('pra_production_token')->nullable();
            $t->string('pra_proxy_url')->nullable();
            $t->string('pra_pos_id')->nullable();
            $t->decimal('pos_tax_rate_cash', 8, 2)->nullable();
            $t->decimal('pos_tax_rate_card', 8, 2)->nullable();
            $t->string('pos_business_day_cutoff')->nullable();
            $t->boolean('inventory_enabled')->default(false);
            $t->decimal('cashier_discount_limit', 8, 2)->nullable();
            $t->string('pos_tax_pricing_mode')->nullable();
            $t->boolean('pos_tax_inclusive')->default(false);
            // The kitchen-flow trio the safety net reads.
            $t->boolean('restaurant_mode')->default(true);
            $t->boolean('kot_on_final_if_unsent')->default(true);
            $t->boolean('delivery_kot_after_payment')->default(false);
            $t->text('feature_flags')->nullable();
            $t->softDeletes();
            $t->timestamps();
        });

        Schema::create('users', function (Blueprint $t) {
            $t->id();
            $t->string('name')->nullable();
            $t->string('email')->nullable()->unique();
            $t->string('password')->nullable();
            $t->unsignedBigInteger('company_id')->nullable();
            $t->string('role')->nullable();
            $t->string('pos_role')->nullable();
            $t->boolean('is_active')->default(true);
            $t->string('language')->nullable();
            // NULLABLE on purpose: NULL = inherit the company flag
            // (User::praReportingEnabled) — a false default would override it.
            $t->boolean('pra_reporting_enabled')->nullable();
            $t->rememberToken();
            $t->timestamps();
        });

        Schema::create('pos_transactions', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('company_id');
            $t->unsignedBigInteger('branch_id')->nullable();
            $t->unsignedBigInteger('terminal_id')->nullable();
            $t->unsignedBigInteger('customer_id')->nullable();
            $t->string('invoice_number');
            $t->string('business_date')->nullable();
            $t->string('status');
            $t->string('invoice_mode')->nullable();
            $t->string('order_type')->nullable();
            $t->string('pra_status')->nullable();
            $t->string('pra_invoice_number')->nullable();
            $t->string('pra_response_code')->nullable();
            $t->text('pra_qr_code')->nullable();
            $t->boolean('is_archived')->default(false);
            $t->timestamp('archived_at')->nullable();
            $t->unsignedBigInteger('archived_by_report_id')->nullable();
            $t->unsignedBigInteger('created_by')->nullable();
            $t->string('customer_name')->nullable();
            $t->string('customer_phone')->nullable();
            $t->string('delivery_address')->nullable();
            $t->decimal('subtotal', 12, 2)->default(0);
            $t->string('discount_type')->nullable();
            $t->decimal('discount_value', 12, 2)->nullable();
            $t->decimal('discount_amount', 12, 2)->default(0);
            $t->decimal('tax_rate', 8, 2)->nullable();
            $t->decimal('tax_amount', 12, 2)->default(0);
            $t->decimal('exempt_amount', 12, 2)->nullable();
            $t->decimal('total_amount', 12, 2)->default(0);
            $t->string('payment_method')->nullable();
            $t->decimal('cash_received', 12, 2)->nullable();
            $t->decimal('change_due', 12, 2)->nullable();
            $t->string('submission_hash')->nullable();
            $t->string('offline_uuid', 64)->nullable();
            $t->boolean('tax_inclusive')->default(false);
            $t->decimal('tax_menu_rate', 8, 2)->nullable();
            $t->text('notes')->nullable();
            $t->timestamps();
            // Mirrors PROD: the race-window safety net behind both replay guards.
            $t->unique(['company_id', 'offline_uuid'], 'pos_txn_offline_uuid_unique');
        });

        Schema::create('pos_transaction_items', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('transaction_id');
            $t->string('item_type')->nullable();
            $t->unsignedBigInteger('item_id')->nullable();
            $t->string('item_name');
            $t->text('special_notes')->nullable();
            $t->text('deal_snapshot')->nullable();
            $t->decimal('quantity', 12, 3)->default(1);
            $t->decimal('unit_price', 12, 2)->default(0);
            $t->decimal('subtotal', 12, 2)->default(0);
            $t->boolean('is_tax_exempt')->default(false);
            $t->decimal('tax_rate', 8, 2)->nullable();
            $t->decimal('tax_amount', 12, 2)->nullable();
            $t->string('item_discount_type')->nullable();
            $t->decimal('item_discount_value', 12, 2)->nullable();
            $t->decimal('item_discount_amount', 12, 2)->nullable();
            $t->timestamps();
        });

        Schema::create('pos_payments', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('transaction_id');
            $t->string('payment_method')->nullable();
            $t->decimal('amount', 12, 2)->default(0);
            $t->string('reference_number')->nullable();
            $t->timestamps();
        });

        Schema::create('branches', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('company_id');
            $t->string('name')->nullable();
            $t->boolean('is_active')->default(true);
            $t->boolean('is_head_office')->default(false);
            $t->timestamps();
        });

        Schema::create('pra_logs', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('company_id')->nullable();
            $t->unsignedBigInteger('transaction_id')->nullable();
            $t->text('request_payload')->nullable();
            $t->text('response_payload')->nullable();
            $t->string('response_code')->nullable();
            $t->string('status')->nullable();
            $t->timestamps();
        });

        // Monthly quota adds back finals hard-deleted by the day-close DELETE policy.
        Schema::create('pos_day_close_reports', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('company_id');
            $t->date('report_date');
            $t->integer('deleted_final_count')->default(0);
            $t->timestamps();
        });

        Schema::create('pricing_plans', function (Blueprint $t) {
            $t->id();
            $t->string('name');
            $t->string('product_type')->default('pos');
            $t->boolean('is_trial')->default(false);
            $t->integer('invoice_limit')->nullable();
            $t->integer('user_limit')->nullable();
            $t->boolean('restaurant_enabled')->default(true);
            $t->boolean('offline_enabled')->default(true);
            $t->boolean('deals_enabled')->default(false);
            $t->timestamps();
        });

        Schema::create('subscriptions', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('company_id');
            $t->unsignedBigInteger('pricing_plan_id')->nullable();
            $t->boolean('active')->default(true);
            $t->date('start_date')->nullable();
            $t->date('end_date')->nullable();
            $t->timestamp('trial_ends_at')->nullable();
            $t->string('override_type')->default('none');
            $t->timestamp('override_until')->nullable();
            $t->timestamp('override_granted_at')->nullable();
            $t->integer('free_invoice_limit')->nullable();
            $t->timestamps();
        });

        Schema::create('restaurant_orders', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('company_id');
            $t->string('order_number')->nullable();
            $t->unsignedBigInteger('table_id')->nullable();
            $t->string('order_type')->nullable();
            $t->string('status')->nullable();
            $t->string('source')->default('pos');
            $t->unsignedBigInteger('assigned_cashier_id')->nullable();
            $t->unsignedBigInteger('customer_id')->nullable();
            $t->string('customer_name')->nullable();
            $t->string('customer_phone')->nullable();
            $t->string('delivery_address')->nullable();
            $t->decimal('subtotal', 12, 2)->default(0);
            $t->string('discount_type')->nullable();
            $t->decimal('discount_value', 12, 2)->nullable();
            $t->decimal('discount_amount', 12, 2)->default(0);
            $t->decimal('tax_amount', 12, 2)->default(0);
            $t->decimal('total_amount', 12, 2)->default(0);
            $t->string('payment_method')->nullable();
            $t->text('kitchen_notes')->nullable();
            $t->string('kitchen_status')->nullable();
            // Stamped by hold on EVERY held order — never a "kitchen saw it" signal.
            $t->timestamp('kot_sent_at')->nullable();
            $t->timestamp('kitchen_cleared_at')->nullable();
            $t->unsignedBigInteger('pos_transaction_id')->nullable();
            $t->unsignedBigInteger('created_by')->nullable();
            $t->timestamps();
        });

        Schema::create('restaurant_order_items', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('order_id');
            $t->string('item_type')->nullable();
            $t->unsignedBigInteger('item_id')->nullable();
            $t->string('item_name');
            $t->decimal('quantity', 12, 3)->default(1);
            $t->decimal('unit_price', 12, 2)->default(0);
            $t->decimal('subtotal', 12, 2)->default(0);
            $t->boolean('is_tax_exempt')->default(false);
            $t->string('item_discount_type')->nullable();
            $t->decimal('item_discount_value', 12, 2)->nullable();
            $t->decimal('item_discount_amount', 12, 2)->nullable();
            // The ONLY trustworthy "the kitchen printed this line" stamp.
            $t->timestamp('kot_printed_at')->nullable();
            $t->integer('kot_batch_no')->nullable();
            $t->timestamps();
        });

        Schema::create('restaurant_tables', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('company_id');
            $t->string('table_number')->nullable();
            $t->integer('seats')->default(4);
            $t->string('status')->default('occupied');
            $t->unsignedBigInteger('locked_by_user_id')->nullable();
            $t->timestamp('locked_at')->nullable();
            $t->timestamp('occupied_since')->nullable();
            $t->boolean('is_active')->default(true);
            $t->timestamps();
        });

        // payOrder's stock validation queries recipes for product lines even
        // when inventory is OFF — the table must exist (and stays empty here).
        Schema::create('product_recipes', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('company_id');
            $t->unsignedBigInteger('product_id');
            $t->unsignedBigInteger('ingredient_id');
            $t->decimal('quantity_needed', 12, 4)->default(0);
            $t->timestamps();
        });
    }

    // ── fixtures ────────────────────────────────────────────────────────────

    /**
     * A reporting-OFF restaurant shop that really uses kitchen tickets:
     * restaurant_mode ON, KOT feature ON (it depends on 'kitchen'), safety-net
     * toggle ON, unlimited plan so the quota gate never interferes.
     */
    private function makeShop(array $attrs = []): int
    {
        $companyId = (int) DB::table('companies')->insertGetId(array_merge([
            'name' => 'KOT Contract Co',
            'product_type' => 'pos',
            'status' => 'active',
            'company_status' => 'active',
            'is_internal_account' => true, // restaurantAllowed() without plan columns
            'pra_reporting_enabled' => false,
            'agent_enabled' => false,
            'pra_connection_mode' => 'cloud',
            'pra_environment' => 'sandbox',
            'inventory_enabled' => false,
            'restaurant_mode' => true,
            'kot_on_final_if_unsent' => true,
            'feature_flags' => json_encode(['tables' => true, 'kot' => true, 'kitchen' => true]),
            // Company-level rate overrides → the PosTaxRule table is never consulted.
            'pos_tax_rate_cash' => 16.00,
            'pos_tax_rate_card' => 16.00,
            'created_at' => now(),
            'updated_at' => now(),
        ], $attrs));

        PosBusinessDay::forgetCutoff($companyId);
        PosFeatureService::flushGateCaches();

        $planId = (int) DB::table('pricing_plans')->insertGetId([
            'name' => 'Business',
            'product_type' => 'pos',
            'is_trial' => false,
            'invoice_limit' => -1, // unlimited
            'restaurant_enabled' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('subscriptions')->insert([
            'company_id' => $companyId,
            'pricing_plan_id' => $planId,
            'active' => true,
            'start_date' => now()->subMonth()->toDateString(),
            'end_date' => now()->addMonth()->toDateString(),
            'override_type' => 'none',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $companyId;
    }

    private function makeCashier(int $companyId): \App\Models\User
    {
        static $seq = 0;
        $id = DB::table('users')->insertGetId([
            'name' => 'POS Owner',
            'email' => 'owner' . $companyId . '-' . (++$seq) . '@taxnest.test',
            'password' => bcrypt('Secret@12345'),
            'company_id' => $companyId,
            'role' => 'company_admin',
            'is_active' => true,
            'language' => 'en',
            'pra_reporting_enabled' => null, // inherit the company flag
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return \App\Models\User::find($id);
    }

    private function makeTable(int $companyId): int
    {
        return (int) DB::table('restaurant_tables')->insertGetId([
            'company_id' => $companyId,
            'table_number' => '02', // the owner's video table
            'status' => 'occupied',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /**
     * A held restaurant order.
     *
     * @param  array<int, bool>  $linesPrinted  one entry per line; true = the kitchen printed it
     */
    private function makeOrder(int $companyId, array $linesPrinted, array $attrs = []): int
    {
        $orderId = (int) DB::table('restaurant_orders')->insertGetId(array_merge([
            'company_id' => $companyId,
            'order_number' => 'ORD-' . uniqid(),
            'order_type' => 'dine_in',
            'status' => 'held',
            'source' => 'pos',
            'subtotal' => 100 * max(1, count($linesPrinted)),
            'discount_type' => 'amount',
            'discount_value' => 0,
            'discount_amount' => 0,
            'total_amount' => 100 * max(1, count($linesPrinted)),
            // Deliberately stamped, exactly like holdOrder does on EVERY held
            // order: any implementation that trusts kot_sent_at fails here.
            'kot_sent_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ], $attrs));

        foreach ($linesPrinted as $i => $printed) {
            DB::table('restaurant_order_items')->insert([
                'order_id' => $orderId,
                'item_type' => 'product',
                'item_id' => 9001 + $i, // no recipe rows → stock validation is a no-op
                'item_name' => 'Karahi ' . ($i + 1),
                'quantity' => 1,
                'unit_price' => 100,
                'subtotal' => 100,
                'is_tax_exempt' => false,
                'item_discount_type' => 'percentage',
                'item_discount_value' => 0,
                'item_discount_amount' => 0,
                'kot_printed_at' => $printed ? now() : null,
                'kot_batch_no' => $printed ? 1 : null,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        return $orderId;
    }

    /** The kitchen printed the whole order — what a real KOT render stamps. */
    private function stampKitchenPrinted(int $orderId): void
    {
        DB::table('restaurant_order_items')
            ->where('order_id', $orderId)
            ->whereNull('kot_printed_at')
            ->update(['kot_printed_at' => now(), 'kot_batch_no' => 1]);
    }

    // ── helpers ─────────────────────────────────────────────────────────────

    private function pay(int $orderId, \App\Models\User $user, array $body = [])
    {
        return $this->actingAs($user, 'pos')
            ->postJson("/pos/restaurant/orders/{$orderId}/pay", array_merge([
                'payment_method' => 'cash',
            ], $body));
    }

    private function settleWaiterOrderAtCounter(int $orderId, \App\Models\User $user)
    {
        return $this->actingAs($user, 'pos')->postJson('/pos/invoice/store', [
            'items' => [[
                'type' => 'product',
                'item_id' => null,
                '_manual' => true,
                'name' => 'Karahi 1',
                'quantity' => 1,
                'unit_price' => 100,
                'is_tax_exempt' => false,
            ]],
            'payment_method' => 'cash',
            'discount_type' => 'amount',
            'discount_value' => 0,
            'incoming_order_id' => $orderId,
        ]);
    }

    /**
     * The whole point of this file: BOTH keys must be present in the body and
     * carry exactly these values. Structure first, so a rename/removal fails
     * with "missing key" rather than a confusing value mismatch.
     */
    private function assertKotSignal($response, bool $pending, ?int $orderId): void
    {
        $response->assertJsonStructure(['kot_pending', 'kot_order_id']);
        $this->assertSame(
            $pending,
            $response->json('kot_pending'),
            'kot_pending is the sale screen\'s ONLY instruction to print a rescue ticket'
        );
        $this->assertSame(
            $orderId,
            $response->json('kot_order_id'),
            'kot_order_id tells the sale screen WHICH order to print — a wrong/absent id prints nothing'
        );
    }

    // ── 1. payOrder: the owner's Table 02 bill ──────────────────────────────

    public function test_dine_in_cart_paid_straight_to_cash_returns_a_pending_kitchen_ticket(): void
    {
        // The owner's video: cashier hits CASH on a dine-in cart without ever
        // pressing "Send to Kitchen". The response MUST ask for the slip.
        $companyId = $this->makeShop();
        $user = $this->makeCashier($companyId);
        $orderId = $this->makeOrder($companyId, [false, false], ['table_id' => $this->makeTable($companyId)]);

        $res = $this->pay($orderId, $user);

        $res->assertOk()->assertJson(['success' => true]);
        $this->assertKotSignal($res, true, $orderId);
    }

    public function test_held_order_whose_ticket_already_printed_asks_for_no_second_slip(): void
    {
        $companyId = $this->makeShop();
        $user = $this->makeCashier($companyId);
        $orderId = $this->makeOrder($companyId, [true, true], ['table_id' => $this->makeTable($companyId)]);

        $res = $this->pay($orderId, $user);

        $res->assertOk()->assertJson(['success' => true]);
        $this->assertKotSignal($res, false, null);
    }

    // ── 2. payOrder: the shop-level switch ──────────────────────────────────

    public function test_shop_toggle_off_silences_the_signal_in_the_pay_response(): void
    {
        // Same unseen-lines cart as the first test — only the switch differs.
        $companyId = $this->makeShop(['kot_on_final_if_unsent' => false]);
        $user = $this->makeCashier($companyId);
        $orderId = $this->makeOrder($companyId, [false, false], ['table_id' => $this->makeTable($companyId)]);

        $res = $this->pay($orderId, $user);

        $res->assertOk()->assertJson(['success' => true]);
        $this->assertKotSignal($res, false, null);
    }

    // ── 3. storeInvoice: the cashier settling a waiter's order ──────────────

    public function test_settling_a_waiter_order_the_kitchen_already_cooked_asks_for_no_slip(): void
    {
        $companyId = $this->makeShop();
        $user = $this->makeCashier($companyId);
        $orderId = $this->makeOrder($companyId, [true], ['source' => 'waiter']);

        $res = $this->settleWaiterOrderAtCounter($orderId, $user);

        $res->assertOk()->assertJson(['success' => true, 'waiter_order_settled' => true]);
        $this->assertKotSignal($res, false, null);
    }

    public function test_settling_a_waiter_order_the_kitchen_never_saw_still_owes_a_slip(): void
    {
        // Without this case a refactor could hardcode kot_pending=false on the
        // storeInvoice path and every other test here would still pass.
        $companyId = $this->makeShop();
        $user = $this->makeCashier($companyId);
        $orderId = $this->makeOrder($companyId, [false], ['source' => 'waiter']);

        $res = $this->settleWaiterOrderAtCounter($orderId, $user);

        $res->assertOk()->assertJson(['success' => true, 'waiter_order_settled' => true]);
        $this->assertKotSignal($res, true, $orderId);
    }

    // ── 4. replayPayByUuid: the lost response ───────────────────────────────

    public function test_replay_of_a_lost_pay_response_repeats_the_same_kot_signal(): void
    {
        // The first pay SUCCEEDED but its response never reached the terminal,
        // so the print chain never ran — the slip is still owed and the replay
        // must say so, pointing at the ORIGINAL order.
        $companyId = $this->makeShop();
        $user = $this->makeCashier($companyId);
        $uuid = 'uuid-kot-lost-response';
        $orderId = $this->makeOrder($companyId, [false, false], ['table_id' => $this->makeTable($companyId)]);

        $first = $this->pay($orderId, $user, ['pay_uuid' => $uuid]);
        $first->assertOk();
        $this->assertKotSignal($first, true, $orderId);

        // Lost response → the cashier's terminal re-held the cart (ghost) and
        // retried the same sale attempt with the same uuid.
        $ghostId = $this->makeOrder($companyId, [false, false]);
        $retry = $this->pay($ghostId, $user, ['pay_uuid' => $uuid]);

        $retry->assertOk()->assertJson(['success' => true, 'replayed' => true]);
        $this->assertKotSignal($retry, true, $orderId);
        $this->assertSame(
            1,
            (int) DB::table('pos_transactions')->where('company_id', $companyId)->where('offline_uuid', $uuid)->count(),
            'the replay must never bill the sale twice'
        );
    }

    public function test_replay_after_the_ticket_printed_does_not_ask_for_a_second_slip(): void
    {
        // Same lost response, but this time the kitchen slip DID print before
        // the response went missing — the retry must print nothing.
        $companyId = $this->makeShop();
        $user = $this->makeCashier($companyId);
        $uuid = 'uuid-kot-printed-then-lost';
        $orderId = $this->makeOrder($companyId, [false], ['table_id' => $this->makeTable($companyId)]);

        $this->pay($orderId, $user, ['pay_uuid' => $uuid])->assertOk();
        $this->stampKitchenPrinted($orderId);

        $retry = $this->pay($orderId, $user, ['pay_uuid' => $uuid]);

        $retry->assertOk()->assertJson(['success' => true, 'replayed' => true]);
        $this->assertKotSignal($retry, false, null);
    }
}
