<?php

namespace Tests\Feature;

use App\Services\PosBusinessDay;
use App\Services\PosFeatureService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * "PAISAY ONLINE AA RAHAY HAIN" — the bill stays OPEN until someone sees the
 * money (owner batch, 26 Aug 2026).
 *
 * The counter prints a Proof Bill that says NOT PAID and then waits for cash.
 * When the customer says they will transfer the money online, two things must
 * be true or the shop loses the sale:
 *
 *   1. The slip must say ONLINE, not NOT PAID (proof-bill.blade.php).
 *   2. The bill must NOT be finalisable until a human at the counter confirms
 *      the transfer actually landed.
 *
 * Rule 2 is enforced on the SERVER — inside payOrder — precisely because the
 * sale screen is not the only way to close a bill: the table board tile, a
 * stale second tab and a crafted POST all reach the same endpoint. A
 * client-side "are you sure?" would leave every one of those doors open.
 *
 * This file drives the real endpoints over HTTP:
 *
 *   • POST /pos/restaurant/orders/{id}/online-payment — set / clear the mark.
 *   • POST /pos/restaurant/orders/{id}/pay            — refuse (422) without
 *     the confirmation, settle with it, and clear the mark on completion.
 *
 * NEVER "fix" a failure here by making the gate client-side, by turning the
 * mark into a new `status` value (held/preparing/ready drive the KDS, the
 * table board, the pending-bills tile and the day-close blocker), or by
 * letting recall + re-hold drop the mark — adding one bottle to the order
 * would then silently unlock the bill.
 *
 * Pattern: APP_ENV=testing + sqlite :memory: + minimal Schema::create, real
 * routes and middleware (same shape as PosKotOnFinalPayResponseTest, whose
 * payOrder schema this reuses). Company stays reporting-OFF (no network).
 *
 * Run:
 *   php vendor/bin/phpunit tests/Feature/PosOnlinePaymentAwaitedTest.php --testdox
 */
class PosOnlinePaymentAwaitedTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

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
            $t->boolean('restaurant_mode')->default(true);
            $t->boolean('kot_on_final_if_unsent')->default(false);
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
            // The feature under test — same shape as the live migration.
            $t->timestamp('online_payment_awaited_at')->nullable();
            $t->unsignedBigInteger('online_payment_marked_by')->nullable();
            $t->text('kitchen_notes')->nullable();
            $t->string('kitchen_status')->nullable();
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

    private function makeShop(array $attrs = []): int
    {
        $companyId = (int) DB::table('companies')->insertGetId(array_merge([
            'name' => 'Online Adaigi Co',
            'product_type' => 'pos',
            'status' => 'active',
            'company_status' => 'active',
            'is_internal_account' => true,
            'pra_reporting_enabled' => false,
            'agent_enabled' => false,
            'pra_connection_mode' => 'cloud',
            'pra_environment' => 'sandbox',
            'inventory_enabled' => false,
            'restaurant_mode' => true,
            'feature_flags' => json_encode(['tables' => true, 'kot' => true, 'kitchen' => true]),
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
            'invoice_limit' => -1,
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
            'name' => 'Counter Wala',
            'email' => 'owner' . $companyId . '-' . (++$seq) . '@taxnest.test',
            'password' => bcrypt('Secret@12345'),
            'company_id' => $companyId,
            'role' => 'company_admin',
            'is_active' => true,
            'language' => 'en',
            'pra_reporting_enabled' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return \App\Models\User::find($id);
    }

    /** A held dine-in order on a table, one 100-rupee line. */
    private function makeOrder(int $companyId, array $attrs = []): int
    {
        $tableId = (int) DB::table('restaurant_tables')->insertGetId([
            'company_id' => $companyId,
            'table_number' => '02',
            'status' => 'occupied',
            'is_active' => true,
            'occupied_since' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $orderId = (int) DB::table('restaurant_orders')->insertGetId(array_merge([
            'company_id' => $companyId,
            'order_number' => 'ORD-' . uniqid(),
            'table_id' => $tableId,
            'order_type' => 'dine_in',
            'status' => 'held',
            'source' => 'pos',
            'customer_name' => 'Bilal',
            'customer_phone' => '03001234567',
            'subtotal' => 100,
            'discount_type' => 'amount',
            'discount_value' => 0,
            'discount_amount' => 0,
            'total_amount' => 100,
            'kot_sent_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ], $attrs));

        DB::table('restaurant_order_items')->insert([
            'order_id' => $orderId,
            'item_type' => 'product',
            'item_id' => 9001,
            'item_name' => 'Chicken Karahi',
            'quantity' => 1,
            'unit_price' => 100,
            'subtotal' => 100,
            'is_tax_exempt' => false,
            'item_discount_type' => 'percentage',
            'item_discount_value' => 0,
            'item_discount_amount' => 0,
            'kot_printed_at' => now(),
            'kot_batch_no' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $orderId;
    }

    // ── helpers ─────────────────────────────────────────────────────────────

    private function mark(int $orderId, \App\Models\User $user, bool $awaited)
    {
        return $this->actingAs($user, 'pos')
            ->postJson("/pos/restaurant/orders/{$orderId}/online-payment", ['awaited' => $awaited]);
    }

    private function pay(int $orderId, \App\Models\User $user, array $body = [])
    {
        return $this->actingAs($user, 'pos')
            ->postJson("/pos/restaurant/orders/{$orderId}/pay", array_merge([
                'payment_method' => 'cash',
            ], $body));
    }

    private function awaitedAt(int $orderId): ?string
    {
        return DB::table('restaurant_orders')->where('id', $orderId)->value('online_payment_awaited_at');
    }

    // ── 1. the mark itself ──────────────────────────────────────────────────

    public function test_counter_can_mark_and_unmark_an_order_as_waiting_on_an_online_transfer(): void
    {
        $companyId = $this->makeShop();
        $user = $this->makeCashier($companyId);
        $orderId = $this->makeOrder($companyId);

        $on = $this->mark($orderId, $user, true);
        $on->assertOk()->assertJson(['success' => true, 'awaited' => true]);
        $this->assertNotNull($this->awaitedAt($orderId), 'the mark must survive in the database, not just the response');
        $this->assertSame(
            $user->id,
            (int) DB::table('restaurant_orders')->where('id', $orderId)->value('online_payment_marked_by'),
            'the shop must be able to see WHO promised the transfer'
        );

        $off = $this->mark($orderId, $user, false);
        $off->assertOk()->assertJson(['success' => true, 'awaited' => false]);
        $this->assertNull($this->awaitedAt($orderId));
    }

    public function test_marking_an_order_from_another_shop_is_refused(): void
    {
        $mineId = $this->makeShop();
        $user = $this->makeCashier($mineId);
        $theirsId = $this->makeShop(['name' => 'Doosri Dukan']);
        $foreignOrder = $this->makeOrder($theirsId);

        $this->mark($foreignOrder, $user, true)->assertStatus(404);
        $this->assertNull($this->awaitedAt($foreignOrder));
    }

    // ── 2. the gate: no confirmation, no final bill ─────────────────────────

    public function test_a_marked_order_refuses_to_go_final_until_the_counter_confirms(): void
    {
        $companyId = $this->makeShop();
        $user = $this->makeCashier($companyId);
        $orderId = $this->makeOrder($companyId);
        $this->mark($orderId, $user, true)->assertOk();

        $res = $this->pay($orderId, $user);

        $res->assertStatus(422)->assertJson(['success' => false, 'code' => 'online_payment_awaited']);
        $this->assertSame(
            'held',
            DB::table('restaurant_orders')->where('id', $orderId)->value('status'),
            'a refused pay must leave the order open — the table is still occupied'
        );
        $this->assertSame(
            0,
            (int) DB::table('pos_transactions')->where('company_id', $companyId)->count(),
            'no bill may exist for money nobody has seen'
        );
    }

    public function test_confirming_the_transfer_settles_the_bill_and_clears_the_mark(): void
    {
        $companyId = $this->makeShop();
        $user = $this->makeCashier($companyId);
        $orderId = $this->makeOrder($companyId);
        $this->mark($orderId, $user, true)->assertOk();

        $res = $this->pay($orderId, $user, ['payment_method' => 'online', 'online_payment_confirmed' => true]);

        $res->assertOk()->assertJson(['success' => true]);
        $this->assertSame('completed', DB::table('restaurant_orders')->where('id', $orderId)->value('status'));
        $this->assertNull(
            $this->awaitedAt($orderId),
            'once the bill is final the wait is over — a leftover mark would haunt reports and reprints'
        );
        $this->assertSame(
            1,
            (int) DB::table('pos_transactions')->where('company_id', $companyId)->count()
        );
    }

    public function test_an_unmarked_order_still_pays_in_one_step(): void
    {
        // The gate must be invisible to every ordinary cash bill.
        $companyId = $this->makeShop();
        $user = $this->makeCashier($companyId);
        $orderId = $this->makeOrder($companyId);

        $this->pay($orderId, $user)->assertOk()->assertJson(['success' => true]);
        $this->assertSame('completed', DB::table('restaurant_orders')->where('id', $orderId)->value('status'));
    }

    public function test_the_mark_cannot_be_set_on_an_order_that_is_already_paid(): void
    {
        $companyId = $this->makeShop();
        $user = $this->makeCashier($companyId);
        $orderId = $this->makeOrder($companyId);
        $this->pay($orderId, $user)->assertOk();

        $this->mark($orderId, $user, true)->assertStatus(404);
        $this->assertNull($this->awaitedAt($orderId));
    }

    // ── 3. the OTHER door: a waiter's order settled at the counter ──────────
    //
    // payOrder is NOT the only way a restaurant order goes final. A waiter's
    // order is loaded into the cashier's cart and rung up through
    // /pos/invoice/store, and the sale screen has a client fallback that calls
    // the incoming-orders complete endpoint. A gate on payOrder alone would let
    // a marked bill walk straight out through either of those.

    private function settleAtCounter(int $orderId, \App\Models\User $user, array $body = [])
    {
        return $this->actingAs($user, 'pos')->postJson('/pos/invoice/store', array_merge([
            'items' => [[
                'type' => 'product',
                'item_id' => null,
                '_manual' => true,
                'name' => 'Chicken Karahi',
                'quantity' => 1,
                'unit_price' => 100,
                'is_tax_exempt' => false,
            ]],
            'payment_method' => 'cash',
            'discount_type' => 'amount',
            'discount_value' => 0,
            'incoming_order_id' => $orderId,
        ], $body));
    }

    public function test_a_marked_waiter_order_cannot_be_rung_up_at_the_counter_without_confirmation(): void
    {
        $companyId = $this->makeShop();
        $user = $this->makeCashier($companyId);
        $orderId = $this->makeOrder($companyId, ['source' => 'waiter']);
        $this->mark($orderId, $user, true)->assertOk();

        $res = $this->settleAtCounter($orderId, $user);

        $res->assertStatus(422)->assertJson(['success' => false, 'code' => 'online_payment_awaited']);
        $this->assertSame('held', DB::table('restaurant_orders')->where('id', $orderId)->value('status'));
        $this->assertSame(
            0,
            (int) DB::table('pos_transactions')->where('company_id', $companyId)->count(),
            'the gate must refuse BEFORE a bill exists — no orphan transaction'
        );
    }

    public function test_confirming_the_transfer_lets_the_counter_ring_up_the_waiter_order(): void
    {
        $companyId = $this->makeShop();
        $user = $this->makeCashier($companyId);
        $orderId = $this->makeOrder($companyId, ['source' => 'waiter']);
        $this->mark($orderId, $user, true)->assertOk();

        $res = $this->settleAtCounter($orderId, $user, ['online_payment_confirmed' => true]);

        $res->assertOk()->assertJson(['success' => true, 'waiter_order_settled' => true]);
        $this->assertSame('completed', DB::table('restaurant_orders')->where('id', $orderId)->value('status'));
        $this->assertNull($this->awaitedAt($orderId), 'settling a marked waiter order must end the wait too');
    }

    public function test_the_client_fallback_complete_endpoint_is_gated_as_well(): void
    {
        $companyId = $this->makeShop();
        $user = $this->makeCashier($companyId);
        $orderId = $this->makeOrder($companyId, ['source' => 'waiter']);
        $this->mark($orderId, $user, true)->assertOk();

        // A bill that exists but has NOT been linked to the order yet — exactly
        // what the fallback carries when the store response missed the link.
        $txnId = (int) DB::table('pos_transactions')->insertGetId([
            'company_id' => $companyId,
            'invoice_number' => 'L001',
            'status' => 'completed',
            'invoice_mode' => 'local',
            'total_amount' => 100,
            'payment_method' => 'cash',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $blocked = $this->actingAs($user, 'pos')
            ->postJson("/pos/api/incoming-orders/{$orderId}/complete", ['transaction_id' => $txnId]);

        $blocked->assertStatus(422)->assertJson(['success' => false, 'code' => 'online_payment_awaited']);
        $this->assertSame('held', DB::table('restaurant_orders')->where('id', $orderId)->value('status'));

        $allowed = $this->actingAs($user, 'pos')
            ->postJson("/pos/api/incoming-orders/{$orderId}/complete", [
                'transaction_id' => $txnId,
                'online_payment_confirmed' => true,
            ]);

        $allowed->assertOk()->assertJson(['success' => true]);
        $this->assertSame('completed', DB::table('restaurant_orders')->where('id', $orderId)->value('status'));
        $this->assertNull($this->awaitedAt($orderId));
    }
}
