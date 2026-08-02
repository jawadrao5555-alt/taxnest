<?php

namespace Tests\Feature;

use Tests\TestCase;
use App\Models\Company;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;

/**
 * DAY-CLOSE AUTO-FINALIZE SWEEP — "bills kabhi ghum na hon" (task guarantee).
 *
 * Locks the edge paths of PosController::finalizeProvisionalsAtDayClose
 * (the 'finalize' day-close provisional action) so future changes can never
 * silently delete / lose provisional bills:
 *
 *   1. Reporting-OFF finalize → 'pra' mode + NULL pra_status (never 'local'),
 *      whole-rupee re-tax, L-number kept (no fiscal serial burned).
 *   2. Quota exhausted mid-sweep → remaining bills carried UNTOUCHED.
 *   3. Previous-month locals + drafts + archived locals → skipped, untouched.
 *   4. PRA connection failure (cloud) → bill FINAL + pra_status 'offline',
 *      retryable — never lost, never archived.
 *   5. Agent-Sync company → bill FINAL + stays 'pending' for the desktop agent.
 *
 * Pattern: APP_ENV=testing + sqlite :memory: + minimal Schema::create
 * (same approach as Phase3LoginIsolationTest). The private sweep method is
 * invoked via reflection — performDayClose drags in the full Z-report schema,
 * which is irrelevant to these guarantees.
 */
