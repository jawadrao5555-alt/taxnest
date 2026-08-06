<?php

namespace Tests\Feature;

use Tests\TestCase;
use App\Models\Company;
use App\Models\FbrPosTransaction;
use App\Http\Controllers\FbrPosController;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * FBR POS store() idempotency replay-guard — end-to-end feature tests (Aug 2026).
 *
 * Locks the double-submit / lost-response-retry protection added to
 * FbrPosController::store(). Exercises the full controller code path
 * (DB writes, khata ledger, stock deduction) via the direct-call pattern
 * used throughout all FBR POS feature tests in this codebase.
 *
 * Scenarios:
 *   1. Normal single cash sale — works, returns success JSON.
 *   2. Double-POST same offline_uuid (cash) — exactly 1 transaction row,
 *      second response is success with replayed:true (not an error).
 *   3. Double-POST same offline_uuid (credit/udhaar) — exactly 1 transaction,
 *      exactly 1 khata ledger entry, second response is success.
 *   4. Double-POST same offline_uuid (inventory-enabled, linked product) —
 *      stock deducted exactly once (1 inventory_movements row).
 *   5. Race-loser forced-insert-conflict — a FbrPosTransaction::creating
 *      observer fires AFTER the app-level guard (which found nothing) but
 *      BEFORE the INSERT.  The observer:
 *        a) manually commits the loser's open PDO transaction (only SELECTs
 *           were done, so no real data is committed),
 *        b) inserts the "winner" row in auto-commit mode — immediately
 *           persistent on the connection,
 *        c) re-begins an empty PDO transaction so DB::transaction's error
 *           handler can cleanly roll it back,
 *        d) throws the QueryException(1062) the loser's INSERT would have
 *           received in production.
 *      The catch block in store() re-lookups by offline_uuid, finds the
 *      committed winner, and returns a replayed success response — no 500.
 *   6. Null offline_uuid — normal sales without a UUID still work (no
 *      unique-index conflict between two NULL-uuid bills).
 *
 * Pattern: APP_ENV=testing + sqlite :memory: + minimal Schema::create.
 * FBR-reporting is OFF on the company (fbr_reporting_enabled=false) so no
 * network call to FBR API is made — the controller's reporting-OFF final
 * path is exercised (invoiceMode='fbr', fbr_status=null).
 *
 * Run:
 *   php vendor/bin/phpunit tests/Feature/FbrPosStoreReplayGuardTest.php --testdox
 */
class FbrPosStoreReplayGuardTest extends TestCase
{
    protected int $companyId;
    protected object $user;

    protected function setUp(): void
    {
        parent::setUp();

        Schema::dropAllTables();

        // ── companies ────────────────────────────────────────────────────────
        Schema::create('companies', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('product_type')->nullable();
            $table->string('status')->nullable();
            $table->string('company_status')->nullable();
            $table->boolean('fbr_reporting_enabled')->default(false);
            $table->boolean('agent_enabled')->default(false);
            $table->boolean('agent_submits_pra')->nullable();
            $table->string('fbr_connection_mode')->nullable(); // null/'cloud' = no fiscal device
            $table->boolean('inventory_enabled')->default(false);
            $table->boolean('is_internal_account')->default(false);
            $table->integer('invoice_limit_override')->nullable();
            $table->decimal('cashier_discount_limit', 5, 2)->nullable();
            $table->decimal('manager_discount_limit', 5, 2)->nullable();
            $table->string('pos_invoice_prefix')->nullable();
            $table->softDeletes();
            $table->timestamps();
        });

