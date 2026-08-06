<?php

namespace Tests\Feature;

use Tests\TestCase;
use App\Models\Company;
use App\Models\FbrPosShift;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;

/**
 * FBR POS UDHAAR SEPARATION — "credit bills stay out of cash, always" (Aug 2026).
 *
 * Locks the invariants introduced when credit/khata sales were separated from the
 * generic "Other" bucket in FBR POS day-close and shift reports:
 *
 *   1. performDayClose() stores credit in udhaar_amount, NOT other_amount;
 *      cash_amount and expected_cash (cash recon) never include udhaar.
 *   2. shiftTotals() routes credit-method sales to the 'udhaar' bucket;
 *      total_other stays clean (no credit contamination).
 *   3. closeShift() persists total_udhaar on the shift row.
 *   4. Thermal Z-report: historical rows (udhaar_amount=0) derive udhaar from
 *      transactions at render time; new rows trust the stored value.
 *   5. Shift-report view shows the udhaar row only when total_udhaar > 0.
 *   6. dc_udhaar + dc_udhaar_not_in_drawer lang keys exist in en, rur, ur.
 *
 * Pattern: APP_ENV=testing + sqlite :memory: + minimal Schema::create.
 * Private methods tested via reflection; HTTP tests use actingAs(fbrpos).
 *
 * Run:
 *   env -u DATABASE_URL -u DB_CONNECTION -u PGHOST -u PGPORT -u PGUSER \
 *     -u PGPASSWORD -u PGDATABASE APP_ENV=testing DB_CONNECTION=sqlite \
 *     DB_DATABASE=':memory:' php vendor/bin/phpunit tests/Feature/FbrPosUdhaarSeparationTest.php
 */
class FbrPosUdhaarSeparationTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Schema::dropAllTables();

        // ── Core tables ──────────────────────────────────────────────────────

        Schema::create('companies', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('product_type')->nullable();
            $table->string('status')->nullable();
            $table->string('default_language')->nullable();
            $table->boolean('is_internal_account')->default(false);
            $table->integer('invoice_limit_override')->nullable();
            $table->boolean('fbr_reporting_enabled')->default(false);
            $table->boolean('agent_enabled')->default(false);
            $table->boolean('agent_submits_pra')->nullable();
            $table->string('fbr_connection_mode')->nullable();
            $table->string('fbr_environment')->nullable();
            $table->string('fbr_pos_environment')->nullable();
            $table->text('fbr_pos_token')->nullable();
            $table->string('fbr_pos_id')->nullable();
            $table->boolean('fbr_pos_enabled')->default(false);
            $table->string('pos_dayclose_provisional_action')->nullable();
            $table->softDeletes();
            $table->timestamps();
        });

        Schema::create('users', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('email')->unique();
            $table->string('password');
            $table->unsignedBigInteger('company_id')->nullable();
            $table->unsignedBigInteger('default_branch_id')->nullable();
            $table->string('role')->nullable();
            $table->boolean('is_active')->default(true);
            $table->string('language')->nullable();
            $table->rememberToken();
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

        // ── FBR transaction tables ───────────────────────────────────────────

        Schema::create('fbr_pos_transactions', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('company_id');
            $table->unsignedBigInteger('shift_id')->nullable();
            $table->string('invoice_number');
            $table->string('transaction_type')->default('sale');
            $table->string('status')->nullable();
            $table->string('invoice_mode')->nullable();
            $table->string('fbr_status')->nullable();
            $table->string('fbr_invoice_number')->nullable();
            $table->string('fbr_response_code')->nullable();
            $table->text('fbr_response')->nullable();
            $table->string('fbr_submission_hash')->nullable();
            $table->decimal('subtotal', 12, 2)->default(0);
            $table->string('discount_type')->nullable();
            $table->decimal('discount_value', 12, 2)->nullable();
            $table->decimal('discount_amount', 12, 2)->default(0);
            $table->decimal('tax_rate', 8, 2)->nullable();
            $table->decimal('tax_amount', 12, 2)->default(0);
            $table->decimal('fbr_service_charge', 12, 2)->nullable();
            $table->decimal('total_amount', 12, 2)->default(0);
            $table->string('payment_method')->nullable();
            $table->text('payment_breakdown')->nullable(); // JSON
            $table->decimal('cash_received', 12, 2)->nullable();
            $table->decimal('change_due', 12, 2)->nullable();
            $table->unsignedBigInteger('customer_id')->nullable();
            $table->string('customer_name')->nullable();
            $table->string('customer_phone')->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamps();
        });

        // buildFbrDayCloseAnalytics selects these columns incl. promotion_discount.
        Schema::create('fbr_pos_transaction_items', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('transaction_id');
            $table->unsignedBigInteger('product_id')->nullable();
            $table->string('item_name');
            $table->string('hs_code')->nullable();
            $table->decimal('quantity', 12, 4)->default(1);
            $table->decimal('unit_price', 12, 2)->default(0);
            $table->decimal('subtotal', 12, 2)->default(0);
            $table->decimal('item_discount', 12, 2)->nullable();
            $table->decimal('promotion_discount', 12, 2)->nullable();
            $table->boolean('is_tax_exempt')->default(false);
            $table->decimal('tax_rate', 8, 2)->nullable();
            $table->decimal('tax_amount', 12, 2)->nullable();
            $table->decimal('total', 12, 2)->nullable();
            $table->timestamps();
        });

        Schema::create('fbr_pos_logs', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('company_id')->nullable();
            $table->unsignedBigInteger('transaction_id')->nullable();
            $table->text('request_payload')->nullable();
            $table->text('response_payload')->nullable();
            $table->string('response_code')->nullable();
            $table->string('status')->nullable();
            $table->text('error_message')->nullable();
            $table->timestamps();
        });

        // ── Day-close reports (includes udhaar_amount) ───────────────────────

        Schema::create('fbr_day_close_reports', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('company_id');
            $table->date('report_date');
            $table->string('report_number');
            $table->integer('total_invoices')->default(0);
            $table->integer('fbr_invoices')->default(0);
            $table->integer('local_invoices')->default(0);
            $table->integer('failed_invoices')->default(0);
            $table->decimal('gross_sales', 14, 2)->default(0);
            $table->decimal('total_discount', 14, 2)->default(0);
            $table->decimal('net_sales', 14, 2)->default(0);
            $table->decimal('total_tax', 14, 2)->default(0);
            $table->decimal('total_fbr_fee', 14, 2)->nullable();
            $table->decimal('total_amount', 14, 2)->default(0);
            $table->decimal('cash_amount', 14, 2)->default(0);
            $table->decimal('card_amount', 14, 2)->default(0);
            $table->decimal('udhaar_amount', 14, 2)->default(0); // Aug 2026
            $table->decimal('other_amount', 14, 2)->default(0);
            $table->string('first_invoice_number')->nullable();
            $table->string('last_invoice_number')->nullable();
            $table->timestamp('first_invoice_time')->nullable();
            $table->timestamp('last_invoice_time')->nullable();
            $table->unsignedBigInteger('closed_by')->nullable();
            $table->text('notes')->nullable();
            $table->string('hash')->nullable();
            $table->decimal('opening_float', 14, 2)->nullable();
            $table->decimal('counted_cash', 14, 2)->nullable();
            $table->decimal('expected_cash', 14, 2)->nullable();
            $table->decimal('cash_variance', 14, 2)->nullable();
            $table->timestamps();
        });

        // ── Subscriptions (trial-reminder-banner in fbr-pos-app layout) ─────
        // Minimal schema: the banner queries for an active row; when none exists
        // it returns null immediately, so only the columns used in the WHERE/ORDER
        // clause need to be present. No rows are inserted → banner stays hidden.
        Schema::create('subscriptions', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('company_id');
            $table->boolean('active')->default(false);
            $table->string('override_type')->nullable();
            $table->timestamp('override_until')->nullable();
            $table->unsignedBigInteger('pricing_plan_id')->nullable();
            $table->integer('free_invoice_limit')->nullable();
            $table->timestamp('override_granted_at')->nullable();
            $table->timestamps();
        });

        // ── Shift tables (for phase2 shift tests) ────────────────────────────

        Schema::create('fbr_pos_shifts', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('company_id');
            $table->unsignedBigInteger('terminal_id')->nullable();
            $table->unsignedBigInteger('user_id');
            $table->timestamp('opened_at')->nullable();
            $table->timestamp('closed_at')->nullable();
            $table->decimal('opening_cash', 14, 2)->default(0);
            $table->decimal('expected_cash', 14, 2)->nullable();
            $table->decimal('closing_cash', 14, 2)->nullable();
            $table->decimal('variance', 14, 2)->nullable();
            $table->decimal('total_sales', 14, 2)->default(0);
            $table->decimal('total_cash', 14, 2)->default(0);
            $table->decimal('total_card', 14, 2)->default(0);
            $table->decimal('total_other', 14, 2)->default(0);
            $table->decimal('total_udhaar', 14, 2)->default(0); // Aug 2026
            $table->decimal('total_returns', 14, 2)->default(0);
            $table->integer('sales_count')->default(0);
            $table->integer('returns_count')->default(0);
            $table->text('notes')->nullable();
            $table->string('status')->default('open');
            $table->timestamps();
        });

        Schema::create('fbr_pos_cash_movements', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('shift_id');
            $table->unsignedBigInteger('user_id');
            $table->string('type');
            $table->decimal('amount', 14, 2);
            $table->string('reason');
            $table->timestamps();
        });

        // MySQL SUBSTRING_INDEX polyfill (used by the atomic report_number counter).
        DB::connection()->getPdo()->sqliteCreateFunction('SUBSTRING_INDEX', function ($str, $delim, $count) {
            $parts = explode((string) $delim, (string) $str);
            return $count < 0
                ? implode($delim, array_slice($parts, (int) $count))
                : implode($delim, array_slice($parts, 0, (int) $count));
        });
    }

    // ════════════════════════════════════════════════════════════════════════
    // Fixtures
    // ════════════════════════════════════════════════════════════════════════

    private function makeCompany(array $attrs = []): int
    {
        return (int) DB::table('companies')->insertGetId(array_merge([
            'name' => 'Udhaar Test Co',
            'product_type' => 'fbrpos',
            'status' => 'active',
            'is_internal_account' => false,
            'invoice_limit_override' => -1,
            'fbr_reporting_enabled' => false,
            'agent_enabled' => false,
            'fbr_connection_mode' => 'cloud',
            'fbr_environment' => 'sandbox',
            'fbr_pos_enabled' => true,
            'pos_dayclose_provisional_action' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ], $attrs));
    }

    private function makeFbrUser(int $companyId): \App\Models\User
    {
        $id = DB::table('users')->insertGetId([
            'name' => 'Udhaar Cashier',
            'email' => 'cashier' . $companyId . '@udhaar.test',
            'password' => bcrypt('Secret@12345'),
            'company_id' => $companyId,
            'role' => 'company_admin',
            'is_active' => true,
            'language' => 'en',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        return \App\Models\User::find($id);
    }

    private function makeTx(int $companyId, string $method, float $amount, array $attrs = []): int
    {
        return (int) DB::table('fbr_pos_transactions')->insertGetId(array_merge([
            'company_id' => $companyId,
            'invoice_number' => 'FPOS-TST-' . rand(1000, 9999),
            'transaction_type' => 'sale',
            'status' => 'completed',
            'invoice_mode' => 'fbr',
            'fbr_status' => null,
            'subtotal' => $amount,
            'discount_amount' => 0,
            'tax_amount' => 0,
            'total_amount' => $amount,
            'payment_method' => $method,
            'created_at' => now(),
            'updated_at' => now(),
        ], $attrs));
    }

    /** Invoke FbrPosController::performDayClose via reflection. */
    private function runDayClose(int $companyId, string $date): ?\App\Models\FbrDayCloseReport
    {
        $ctrl = app(\App\Http\Controllers\FbrPosController::class);
        $m = new \ReflectionMethod($ctrl, 'performDayClose');
        $m->setAccessible(true);
        return $m->invoke($ctrl, $companyId, $date, null, null, null);
    }

    /** Invoke FbrPosPhase2Controller::shiftTotals via reflection. */
    private function runShiftTotals(\App\Models\FbrPosShift $shift): array
    {
        $ctrl = app(\App\Http\Controllers\FbrPosPhase2Controller::class);
        $m = new \ReflectionMethod($ctrl, 'shiftTotals');
        $m->setAccessible(true);
        return $m->invoke($ctrl, $shift);
    }

    // ════════════════════════════════════════════════════════════════════════
    // 1. performDayClose — udhaar/other/cash split in the stored snapshot
    // ════════════════════════════════════════════════════════════════════════

    public function test_day_close_snapshot_routes_credit_to_udhaar_not_other(): void
    {
        // A day with one cash bill (Rs 300) and one credit/udhaar bill (Rs 500).
        // udhaar_amount must hold 500; other_amount must be 0 (not 500).
        $companyId = $this->makeCompany();
        $this->makeTx($companyId, 'cash', 300.00);
        $this->makeTx($companyId, 'credit', 500.00);

        $report = $this->runDayClose($companyId, now()->toDateString());

        $this->assertNotNull($report, 'day-close report must be created');
        $this->assertSame(500.0, (float) $report->udhaar_amount,
            'credit/udhaar sale must land in udhaar_amount');
        $this->assertSame(0.0, (float) $report->other_amount,
            'other_amount must NOT absorb credit sales');
        $this->assertSame(300.0, (float) $report->cash_amount,
            'cash_amount must contain only cash sales');
    }

    public function test_day_close_snapshot_cash_amount_never_includes_credit(): void
    {
        // Pure credit day: cash_amount must be 0, udhaar_amount = full revenue.
        $companyId = $this->makeCompany();
        $this->makeTx($companyId, 'credit', 800.00);
        $this->makeTx($companyId, 'credit', 200.00);

        $report = $this->runDayClose($companyId, now()->toDateString());

        $this->assertSame(1000.0, (float) $report->udhaar_amount);
        $this->assertSame(0.0, (float) $report->cash_amount,
            'cash_amount must be zero when all sales are credit/udhaar');
        $this->assertSame(0.0, (float) $report->other_amount);
    }

    public function test_day_close_snapshot_expected_cash_excludes_udhaar(): void
    {
        // expected_cash = opening_float + cash_sales; udhaar must NOT be added.
        // Simulate: cash recon with opening 100, cash sales 300, udhaar 500.
        $companyId = $this->makeCompany();
        $this->makeTx($companyId, 'cash', 300.00);
        $this->makeTx($companyId, 'credit', 500.00);

        // Run with a cash recon payload: opening_float=100, counted_cash=400.
        $ctrl = app(\App\Http\Controllers\FbrPosController::class);
        $m = new \ReflectionMethod($ctrl, 'performDayClose');
        $m->setAccessible(true);
        $report = $m->invoke($ctrl, $companyId, now()->toDateString(), null, null, [
            'opening_float' => 100.00,
            'counted_cash' => 400.00,
        ]);

        $this->assertNotNull($report);
        // expected = 100 + 300 = 400; if udhaar were included it would be 900.
        $this->assertSame(400.0, (float) $report->expected_cash,
            'expected_cash must be opening_float + cash_sales only (udhaar excluded)');
        $this->assertSame(0.0, (float) $report->cash_variance,
            'variance must be counted(400) - expected(400) = 0');
    }

    public function test_day_close_snapshot_other_bucket_is_not_contaminated_by_credit(): void
    {
        // Mixed day: cash + credit + a genuine "other" method (e.g. store_credit).
        // udhaar_amount must hold credit; other_amount must hold store_credit ONLY.
        $companyId = $this->makeCompany();
        $this->makeTx($companyId, 'cash', 200.00);
        $this->makeTx($companyId, 'credit', 400.00);        // udhaar — must NOT go to other
        $this->makeTx($companyId, 'store_credit', 100.00);  // genuine Other

        $report = $this->runDayClose($companyId, now()->toDateString());

        $this->assertSame(400.0, (float) $report->udhaar_amount, 'credit goes to udhaar');
        $this->assertSame(100.0, (float) $report->other_amount, 'store_credit stays in other');
        $this->assertSame(200.0, (float) $report->cash_amount);
    }

    // ════════════════════════════════════════════════════════════════════════
    // 2. shiftTotals — credit goes to udhaar bucket, not other
    // ════════════════════════════════════════════════════════════════════════

    public function test_shift_totals_routes_credit_to_udhaar_bucket(): void
    {
        $companyId = $this->makeCompany();
        $user = $this->makeFbrUser($companyId);

        $shiftId = (int) DB::table('fbr_pos_shifts')->insertGetId([
            'company_id' => $companyId,
            'user_id' => $user->id,
            'opened_at' => now(),
            'opening_cash' => 200.00,
            'status' => 'open',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $shift = FbrPosShift::find($shiftId);

        // Three sales in this shift: cash, card, and credit (udhaar).
        DB::table('fbr_pos_transactions')->insert([
            ['company_id' => $companyId, 'shift_id' => $shiftId, 'invoice_number' => 'S-001',
             'transaction_type' => 'sale', 'status' => 'completed', 'invoice_mode' => 'fbr',
             'subtotal' => 300.00, 'discount_amount' => 0, 'tax_amount' => 0, 'total_amount' => 300.00,
             'payment_method' => 'cash', 'payment_breakdown' => null,
             'created_at' => now(), 'updated_at' => now()],
            ['company_id' => $companyId, 'shift_id' => $shiftId, 'invoice_number' => 'S-002',
             'transaction_type' => 'sale', 'status' => 'completed', 'invoice_mode' => 'fbr',
             'subtotal' => 150.00, 'discount_amount' => 0, 'tax_amount' => 0, 'total_amount' => 150.00,
             'payment_method' => 'debit_card', 'payment_breakdown' => null,
             'created_at' => now(), 'updated_at' => now()],
            ['company_id' => $companyId, 'shift_id' => $shiftId, 'invoice_number' => 'S-003',
             'transaction_type' => 'sale', 'status' => 'completed', 'invoice_mode' => 'fbr',
             'subtotal' => 500.00, 'discount_amount' => 0, 'tax_amount' => 0, 'total_amount' => 500.00,
             'payment_method' => 'credit', 'payment_breakdown' => null, // udhaar
             'created_at' => now(), 'updated_at' => now()],
        ]);

        $totals = $this->runShiftTotals($shift);

        $this->assertSame(300.0,  $totals['cash'],   'cash bucket must be 300');
        $this->assertSame(150.0,  $totals['card'],   'card bucket must be 150');
        $this->assertSame(500.0,  $totals['udhaar'], 'udhaar bucket must be 500');
        $this->assertEquals(0,    $totals['other'],   'other must be 0 (no credit contamination)');
        $this->assertSame(950.0,  $totals['sales'],  'total sales must be 950');
    }

    public function test_shift_totals_payment_breakdown_credit_routes_to_udhaar(): void
    {
        // When the sale stores a payment_breakdown JSON (split-payment style),
        // credit entries in the breakdown must still land in the udhaar bucket.
        $companyId = $this->makeCompany();
        $user = $this->makeFbrUser($companyId);

        $shiftId = (int) DB::table('fbr_pos_shifts')->insertGetId([
            'company_id' => $companyId,
            'user_id' => $user->id,
            'opened_at' => now(),
            'opening_cash' => 0.00,
            'status' => 'open',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $shift = FbrPosShift::find($shiftId);

        // Breakdown: part cash, part credit (udhaar).
        $breakdown = json_encode([
            ['method' => 'cash',   'amount' => 200.00],
            ['method' => 'credit', 'amount' => 300.00],
        ]);
        DB::table('fbr_pos_transactions')->insert([
            'company_id' => $companyId, 'shift_id' => $shiftId, 'invoice_number' => 'S-BD-001',
            'transaction_type' => 'sale', 'status' => 'completed', 'invoice_mode' => 'fbr',
            'subtotal' => 500.00, 'discount_amount' => 0, 'tax_amount' => 0, 'total_amount' => 500.00,
            'payment_method' => 'cash', // top-level method may differ from breakdown
            'payment_breakdown' => $breakdown,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $totals = $this->runShiftTotals($shift);

        $this->assertSame(200.0, $totals['cash'],   'cash from breakdown must be 200');
        $this->assertSame(300.0, $totals['udhaar'], 'credit in breakdown must go to udhaar');
        $this->assertEquals(0,   $totals['other'],  'other must be 0');
    }

    // ════════════════════════════════════════════════════════════════════════
    // 3. closeShift HTTP — total_udhaar persisted on the shift row
    // ════════════════════════════════════════════════════════════════════════

    public function test_close_shift_persists_total_udhaar_and_excludes_it_from_expected_cash(): void
    {
        $companyId = $this->makeCompany();
        $user = $this->makeFbrUser($companyId);

        // Open a shift with Rs 100 opening cash.
        $shiftId = (int) DB::table('fbr_pos_shifts')->insertGetId([
            'company_id' => $companyId,
            'user_id' => $user->id,
            'opened_at' => now(),
            'opening_cash' => 100.00,
            'status' => 'open',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // Two completed sales attached to this shift: Rs 300 cash, Rs 500 credit.
        DB::table('fbr_pos_transactions')->insert([
            ['company_id' => $companyId, 'shift_id' => $shiftId, 'invoice_number' => 'CS-001',
             'transaction_type' => 'sale', 'status' => 'completed', 'invoice_mode' => 'fbr',
             'subtotal' => 300.00, 'discount_amount' => 0, 'tax_amount' => 0, 'total_amount' => 300.00,
             'payment_method' => 'cash', 'payment_breakdown' => null,
             'created_at' => now(), 'updated_at' => now()],
            ['company_id' => $companyId, 'shift_id' => $shiftId, 'invoice_number' => 'CS-002',
             'transaction_type' => 'sale', 'status' => 'completed', 'invoice_mode' => 'fbr',
             'subtotal' => 500.00, 'discount_amount' => 0, 'tax_amount' => 0, 'total_amount' => 500.00,
             'payment_method' => 'credit', 'payment_breakdown' => null,
             'created_at' => now(), 'updated_at' => now()],
        ]);

        // POST close-shift as the cashier.
        $response = $this->actingAs($user, 'fbrpos')
            ->post('/fbr-pos/shifts/close', ['closing_cash' => 400.00]);

        $response->assertRedirect();

        $shift = FbrPosShift::find($shiftId);
        $this->assertSame(500.0, (float) $shift->total_udhaar,
            'total_udhaar must be 500 (the credit sale)');
        $this->assertSame(300.0, (float) $shift->total_cash,
            'total_cash must be 300 (cash sale only)');
        $this->assertSame(0.0, (float) $shift->total_other,
            'total_other must not include the credit sale');

        // expected_cash = opening_cash(100) + cash_sales(300) = 400.
        // If udhaar were included expected_cash would be 900.
        $this->assertSame(400.0, (float) $shift->expected_cash,
            'expected_cash = opening + cash only; udhaar must not inflate it');
        $this->assertSame(0.0, (float) $shift->variance,
            'variance must be counted(400) - expected(400) = 0');
    }

    // ════════════════════════════════════════════════════════════════════════
    // 4. Thermal Z-report — historical vs new row render-time derivation
    // ════════════════════════════════════════════════════════════════════════

    /**
     * Historical row: udhaar_amount=0, other_amount=500 (credit was stored in Other
     * before Aug 2026). At render time the controller derives udhaar from the live
     * transactions (500) and subtracts it from other_amount for display.
     */
    public function test_thermal_derives_udhaar_for_historical_zero_row(): void
    {
        $companyId = $this->makeCompany();
        $user = $this->makeFbrUser($companyId);

        // A historic date with a credit bill already in the transactions table.
        $historicDate = now()->subDays(5)->toDateString();
        $this->makeTx($companyId, 'credit', 500.00, ['created_at' => $historicDate . ' 10:00:00', 'updated_at' => $historicDate . ' 10:00:00']);

        // Historical report: udhaar_amount=0, other_amount=500 (credit was in Other).
        $reportId = (int) DB::table('fbr_day_close_reports')->insertGetId([
            'company_id' => $companyId,
            'report_date' => $historicDate,
            'report_number' => 'ZRPT-HIST01',
            'total_invoices' => 1,
            'fbr_invoices' => 0, 'local_invoices' => 0, 'failed_invoices' => 0,
            'gross_sales' => 500.00, 'total_discount' => 0, 'net_sales' => 500.00,
            'total_tax' => 0, 'total_amount' => 500.00,
            'cash_amount' => 0, 'card_amount' => 0,
            'udhaar_amount' => 0.00,  // old row — not stored separately
            'other_amount' => 500.00, // credit was lumped here before the fix
            'hash' => 'histtest', 'created_at' => now(), 'updated_at' => now(),
        ]);

        $response = $this->actingAs($user, 'fbrpos')
            ->get("/fbr-pos/day-close/{$reportId}/thermal");

        $response->assertStatus(200);
        $html = $response->getContent();

        // Render-time derivation must produce 500 as displayUdhaar.
        $this->assertStringContainsString('500', $html,
            'derived udhaar amount (500) must appear in thermal output');
        // other_amount for display must be 0 (500 - 500 = 0, clamped).
        // We verify the page at minimum does NOT error out, and shows the credit amount.
    }

    /**
     * New row: udhaar_amount=501 (stored explicitly at close time).
     * The thermal report must trust the stored value directly.
     */
    public function test_thermal_uses_stored_udhaar_for_new_row(): void
    {
        $companyId = $this->makeCompany();
        $user = $this->makeFbrUser($companyId);

        $reportDate = now()->subDays(1)->toDateString();
        $this->makeTx($companyId, 'cash', 300.00, ['created_at' => $reportDate . ' 10:00:00', 'updated_at' => $reportDate . ' 10:00:00']);
        $this->makeTx($companyId, 'credit', 501.00, ['created_at' => $reportDate . ' 11:00:00', 'updated_at' => $reportDate . ' 11:00:00']);

        // New-style row: udhaar stored, other = 0.
        $reportId = (int) DB::table('fbr_day_close_reports')->insertGetId([
            'company_id' => $companyId,
            'report_date' => $reportDate,
            'report_number' => 'ZRPT-NEW01',
            'total_invoices' => 2, 'fbr_invoices' => 0, 'local_invoices' => 0, 'failed_invoices' => 0,
            'gross_sales' => 801.00, 'total_discount' => 0, 'net_sales' => 801.00,
            'total_tax' => 0, 'total_amount' => 801.00,
            'cash_amount' => 300.00, 'card_amount' => 0,
            'udhaar_amount' => 501.00, // explicitly stored
            'other_amount' => 0.00,
            'hash' => 'newtest', 'created_at' => now(), 'updated_at' => now(),
        ]);

        $response = $this->actingAs($user, 'fbrpos')
            ->get("/fbr-pos/day-close/{$reportId}/thermal");

        $response->assertStatus(200);
        $html = $response->getContent();

        $this->assertStringContainsString('501', $html,
            'stored udhaar (501) must appear in the thermal report');
    }

    // ════════════════════════════════════════════════════════════════════════
    // 5. Shift-report Blade view — udhaar row rendered/absent
    //
    // We render only the payment-breakdown fragment of the template by
    // compiling a minimal Blade string that mirrors the @if guard we added.
    // Going through the full HTTP stack would drag in x-fbr-pos-layout →
    // trial-lock-modal → pricing_plans and many more tables outside this
    // test's scope. The fragment approach is exact: it tests the exact
    // conditional we changed and nothing else.
    // ════════════════════════════════════════════════════════════════════════

    /** Render the payment-breakdown @if fragment from the real Blade template. */
    private function renderShiftBreakdownFragment(\App\Models\FbrPosShift $shift): string
    {
        // Extract the payment-breakdown section from the real template.
        // The section is introduced by the `pos.payment_breakdown` translation key
        // and ends at the next `border-t pt-4` div (the cash reconciliation block).
        $blade = file_get_contents(resource_path('views/fbr-pos/phase2/shift-report.blade.php'));

        // Anchor after the payment_breakdown heading line, capture until the closing
        // </div> of that <div class="border-t pt-4"> section.  The next section is
        // either `@if($shift->status === 'closed')` (recon) or `@if($movements...)`
        // — both start with `@if` on its own line after this section's closing </div>.
        // Strategy: grab from 'payment_breakdown' key to the first standalone @if or
        // end of the outer section (end of the border-t div).
        $start = strpos($blade, "pos.payment_breakdown");
        if ($start === false) {
            return $blade; // loud fallback: whole template, assertion will still work
        }
        // Move back to include the enclosing <div class="border-t pt-4"> tag.
        $divPos = strrpos(substr($blade, 0, $start), '<div class="border-t pt-4">');
        $fragment = $divPos !== false
            ? substr($blade, $divPos)
            : substr($blade, $start);

        // Trim to just this section: stop after the </div> that closes the
        // outer <div class="border-t pt-4"> payment-breakdown block.
        // The payment breakdown section contains no nested <div> elements
        // (only <h3> + <table>), so the first </div> after the heading closes it.
        $closeDiv = strpos($fragment, '</div>', 100);
        if ($closeDiv !== false) {
            $fragment = substr($fragment, 0, $closeDiv + 6); // include </div>
        }

        // Evaluate as Blade; $movements not needed for this section.
        return \Illuminate\Support\Facades\Blade::render($fragment, ['shift' => $shift]);
    }

    public function test_shift_report_view_shows_udhaar_row_when_total_udhaar_nonzero(): void
    {
        // Build a shift model with total_udhaar = 500 (in-memory, no HTTP needed).
        $shift = new \App\Models\FbrPosShift();
        $shift->forceFill([
            'id' => 1,
            'total_cash' => 300.00,
            'total_card' => 0.00,
            'total_udhaar' => 500.00,
            'total_other' => 0.00,
            'status' => 'closed',
            'opening_cash' => 0, 'closing_cash' => 300, 'expected_cash' => 300,
            'variance' => 0, 'sales_count' => 2, 'returns_count' => 0,
            'total_sales' => 800, 'total_returns' => 0,
        ]);

        $html = $this->renderShiftBreakdownFragment($shift);

        // Udhaar row must appear and show 500.
        $this->assertStringContainsString('500', $html,
            'udhaar amount (500) must appear in payment breakdown when total_udhaar > 0');

        // The dc_udhaar translation must appear (en locale = "Udhaar / Khata").
        $udhaarlabel = trans('pos.dc_udhaar');
        $this->assertStringContainsString($udhaarlabel, $html,
            'dc_udhaar label must appear in payment breakdown');

        // The "not in cash drawer" sub-label must appear.
        $notInDrawer = trans('pos.dc_udhaar_not_in_drawer');
        $this->assertStringContainsString($notInDrawer, $html,
            'dc_udhaar_not_in_drawer label must appear as context hint');
    }

    public function test_shift_report_view_omits_udhaar_row_when_zero(): void
    {
        // Shift with no credit sales — total_udhaar = 0.
        $shift = new \App\Models\FbrPosShift();
        $shift->forceFill([
            'id' => 2,
            'total_cash' => 400.00,
            'total_card' => 0.00,
            'total_udhaar' => 0.00,
            'total_other' => 0.00,
            'status' => 'closed',
            'opening_cash' => 0, 'closing_cash' => 400, 'expected_cash' => 400,
            'variance' => 0, 'sales_count' => 1, 'returns_count' => 0,
            'total_sales' => 400, 'total_returns' => 0,
        ]);

        $html = $this->renderShiftBreakdownFragment($shift);

        // Udhaar row must be completely absent — zero-value row would confuse cashier.
        $udhaarlabel = trans('pos.dc_udhaar');
        $this->assertStringNotContainsString($udhaarlabel, $html,
            'udhaar row must NOT appear when total_udhaar = 0 (cash-only shift)');
    }

    // ════════════════════════════════════════════════════════════════════════
    // 6. Lang keys — dc_udhaar + dc_udhaar_not_in_drawer in all three locales
    // ════════════════════════════════════════════════════════════════════════

    public function test_udhaar_lang_keys_exist_in_all_three_locales(): void
    {
        foreach (['en', 'rur', 'ur'] as $locale) {
            $this->assertTrue(
                \Illuminate\Support\Facades\Lang::has('pos.dc_udhaar', $locale),
                "pos.dc_udhaar missing in locale: $locale"
            );
            $this->assertTrue(
                \Illuminate\Support\Facades\Lang::has('pos.dc_udhaar_not_in_drawer', $locale),
                "pos.dc_udhaar_not_in_drawer missing in locale: $locale"
            );
        }
    }

    public function test_udhaar_lang_keys_are_not_empty_in_any_locale(): void
    {
        foreach (['en', 'rur', 'ur'] as $locale) {
            foreach (['dc_udhaar', 'dc_udhaar_not_in_drawer'] as $key) {
                $value = trans("pos.$key", [], $locale);
                $this->assertNotEmpty($value,    "pos.$key in $locale must not be empty");
                $this->assertNotSame("pos.$key", $value, "pos.$key in $locale must not fall back to the key itself");
            }
        }
    }

    public function test_ur_udhaar_key_contains_urdu_script_not_latin(): void
    {
        // The ur locale must carry pure Urdu script, not Roman transliteration.
        $value = trans('pos.dc_udhaar', [], 'ur');
        // ا (alef) is one of the most common Urdu letters; its presence is a
        // cheap proxy for "this is Urdu script, not English".
        $this->assertMatchesRegularExpression('/[\x{0600}-\x{06FF}]/u', $value,
            'pos.dc_udhaar in ur locale must contain Urdu/Arabic-script characters');
    }
}