class PosDayCloseAutoFinalizeTest extends TestCase
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
            $table->string('pos_dayclose_provisional_action')->nullable();
            $table->string('pos_dayclose_final_local_action')->nullable();
            $table->boolean('pos_customer_spend_persist')->default(true);
            $table->string('pos_business_day_cutoff')->nullable();
            $table->boolean('is_internal_account')->default(false);
            $table->integer('invoice_limit_override')->nullable();
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
            $table->softDeletes();
            $table->timestamps();
        });

        // praReportingActive() falls back to a users-table lookup when the
        // company-level flag is OFF.
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
            $table->boolean('pra_reporting_enabled')->default(false);
            $table->rememberToken();
            $table->timestamps();
        });

        Schema::create('pos_transactions', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('company_id');
            $table->string('invoice_number');
            $table->string('business_date')->nullable(); // stored as date string; NEVER whereDate()d
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
            $table->decimal('subtotal', 12, 2)->default(0);
            $table->decimal('discount_amount', 12, 2)->default(0);
            $table->decimal('tax_rate', 8, 2)->nullable();
            $table->decimal('tax_amount', 12, 2)->default(0);
            $table->decimal('exempt_amount', 12, 2)->nullable();
            $table->decimal('total_amount', 12, 2)->default(0);
            $table->string('payment_method')->nullable();
            $table->decimal('cash_received', 12, 2)->nullable();
            $table->decimal('change_due', 12, 2)->nullable();
            $table->string('submission_hash')->nullable();
            $table->boolean('tax_inclusive')->default(false);
            $table->decimal('tax_menu_rate', 8, 2)->nullable();
            $table->timestamps();
        });

        Schema::create('pos_transaction_items', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('transaction_id');
            $table->string('item_type')->nullable();
            $table->unsignedBigInteger('item_id')->nullable();
            $table->string('item_name');
            $table->decimal('quantity', 12, 3)->default(1);
            $table->decimal('unit_price', 12, 2)->default(0);
            $table->decimal('subtotal', 12, 2)->default(0);
            $table->boolean('is_tax_exempt')->default(false);
            $table->decimal('tax_rate', 8, 2)->nullable();
            $table->decimal('tax_amount', 12, 2)->nullable();
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

        // Branch context resolution during HTTP requests (Task 157 flash tests).
        Schema::create('branches', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('company_id');
            $table->string('name');
            $table->boolean('is_active')->default(true);
            $table->boolean('is_head_office')->default(false);
            $table->timestamps();
        });

        // sendInvoice() writes a request log row before hitting the network.
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
        // HTTP flash tests (Task 157) drive the FULL closeDayReport path, so the
        // Z-report row needs its real data columns too.
        Schema::create('pos_day_close_reports', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('company_id');
            $table->date('report_date');
            $table->string('report_number')->nullable();
            $table->integer('deleted_final_count')->default(0);
            $table->integer('total_invoices')->default(0);
            $table->integer('pra_invoices')->default(0);
            $table->integer('local_invoices')->default(0);
            $table->integer('offline_invoices')->default(0);
            $table->decimal('gross_sales', 14, 2)->default(0);
            $table->decimal('total_discount', 14, 2)->default(0);
            $table->decimal('net_sales', 14, 2)->default(0);
            $table->decimal('total_tax', 14, 2)->default(0);
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
    }

    // ── fixtures ────────────────────────────────────────────────────────────

    private function makeCompany(array $attrs = []): int
    {
        return (int) DB::table('companies')->insertGetId(array_merge([
            'name' => 'Sweep Test Co',
            'is_internal_account' => false,
            'invoice_limit_override' => -1, // unlimited → quota path stays out of the way
            'pra_reporting_enabled' => false,
            'agent_enabled' => false,
            'pra_connection_mode' => 'cloud',
            'pra_environment' => 'sandbox',
            // Company-level rate overrides → PosTaxRule table never consulted.
            'pos_tax_rate_cash' => 16.00,
            'pos_tax_rate_card' => 16.00,
            'created_at' => now(),
            'updated_at' => now(),
        ], $attrs));
    }

    private function makeProvisional(int $companyId, string $number, array $attrs = [], array $itemAttrs = []): int
    {
        $subtotal = (float) ($attrs['subtotal'] ?? 100.00);
        $id = (int) DB::table('pos_transactions')->insertGetId(array_merge([
            'company_id' => $companyId,
            'invoice_number' => $number,
            'business_date' => now()->toDateString(),
            'status' => 'completed',
            'invoice_mode' => 'local',
            'pra_status' => 'local',
            'pra_invoice_number' => null,
            'is_archived' => false,
            'subtotal' => $subtotal,
            'discount_amount' => 0,
            'tax_rate' => 0,
            'tax_amount' => 0,
            'total_amount' => $subtotal,
            'payment_method' => 'cash',
            'tax_inclusive' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ], $attrs));

        DB::table('pos_transaction_items')->insert(array_merge([
            'transaction_id' => $id,
            'item_name' => 'Test Item',
            'quantity' => 1,
            'unit_price' => $subtotal,
            'subtotal' => $subtotal,
            'is_tax_exempt' => false,
            'tax_rate' => 0,
            'tax_amount' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ], $itemAttrs));

        return $id;
    }

    /** Invoke the private sweep exactly as performDayClose does. */
    private function runSweep(int $companyId, ?string $date = null): array
    {
        $controller = app(\App\Http\Controllers\PosController::class);
        $method = new \ReflectionMethod($controller, 'finalizeProvisionalsAtDayClose');
        $method->setAccessible(true);

        return $method->invoke($controller, $companyId, Company::find($companyId), $date ?? now()->toDateString());
    }

    private function tx(int $id): object
    {
        return DB::table('pos_transactions')->where('id', $id)->first();
    }

    // ── 1. reporting-OFF finalize ───────────────────────────────────────────

    public function test_reporting_off_finalize_is_pra_mode_null_status_whole_rupee(): void
    {
        $companyId = $this->makeCompany(); // reporting OFF, no user has the flag

        $a = $this->makeProvisional($companyId, 'L-0001', ['subtotal' => 99.50, 'total_amount' => 99.50]);
        $b = $this->makeProvisional($companyId, 'L-0002');

        $sweep = $this->runSweep($companyId);

        $this->assertSame(2, $sweep['finalized']);
        $this->assertSame(0, $sweep['submitted']);
        $this->assertSame(0, $sweep['queued']);
        $this->assertSame(0, $sweep['offline']);
        $this->assertSame(0, $sweep['quota_blocked']);
        $this->assertSame(0, $sweep['skipped']);
        $this->assertSame(232.0, $sweep['finalized_amount']); // 116 + 116

        $txA = $this->tx($a);
        // Reporting-OFF Finals Invariant: regulator mode + NULL status — NEVER 'local'.
        $this->assertSame('pra', $txA->invoice_mode);
        $this->assertNull($txA->pra_status);
        // No fiscal serial burned — the L number stays.
        $this->assertSame('L-0001', $txA->invoice_number);
        // Whole-rupee convention: 99.50 → tax round(15.92)=16, total round(115.50)=116.
        $this->assertSame(16.0, (float) $txA->tax_amount);
        $this->assertSame(116.0, (float) $txA->total_amount);
        $this->assertSame(99.5, (float) $txA->subtotal);
        $this->assertSame(116.0, (float) $txA->cash_received);
        $this->assertFalse((bool) $txA->is_archived);
        // Item lines stay 2dp (only the header is whole-rupee).
        $this->assertSame(15.92, (float) DB::table('pos_transaction_items')->where('transaction_id', $a)->value('tax_amount'));
        // Payment record synced to the re-taxed total.
        $this->assertSame(116.0, (float) DB::table('pos_payments')->where('transaction_id', $a)->value('amount'));

        $txB = $this->tx($b);
        $this->assertSame('pra', $txB->invoice_mode);
        $this->assertNull($txB->pra_status);
        $this->assertSame(116.0, (float) $txB->total_amount);
    }

    // ── 2. quota exhausted mid-sweep ────────────────────────────────────────

    public function test_quota_block_mid_sweep_carries_remaining_bills_untouched(): void
    {
        // Admin override: 1 final bill per month.
        $companyId = $this->makeCompany(['invoice_limit_override' => 1]);

        $first = $this->makeProvisional($companyId, 'L-0001');
        $second = $this->makeProvisional($companyId, 'L-0002');
        $third = $this->makeProvisional($companyId, 'L-0003');

        $sweep = $this->runSweep($companyId);

        $this->assertSame(1, $sweep['finalized']);
        $this->assertSame(2, $sweep['quota_blocked']);
        $this->assertSame(0, $sweep['skipped']);

        // First bill consumed the quota and became a real final.
        $this->assertSame('pra', $this->tx($first)->invoice_mode);

        // The remaining two are CARRIED — byte-for-byte untouched provisionals.
        foreach ([$second => 'L-0002', $third => 'L-0003'] as $id => $number) {
            $tx = $this->tx($id);
            $this->assertSame('local', $tx->invoice_mode, "bill {$number} must stay provisional");
            $this->assertSame('local', $tx->pra_status, "bill {$number} must stay provisional");
            $this->assertSame($number, $tx->invoice_number);
            $this->assertSame(100.0, (float) $tx->total_amount);
            $this->assertSame(0.0, (float) $tx->tax_amount);
            $this->assertFalse((bool) $tx->is_archived);
            $this->assertNull($tx->archived_at);
            $this->assertSame(0, DB::table('pos_payments')->where('transaction_id', $id)->count());
        }
    }

    // ── 3. month-closed / drafts / archived locals ──────────────────────────

    public function test_previous_month_drafts_and_archived_locals_are_left_untouched(): void
    {
        $companyId = $this->makeCompany();

        $lastMonthDate = now()->startOfMonth()->subDays(2);
        $oldBill = $this->makeProvisional($companyId, 'L-0001', [
            'business_date' => $lastMonthDate->toDateString(),
            'created_at' => $lastMonthDate,
            'updated_at' => $lastMonthDate,
        ]);
        $draft = $this->makeProvisional($companyId, 'L-0002', ['status' => 'draft']);
        $archivedLocal = $this->makeProvisional($companyId, 'L-0003', [
            'is_archived' => true,
            'archived_at' => now(),
        ]);

        $sweep = $this->runSweep($companyId);

        // Old bill selected (business_date <= close date) but month-gate SKIPPED;
        // draft + archived local never even enter the selector.
        $this->assertSame(0, $sweep['finalized']);
        $this->assertSame(1, $sweep['skipped']);
        $this->assertSame(0, $sweep['quota_blocked']);

        foreach ([
            $oldBill => ['completed', 'L-0001'],
            $draft => ['draft', 'L-0002'],
        ] as $id => [$status, $number]) {
            $tx = $this->tx($id);
            $this->assertSame('local', $tx->invoice_mode);
            $this->assertSame('local', $tx->pra_status);
            $this->assertSame($status, $tx->status);
            $this->assertSame($number, $tx->invoice_number);
            $this->assertSame(100.0, (float) $tx->total_amount);
        }

        $tx = $this->tx($archivedLocal);
        $this->assertSame('local', $tx->invoice_mode);
        $this->assertSame('local', $tx->pra_status);
        $this->assertTrue((bool) $tx->is_archived, 'deliberate local final must stay archived');
    }

    // ── 4. PRA connection failure → offline, bill never lost ────────────────

    public function test_pra_connection_failure_finalizes_bill_as_offline_never_lost(): void
    {
        // Reporting ON, cloud mode, relay pointed at a dead local port →
        // instant connection refused, no external network in tests.
        $companyId = $this->makeCompany([
            'pra_reporting_enabled' => true,
            'pra_proxy_url' => 'http://127.0.0.1:9',
        ]);

        $bill = $this->makeProvisional($companyId, 'L-0001', ['subtotal' => 200.00, 'total_amount' => 200.00]);

        $sweep = $this->runSweep($companyId);

        $this->assertSame(1, $sweep['finalized']);
        $this->assertSame(1, $sweep['offline']);
        $this->assertSame(0, $sweep['submitted']);
        $this->assertSame(0, $sweep['queued']);

        $tx = $this->tx($bill);
        // FINAL + offline-retryable — the bill is never lost and never archived.
        $this->assertSame('pra', $tx->invoice_mode);
        $this->assertSame('offline', $tx->pra_status);
        $this->assertNull($tx->pra_invoice_number);
        $this->assertFalse((bool) $tx->is_archived);
        // Reporting ON → renumbered onto the real fiscal serial.
        $this->assertSame('POS-' . now()->format('Y') . '-00001', $tx->invoice_number);
        $this->assertSame(232.0, (float) $tx->total_amount); // whole-rupee re-tax @16%
        // The submit attempt itself was logged (request row written pre-network).
        $this->assertSame(1, DB::table('pra_logs')->where('transaction_id', $bill)->count());
    }

    // ── 5. Agent-Sync company → queued for the desktop agent ────────────────

    public function test_agent_sync_company_keeps_bill_pending_for_agent(): void
    {
        $companyId = $this->makeCompany([
            'pra_reporting_enabled' => true,
            'agent_enabled' => true,
            'agent_submits_pra' => true,
        ]);

        $bill = $this->makeProvisional($companyId, 'L-0001');

        $sweep = $this->runSweep($companyId);

        $this->assertSame(1, $sweep['finalized']);
        $this->assertSame(1, $sweep['queued']);
        $this->assertSame(0, $sweep['offline']);

        $tx = $this->tx($bill);
        $this->assertSame('pra', $tx->invoice_mode);
        $this->assertSame('pending', $tx->pra_status, 'agent picks the row up — must stay pending');
        $this->assertSame('POS-' . now()->format('Y') . '-00001', $tx->invoice_number);
        // No server-side network attempt for Agent-Sync companies.
        $this->assertSame(0, DB::table('pra_logs')->count());
    }

    // ════════════════════════════════════════════════════════════════════════
    // 6. HTTP FLASH LOCK (Task 157) — closeDayReport POST sweep summary
    // ════════════════════════════════════════════════════════════════════════

    /** Company attrs every HTTP test needs to survive PosAuth + company.approval. */
    private function makeHttpCompany(array $attrs = []): int
    {
        return $this->makeCompany(array_merge([
            'product_type' => 'pos',
            'status' => 'active',
            'pos_dayclose_provisional_action' => 'finalize',
        ], $attrs));
    }

    private function makePosUser(int $companyId, string $language = 'en'): \App\Models\User
    {
        $id = DB::table('users')->insertGetId([
            'name' => 'POS Cashier',
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
        return $this->actingAs($user, 'pos')
            ->from('/pos/day-close')
            ->post('/pos/day-close', []);
    }

    /** The base "report generated" message for today's freshly-created report. */
    private function baseMessage(int $companyId): string
    {
        $report = DB::table('pos_day_close_reports')->where('company_id', $companyId)->first();
        $this->assertNotNull($report, 'day-close report row must exist');

        return __('pos.dayclose_report_generated', [
            'number' => $report->report_number,
            'date' => \Carbon\Carbon::parse($report->report_date)->format('d M Y'),
        ]);
    }

    public function test_flash_shows_finalized_count_and_skips_zero_count_branches(): void
    {
        // Reporting OFF: sweep finalizes but nothing is submitted/queued/offline
        // → flash must carry the finalized count and NO zero-count suffixes.
        $companyId = $this->makeHttpCompany();
        $this->makeProvisional($companyId, 'L-0001');
        $this->makeProvisional($companyId, 'L-0002');

        $response = $this->closeDay($this->makePosUser($companyId));

        $response->assertSessionHas('success');
        $msg = session('success');
        $this->assertStringContainsString(__('pos.dayclose_bills_finalized', ['count' => 2]), $msg);
        $this->assertStringNotContainsString(__('pos.dayclose_bills_submitted_pra', ['count' => 0]), $msg);
        $this->assertStringNotContainsString(__('pos.dayclose_bills_queued_pra', ['count' => 0]), $msg);
        $this->assertStringNotContainsString(__('pos.dayclose_bills_offline_pra', ['count' => 0]), $msg);
    }

    public function test_flash_shows_queued_count_for_agent_sync_company(): void
    {
        $companyId = $this->makeHttpCompany([
            'pra_reporting_enabled' => true,
            'agent_enabled' => true,
            'agent_submits_pra' => true,
        ]);
        $this->makeProvisional($companyId, 'L-0001');

        $response = $this->closeDay($this->makePosUser($companyId));

        $response->assertSessionHas('success');
        $msg = session('success');
        $this->assertStringContainsString(__('pos.dayclose_bills_finalized', ['count' => 1]), $msg);
        $this->assertStringContainsString(__('pos.dayclose_bills_queued_pra', ['count' => 1]), $msg);
        $this->assertStringNotContainsString(__('pos.dayclose_bills_submitted_pra', ['count' => 0]), $msg);
        $this->assertStringNotContainsString(__('pos.dayclose_bills_offline_pra', ['count' => 0]), $msg);
    }

    public function test_flash_shows_offline_count_on_pra_connection_failure(): void
    {
        // Cloud mode, relay at a dead local port → instant refusal, no network.
        $companyId = $this->makeHttpCompany([
            'pra_reporting_enabled' => true,
            'pra_proxy_url' => 'http://127.0.0.1:9',
        ]);
        $this->makeProvisional($companyId, 'L-0001');

        $response = $this->closeDay($this->makePosUser($companyId));

        $response->assertSessionHas('success');
        $msg = session('success');
        $this->assertStringContainsString(__('pos.dayclose_bills_finalized', ['count' => 1]), $msg);
        $this->assertStringContainsString(__('pos.dayclose_bills_offline_pra', ['count' => 1]), $msg);
    }

    public function test_no_sweep_summary_when_policy_is_not_finalize(): void
    {
        // Default 'save' policy: provisional gets ARCHIVED (not finalized) →
        // base message + archived suffix, but NO finalized line.
        $companyId = $this->makeHttpCompany(['pos_dayclose_provisional_action' => 'save']);
        $this->makeProvisional($companyId, 'L-0001');
        // A completed PRA bill so the report itself is never 'empty'.
        $this->makeProvisional($companyId, 'P-0001', [
            'invoice_mode' => 'pra',
            'pra_status' => 'submitted',
            'pra_invoice_number' => 'PRA-123',
        ]);

        $response = $this->closeDay($this->makePosUser($companyId));

        $response->assertSessionHas('success');
        $this->assertStringNotContainsString(
            __('pos.dayclose_bills_finalized', ['count' => 1]),
            session('success')
        );
    }

    public function test_no_sweep_summary_when_nothing_finalized(): void
    {
        // Policy 'finalize' but no provisionals → finalized 0 → no sweep suffix.
        $companyId = $this->makeHttpCompany();
        $this->makeProvisional($companyId, 'P-0001', [
            'invoice_mode' => 'pra',
            'pra_status' => 'submitted',
            'pra_invoice_number' => 'PRA-123',
        ]);

        $response = $this->closeDay($this->makePosUser($companyId));

        $response->assertSessionHas('success');
        $this->assertStringNotContainsString(
            trans('pos.dayclose_bills_finalized', ['count' => 0]),
            session('success')
        );
        $this->assertStringContainsString($this->baseMessage($companyId), session('success'));
    }

    public function test_urdu_user_gets_urdu_sweep_summary(): void
    {
        // Cashier language 'ur' → SetPosLocale flips the request locale, so the
        // flash must be built from the ur keys (and differ from the en render).
        $companyId = $this->makeHttpCompany();
        $this->makeProvisional($companyId, 'L-0001');

        $response = $this->closeDay($this->makePosUser($companyId, 'ur'));

        $response->assertSessionHas('success');
        $urSuffix = trans('pos.dayclose_bills_finalized', ['count' => 1], 'ur');
        $this->assertStringContainsString($urSuffix, session('success'));
        $this->assertNotSame(
            trans('pos.dayclose_bills_finalized', ['count' => 1], 'en'),
            $urSuffix,
            'ur key must not silently fall back to English'
        );
    }

    public function test_all_pra_sweep_summary_keys_exist_in_en_and_ur(): void
    {
        foreach (['dayclose_bills_finalized', 'dayclose_bills_submitted_pra', 'dayclose_bills_queued_pra', 'dayclose_bills_offline_pra'] as $key) {
            foreach (['en', 'ur'] as $locale) {
                $this->assertTrue(
                    \Illuminate\Support\Facades\Lang::has("pos.$key", $locale),
                    "pos.$key missing in $locale"
                );
                $this->assertStringContainsString(
                    ':count',
                    \Illuminate\Support\Facades\Lang::get("pos.$key", [], $locale, false),
                    "pos.$key ($locale) must carry the :count placeholder"
                );
            }
        }
    }
}
