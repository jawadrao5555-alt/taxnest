<?php

namespace Tests\Feature;

use Tests\TestCase;
use App\Http\Controllers\RestaurantWaiterController;
use App\Models\Company;
use App\Models\PosTransaction;
use App\Models\RestaurantOrder;
use App\Models\User;
use App\Services\PosBusinessDay;
use App\Services\PosFeatureService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * CONCURRENT WAITER-ORDER PAYMENT RACE — Task 923.
 *
 * Task 880 moved settleWaiterOrder() inside the DB transaction in storeInvoice,
 * making the settle atomic with the bill commit. When a second cashier races to
 * pay the same waiter order, settleWaiterOrder returns false → storeInvoice
 * throws RuntimeException(__('pos.waiter_order_already_settled')) → DB::rollBack
 * → the duplicate bill never lands and the caller receives a 500 JSON error.
 *
 * ── Section A: HTTP integration (storeInvoice route) ────────────────────────
 *
 *   1. First cashier POSTs /pos/invoice/store with an incoming_order_id → 200,
 *      one PosTransaction row, order linked to that transaction.
 *   2. Second cashier POSTs the same incoming_order_id → 500 JSON whose
 *      message contains the waiter_order_already_settled text.
 *   3. After the failed second POST, still exactly ONE PosTransaction row.
 *   4. The restaurant order's pos_transaction_id matches the FIRST transaction.
 *
 * ── Section B: atomic settle unit tests ────────────────────────────────────
 *
 *   5. Double settleWaiterOrder call → first true / second false, one link.
 *   6. Rollback path: a hand-rolled transaction wrapping the settle mirrors
 *      storeInvoice's DB::transaction block and verifies no extra row persists.
 *   7. Assigned-cashier variant: different user refused, first txn link survives.
 *
 * Pattern: sqlite :memory: + minimal Schema::create, full HTTP through the real
 * route stack for Section A (same approach as PosDineInTableRequiredInvoicePathTest),
 * direct helper calls for Section B.
 *
 * Run:
 *   php vendor/bin/phpunit tests/Feature/PosWaiterConcurrentPayTest.php --testdox
 */
class PosWaiterConcurrentPayTest extends TestCase
{
    // ── Section B constants ───────────────────────────────────────────────
    private const UNIT_COMPANY_ID = 523;
    private const UNIT_TABLE_ID   = 33;

    protected function setUp(): void
    {
        parent::setUp();

        PosFeatureService::flushGateCaches();

        Schema::dropAllTables();

        // ── companies ────────────────────────────────────────────────────
        Schema::create('companies', function (Blueprint $t) {
            $t->id();
            $t->string('name');
            $t->string('product_type')->default('pos');
            $t->string('status')->default('active');
            $t->string('company_status')->default('active');
            $t->boolean('is_internal_account')->default(false);
            $t->integer('invoice_limit_override')->nullable();
            $t->boolean('pra_reporting_enabled')->default(false);
            $t->boolean('inventory_enabled')->default(false);
            $t->text('feature_flags')->nullable();
            $t->string('business_category')->nullable();
            $t->boolean('restaurant_mode')->default(false);
            $t->decimal('cashier_discount_limit', 8, 2)->nullable();
            $t->decimal('pos_tax_rate_cash', 8, 2)->nullable();
            $t->decimal('pos_tax_rate_card', 8, 2)->nullable();
            $t->string('pos_business_day_cutoff', 5)->nullable();
            $t->softDeletes();
            $t->timestamps();
        });

        // ── users ────────────────────────────────────────────────────────
        Schema::create('users', function (Blueprint $t) {
            $t->id();
            $t->string('name');
            $t->string('email')->unique();
            $t->string('password');
            $t->unsignedBigInteger('company_id')->nullable();
            $t->string('role')->nullable();
            $t->string('pos_role')->nullable();
            $t->boolean('is_active')->default(true);
            $t->boolean('pra_reporting_enabled')->nullable();
            $t->rememberToken();
            $t->timestamps();
        });

        // ── pricing_plans + subscriptions (plan.limit:invoices middleware) ─
        Schema::create('pricing_plans', function (Blueprint $t) {
            $t->id();
            $t->string('name');
            $t->string('product_type')->default('pos');
            $t->boolean('is_trial')->default(false);
            $t->boolean('offline_enabled')->default(true);
            $t->boolean('deals_enabled')->default(false);
            $t->integer('invoice_limit')->nullable();
            $t->timestamps();
        });

        Schema::create('subscriptions', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('company_id');
            $t->unsignedBigInteger('pricing_plan_id')->nullable();
            $t->boolean('active')->default(true);
            $t->date('start_date')->nullable();
            $t->date('end_date')->nullable();
            $t->string('override_type')->default('none');
            $t->timestamp('override_expires_at')->nullable();
            $t->unsignedBigInteger('override_by')->nullable();
            $t->text('override_reason')->nullable();
            $t->timestamp('override_granted_at')->nullable();
            $t->integer('free_invoice_limit')->nullable();
            $t->timestamps();
        });

