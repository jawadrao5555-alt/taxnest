<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\FbrCustomerLedger;
use App\Models\PosCustomer;
use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * (Khata upgrade Aug 2026) — locks the five Udhaar/Khata improvements:
 *
 *   1. UDHAAR HADD — a per-customer credit limit is enforced server-side in the
 *      credit-sale path: a cashier is HARD-blocked (no transaction row, no
 *      ledger row, balance unchanged); a manager may override (writes normally
 *      with an audit note); no limit set = today's behaviour exactly. The
 *      khata_limit column is fillable (verified with a real DB SELECT).
 *   2. PARCHI — a credit bill's receipt shows pehle/ye bill/kul baqaya from the
 *      ledger SNAPSHOT (survives a reprint after later bills); cash bill: hidden.
 *   3. WASOOLI KI RASID — a manager can print a slip keyed on the wasooli ledger
 *      entry; a cashier gets 403.
 *   4. WHATSAPP REMINDER — markReminderSent stamps khata_last_reminder_at
 *      (manager only; 403 for cashier).
 *   5. UMAR (aging) — the khata page buckets outstanding by the age of the
 *      OLDEST unpaid udhaar with FIFO wasooli allocation.
 *
 * Pattern: APP_ENV=testing + sqlite :memory: + minimal Schema::create; the sale
 * tests run the REAL FbrPosController::store() path (mirrors
 * FbrPosDraftLockWhatsappAuthTest).
 *
 * Run:
 *   env -u DATABASE_URL -u DB_CONNECTION -u PGHOST -u PGPORT -u PGUSER \
 *     -u PGPASSWORD -u PGDATABASE APP_ENV=testing DB_CONNECTION=sqlite \
 *     DB_DATABASE=':memory:' CACHE_STORE=array php vendor/bin/phpunit \
 *     --filter FbrPosKhataUpgradeTest
 */
class FbrPosKhataUpgradeTest extends TestCase
{
    protected Company $company;
    protected User $admin;
    protected User $cashier;

    protected function setUp(): void
    {
        parent::setUp();

        // The column-probe cache is process-static; a prior test may have dropped
        // a column. Clear it so each fresh schema is re-probed correctly.
        PosCustomer::flushKhataColumnCache();

        Schema::dropAllTables();

        Schema::create('companies', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('product_type')->nullable();
            $table->string('status')->nullable();
            $table->string('company_status')->nullable();
            $table->string('default_language')->nullable();
            $table->boolean('is_internal_account')->default(false);
            $table->boolean('fbr_pos_enabled')->default(true);
            $table->boolean('fbr_reporting_enabled')->default(false);
            $table->boolean('inventory_enabled')->default(false);
            $table->softDeletes();
            $table->timestamps();
        });

