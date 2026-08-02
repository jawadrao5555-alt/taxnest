<?php

namespace Tests\Feature;

use Tests\TestCase;
use App\Models\Company;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;

/**
 * FBR DAY-CLOSE AUTO-FINALIZE SWEEP — "bills kabhi ghum na hon" (task guarantee).
 *
 * FBR mirror of tests/Feature/PosDayCloseAutoFinalizeTest.php. Locks the edge
 * paths of FbrPosController::finalizeFbrProvisionalsAtDayClose (the 'finalize'
 * day-close pending-bill policy added with the FBR "Khud Final" option) so
 * future changes can never silently delete / lose provisional bills:
 *
 *   1. Reporting-OFF finalize → 'fbr' mode + NULL fbr_status (Reporting-OFF
 *      Finals Invariant: NEVER 'local', NEVER left 'pending'), invoice number
 *      and amounts untouched (FBR POS keeps decimals — no re-tax, no renumber).
 *   2. No quota gate in the sweep BY DESIGN: FBR quota counts rows at CREATION
 *      time, so promoting existing provisionals must never be quota-blocked —
 *      even with a tiny invoice_limit_override every bill is finalized.
 *   3. Previous-month locals → month-gate SKIPPED, byte-for-byte untouched;
 *      already-final / already-pending rows never re-claimed.
 *   4. Cloud submit failure (POSID not configured — deterministic, pre-network)
 *      → bill FINAL + fbr_status 'failed', Fail-Queue retryable — never lost,
 *      failure logged in fbr_pos_logs.
 *   5. Fiscal Device (agent) company → bill FINAL + stays 'pending' for the
 *      desktop agent; no server-side submit attempt, no log row.
 *   6. HTTP flash lock (Task 150): closeDayReport POST must surface the sweep
 *      counts in the session 'success' message (X FINAL / Y submitted /
 *      Z queued / W failed) — and show ONLY the base "report generated"
 *      message when the policy is off or the sweep did nothing. If a refactor
 *      drops $this->lastFinalizeSweep on the floor or misreads a key, the
 *      cashier gets a wrong/empty summary — these tests scream instead.
 *
 * Pattern: APP_ENV=testing + sqlite :memory: + minimal Schema::create.
 * The private sweep method is invoked via reflection — performDayClose drags
 * in the full Z-report schema, which is irrelevant to these guarantees.
 *
 * Run:
 *   env -u DATABASE_URL -u DB_CONNECTION -u PGHOST -u PGPORT -u PGUSER \
 *     -u PGPASSWORD -u PGDATABASE APP_ENV=testing DB_CONNECTION=sqlite \
 *     DB_DATABASE=':memory:' php vendor/bin/phpunit tests/Feature/FbrPosDayCloseAutoFinalizeTest.php
 */
class FbrPosDayCloseAutoFinalizeTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Schema::dropAllTables();

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

        Schema::create('fbr_pos_transactions', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('company_id');
            $table->string('invoice_number');
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
            $table->decimal('cash_received', 12, 2)->nullable();
            $table->decimal('change_due', 12, 2)->nullable();
            $table->timestamps();
        });

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
            $table->boolean('is_tax_exempt')->default(false);
            $table->decimal('tax_rate', 8, 2)->nullable();
            $table->decimal('tax_amount', 12, 2)->nullable();
            $table->decimal('total', 12, 2)->nullable();
            $table->timestamps();
        });

        // submitFbrPosTransaction writes a log row on every failure path.
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

        // ── HTTP flash tests (Task 150) need the full closeDayReport path ──

        // fbrpos guard authenticates against users.
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

        // FbrPosAuth resolves the active branch via BranchContextService.
        Schema::create('branches', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('company_id');
            $table->string('name');
            $table->boolean('is_active')->default(true);
            $table->boolean('is_head_office')->default(false);
            $table->timestamps();
        });

        // performDayClose persists the Z-report row.
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

        // Report numbering uses MySQL SUBSTRING_INDEX — polyfill it for sqlite
        // so the atomic MAX+1 raw expression works in this suite.
        DB::connection()->getPdo()->sqliteCreateFunction('SUBSTRING_INDEX', function ($str, $delim, $count) {
            $parts = explode((string) $delim, (string) $str);
            return $count < 0
                ? implode($delim, array_slice($parts, (int) $count))
                : implode($delim, array_slice($parts, 0, (int) $count));
        });
    }

    // ── fixtures ────────────────────────────────────────────────────────────

    private function makeCompany(array $attrs = []): int
    {
        return (int) DB::table('companies')->insertGetId(array_merge([
            'name' => 'FBR Sweep Test Co',
            'is_internal_account' => false,
            'invoice_limit_override' => -1, // unlimited by default
            'fbr_reporting_enabled' => false,
            'agent_enabled' => false,
            'fbr_connection_mode' => 'cloud',
            'fbr_environment' => 'sandbox',
            'pos_dayclose_provisional_action' => 'finalize',
            'created_at' => now(),
            'updated_at' => now(),
        ], $attrs));
    }

    private function makeProvisional(int $companyId, string $number, array $attrs = []): int
    {
        $subtotal = (float) ($attrs['subtotal'] ?? 100.00);
        $id = (int) DB::table('fbr_pos_transactions')->insertGetId(array_merge([
            'company_id' => $companyId,
            'invoice_number' => $number,
            'status' => 'completed',
            'invoice_mode' => 'local',
            'fbr_status' => 'local',
            'fbr_invoice_number' => null,
            'subtotal' => $subtotal,
            'discount_amount' => 0,
            'tax_rate' => 18,
            'tax_amount' => round($subtotal * 0.18, 2),
            'total_amount' => round($subtotal * 1.18, 2),
            'payment_method' => 'cash',
            'created_at' => now(),
            'updated_at' => now(),
        ], $attrs));

        DB::table('fbr_pos_transaction_items')->insert([
            'transaction_id' => $id,
            'item_name' => 'Test Item',
            'quantity' => 1,
            'unit_price' => $subtotal,
            'subtotal' => $subtotal,
            'is_tax_exempt' => false,
            'tax_rate' => 18,
            'tax_amount' => round($subtotal * 0.18, 2),
            'total' => round($subtotal * 1.18, 2),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $id;
    }

    /** Invoke the private sweep exactly as performDayClose does. */
    private function runSweep(int $companyId, ?string $date = null): array
    {
        $controller = app(\App\Http\Controllers\FbrPosController::class);
        $method = new \ReflectionMethod($controller, 'finalizeFbrProvisionalsAtDayClose');
        $method->setAccessible(true);

        return $method->invoke($controller, $companyId, Company::find($companyId), $date ?? now()->toDateString());
    }

    private function tx(int $id): object
    {
        return DB::table('fbr_pos_transactions')->where('id', $id)->first();
    }

    // ── 1. reporting-OFF finalize ───────────────────────────────────────────

    public function test_reporting_off_finalize_is_fbr_mode_null_status_amounts_untouched(): void
    {
        $companyId = $this->makeCompany(); // reporting OFF

        $a = $this->makeProvisional($companyId, 'L-0001', [
            'subtotal' => 99.50, 'tax_amount' => 17.91, 'total_amount' => 117.41,
        ]);
        $b = $this->makeProvisional($companyId, 'L-0002');

        $sweep = $this->runSweep($companyId);

        $this->assertSame(2, $sweep['finalized']);
        $this->assertSame(0, $sweep['submitted']);
        $this->assertSame(0, $sweep['queued']);
        $this->assertSame(0, $sweep['failed']);
        $this->assertSame(0, $sweep['skipped']);

        $txA = $this->tx($a);
        // Reporting-OFF Finals Invariant: regulator mode + NULL status —
        // NEVER 'local' (would re-enter the F10 provisional modal) and
        // NEVER 'pending' (would strand it in the Fail Queue forever).
        $this->assertSame('fbr', $txA->invoice_mode);
        $this->assertNull($txA->fbr_status);
        // FBR POS: no renumbering and no re-tax at promote — amounts & serial
        // are byte-for-byte the stored fiscal snapshots (decimals kept).
        $this->assertSame('L-0001', $txA->invoice_number);
        $this->assertSame(99.5, (float) $txA->subtotal);
        $this->assertSame(17.91, (float) $txA->tax_amount);
        $this->assertSame(117.41, (float) $txA->total_amount);
        $this->assertNull($txA->fbr_invoice_number);

        $txB = $this->tx($b);
        $this->assertSame('fbr', $txB->invoice_mode);
        $this->assertNull($txB->fbr_status);
        $this->assertSame('L-0002', $txB->invoice_number);

        // No submission attempted → no log rows.
        $this->assertSame(0, DB::table('fbr_pos_logs')->count());
    }

    // ── 2. no quota gate at promote (creation-time counting) ────────────────

    public function test_tiny_invoice_limit_never_blocks_the_sweep_all_bills_finalized(): void
    {
        // FBR quota counts bills at CREATION time, so promoting existing
        // provisionals consumes no quota — a tiny limit must never strand
        // (or worse, lose) pending bills at day close.
        $companyId = $this->makeCompany(['invoice_limit_override' => 1]);

        $ids = [
            $this->makeProvisional($companyId, 'L-0001'),
            $this->makeProvisional($companyId, 'L-0002'),
            $this->makeProvisional($companyId, 'L-0003'),
        ];

        $sweep = $this->runSweep($companyId);

        $this->assertSame(3, $sweep['finalized']);
        $this->assertSame(0, $sweep['skipped']);
        $this->assertSame(0, $sweep['failed']);

        foreach ($ids as $id) {
            $tx = $this->tx($id);
            $this->assertSame('fbr', $tx->invoice_mode);
            $this->assertNull($tx->fbr_status); // reporting OFF default
        }
        // Nothing deleted — every bill still present.
        $this->assertSame(3, DB::table('fbr_pos_transactions')->count());
    }

    // ── 3. month gate + already-final rows untouched ────────────────────────

    public function test_previous_month_locals_and_non_local_rows_are_left_untouched(): void
    {
        $companyId = $this->makeCompany();

        $lastMonthDate = now()->startOfMonth()->subDays(2);
        $oldBill = $this->makeProvisional($companyId, 'L-0001', [
            'created_at' => $lastMonthDate,
            'updated_at' => $lastMonthDate,
        ]);
        // Already-final rows (fbr mode / submitted) must never be re-claimed.
        $submitted = $this->makeProvisional($companyId, 'F-0001', [
            'invoice_mode' => 'fbr',
            'fbr_status' => 'submitted',
            'fbr_invoice_number' => 'FBR-123',
        ]);
        $pending = $this->makeProvisional($companyId, 'F-0002', [
            'invoice_mode' => 'fbr',
            'fbr_status' => 'pending',
        ]);

        $sweep = $this->runSweep($companyId);

        // Old bill selected (created_at <= close date) but month-gate SKIPPED;
        // submitted/pending rows never even enter the selector.
        $this->assertSame(0, $sweep['finalized']);
        $this->assertSame(1, $sweep['skipped']);

        $tx = $this->tx($oldBill);
        $this->assertSame('local', $tx->invoice_mode, 'previous-month local must be carried, untouched');
        $this->assertSame('local', $tx->fbr_status);
        $this->assertSame('L-0001', $tx->invoice_number);
        $this->assertSame(118.0, (float) $tx->total_amount);

        $this->assertSame('submitted', $this->tx($submitted)->fbr_status);
        $this->assertSame('FBR-123', $this->tx($submitted)->fbr_invoice_number);
        $this->assertSame('pending', $this->tx($pending)->fbr_status);

        // Nothing lost.
        $this->assertSame(3, DB::table('fbr_pos_transactions')->count());
    }

    // ── 4. cloud submit failure → FINAL + 'failed', retryable, never lost ───

    public function test_cloud_submit_failure_leaves_bill_final_and_retryable_never_lost(): void
    {
        // Reporting ON, cloud mode, NO POSID configured → submitFbrPosTransaction
        // fails deterministically at the mandatory-field guard BEFORE any network
        // call: fbr_status stamped 'failed' + a log row written.
        $companyId = $this->makeCompany([
            'fbr_reporting_enabled' => true,
            'fbr_pos_id' => null,
        ]);

        $bill = $this->makeProvisional($companyId, 'L-0001', ['subtotal' => 200.00]);

        $sweep = $this->runSweep($companyId);

        $this->assertSame(1, $sweep['finalized']);
        $this->assertSame(1, $sweep['failed']);
        $this->assertSame(0, $sweep['submitted']);
        $this->assertSame(0, $sweep['queued']);

        $tx = $this->tx($bill);
        // FINAL + Fail-Queue retryable — the bill is never lost, never deleted.
        $this->assertSame('fbr', $tx->invoice_mode);
        $this->assertSame('failed', $tx->fbr_status);
        $this->assertNull($tx->fbr_invoice_number);
        $this->assertSame('L-0001', $tx->invoice_number);
        $this->assertSame(200.0, (float) $tx->subtotal);
        // Hash lock cleared on failure — a later manual retry is not stranded.
        $this->assertNull($tx->fbr_submission_hash);
        // The failed attempt was logged.
        $this->assertSame(1, DB::table('fbr_pos_logs')->where('transaction_id', $bill)->where('status', 'failed')->count());
    }

    // ── 5. Fiscal Device (agent) company → queued for the desktop agent ─────

    public function test_fiscal_device_company_keeps_bill_pending_for_agent(): void
    {
        $companyId = $this->makeCompany([
            'fbr_reporting_enabled' => true,
            'agent_enabled' => true,
            'fbr_pos_enabled' => true,
            'fbr_connection_mode' => 'fiscal_device',
        ]);

        $bill = $this->makeProvisional($companyId, 'L-0001');

        $sweep = $this->runSweep($companyId);

        $this->assertSame(1, $sweep['finalized']);
        $this->assertSame(1, $sweep['queued']);
        $this->assertSame(0, $sweep['failed']);
        $this->assertSame(0, $sweep['submitted']);

        $tx = $this->tx($bill);
        $this->assertSame('fbr', $tx->invoice_mode);
        $this->assertSame('pending', $tx->fbr_status, 'desktop agent polls the row — must stay pending');
        $this->assertSame('L-0001', $tx->invoice_number);
        // No server-side network attempt / no log row for Fiscal Device companies.
        $this->assertSame(0, DB::table('fbr_pos_logs')->count());
    }

    // ════════════════════════════════════════════════════════════════════════
    // 6. HTTP FLASH LOCK (Task 150) — closeDayReport POST sweep summary
    // ════════════════════════════════════════════════════════════════════════

    /** Company attrs every HTTP test needs to survive FbrPosAuth + company.approval. */
    private function makeHttpCompany(array $attrs = []): int
    {
        return $this->makeCompany(array_merge([
            'product_type' => 'fbrpos',
            'status' => 'active',
            'fbr_pos_enabled' => true,
        ], $attrs));
    }

    private function makeFbrUser(int $companyId, string $language = 'en'): \App\Models\User
    {
        $id = DB::table('users')->insertGetId([
            'name' => 'FBR Cashier',
            'email' => 'cashier' . $companyId . '@taxnest.test',
            'password' => bcrypt('Secret@12345'),
            'company_id' => $companyId,
            'role' => 'company_admin',
            'is_active' => true,
            'language' => $language,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return \App\Models\User::find($id);
    }

    /** POST the real close-day route as the given user and return the response. */
    private function closeDay(\App\Models\User $user)
    {
        return $this->actingAs($user, 'fbrpos')
            ->from('/fbr-pos/day-close')
            ->post('/fbr-pos/day-close', []);
    }

    /** The base "report generated" message for today's freshly-created report. */
    private function baseMessage(int $companyId): string
    {
        $report = DB::table('fbr_day_close_reports')->where('company_id', $companyId)->first();
        $this->assertNotNull($report, 'day-close report row must exist');

        return __('pos.dayclose_report_generated_for', [
            'number' => $report->report_number,
            'date' => \Carbon\Carbon::parse($report->report_date)->format('d M Y'),
        ]);
    }

    public function test_flash_shows_finalized_count_when_sweep_promotes_bills(): void
    {
        // Reporting OFF + policy 'finalize' + 2 provisionals → "2 FINAL", and
        // no submitted/queued/failed suffixes (those counts are all zero).
        $companyId = $this->makeHttpCompany();
        $this->makeProvisional($companyId, 'L-0001');
        $this->makeProvisional($companyId, 'L-0002');

        $response = $this->closeDay($this->makeFbrUser($companyId));

        $response->assertRedirect('/fbr-pos/day-close');
        $response->assertSessionHas('success');

        $msg = session('success');
        $expected = $this->baseMessage($companyId)
            . __('pos.dayclose_bills_finalized', ['count' => 2]);
        $this->assertSame($expected, $msg, 'flash must be base message + finalized suffix ONLY');

        // Zero-count branches must not leak into the message.
        $this->assertStringNotContainsString(__('pos.dayclose_bills_submitted', ['count' => 0]), $msg);
        $this->assertStringNotContainsString(__('pos.dayclose_bills_queued', ['count' => 0]), $msg);
        $this->assertStringNotContainsString(__('pos.dayclose_bills_failed', ['count' => 0]), $msg);
    }

    public function test_flash_shows_queued_count_for_fiscal_device_company(): void
    {
        // Agent (Fiscal Device) company → finalized + queued suffixes.
        $companyId = $this->makeHttpCompany([
            'fbr_reporting_enabled' => true,
            'agent_enabled' => true,
            'fbr_connection_mode' => 'fiscal_device',
        ]);
        $this->makeProvisional($companyId, 'L-0001');

        $response = $this->closeDay($this->makeFbrUser($companyId));

        $response->assertSessionHas('success');
        $expected = $this->baseMessage($companyId)
            . __('pos.dayclose_bills_finalized', ['count' => 1])
            . __('pos.dayclose_bills_queued', ['count' => 1]);
        $this->assertSame($expected, session('success'));
    }

    public function test_flash_shows_failed_count_when_cloud_submit_fails(): void
    {
        // Cloud mode + reporting ON + no POSID → deterministic submit failure
        // → finalized + failed suffixes (Fail Queue — retryable, never lost).
        $companyId = $this->makeHttpCompany([
            'fbr_reporting_enabled' => true,
            'fbr_pos_id' => null,
        ]);
        $this->makeProvisional($companyId, 'L-0001');

        $response = $this->closeDay($this->makeFbrUser($companyId));

        $response->assertSessionHas('success');
        $expected = $this->baseMessage($companyId)
            . __('pos.dayclose_bills_finalized', ['count' => 1])
            . __('pos.dayclose_bills_failed', ['count' => 1]);
        $this->assertSame($expected, session('success'));
    }

    public function test_flash_is_base_message_only_when_policy_off(): void
    {
        // Policy NULL → sweep never runs; provisional stays local and the flash
        // carries NO sweep suffix at all.
        $companyId = $this->makeHttpCompany(['pos_dayclose_provisional_action' => null]);
        $bill = $this->makeProvisional($companyId, 'L-0001');

        $response = $this->closeDay($this->makeFbrUser($companyId));

        $response->assertSessionHas('success');
        $this->assertSame($this->baseMessage($companyId), session('success'));
        $this->assertSame('local', $this->tx($bill)->fbr_status, 'policy off — bill untouched');
    }

    public function test_flash_is_base_message_only_when_sweep_finds_nothing(): void
    {
        // Policy 'finalize' but no provisionals (only an already-final bill so
        // the report itself generates) → finalized 0 → base message only.
        $companyId = $this->makeHttpCompany();
        $this->makeProvisional($companyId, 'F-0001', [
            'invoice_mode' => 'fbr',
            'fbr_status' => null,
        ]);

        $response = $this->closeDay($this->makeFbrUser($companyId));

        $response->assertSessionHas('success');
        $this->assertSame($this->baseMessage($companyId), session('success'));
    }

    public function test_urdu_user_gets_urdu_sweep_summary(): void
    {
        // Cashier language 'ur' → SetPosLocale flips the request locale, so the
        // flash must be built from the ur keys (and differ from the en render).
        $companyId = $this->makeHttpCompany();
        $this->makeProvisional($companyId, 'L-0001');

        $response = $this->closeDay($this->makeFbrUser($companyId, 'ur'));

        $response->assertSessionHas('success');
        $urSuffix = trans('pos.dayclose_bills_finalized', ['count' => 1], 'ur');
        $this->assertStringContainsString($urSuffix, session('success'));
        $this->assertNotSame(
            trans('pos.dayclose_bills_finalized', ['count' => 1], 'en'),
            $urSuffix,
            'ur key must not silently fall back to English'
        );
    }

    public function test_all_sweep_summary_keys_exist_in_en_and_ur(): void
    {
        foreach (['dayclose_bills_finalized', 'dayclose_bills_submitted', 'dayclose_bills_queued', 'dayclose_bills_failed'] as $key) {
            foreach (['en', 'ur'] as $locale) {
                $this->assertTrue(
                    \Illuminate\Support\Facades\Lang::has("pos.$key", $locale),
                    "pos.$key missing in $locale"
                );
                $this->assertStringContainsString(
                    ':count',
                    trans("pos.$key", [], $locale) === "pos.$key" ? '' : \Illuminate\Support\Facades\Lang::get("pos.$key", [], $locale, false),
                    "pos.$key ($locale) must carry the :count placeholder"
                );
            }
        }
    }
}