        // ── pos_transactions ─────────────────────────────────────────────
        Schema::create('pos_transactions', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('company_id');
            $t->unsignedBigInteger('branch_id')->nullable();
            $t->unsignedBigInteger('terminal_id')->nullable();
            $t->string('invoice_number');
            $t->string('invoice_mode')->nullable();
            $t->unsignedBigInteger('customer_id')->nullable();
            $t->string('customer_name')->nullable();
            $t->string('customer_phone')->nullable();
            $t->string('delivery_address')->nullable();
            $t->decimal('subtotal', 12, 2)->default(0);
            $t->string('discount_type')->nullable();
            $t->decimal('discount_value', 12, 2)->default(0);
            $t->decimal('discount_amount', 12, 2)->default(0);
            $t->decimal('tax_rate', 8, 2)->default(0);
            $t->decimal('tax_amount', 12, 2)->default(0);
            $t->decimal('exempt_amount', 12, 2)->default(0);
            $t->decimal('total_amount', 12, 2)->default(0);
            $t->string('payment_method')->nullable();
            $t->decimal('cash_received', 12, 2)->nullable();
            $t->decimal('change_due', 12, 2)->nullable();
            $t->string('status');
            $t->string('pra_status')->nullable();
            $t->string('pra_invoice_number')->nullable();
            $t->string('submission_hash')->nullable();
            $t->string('offline_uuid', 64)->nullable();
            $t->unsignedBigInteger('created_by')->nullable();
            $t->text('notes')->nullable();
            $t->boolean('is_archived')->default(false);
            $t->timestamp('archived_at')->nullable();
            $t->string('business_date')->nullable();
            $t->string('order_type')->nullable();
            $t->timestamps();
            $t->unique(['company_id', 'invoice_number']);
            $t->unique(['company_id', 'offline_uuid'], 'pos_txn_offline_uuid_unique');
        });

        Schema::create('pos_transaction_items', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('transaction_id');
            $t->string('item_type')->default('product');
            $t->unsignedBigInteger('item_id')->nullable();
            $t->string('item_name');
            $t->text('special_notes')->nullable();
            $t->text('deal_snapshot')->nullable();
            $t->decimal('quantity', 10, 2)->default(1);
            $t->decimal('unit_price', 12, 2)->default(0);
            $t->decimal('subtotal', 12, 2)->default(0);
            $t->boolean('is_tax_exempt')->default(false);
            $t->decimal('tax_rate', 8, 2)->default(0);
            $t->decimal('tax_amount', 12, 2)->default(0);
            $t->timestamps();
        });

        Schema::create('pos_payments', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('transaction_id');
            $t->string('payment_method');
            $t->decimal('amount', 12, 2)->default(0);
            $t->string('reference_number')->nullable();
            $t->timestamps();
        });

        Schema::create('branches', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('company_id');
            $t->string('name')->nullable();
            $t->boolean('is_head_office')->default(false);
            $t->boolean('is_active')->default(true);
            $t->timestamps();
        });

        Schema::create('pos_tax_rules', function (Blueprint $t) {
            $t->id();
            $t->string('payment_method');
            $t->decimal('tax_rate', 8, 2)->default(0);
            $t->boolean('is_active')->default(true);
            $t->timestamps();
        });
        DB::table('pos_tax_rules')->insert([
            ['payment_method' => 'cash', 'tax_rate' => 16, 'is_active' => true, 'created_at' => now(), 'updated_at' => now()],
        ]);

        Schema::create('pos_day_close_reports', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('company_id');
            $t->date('report_date');
            $t->string('report_number')->nullable();
            $t->integer('deleted_final_count')->default(0);
            $t->integer('deleted_provisional_count')->default(0);
            $t->integer('total_invoices')->default(0);
            $t->decimal('total_amount', 14, 2)->default(0);
            $t->unsignedBigInteger('closed_by')->nullable();
            $t->text('notes')->nullable();
            $t->string('hash')->nullable();
            $t->timestamps();
        });

        // ── restaurant_orders + restaurant_tables (the waiter-order settle path) ─
        Schema::create('restaurant_orders', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('company_id');
            $t->unsignedBigInteger('pos_transaction_id')->nullable();
            $t->string('order_number');
            $t->string('source')->default('waiter');
            $t->string('status')->default('held');
            $t->string('payment_method')->nullable();
            $t->unsignedBigInteger('assigned_cashier_id')->nullable();
            $t->unsignedBigInteger('table_id')->nullable();
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
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    // ── HTTP integration seed helpers ────────────────────────────────────

    /**
     * Create a company + unlimited plan + admin user that can call storeInvoice.
     * is_internal_account=true → restaurantAllowed() passes without a plan column.
     */
    private function makeRestaurantShop(string $slug = 'shop'): array
    {
        $companyId = DB::table('companies')->insertGetId([
            'name' => 'Race Shop ' . $slug,
            'product_type' => 'pos',
            'status' => 'active',
            'company_status' => 'active',
            'is_internal_account' => true,
            'restaurant_mode' => true,
            'feature_flags' => json_encode(['tables' => true, 'kot' => true]),
            'pra_reporting_enabled' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        PosBusinessDay::forgetCutoff($companyId);

        $planId = DB::table('pricing_plans')->insertGetId([
            'name' => 'Business',
            'product_type' => 'pos',
            'offline_enabled' => true,
            'is_trial' => false,
            'invoice_limit' => -1, // unlimited
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

        $userId = DB::table('users')->insertGetId([
            'name' => 'Cashier ' . $slug,
            'email' => "cashier-{$slug}@race.pk",
            'password' => bcrypt('secret'),
            'company_id' => $companyId,
            'pos_role' => 'pos_cashier',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return [Company::findOrFail($companyId), User::findOrFail($userId)];
    }

    private function seedWaiterOrder(int $companyId, ?int $assignedTo = null): int
    {
        return DB::table('restaurant_orders')->insertGetId([
            'company_id' => $companyId,
            'order_number' => 'ORD-' . uniqid(),
            'source' => 'waiter',
            'status' => 'held',
            'assigned_cashier_id' => $assignedTo,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function salePayload(int $orderId, array $overrides = []): array
    {
        return array_merge([
            'items' => [[
                'type' => 'product',
                'item_id' => null,
                '_manual' => true,
                'name' => 'Biryani',
                'quantity' => 1,
                'unit_price' => 350,
                'is_tax_exempt' => false,
            ]],
            'payment_method' => 'cash',
            'discount_type' => 'amount',
            'discount_value' => 0,
            'incoming_order_id' => $orderId,
        ], $overrides);
    }

    // ════════════════════════════════════════════════════════════════════════
    // SECTION A — HTTP integration through storeInvoice
    // ════════════════════════════════════════════════════════════════════════

    /**
     * First POST with incoming_order_id → 200, bill persisted, order linked.
     */
    public function test_first_cashier_pays_waiter_order_successfully(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-08-16 14:00:00'));
        [$company, $cashierA] = $this->makeRestaurantShop('first');
        $orderId = $this->seedWaiterOrder($company->id, $cashierA->id);

        $res = $this->actingAs($cashierA, 'pos')
            ->postJson('/pos/invoice/store', $this->salePayload($orderId));

        $res->assertOk()->assertJson(['success' => true]);
        $this->assertSame(1, (int) DB::table('pos_transactions')->count(),
            'Exactly one bill must be created after the first pay');

        $order = DB::table('restaurant_orders')->find($orderId);
        $this->assertSame('completed', $order->status);
        $this->assertNotNull($order->pos_transaction_id,
            'pos_transaction_id must be linked after the first pay');
    }

    /**
     * Second POST for the SAME incoming_order_id → 500 JSON containing the
     * already-settled message; no extra PosTransaction row is persisted.
     */
    public function test_second_cashier_gets_500_with_already_settled_error(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-08-16 14:00:00'));
        [$company, $cashierA] = $this->makeRestaurantShop('second');

        // Second cashier is a separate user on the same company.
        $cashierBId = DB::table('users')->insertGetId([
            'name' => 'Cashier B',
            'email' => 'cashier-b@race.pk',
            'password' => bcrypt('secret'),
            'company_id' => $company->id,
            'pos_role' => 'pos_cashier',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $cashierB = User::findOrFail($cashierBId);

        // Unassigned order so either cashier may legitimately attempt it.
        $orderId = $this->seedWaiterOrder($company->id, null);

        // ── Cashier A pays first ──────────────────────────────────────────
        $this->actingAs($cashierA, 'pos')
            ->postJson('/pos/invoice/store', $this->salePayload($orderId))
            ->assertOk()
            ->assertJson(['success' => true]);

        $winnerTxnId = DB::table('pos_transactions')->value('id');

        // ── Cashier B tries the same order ───────────────────────────────
        $res = $this->actingAs($cashierB, 'pos')
            ->postJson('/pos/invoice/store', $this->salePayload($orderId));

        $res->assertStatus(500);
        $body = $res->json();
        $this->assertFalse($body['success']);
        // The 500 message is: failed_create_invoice wrapping waiter_order_already_settled.
        // Use the lang helper so the assertion passes regardless of active locale.
        $this->assertStringContainsString(
            __('pos.waiter_order_already_settled'),
            $body['message'],
            '500 JSON message must contain the waiter_order_already_settled lang text'
        );
    }

    /**
     * After the failed second POST, exactly ONE PosTransaction row survives and
     * the restaurant order's pos_transaction_id points to the FIRST transaction.
     */
    public function test_only_one_bill_persists_after_concurrent_pay_attempt(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-08-16 14:00:00'));
        [$company, $cashierA] = $this->makeRestaurantShop('oneonly');

        $cashierBId = DB::table('users')->insertGetId([
            'name' => 'Cashier B2',
            'email' => 'cashier-b2@race.pk',
            'password' => bcrypt('secret'),
            'company_id' => $company->id,
            'pos_role' => 'pos_cashier',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $cashierB = User::findOrFail($cashierBId);

        $orderId = $this->seedWaiterOrder($company->id, null);

        // A wins.
        $this->actingAs($cashierA, 'pos')
            ->postJson('/pos/invoice/store', $this->salePayload($orderId))
            ->assertOk();

        $winnerTxnId = (int) DB::table('pos_transactions')->value('id');

        // B fails.
        $this->actingAs($cashierB, 'pos')
            ->postJson('/pos/invoice/store', $this->salePayload($orderId))
            ->assertStatus(500);

        // Exactly one transaction row: the rolled-back bill must not persist.
        $this->assertSame(1, (int) DB::table('pos_transactions')->count(),
            'Rolled-back duplicate bill must not persist — only the winner\'s bill survives');

        // The order must still point to A's transaction.
        $order = DB::table('restaurant_orders')->find($orderId);
        $this->assertSame($winnerTxnId, (int) $order->pos_transaction_id,
            'pos_transaction_id must remain the FIRST (winning) transaction after rollback');
    }

    // ════════════════════════════════════════════════════════════════════════
    // SECTION B — Unit tests for the settleWaiterOrder atomic claim
    // ════════════════════════════════════════════════════════════════════════

    // ── Section B seed helpers ────────────────────────────────────────────

    private function makeUnitOrder(?int $assignedTo = null): int
    {
        return DB::table('restaurant_orders')->insertGetId([
            'company_id' => self::UNIT_COMPANY_ID,
            'order_number' => 'ORD-UNIT-' . uniqid(),
            'source' => 'waiter',
            'status' => 'held',
            'assigned_cashier_id' => $assignedTo,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function makeUnitUser(int $id, string $posRole): User
    {
        $u = new User();
        $u->id       = $id;
        $u->pos_role = $posRole;
        $u->role     = null;
        return $u;
    }

    private function makeUnitTxn(int $id): PosTransaction
    {
        DB::table('pos_transactions')->insert([
            'id'             => $id,
            'company_id'     => self::UNIT_COMPANY_ID,
            'invoice_number' => 'L-UNIT-' . $id,
            'payment_method' => 'cash',
            'status'         => 'completed',
            'created_at'     => now(),
            'updated_at'     => now(),
        ]);
        $txn = new PosTransaction(['payment_method' => 'cash']);
        $txn->id         = $id;
        $txn->company_id = self::UNIT_COMPANY_ID;
        return $txn;
    }

    private function unitOrderRow(int $id): object
    {
        return DB::table('restaurant_orders')->find($id);
    }

    /**
     * Double settleWaiterOrder: first call wins, second returns false,
     * only one pos_transaction_id is ever linked.
     */
    public function test_double_settle_call_first_wins_second_refused(): void
    {
        $cashierA = $this->makeUnitUser(201, 'pos_cashier');
        $cashierB = $this->makeUnitUser(202, 'pos_cashier');
        $orderId  = $this->makeUnitOrder(null);

        $txn1 = $this->makeUnitTxn(9301);
        $txn2 = $this->makeUnitTxn(9302);

        $first  = RestaurantWaiterController::settleWaiterOrder(self::UNIT_COMPANY_ID, $orderId, $txn1, $cashierA);
        $second = RestaurantWaiterController::settleWaiterOrder(self::UNIT_COMPANY_ID, $orderId, $txn2, $cashierB);

        $this->assertTrue($first,  'First cashier must win the settle');
        $this->assertFalse($second, 'Second cashier must be refused');

        $row = $this->unitOrderRow($orderId);
        $this->assertSame(9301, (int) $row->pos_transaction_id,
            'pos_transaction_id must be the FIRST (winning) transaction');
        $this->assertSame('completed', $row->status);
    }

    /**
     * storeInvoice rollback pattern: a hand-rolled transaction mirrors
     * the real storeInvoice DB::transaction block; verifies the rolled-back
     * duplicate transaction row does not persist.
     */
    public function test_rollback_on_failed_settle_leaves_no_extra_transaction(): void
    {
        $cashierA = $this->makeUnitUser(203, 'pos_cashier');
        $cashierB = $this->makeUnitUser(204, 'pos_cashier');
        $orderId  = $this->makeUnitOrder(null);

        // A wins.
        $txnA = $this->makeUnitTxn(9303);
        RestaurantWaiterController::settleWaiterOrder(self::UNIT_COMPANY_ID, $orderId, $txnA, $cashierA);
        $this->assertSame(1, (int) DB::table('pos_transactions')->count());

        // B tries — mirrors storeInvoice's try/catch/rollback.
        try {
            DB::beginTransaction();
            DB::table('pos_transactions')->insert([
                'id' => 9304, 'company_id' => self::UNIT_COMPANY_ID,
                'invoice_number' => 'L-UNIT-9304', 'payment_method' => 'cash',
                'status' => 'completed', 'created_at' => now(), 'updated_at' => now(),
            ]);
            $txnB = new PosTransaction(['payment_method' => 'cash']);
            $txnB->id = 9304;
            $txnB->company_id = self::UNIT_COMPANY_ID;

            $settled = RestaurantWaiterController::settleWaiterOrder(self::UNIT_COMPANY_ID, $orderId, $txnB, $cashierB);
            if (!$settled) {
                throw new \RuntimeException(__('pos.waiter_order_already_settled'));
            }
            DB::commit();
            $this->fail('RuntimeException was not thrown — test setup is wrong');
        } catch (\RuntimeException $e) {
            DB::rollBack();
            $this->assertStringContainsString(__('pos.waiter_order_already_settled'), $e->getMessage(),
                'Exception message must match the waiter_order_already_settled lang text');
        }

        // Only A's transaction survives.
        $this->assertSame(1, (int) DB::table('pos_transactions')->count(),
            'Rolled-back transaction must not persist');
        $this->assertSame(9303, (int) $this->unitOrderRow($orderId)->pos_transaction_id,
            'pos_transaction_id must remain the FIRST (winning) transaction after rollback');
    }

    /**
     * Assigned-order variant: the assigned cashier wins; a different user is
     * refused and their transaction rolls back.
     */
    public function test_race_on_assigned_order_second_cashier_refused(): void
    {
        $cashierA = $this->makeUnitUser(205, 'pos_cashier');
        $cashierB = $this->makeUnitUser(206, 'pos_cashier');
        $orderId  = $this->makeUnitOrder(205); // assigned to cashier A

        $txnA = $this->makeUnitTxn(9305);
        $txnB = $this->makeUnitTxn(9306);

        $winA = RestaurantWaiterController::settleWaiterOrder(self::UNIT_COMPANY_ID, $orderId, $txnA, $cashierA);
        $winB = RestaurantWaiterController::settleWaiterOrder(self::UNIT_COMPANY_ID, $orderId, $txnB, $cashierB);

        $this->assertTrue($winA,  'Assigned cashier must win');
        $this->assertFalse($winB, 'Different cashier must be refused');

        $row = $this->unitOrderRow($orderId);
        $this->assertSame(9305, (int) $row->pos_transaction_id);
        $this->assertSame('completed', $row->status);
    }
}