        Schema::create('users', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('email')->unique();
            $table->string('password');
            $table->unsignedBigInteger('company_id')->nullable();
            $table->string('role')->nullable();
            $table->string('pos_role')->nullable();
            $table->boolean('is_active')->default(true);
            $table->string('language')->nullable();
            $table->rememberToken();
            $table->timestamps();
        });

        Schema::create('branches', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('company_id');
            $table->string('name')->nullable();
            $table->boolean('is_active')->default(true);
            $table->boolean('is_head_office')->default(false);
            $table->timestamps();
        });

        // pos_customers WITH the khata upgrade columns.
        Schema::create('pos_customers', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('company_id');
            $table->string('name')->nullable();
            $table->string('phone')->nullable();
            $table->string('email')->nullable();
            $table->string('address')->nullable();
            $table->string('city')->nullable();
            $table->string('ntn')->nullable();
            $table->string('cnic')->nullable();
            $table->string('type')->default('unregistered');
            $table->boolean('is_active')->default(true);
            $table->integer('loyalty_points')->default(0);
            $table->string('loyalty_tier')->nullable();
            $table->decimal('total_spent', 12, 2)->default(0);
            $table->integer('total_orders')->default(0);
            $table->decimal('khata_balance', 12, 2)->default(0);
            $table->decimal('khata_limit', 12, 2)->nullable();
            $table->timestamp('khata_last_reminder_at')->nullable();
            $table->timestamps();
        });

        Schema::create('fbr_customer_ledgers', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('company_id')->index();
            $table->unsignedBigInteger('customer_id')->index();
            $table->string('entry_type', 20);
            $table->decimal('amount', 12, 2);
            $table->decimal('balance_after', 12, 2)->default(0);
            $table->unsignedBigInteger('transaction_id')->nullable();
            $table->string('note', 500)->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamps();
        });

        Schema::create('subscriptions', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('company_id');
            $table->boolean('active')->default(false);
            $table->string('override_type')->nullable();
            $table->timestamp('override_until')->nullable();
            $table->unsignedBigInteger('pricing_plan_id')->nullable();
            $table->timestamp('override_granted_at')->nullable();
            $table->integer('free_invoice_limit')->nullable();
            $table->timestamps();
        });

        Schema::create('fbr_pos_transactions', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('company_id');
            $table->unsignedBigInteger('branch_id')->nullable();
            $table->unsignedBigInteger('terminal_id')->nullable();
            $table->unsignedBigInteger('shift_id')->nullable();
            $table->string('invoice_number')->nullable();
            $table->string('invoice_mode')->nullable();
            $table->string('transaction_type')->nullable();
            $table->string('customer_name')->nullable();
            $table->string('customer_phone')->nullable();
            $table->string('customer_ntn')->nullable();
            $table->unsignedBigInteger('customer_id')->nullable();
            $table->decimal('subtotal', 12, 2)->default(0);
            $table->string('discount_type')->nullable();
            $table->decimal('discount_value', 12, 2)->default(0);
            $table->decimal('discount_amount', 12, 2)->default(0);
            $table->unsignedBigInteger('promotion_id')->nullable();
            $table->string('promotion_code')->nullable();
            $table->decimal('tax_rate', 8, 2)->default(0);
            $table->decimal('tax_amount', 12, 2)->default(0);
            $table->decimal('fbr_service_charge', 8, 2)->default(0);
            $table->integer('loyalty_points_earned')->default(0);
            $table->integer('loyalty_points_redeemed')->default(0);
            $table->decimal('loyalty_redemption_amount', 12, 2)->default(0);
            $table->decimal('total_amount', 12, 2)->default(0);
            $table->string('payment_method')->nullable();
            $table->text('payment_breakdown')->nullable();
            $table->decimal('cash_received', 12, 2)->nullable();
            $table->decimal('change_due', 12, 2)->nullable();
            $table->string('status')->nullable();
            $table->string('fbr_status')->nullable();
            $table->string('fbr_invoice_number')->nullable();
            $table->string('offline_uuid')->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamps();
        });

        Schema::create('fbr_pos_transaction_items', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('transaction_id');
            $table->string('item_name');
            $table->string('hs_code')->nullable();
            $table->string('uom')->nullable();
            $table->unsignedBigInteger('product_id')->nullable();
            $table->decimal('quantity', 12, 3)->default(0);
            $table->decimal('unit_price', 12, 2)->default(0);
            $table->decimal('cost_price', 12, 2)->nullable();
            $table->decimal('discount', 12, 2)->default(0);
            $table->decimal('item_discount', 12, 2)->default(0);
            $table->decimal('tax_rate', 8, 2)->default(0);
            $table->decimal('tax_amount', 12, 2)->default(0);
            $table->decimal('subtotal', 12, 2)->default(0);
            $table->decimal('total', 12, 2)->default(0);
            $table->boolean('is_tax_exempt')->default(false);
            $table->timestamps();
        });

        Schema::create('fbr_pos_loyalty_settings', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('company_id');
            $table->boolean('is_enabled')->default(false);
            $table->decimal('rs_per_point', 12, 2)->default(100);
            $table->decimal('point_value', 12, 2)->default(1);
            $table->integer('min_redeem_points')->default(50);
            $table->timestamps();
        });

        Schema::create('fbr_pos_shifts', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('company_id');
            $table->unsignedBigInteger('user_id');
            $table->string('status')->nullable();
            $table->integer('sales_count')->default(0);
            $table->decimal('total_sales', 12, 2)->default(0);
            $table->decimal('total_cash', 12, 2)->default(0);
            $table->decimal('total_card', 12, 2)->default(0);
            $table->decimal('total_other', 12, 2)->default(0);
            $table->timestamps();
        });

        $this->company = Company::create([
            'name' => 'Khata Upgrade Shop',
            'product_type' => 'fbrpos',
            'status' => 'approved',
            'company_status' => 'active',
        ]);

        $mk = fn (string $name, ?string $posRole, string $role = 'user') => User::create([
            'name' => $name,
            'email' => strtolower(str_replace(' ', '.', $name)) . '@khata.test',
            'password' => bcrypt('secret-qa'),
            'company_id' => $this->company->id,
            'role' => $role,
            'pos_role' => $posRole,
            'is_active' => true,
        ]);

        $this->admin = $mk('Admin User', null, 'company_admin');
        $this->cashier = $mk('Cashier User', 'pos_cashier');

        // plan.limit middleware: lifetime override = allowed + bypasses caps.
        DB::table('subscriptions')->insert([
            'company_id' => $this->company->id,
            'active' => 1,
            'override_type' => 'lifetime',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function asUser(User $u)
    {
        return $this->actingAs($u, 'fbrpos');
    }

    private function makeCustomer(array $attrs = []): PosCustomer
    {
        return PosCustomer::create(array_merge([
            'company_id' => $this->company->id,
            'name' => 'Udhaar Grahak',
            'phone' => '03001234567',
            'type' => 'unregistered',
            'is_active' => true,
            'khata_balance' => 0,
        ], $attrs));
    }

    private function creditSalePayload(int $customerId, float $unitPrice, array $extra = []): array
    {
        return array_merge([
            'items' => [['item_name' => 'Cheeni', 'quantity' => 1, 'unit_price' => $unitPrice]],
            'payment_method' => 'credit',
            'customer_id' => $customerId,
            'customer_name' => 'Udhaar Grahak',
            'save_as_provisional' => true,
            'offline_uuid' => 'k-' . uniqid(),
        ], $extra);
    }

    // ── 1. UDHAAR HADD ───────────────────────────────────────────────────────

    /** The new column must actually persist — a real DB SELECT, per the brief. */
    public function test_khata_limit_column_is_fillable(): void
    {
        $c = $this->makeCustomer(['khata_limit' => 5000]);
        $raw = DB::table('pos_customers')->where('id', $c->id)->value('khata_limit');
        $this->assertSame(5000.0, (float) $raw, 'khata_limit must persist (fillable)');
    }

    public function test_cashier_credit_sale_blocked_when_over_limit_writes_nothing(): void
    {
        // Limit 1000, no balance yet, bill 1500 → must be blocked.
        $c = $this->makeCustomer(['khata_limit' => 1000, 'khata_balance' => 0]);

        $this->asUser($this->cashier)
            ->postJson('/fbr-pos/store', $this->creditSalePayload($c->id, 1500))
            ->assertStatus(422);

        // NOTHING written: no transaction, no ledger row, balance unchanged.
        $this->assertSame(0, DB::table('fbr_pos_transactions')->count(), 'blocked sale must create no transaction');
        $this->assertSame(0, FbrCustomerLedger::count(), 'blocked sale must create no ledger row');
        $this->assertSame(0.0, (float) $c->fresh()->khata_balance, 'balance must be unchanged');
    }

    public function test_cashier_override_flag_does_not_bypass_limit(): void
    {
        // Even WITH the override flag, a cashier must be hard-blocked.
        $c = $this->makeCustomer(['khata_limit' => 1000, 'khata_balance' => 0]);

        $this->asUser($this->cashier)
            ->postJson('/fbr-pos/store', $this->creditSalePayload($c->id, 1500, ['khata_limit_override' => true]))
            ->assertStatus(422);

        $this->assertSame(0, DB::table('fbr_pos_transactions')->count());
        $this->assertSame(0, FbrCustomerLedger::count());
    }

    public function test_manager_override_writes_normally_with_audit_note(): void
    {
        $c = $this->makeCustomer(['khata_limit' => 1000, 'khata_balance' => 0]);

        $this->asUser($this->admin)
            ->postJson('/fbr-pos/store', $this->creditSalePayload($c->id, 1500, ['khata_limit_override' => true]))
            ->assertOk()
            ->assertJson(['success' => true]);

        $this->assertSame(1, DB::table('fbr_pos_transactions')->count());
        $ledger = FbrCustomerLedger::where('entry_type', 'udhaar')->first();
        $this->assertNotNull($ledger, 'override sale must write the udhaar ledger row');
        $this->assertStringContainsStringIgnoringCase('override', (string) $ledger->note,
            'override must be recorded in the ledger note for audit');
        // balance == the bill's total_amount (may include default tax) — the
        // point is the sale wrote through, not the exact tax-inclusive figure.
        $txTotal = (float) DB::table('fbr_pos_transactions')->latest('id')->value('total_amount');
        $this->assertGreaterThanOrEqual(1500.0, $txTotal);
        $this->assertSame($txTotal, (float) $c->fresh()->khata_balance);
    }

    /**
     * BLOCKER FIX (Aug 2026): the limit decision must be made from the row the
     * ledger write locks (lockForUpdate), NOT an earlier unlocked read. We
     * simulate a competing write that raised the balance AFTER the request began:
     * the sale fit under the limit at request time (balance 0) but no longer does
     * once the other write committed (balance 900) — it MUST be refused, because
     * the decision reads the fresh locked balance, not the stale one.
     */
    public function test_limit_decision_uses_locked_row_not_stale_balance(): void
    {
        $c = $this->makeCustomer(['khata_limit' => 1000, 'khata_balance' => 0]);

        // A concurrent credit sale committed after this request began — raise the
        // persisted balance directly (bypassing the model instance) to mimic it.
        DB::table('pos_customers')->where('id', $c->id)->update(['khata_balance' => 900]);

        // Bill of 200: fits against the stale 0 (0+200 <= 1000) but NOT against
        // the fresh locked 900 (900+200 = 1100 > 1000). Must be blocked.
        $this->asUser($this->cashier)
            ->postJson('/fbr-pos/store', $this->creditSalePayload($c->id, 200))
            ->assertStatus(422);

        $this->assertSame(0, DB::table('fbr_pos_transactions')->count(),
            'a sale that exceeds the limit on the FRESH locked balance must leave no transaction');
        $this->assertSame(0, FbrCustomerLedger::count(), 'and no ledger row');
        $this->assertSame(900.0, (float) $c->fresh()->khata_balance, 'balance must be untouched');
    }

    /**
     * Source guard for the one-lock/one-decision invariant. sqlite treats
     * lockForUpdate() as a no-op (no "FOR UPDATE" reaches the wire) and is
     * single-threaded, so the lock can't be exercised at runtime here. Instead we
     * assert the STRUCTURE that made the fix correct: in the credit-sale path the
     * khata_limit read for the limit decision is bound to the SAME lockForUpdate
     * customer instance that writes the ledger + balance — never a separate
     * unlocked read. If a future edit reintroduces an unlocked
     * `PosCustomer::...->khata_limit` decision before the lock, this trips.
     */
    public function test_source_makes_limit_decision_from_the_locked_customer(): void
    {
        $src = file_get_contents(base_path('app/Http/Controllers/FbrPosController.php'));

        // The credit ledger block locks the customer into $khataCustomer …
        $this->assertMatchesRegularExpression(
            '/\$khataCustomer\s*=\s*\\\\App\\\\Models\\\\PosCustomer::lockForUpdate\(\)/',
            $src,
            'the credit path must lock the customer into $khataCustomer'
        );
        // … and the limit decision must read from THAT locked instance.
        $this->assertMatchesRegularExpression(
            '/\$khataLimit\s*=\s*\\\\App\\\\Models\\\\PosCustomer::khataColumnExists\([^)]*\)\s*\?\s*\$khataCustomer->khata_limit/',
            $src,
            'the limit decision must read khata_limit from the locked $khataCustomer, not a separate unlocked read'
        );
        // There must be NO unlocked khata_limit read feeding a decision (the bug).
        $this->assertDoesNotMatchRegularExpression(
            '/PosCustomer::where\([^;]*\)\s*->\s*find\([^;]*\)\s*;[^;]*->khata_limit/s',
            $src,
            'no unlocked PosCustomer find() may read khata_limit for the limit decision'
        );
    }

    /**
     * PROD schema-drift safety: if khata_limit is missing on a lagging DB, the
     * credit sale must NOT crash and must behave as "no limit" (write through).
     */
    public function test_credit_sale_degrades_when_khata_limit_column_missing(): void
    {
        $c = $this->makeCustomer(['khata_limit' => 100, 'khata_balance' => 0]);

        // Simulate the drifted PROD DB: drop the column so the guard can't read it.
        PosCustomer::flushKhataColumnCache();
        Schema::table('pos_customers', function (Blueprint $t) {
            $t->dropColumn('khata_limit');
        });
        // Clear Laravel's cached schema + our probe cache so the NEXT probe
        // re-reads the mutated schema and sees the column gone.
        PosCustomer::flushKhataColumnCache();

        // Would exceed the (now unreadable) limit, but with the column gone it must
        // degrade to "no limit" and write through rather than 500.
        $this->asUser($this->cashier)
            ->postJson('/fbr-pos/store', $this->creditSalePayload($c->id, 5000))
            ->assertOk()
            ->assertJson(['success' => true]);

        $this->assertSame(1, DB::table('fbr_pos_transactions')->count());
        $this->assertSame(1, FbrCustomerLedger::where('entry_type', 'udhaar')->count());
    }

    public function test_no_limit_set_behaves_exactly_as_before(): void
    {
        // NULL limit = no cap: a big credit sale sails through.
        $c = $this->makeCustomer(['khata_limit' => null, 'khata_balance' => 0]);

        $this->asUser($this->cashier)
            ->postJson('/fbr-pos/store', $this->creditSalePayload($c->id, 999999))
            ->assertOk()
            ->assertJson(['success' => true]);

        $this->assertSame(1, DB::table('fbr_pos_transactions')->count());
        $txTotal = (float) DB::table('fbr_pos_transactions')->latest('id')->value('total_amount');
        $this->assertSame($txTotal, (float) $c->fresh()->khata_balance,
            'no-limit credit sale writes the balance normally');
    }

    // ── 2. PARCHI (ledger snapshot on the credit receipt) ─────────────────────

    public function test_credit_receipt_shows_previous_and_total_from_snapshot(): void
    {
        $c = $this->makeCustomer(['khata_balance' => 0]);

        // First credit bill (previous 0). Its total may include default tax.
        $this->asUser($this->cashier)
            ->postJson('/fbr-pos/store', $this->creditSalePayload($c->id, 400))
            ->assertOk();
        $firstTx = DB::table('fbr_pos_transactions')->latest('id')->first();
        $firstTotal = (float) $firstTx->total_amount;

        // A LATER bill pushes the live balance well past the first bill's total.
        $this->asUser($this->cashier)
            ->postJson('/fbr-pos/store', $this->creditSalePayload($c->id, 600))
            ->assertOk();
        $liveBalance = (float) $c->fresh()->khata_balance;
        $this->assertGreaterThan($firstTotal, $liveBalance);

        // Reprinting the FIRST receipt must still show ITS snapshot: previous 0,
        // ye bill = first total, kul = first total — NOT the live balance.
        $html = $this->asUser($this->admin)
            ->get('/fbr-pos/transaction/' . $firstTx->id . '/receipt')
            ->assertOk()
            ->getContent();

        // Labels are locale-dependent — assert via the resolved translation so the
        // test survives whichever locale the receipt renders in.
        $this->assertStringContainsString(__('pos.rcpt_khata_previous'), $html);
        $this->assertStringContainsString(__('pos.rcpt_khata_total'), $html);
        // The snapshot kul equals the FIRST bill's total, printed with 2 decimals.
        $this->assertStringContainsString('PKR ' . number_format($firstTotal, 2), $html);
        // The live combined balance must NOT appear (proves snapshot, not live).
        $this->assertStringNotContainsString('PKR ' . number_format($liveBalance, 2), $html);
    }

    public function test_cash_receipt_has_no_khata_lines(): void
    {
        $this->asUser($this->cashier)
            ->postJson('/fbr-pos/store', [
                'items' => [['item_name' => 'Cheeni', 'quantity' => 1, 'unit_price' => 100]],
                'payment_method' => 'cash',
                'cash_received' => 100000, // generous — cover any default tax
                'save_as_provisional' => true,
                'offline_uuid' => 'cash-' . uniqid(),
            ])
            ->assertOk();
        $tx = DB::table('fbr_pos_transactions')->latest('id')->first();

        $html = $this->asUser($this->admin)
            ->get('/fbr-pos/transaction/' . $tx->id . '/receipt')
            ->assertOk()
            ->getContent();

        $this->assertStringNotContainsString(__('pos.rcpt_khata_previous'), $html, 'cash bill must not print khata lines');
    }

    // ── 3. WASOOLI KI RASID ────────────────────────────────────────────────────

    public function test_wasooli_receipt_visible_to_manager_blocked_for_cashier(): void
    {
        $c = $this->makeCustomer(['khata_balance' => 500]);
        $entry = FbrCustomerLedger::create([
            'company_id' => $this->company->id,
            'customer_id' => $c->id,
            'entry_type' => 'wasooli',
            'amount' => -200,
            'balance_after' => 300,
            'note' => 'Cash wasooli',
            'created_by' => $this->admin->id,
        ]);

        $html = $this->asUser($this->admin)
            ->get('/fbr-pos/khata/wasooli/' . $entry->id . '/receipt')
            ->assertOk()
            ->getContent();
        $this->assertStringContainsString(__('pos.wasooli_receipt_title'), $html);
        $this->assertStringContainsString('Rs ' . number_format(200, 2), $html);   // received
        $this->assertStringContainsString('Rs ' . number_format(300, 2), $html);   // balance now

        $this->asUser($this->cashier)
            ->get('/fbr-pos/khata/wasooli/' . $entry->id . '/receipt')
            ->assertStatus(403);
    }

    // ── 4. WHATSAPP REMINDER ───────────────────────────────────────────────────

    public function test_mark_reminder_sent_stamps_timestamp_manager_only(): void
    {
        $c = $this->makeCustomer(['khata_balance' => 500]);
        $this->assertNull($c->khata_last_reminder_at);

        // Cashier blocked.
        $this->asUser($this->cashier)
            ->postJson('/fbr-pos/khata/' . $c->id . '/reminder-sent')
            ->assertStatus(403);
        $this->assertNull($c->fresh()->khata_last_reminder_at, 'blocked call must not stamp');

        // Manager stamps it.
        $this->asUser($this->admin)
            ->postJson('/fbr-pos/khata/' . $c->id . '/reminder-sent')
            ->assertOk()
            ->assertJson(['success' => true]);
        $this->assertNotNull($c->fresh()->khata_last_reminder_at, 'manager call must stamp khata_last_reminder_at');

        // A refresh/double click must not permit a second WhatsApp reminder.
        $this->asUser($this->admin)
            ->postJson('/fbr-pos/khata/' . $c->id . '/reminder-sent')
            ->assertStatus(409)
            ->assertJson(['success' => false]);
    }

    public function test_sale_screen_shortcut_preopens_wasooli_only_for_own_outstanding_customer(): void
    {
        $customer = $this->makeCustomer(['khata_balance' => 500]);

        $html = $this->asUser($this->admin)
            ->get('/fbr-pos/khata?wasooli_customer=' . $customer->id)
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('wasooliCustomerId: ' . $customer->id, $html);
        $this->assertStringContainsString('showWasooli: true', $html);
    }

    // ── 5. UMAR (aging) ─────────────────────────────────────────────────────────

    public function test_khata_page_buckets_by_oldest_unpaid_udhaar_fifo(): void
    {
        // Customer has an OLD udhaar (70 days) partly paid, plus a recent udhaar.
        // FIFO: the wasooli pays the OLD lot first, so the oldest STILL-unpaid lot
        // decides the bucket.
        $c = $this->makeCustomer(['khata_balance' => 800]);

        FbrCustomerLedger::create([
            'company_id' => $this->company->id, 'customer_id' => $c->id,
            'entry_type' => 'udhaar', 'amount' => 500, 'balance_after' => 500,
            'created_at' => now()->subDays(70), 'updated_at' => now()->subDays(70),
        ]);
        FbrCustomerLedger::create([
            'company_id' => $this->company->id, 'customer_id' => $c->id,
            'entry_type' => 'udhaar', 'amount' => 300, 'balance_after' => 800,
            'created_at' => now()->subDays(5), 'updated_at' => now()->subDays(5),
        ]);
        // Wasooli of 500 fully clears the OLD lot → oldest unpaid is the 5-day lot.
        FbrCustomerLedger::create([
            'company_id' => $this->company->id, 'customer_id' => $c->id,
            'entry_type' => 'wasooli', 'amount' => -500, 'balance_after' => 300,
            'created_at' => now()->subDays(1), 'updated_at' => now()->subDays(1),
        ]);
        // Correct the cache to match (500+300-500=300 outstanding still != 0 → shows).
        $c->update(['khata_balance' => 300]);

        $ctrl = app(\App\Http\Controllers\FbrPosKhataController::class);
        $m = new \ReflectionMethod($ctrl, 'computeAging');
        $m->setAccessible(true);
        $aging = $m->invoke($ctrl, $this->company->id);

        $this->assertArrayHasKey($c->id, $aging);
        $this->assertSame('0_15', $aging[$c->id]['bucket'],
            'FIFO: wasooli cleared the old lot, so the youngest unpaid udhaar sets the bucket');
    }

    public function test_khata_page_renders_aging_buckets_for_manager(): void
    {
        $c = $this->makeCustomer(['khata_balance' => 1000]);
        FbrCustomerLedger::create([
            'company_id' => $this->company->id, 'customer_id' => $c->id,
            'entry_type' => 'udhaar', 'amount' => 1000, 'balance_after' => 1000,
            'created_at' => now()->subDays(90), 'updated_at' => now()->subDays(90),
        ]);

        $html = $this->asUser($this->admin)
            ->get('/fbr-pos/khata')
            ->assertOk()
            ->getContent();

        // The 60+ bucket tile is rendered (locale-independent Alpine hook) and the
        // customer's row carries that bucket via the filter predicate.
        $this->assertStringContainsString("toggleBucket('60_plus')", $html);
        $this->assertStringContainsString("bucketFilter === '60_plus'", $html);
    }
}