        // ── users (fbrpos guard authenticates against this) ──────────────────
        Schema::create('users', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('email')->unique();
            $table->string('password');
            $table->unsignedBigInteger('company_id')->nullable();
            $table->string('role')->nullable();       // 'company_admin'
            $table->boolean('is_active')->default(true);
            $table->rememberToken();
            $table->timestamps();
        });

        // ── fbr_pos_transactions WITH offline_uuid ───────────────────────────
        Schema::create('fbr_pos_transactions', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('company_id');
            $table->unsignedBigInteger('branch_id')->nullable();
            $table->unsignedBigInteger('terminal_id')->nullable();
            $table->unsignedBigInteger('shift_id')->nullable();
            $table->string('invoice_number');
            $table->string('invoice_mode')->nullable();
            $table->string('transaction_type')->nullable();
            $table->string('status')->nullable();
            $table->string('fbr_status')->nullable();
            $table->string('fbr_invoice_number')->nullable();
            $table->string('fbr_response_code')->nullable();
            $table->text('fbr_response')->nullable();
            $table->string('fbr_submission_hash')->nullable();
            $table->unsignedBigInteger('customer_id')->nullable();
            $table->string('customer_name')->nullable();
            $table->string('customer_phone')->nullable();
            $table->string('customer_ntn')->nullable();
            $table->decimal('subtotal', 12, 2)->default(0);
            $table->string('discount_type')->nullable();
            $table->decimal('discount_value', 12, 2)->nullable();
            $table->decimal('discount_amount', 12, 2)->default(0);
            $table->decimal('tax_rate', 8, 2)->nullable();
            $table->decimal('tax_amount', 12, 2)->default(0);
            $table->decimal('fbr_service_charge', 12, 2)->nullable();
            $table->decimal('loyalty_points_earned', 12, 2)->nullable();
            $table->decimal('loyalty_points_redeemed', 12, 2)->nullable();
            $table->decimal('loyalty_redemption_amount', 12, 2)->nullable();
            $table->decimal('total_amount', 12, 2)->default(0);
            $table->string('payment_method')->nullable();
            $table->text('payment_breakdown')->nullable();
            $table->decimal('cash_received', 12, 2)->nullable();
            $table->decimal('change_due', 12, 2)->nullable();
            $table->unsignedBigInteger('promotion_id')->nullable();
            $table->string('promotion_code')->nullable();
            $table->unsignedBigInteger('parent_transaction_id')->nullable();
            $table->string('order_type')->nullable();
            $table->string('delivery_address')->nullable();
            // Aug 2026: idempotency key — THE column under test
            $table->string('offline_uuid', 64)->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamps();
            // Composite unique index (mirrors the real migration)
            $table->unique(['company_id', 'offline_uuid'], 'fbr_txn_offline_uuid_unique');
        });

        // ── fbr_pos_transaction_items ────────────────────────────────────────
        Schema::create('fbr_pos_transaction_items', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('transaction_id');
            $table->unsignedBigInteger('product_id')->nullable();
            $table->string('item_name');
            $table->string('hs_code')->nullable();
            $table->string('uom')->nullable();
            $table->decimal('quantity', 12, 4)->default(1);
            $table->decimal('unit_price', 12, 2)->default(0);
            // Aug 2026: cost snapshot for the Munafa profit report
            $table->decimal('cost_price', 12, 4)->nullable();
            $table->decimal('discount', 12, 2)->nullable();
            $table->decimal('item_discount', 12, 2)->nullable();
            $table->boolean('is_tax_exempt')->default(false);
            $table->decimal('tax_rate', 8, 2)->nullable();
            $table->decimal('tax_amount', 12, 2)->nullable();
            $table->decimal('subtotal', 12, 2)->nullable();
            $table->decimal('total', 12, 2)->nullable();
            $table->timestamps();
        });

        // ── fbr_pos_loyalty_settings (FbrPosLoyaltySetting::forCompany uses firstOrCreate) ──
        Schema::create('fbr_pos_loyalty_settings', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('company_id')->unique();
            $table->boolean('is_enabled')->default(false);
            $table->decimal('rs_per_point', 8, 2)->default(100);
            $table->decimal('point_value', 8, 2)->default(1);
            $table->integer('min_redeem_points')->default(50);
            $table->integer('points_expiry_days')->nullable();
            $table->timestamps();
        });

        // ── fbr_pos_shifts (queried but can return null — no data needed) ────
        Schema::create('fbr_pos_shifts', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('company_id');
            $table->unsignedBigInteger('user_id');
            $table->string('status')->default('open');
            $table->decimal('sales_count', 12, 2)->default(0);
            $table->decimal('total_sales', 12, 2)->default(0);
            $table->decimal('total_cash', 12, 2)->default(0);
            $table->decimal('total_card', 12, 2)->default(0);
            $table->decimal('total_other', 12, 2)->default(0);
            $table->timestamps();
        });

        // ── pos_customers (for credit/udhaar sales) ──────────────────────────
        Schema::create('pos_customers', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('company_id');
            $table->string('name');
            $table->string('phone')->nullable();
            $table->decimal('khata_balance', 12, 2)->default(0);
            $table->integer('loyalty_points')->default(0);
            $table->decimal('total_spent', 12, 2)->default(0);
            $table->integer('total_orders')->default(0);
            $table->timestamps();
        });

        // ── fbr_customer_ledgers (khata ledger for credit sales) ─────────────
        Schema::create('fbr_customer_ledgers', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('company_id');
            $table->unsignedBigInteger('customer_id');
            $table->unsignedBigInteger('transaction_id')->nullable();
            $table->string('entry_type');
            $table->decimal('amount', 12, 2);
            $table->decimal('balance_after', 12, 2);
            $table->string('note')->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamps();
        });

        // ── products (for fixed-price enforcement lookup) ─────────────────────
        Schema::create('products', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('company_id');
            $table->string('name');
            $table->decimal('default_price', 12, 2)->default(0);
            $table->boolean('is_price_editable')->default(true);
            $table->timestamps();
        });

        // ── inventory_stocks + inventory_movements (stock deduction) ──────────
        Schema::create('inventory_stocks', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('company_id');
            $table->unsignedBigInteger('product_id');
            $table->unsignedBigInteger('branch_id')->nullable();
            $table->decimal('quantity', 12, 4)->default(0);
            $table->decimal('min_stock_level', 12, 4)->default(0);
            $table->decimal('avg_purchase_price', 12, 2)->default(0);
            $table->decimal('last_purchase_price', 12, 2)->default(0);
            $table->timestamps();
        });

        Schema::create('inventory_movements', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('company_id');
            $table->unsignedBigInteger('product_id');
            $table->unsignedBigInteger('branch_id')->nullable();
            $table->string('type')->nullable();
            $table->decimal('quantity', 12, 4)->default(0);
            $table->decimal('unit_price', 12, 2)->default(0);
            $table->decimal('total_price', 12, 2)->default(0);
            $table->decimal('balance_after', 12, 4)->default(0);
            $table->string('reference_type')->nullable();
            $table->unsignedBigInteger('reference_id')->nullable();
            $table->string('reference_number')->nullable();
            $table->text('notes')->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamps();
        });

        // ── seed company + user ───────────────────────────────────────────────
        $company = Company::create([
            'name' => 'FBR Replay Test Shop',
            'fbr_reporting_enabled' => false, // reporting-OFF → no FBR API call
            'agent_enabled' => false,
            'fbr_connection_mode' => 'cloud',
            'inventory_enabled' => false,
        ]);
        $this->companyId = $company->id;

        // Pre-seed loyalty settings so FbrPosLoyaltySetting::forCompany() only
        // issues a SELECT inside DB::transaction — keeping the loser connection
        // as a pure reader (no writes) at the point the creating event fires in
        // test 5.  This matters because pdo->commit() in the event is a no-op
        // when there are no pending writes.
        DB::table('fbr_pos_loyalty_settings')->insert([
            'company_id'        => $this->companyId,
            'is_enabled'        => false,
            'rs_per_point'      => 100.00,
            'point_value'       => 1.00,
            'min_redeem_points' => 50,
            'created_at'        => now()->toDateTimeString(),
            'updated_at'        => now()->toDateTimeString(),
        ]);

        $this->user = (object) [
            'id' => DB::table('users')->insertGetId([
                'name' => 'Test Cashier',
                'email' => 'cashier@fbrtest.pk',
                'password' => bcrypt('test'),
                'company_id' => $this->companyId,
                'role' => 'company_admin',
                'created_at' => now(),
                'updated_at' => now(),
            ]),
            'company_id' => $this->companyId,
            'role' => 'company_admin',
            'pos_role' => 'pos_admin',
        ];

        // Bind currentCompanyId — mirrors fbrpos.auth middleware behaviour
        app()->bind('currentCompanyId', fn () => $this->companyId);
        // Bind currentBranchId as null so the branch_id store is skipped cleanly
        app()->bind('currentBranchId', fn () => null);
    }

    protected function tearDown(): void
    {
        // Remove the creating event listener registered by test 5 so it does
        // not bleed into subsequent tests in the same process.
        $dispatcher = FbrPosTransaction::getEventDispatcher();
        if ($dispatcher) {
            $dispatcher->forget('eloquent.creating: ' . FbrPosTransaction::class);
        }

        parent::tearDown();
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Helpers
    // ─────────────────────────────────────────────────────────────────────────

    /** Build a basic cash-sale payload. Override keys as needed. */
    private function cashPayload(string $offlineUuid, array $override = []): array
    {
        return array_merge([
            'items' => [[
                'item_name'    => 'Rooh Afza',
                'quantity'     => 2,
                'unit_price'   => 150,
                'uom'          => 'U',
                'tax_rate'     => 0,
                'is_tax_exempt'=> true,
                'item_discount'=> 0,
            ]],
            'payment_method' => 'cash',
            'cash_received'  => 300,
            'discount_type'  => 'percentage',
            'discount_value' => 0,
            'tax_inclusive'  => false,
            'offline_uuid'   => $offlineUuid,
        ], $override);
    }

    /** Invoke FbrPosController::store() with a JSON-accepting request. */
    private function callStore(array $payload): \Illuminate\Http\JsonResponse|\Illuminate\Http\RedirectResponse
    {
        // Auth guard must know the current user — mirrors actingAs behaviour
        // for the fbrpos guard without routing through middleware.
        $userModel = new \App\Models\User();
        $userModel->id = $this->user->id;
        $userModel->role = $this->user->role;
        $userModel->company_id = $this->companyId;
        \Illuminate\Support\Facades\Auth::guard('fbrpos')->setUser($userModel);

        $req = Request::create('/fbr-pos/store', 'POST', $payload);
        $req->headers->set('Accept', 'application/json');

        return (new FbrPosController())->store($req);
    }

    /** Count rows in a table with optional wheres. */
    private function dbCount(string $table, array $where = []): int
    {
        $q = DB::table($table);
        foreach ($where as $col => $val) { $q->where($col, $val); }
        return $q->count();
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Test 1 — Normal single cash sale works end to end
    // ─────────────────────────────────────────────────────────────────────────

    public function test_single_cash_sale_creates_transaction_and_items(): void
    {
        $uuid = 'e2e-cash-' . uniqid();
        $res = $this->callStore($this->cashPayload($uuid));

        $data = $res->getData(true);
        $this->assertTrue($data['success'], 'success flag');
        $this->assertFalse($data['replayed'] ?? false, 'must not be a replay');
        $this->assertArrayHasKey('transaction_id', $data);
        $this->assertArrayHasKey('invoice_number', $data);
        $this->assertSame(300.0, (float) $data['total_amount']);

        // DB state
        $this->assertSame(1, $this->dbCount('fbr_pos_transactions', ['company_id' => $this->companyId]));
        $txId = $data['transaction_id'];
        $this->assertSame(1, $this->dbCount('fbr_pos_transaction_items', ['transaction_id' => $txId]));

        // offline_uuid stored on the row
        $storedUuid = DB::table('fbr_pos_transactions')->where('id', $txId)->value('offline_uuid');
        $this->assertSame($uuid, $storedUuid, 'offline_uuid stored in DB');
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Test 2 — Double-POST same offline_uuid (cash sale)
    // ─────────────────────────────────────────────────────────────────────────

    public function test_double_post_same_uuid_cash_returns_success_and_creates_one_row(): void
    {
        $uuid = 'e2e-double-cash-' . uniqid();
        $payload = $this->cashPayload($uuid);

        // First submit
        $res1 = $this->callStore($payload);
        $data1 = $res1->getData(true);
        $this->assertTrue($data1['success'], '1st response success');
        $this->assertFalse($data1['replayed'] ?? false, '1st must not be replayed');
        $txId = $data1['transaction_id'];

        // Second submit — identical payload, same offline_uuid
        $res2 = $this->callStore($payload);
        $data2 = $res2->getData(true);

        // ── Core assertions ───────────────────────────────────────────────────
        // a) Second response is SUCCESS (not an error — client must not show error popup)
        $this->assertTrue($data2['success'], 'second response must be success');
        $this->assertTrue($data2['replayed'] ?? false, 'second response must carry replayed:true');

        // b) Exactly ONE transaction row in DB
        $this->assertSame(
            1,
            $this->dbCount('fbr_pos_transactions', ['company_id' => $this->companyId]),
            'DB: exactly 1 transaction row after double-POST'
        );

        // c) Second response returns the SAME transaction
        $this->assertSame($txId, $data2['transaction_id'], 'same transaction_id returned');
        $this->assertSame($data1['invoice_number'], $data2['invoice_number'], 'same invoice_number');
        $this->assertSame(300.0, (float) $data2['total_amount'], 'same total_amount');

        // d) Response shape the UI expects is present
        $this->assertArrayHasKey('fbr_invoice_number', $data2);
        $this->assertArrayHasKey('fbr_status', $data2);
        $this->assertArrayHasKey('change_due', $data2);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Test 3 — Double-POST same offline_uuid (credit / udhaar sale)
    //          Khata ledger must NOT be duplicated
    // ─────────────────────────────────────────────────────────────────────────

    public function test_double_post_credit_sale_creates_one_transaction_and_one_ledger_entry(): void
    {
        // Create a saved customer
        $customerId = DB::table('pos_customers')->insertGetId([
            'company_id'    => $this->companyId,
            'name'          => 'Rehman Sahib',
            'phone'         => '03001234567',
            'khata_balance' => 0,
            'created_at'    => now(),
            'updated_at'    => now(),
        ]);

        $uuid = 'e2e-credit-' . uniqid();
        $payload = $this->cashPayload($uuid, [
            'payment_method' => 'credit',
            'customer_id'    => $customerId,
            'customer_name'  => 'Rehman Sahib',
            'cash_received'  => 0,
        ]);

        // First submit
        $res1 = $this->callStore($payload);
        $this->assertTrue($res1->getData(true)['success'], '1st credit response success');
        $txId = $res1->getData(true)['transaction_id'];

        // Second submit — identical payload, same offline_uuid
        $res2 = $this->callStore($payload);
        $data2 = $res2->getData(true);

        // a) Second response is SUCCESS
        $this->assertTrue($data2['success'], 'second credit response must be success');
        $this->assertTrue($data2['replayed'] ?? false, 'second credit response must be replayed');

        // b) Exactly ONE transaction row
        $this->assertSame(
            1,
            $this->dbCount('fbr_pos_transactions', ['company_id' => $this->companyId]),
            'DB: exactly 1 transaction row'
        );

        // c) Exactly ONE khata ledger entry (duplicate would double the debt)
        $ledgerCount = $this->dbCount('fbr_customer_ledgers', [
            'company_id'  => $this->companyId,
            'customer_id' => $customerId,
            'entry_type'  => 'udhaar',
        ]);
        $this->assertSame(1, $ledgerCount, 'DB: exactly 1 khata ledger entry — no duplicate debt');

        // d) khata_balance updated exactly once (Rs 300, not Rs 600)
        $balance = DB::table('pos_customers')->where('id', $customerId)->value('khata_balance');
        $this->assertSame('300.00', number_format((float) $balance, 2), 'khata_balance debited once');
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Test 4 — Double-POST same offline_uuid (inventory-enabled, linked product)
    //          Stock must be deducted exactly once
    // ─────────────────────────────────────────────────────────────────────────

    public function test_double_post_inventory_sale_deducts_stock_exactly_once(): void
    {
        // Enable inventory on company
        DB::table('companies')
            ->where('id', $this->companyId)
            ->update(['inventory_enabled' => true]);

        // Seed a product and its opening stock (50 units)
        $productId = DB::table('products')->insertGetId([
            'company_id'       => $this->companyId,
            'name'             => 'Sprite 500ml',
            'default_price'    => 80,
            'is_price_editable'=> true,
            'created_at'       => now(),
            'updated_at'       => now(),
        ]);
        DB::table('inventory_stocks')->insertGetId([
            'company_id' => $this->companyId,
            'product_id' => $productId,
            'branch_id'  => null,
            'quantity'   => 50,
            'min_stock_level'    => 0,
            'avg_purchase_price' => 80,
            'last_purchase_price'=> 80,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $uuid = 'e2e-inventory-' . uniqid();
        $payload = $this->cashPayload($uuid, [
            'items' => [[
                'item_name'     => 'Sprite 500ml',
                'quantity'      => 3,
                'unit_price'    => 80,
                'product_id'    => $productId,
                'uom'           => 'U',
                'tax_rate'      => 0,
                'is_tax_exempt' => true,
                'item_discount' => 0,
            ]],
            'cash_received' => 240,
        ]);

        // First submit
        $res1 = $this->callStore($payload);
        $this->assertTrue($res1->getData(true)['success'], '1st inventory response success');

        // Second submit — same uuid
        $res2 = $this->callStore($payload);
        $data2 = $res2->getData(true);
        $this->assertTrue($data2['success'], 'second inventory response must be success');
        $this->assertTrue($data2['replayed'] ?? false, 'second must be replayed');

        // Exactly 1 transaction row
        $this->assertSame(
            1,
            $this->dbCount('fbr_pos_transactions', ['company_id' => $this->companyId]),
            'DB: exactly 1 transaction row'
        );

        // Stock deducted exactly once (3 units, from 50 → 47)
        $stockQty = DB::table('inventory_stocks')
            ->where('company_id', $this->companyId)
            ->where('product_id', $productId)
            ->value('quantity');
        $this->assertSame('47.0000', number_format((float) $stockQty, 4), 'stock deducted exactly once (50 - 3 = 47)');

        // Exactly 1 inventory_movement row
        $movementCount = $this->dbCount('inventory_movements', [
            'company_id' => $this->companyId,
            'product_id' => $productId,
        ]);
        $this->assertSame(1, $movementCount, 'DB: exactly 1 inventory movement — no double deduction');

        // Restore
        DB::table('companies')->where('id', $this->companyId)->update(['inventory_enabled' => false]);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Test 5 — Race-loser forced-insert-conflict: QueryException(1062) path
    //
    // Why the creating-event + manual-commit approach?
    //
    // In production the race is concurrent: Request A (winner) commits before
    // Request B (loser) reaches its INSERT, so B's INSERT gets 1062. But in a
    // single-thread :memory: SQLite test there is no concurrency: if we
    // pre-insert the winner, the app-level guard finds it immediately and
    // returns early (never reaching the catch block).
    //
    // Solution: intercept FbrPosTransaction::creating (fires INSIDE the
    // loser's DB::transaction, AFTER the guard already found nothing):
    //
    //   a) pdo->commit() — the loser's transaction contained only SELECTs, so
    //      committing is a logical no-op.  The connection is now in auto-commit
    //      mode, just as if the PDO tx had never started.
    //   b) Insert the winner via DB::table()->insertGetId() — because PDO has
    //      no active transaction the INSERT auto-commits immediately, making it
    //      visible on the same connection once the loser's "transaction" ends.
    //   c) pdo->beginTransaction() — gives DB::transaction's error handler a
    //      real PDO tx to roll back; without this it would throw
    //      "There is no active transaction" instead of the 1062 exception.
    //   d) throw QueryException(1062) — the exception the loser's INSERT would
    //      have received in production (same SQLSTATE code + index name that
    //      the catch block in store() checks for).
    //
    //   Flow result:
    //   • DB::transaction: rolls back the empty tx from (c), rethrows the 1062.
    //   • store() catch block: re-lookups by offline_uuid, finds the winner
    //     committed in (b), returns replayed success — no 500.
    // ─────────────────────────────────────────────────────────────────────────

    public function test_race_loser_query_exception_recovered_as_success(): void
    {
        $uuid      = 'e2e-race-' . uniqid();
        $companyId = $this->companyId;
        $winnerTxId = null;

        // Register the creating observer BEFORE calling store(), so it fires
        // inside the loser's DB::transaction — after the app-level guard (which
        // found nothing) but before the actual INSERT.
        FbrPosTransaction::creating(function ($model) use ($uuid, $companyId, &$winnerTxId) {
            // Only intercept the specific race-test transaction.
            if ($model->offline_uuid !== $uuid) {
                return;
            }

            $pdo = DB::connection()->getPdo();

            // (a) Commit the loser's open PDO transaction.
            //     setUp() pre-seeded loyalty settings, so the only DB work done
            //     inside the transaction so far is SELECTs — no real data is
            //     committed.  The connection transitions to auto-commit mode.
            $pdo->commit();

            // (b) Insert the winner in auto-commit mode.
            //     No active PDO transaction → INSERT is immediately persistent
            //     on this connection, mirroring a concurrent winner's commit.
            $winnerTxId = DB::table('fbr_pos_transactions')->insertGetId([
                'company_id'     => $companyId,
                'invoice_number' => 'RACE-WIN-001',
                'offline_uuid'   => $uuid,
                'invoice_mode'   => 'fbr',
                'fbr_status'     => null,
                'status'         => 'completed',
                'subtotal'       => 300.00,
                'discount_amount'=> 0.00,
                'tax_amount'     => 0.00,
                'total_amount'   => 300.00,
                'payment_method' => 'cash',
                'cash_received'  => 300.00,
                'change_due'     => 0.00,
                'created_at'     => now()->toDateTimeString(),
                'updated_at'     => now()->toDateTimeString(),
            ]);

            // (c) Re-begin an empty PDO transaction so DB::transaction's error
            //     handler can call PDO::rollBack() without "no active tx" error.
            $pdo->beginTransaction();

            // (d) Throw the QueryException the loser's INSERT would have
            //     received — same SQLSTATE code (23000) and index name
            //     (fbr_txn_offline_uuid_unique) that the catch block checks.
            $pdoEx = new \PDOException(
                "SQLSTATE[23000]: Integrity constraint violation: 1062 Duplicate entry "
                . "'{$companyId}-{$uuid}' for key 'fbr_txn_offline_uuid_unique'",
                23000
            );
            throw new \Illuminate\Database\QueryException(
                'sqlite',
                'INSERT INTO `fbr_pos_transactions` (company_id, offline_uuid, ...) VALUES (...)',
                [],
                $pdoEx
            );
        });

        // Call store() as the loser.
        //   1. App-level guard SELECT → null (winner not committed yet)
        //   2. DB::transaction begins (Laravel transactions counter = 1)
        //   3. SELECTs: cost snapshot, loyalty settings, shift, invoice number
        //   4. FbrPosTransaction::create() → creating event fires (steps a–d above)
        //   5. DB::transaction: rolls back the empty tx from (c) → rethrows 1062
        //   6. Outer catch(QueryException): re-lookups by offline_uuid →
        //      finds winner committed in (b) → returns replayed success JSON
        $res  = $this->callStore($this->cashPayload($uuid));
        $data = $res->getData(true);

        $this->assertTrue($data['success'],
            'race loser: the QueryException catch block must return success (not 500)');
        $this->assertTrue($data['replayed'] ?? false,
            'race loser response must carry replayed:true');
        $this->assertNotNull($winnerTxId,
            'creating event must have fired and committed the winner row');
        $this->assertSame($winnerTxId, $data['transaction_id'],
            'response must return the winner\'s transaction_id');
        $this->assertSame('RACE-WIN-001', $data['invoice_number'],
            'response must return the winner\'s invoice_number');

        // DB: exactly 1 row with this UUID (the winner's)
        $this->assertSame(
            1,
            $this->dbCount('fbr_pos_transactions', ['company_id' => $companyId, 'offline_uuid' => $uuid]),
            'DB: exactly 1 row for UUID — no stray loser row'
        );
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Test 6 — Null offline_uuid: normal sales without a UUID still work
    //          Two NULL-uuid bills must coexist (no unique index conflict)
    // ─────────────────────────────────────────────────────────────────────────

    public function test_null_uuid_sales_coexist_no_unique_conflict(): void
    {
        $payloadNoUuid = [
            'items' => [[
                'item_name'     => 'Tea',
                'quantity'      => 1,
                'unit_price'    => 50,
                'uom'           => 'U',
                'tax_rate'      => 0,
                'is_tax_exempt' => true,
                'item_discount' => 0,
            ]],
            'payment_method' => 'cash',
            'cash_received'  => 50,
            'discount_type'  => 'percentage',
            'discount_value' => 0,
            'tax_inclusive'  => false,
            // No offline_uuid key — simulates a client that doesn't send one
        ];

        $res1 = $this->callStore($payloadNoUuid);
        $res2 = $this->callStore($payloadNoUuid);

        $this->assertTrue($res1->getData(true)['success'], '1st null-uuid sale success');
        $this->assertTrue($res2->getData(true)['success'], '2nd null-uuid sale success');
        $this->assertFalse($res1->getData(true)['replayed'] ?? false, '1st must not be replayed');
        $this->assertFalse($res2->getData(true)['replayed'] ?? false, '2nd must not be replayed');

        // Both create distinct rows (SQLite/MySQL allow unlimited NULLs in a unique index)
        $this->assertSame(2, $this->dbCount('fbr_pos_transactions', ['company_id' => $this->companyId]));
    }
}
