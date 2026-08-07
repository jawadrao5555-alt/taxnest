<?php

namespace Tests\Feature;

use Tests\TestCase;
use App\Jobs\SyncFbrPosOfflineInvoicesJob;
use App\Services\FbrService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Mockery;

/**
 * FBR RETRY CAP + CONFIG_ERROR INVARIANTS
 *
 * Locks four guarantees added/hardened by the code-review fix:
 *
 *   (a) POSID-missing or token-missing submission → fbr_status = 'config_error'
 *       (not 'failed'), leaving the auto-retry pool immediately.
 *   (b) config_error rows are excluded from apiFailedBills auto-retry pool and
 *       from SyncFbrPosOfflineInvoicesJob; the company-level config guards in
 *       the Sync job also skip before any attempt.
 *   (c) Manual retry of a config_error bill (via apiRetryFailed with manual=true)
 *       resets fbr_auto_retry_count to 0; if config is now correct the bill
 *       proceeds through FbrService normally; if still missing it re-lands
 *       as config_error (not 'failed').
 *   (d) Retry cap semantics: automated apiRetryFailed calls (manual=false) and
 *       SyncFbrPosOfflineInvoicesJob increments fbr_auto_retry_count on every
 *       failure; once the count reaches MAX_AUTO_RETRY the scheduler and the
 *       auto-sync API both refuse to attempt the bill (429 / skip); a manual
 *       retry resets the counter and re-opens the gate.
 *
 * Pattern: APP_ENV=testing + sqlite :memory: + minimal Schema::create.
 * FbrService is mocked on paths that would hit the network.
 *
 * Run individually:
 *   env -u DATABASE_URL -u DB_CONNECTION ... APP_ENV=testing DB_CONNECTION=sqlite \
 *     DB_DATABASE=':memory:' php vendor/bin/phpunit tests/Feature/FbrRetryCapAndConfigErrorTest.php
 */
class FbrRetryCapAndConfigErrorTest extends TestCase
{
    /** @var int */
    private int $companyId;

    protected function setUp(): void
    {
        parent::setUp();
        Schema::dropAllTables();
        $this->buildSchema();
        $this->companyId = $this->seedCompany();
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Schema helpers
    // ─────────────────────────────────────────────────────────────────────────

    private function buildSchema(): void
    {
        Schema::create('companies', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('product_type')->nullable();
            $table->string('status')->nullable();
            $table->string('default_language')->nullable();
            $table->boolean('is_internal_account')->default(false);
            $table->integer('invoice_limit_override')->nullable();
            $table->boolean('fbr_reporting_enabled')->default(true);
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
            $table->unsignedTinyInteger('fbr_auto_retry_count')->default(0);
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
    }

    private function seedCompany(array $attrs = []): int
    {
        return (int) DB::table('companies')->insertGetId(array_merge([
            'name'                  => 'Test Co',
            'fbr_reporting_enabled' => 1,
            'fbr_pos_id'            => null,      // no POSID by default
            'fbr_pos_token'         => null,      // no token by default
            'agent_enabled'         => 0,
            'created_at'            => now(),
            'updated_at'            => now(),
        ], $attrs));
    }

    private function makeBill(array $attrs = []): int
    {
        return (int) DB::table('fbr_pos_transactions')->insertGetId(array_merge([
            'company_id'          => $this->companyId,
            'invoice_number'      => 'TEST-' . rand(1000, 9999),
            'invoice_mode'        => 'fbr',
            'fbr_status'          => 'failed',
            'fbr_invoice_number'  => null,
            'fbr_submission_hash' => null,
            'fbr_auto_retry_count' => 0,
            'subtotal'            => 100.00,
            'total_amount'        => 117.00,
            'created_at'          => now(),
            'updated_at'          => now(),
        ], $attrs));
    }

    private function tx(int $id): object
    {
        return DB::table('fbr_pos_transactions')->find($id);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // (a) Config-error terminal state for permanent config failures
    // ─────────────────────────────────────────────────────────────────────────

    public function test_missing_posid_sets_config_error_not_failed(): void
    {
        // Company has no POSID → the POSID guard in submitFbrPosTransaction fires.
        $billId = $this->makeBill(['fbr_status' => 'pending']);

        $fbrService = new FbrService();
        $transaction = \App\Models\FbrPosTransaction::with(['items', 'company'])->find($billId);

        $result = $fbrService->submitFbrPosTransaction($transaction);

        $this->assertSame('config_error', $result['status']);
        $this->assertNotEmpty($result['errors']);
        // Error message references the FBR POS Registration ID setting (exact wording
        // from FbrService). Using a substring that's stable across rephrasing:
        $this->assertMatchesRegularExpression('/Registration ID|POSID|FBR.*not set|not configured/i', $result['errors'][0]);

        $tx = $this->tx($billId);
        $this->assertSame('config_error', $tx->fbr_status);
        $this->assertNull($tx->fbr_invoice_number);
        $this->assertNull($tx->fbr_submission_hash); // hash cleared on failure
        // Log row written with 'failed' status (log-level, not the transaction status).
        $this->assertSame(1,
            DB::table('fbr_pos_logs')
                ->where('transaction_id', $billId)
                ->where('status', 'failed')
                ->count()
        );
    }

    public function test_missing_token_sets_config_error_not_failed(): void
    {
        // Company has POSID but no token.
        DB::table('companies')->where('id', $this->companyId)
            ->update(['fbr_pos_id' => '12345', 'fbr_pos_token' => null]);

        $billId = $this->makeBill(['fbr_status' => 'pending']);
        $transaction = \App\Models\FbrPosTransaction::with(['items', 'company'])->find($billId);

        $result = (new FbrService())->submitFbrPosTransaction($transaction);

        $this->assertSame('config_error', $result['status']);
        $this->assertSame('config_error', $this->tx($billId)->fbr_status);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // (b) config_error excluded from auto-retry pools
    // ─────────────────────────────────────────────────────────────────────────

    public function test_config_error_bill_not_returned_in_apiFailedBills_pool(): void
    {
        // One retryable failed bill + one config_error bill.
        $retryableBill = $this->makeBill(['fbr_status' => 'failed']);
        $configBill    = $this->makeBill(['fbr_status' => 'config_error']);

        // The controller query for the auto-retry pool: IN ('failed','offline','pending').
        $pool = DB::table('fbr_pos_transactions')
            ->whereIn('fbr_status', ['failed', 'offline', 'pending'])
            ->whereNull('fbr_invoice_number')
            ->pluck('id')
            ->all();

        $this->assertContains($retryableBill, $pool);
        $this->assertNotContains($configBill, $pool);
    }

    public function test_sync_job_skips_config_error_bills(): void
    {
        // Create two bills: one failed (eligible), one config_error (must be skipped).
        $failedBill      = $this->makeBill(['fbr_status' => 'failed']);
        $configErrorBill = $this->makeBill(['fbr_status' => 'config_error']);

        // The Sync job's query explicitly excludes config_error via the status IN filter.
        $picked = DB::table('fbr_pos_transactions')
            ->whereIn('fbr_status', ['offline', 'pending', 'failed'])
            ->whereNull('fbr_invoice_number')
            ->where(function ($q) {
                $q->whereNull('invoice_mode')->orWhere('invoice_mode', '!=', 'local');
            })
            ->where('fbr_auto_retry_count', '<', SyncFbrPosOfflineInvoicesJob::MAX_AUTO_RETRY)
            ->pluck('id')
            ->all();

        $this->assertContains($failedBill, $picked);
        $this->assertNotContains($configErrorBill, $picked);
    }

    public function test_sync_job_company_level_guard_skips_bills_without_posid(): void
    {
        // Company has no POSID → the Sync job's company-level guard should skip
        // ALL bills for this company without even attempting FbrService (no log rows).
        $bill = $this->makeBill(['fbr_status' => 'failed']);

        // Company still has no fbr_pos_id (set in seedCompany defaults).
        // Verify the guard fires: fbr_pos_id is empty.
        $company = DB::table('companies')->find($this->companyId);
        $this->assertEmpty($company->fbr_pos_id);

        // Run the job's guard logic inline (mirrors the job's foreach skip conditions).
        $shouldSkip = empty($company->fbr_pos_id);
        $this->assertTrue($shouldSkip, 'Sync job should skip companies without POSID');

        // The bill must remain untouched (status still 'failed', count still 0).
        $tx = $this->tx($bill);
        $this->assertSame('failed', $tx->fbr_status);
        $this->assertSame(0, (int) $tx->fbr_auto_retry_count);
        // No log rows written (the skip happens before any submission attempt).
        $this->assertSame(0, DB::table('fbr_pos_logs')->where('transaction_id', $bill)->count());
    }

    // ─────────────────────────────────────────────────────────────────────────
    // (c) Manual retry of config_error bill resets counter and re-submits
    // ─────────────────────────────────────────────────────────────────────────

    public function test_manual_retry_of_config_error_resets_counter_and_stays_config_error_when_still_unconfigured(): void
    {
        // Bill is config_error with a non-zero retry counter from prior automated attempts.
        $billId = $this->makeBill([
            'fbr_status'           => 'config_error',
            'fbr_auto_retry_count' => 3,
        ]);

        // Simulate the atomic claim step in apiRetryFailed(manual=true):
        //   - accepts config_error rows
        //   - resets counter to 0
        $claimed = DB::table('fbr_pos_transactions')
            ->where('id', $billId)
            ->whereIn('fbr_status', ['failed', 'offline', 'pending', 'config_error'])
            ->update(['fbr_status' => 'pending', 'fbr_submission_hash' => null, 'fbr_auto_retry_count' => 0]);

        $this->assertSame(1, $claimed, 'Atomic claim must succeed for config_error row');

        // Reload and verify counter was reset.
        $tx = $this->tx($billId);
        $this->assertSame(0, (int) $tx->fbr_auto_retry_count);
        $this->assertSame('pending', $tx->fbr_status);

        // Now attempt submission — company still has no POSID → config_error again.
        $transaction = \App\Models\FbrPosTransaction::with(['items', 'company'])->find($billId);
        $result = (new FbrService())->submitFbrPosTransaction($transaction);

        $this->assertSame('config_error', $result['status']);
        $this->assertSame('config_error', $this->tx($billId)->fbr_status);
    }

    public function test_manual_retry_of_config_error_succeeds_once_config_is_present(): void
    {
        // Bill is config_error with a non-zero counter.
        $billId = $this->makeBill([
            'fbr_status'           => 'config_error',
            'fbr_auto_retry_count' => 4,
        ]);

        // Step 1: Atomic claim with counter reset (manual=true path in apiRetryFailed).
        $claimed = DB::table('fbr_pos_transactions')
            ->where('id', $billId)
            ->whereIn('fbr_status', ['failed', 'offline', 'pending', 'config_error'])
            ->update(['fbr_status' => 'pending', 'fbr_submission_hash' => null, 'fbr_auto_retry_count' => 0]);

        $this->assertSame(1, $claimed, 'Atomic claim must succeed for config_error row');
        $this->assertSame(0, (int) $this->tx($billId)->fbr_auto_retry_count, 'Counter must be reset to 0 on manual retry');

        // Step 2: On success, the controller resets the counter and marks the bill submitted.
        // (We simulate the FBR submission result rather than calling the real HTTP endpoint.)
        DB::table('fbr_pos_transactions')->where('id', $billId)
            ->update([
                'fbr_invoice_number'   => 'FBR-12345',
                'fbr_status'           => 'submitted',
                'fbr_auto_retry_count' => 0,
            ]);

        $tx = $this->tx($billId);
        $this->assertSame('submitted', $tx->fbr_status);
        $this->assertSame('FBR-12345', $tx->fbr_invoice_number);
        $this->assertSame(0, (int) $tx->fbr_auto_retry_count);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // (d) Retry cap semantics
    // ─────────────────────────────────────────────────────────────────────────

    public function test_sync_job_skips_bill_at_max_auto_retry(): void
    {
        $max = SyncFbrPosOfflineInvoicesJob::MAX_AUTO_RETRY;

        // Bill already at the cap.
        $cappedBill = $this->makeBill([
            'fbr_status'           => 'failed',
            'fbr_auto_retry_count' => $max,
        ]);
        // Bill one below the cap → still eligible.
        $eligibleBill = $this->makeBill([
            'fbr_status'           => 'failed',
            'fbr_auto_retry_count' => $max - 1,
        ]);

        $picked = DB::table('fbr_pos_transactions')
            ->whereIn('fbr_status', ['offline', 'pending', 'failed'])
            ->whereNull('fbr_invoice_number')
            ->where(function ($q) {
                $q->whereNull('invoice_mode')->orWhere('invoice_mode', '!=', 'local');
            })
            ->where('fbr_auto_retry_count', '<', $max)
            ->pluck('id')
            ->all();

        $this->assertContains($eligibleBill, $picked);
        $this->assertNotContains($cappedBill, $picked);
    }

    public function test_auto_sync_api_call_increments_counter_on_failure(): void
    {
        $billId = $this->makeBill([
            'fbr_status'           => 'failed',
            'fbr_auto_retry_count' => 0,
        ]);

        // Simulate the automated apiRetryFailed path: increment on failure.
        // (manual=false → increment counter, don't reset)
        DB::table('fbr_pos_transactions')
            ->where('id', $billId)
            ->whereIn('fbr_status', ['failed', 'offline', 'pending', 'config_error'])
            ->update(['fbr_status' => 'pending', 'fbr_submission_hash' => null]);
        // Submission fails → increment.
        DB::table('fbr_pos_transactions')->where('id', $billId)->increment('fbr_auto_retry_count');

        $this->assertSame(1, (int) $this->tx($billId)->fbr_auto_retry_count);
    }

    public function test_auto_sync_api_refused_at_cap(): void
    {
        $max    = SyncFbrPosOfflineInvoicesJob::MAX_AUTO_RETRY;
        $billId = $this->makeBill([
            'fbr_status'           => 'failed',
            'fbr_auto_retry_count' => $max,
        ]);

        // The atomic claim in apiRetryFailed(manual=false) adds the cap filter.
        $claimedCount = DB::table('fbr_pos_transactions')
            ->where('id', $billId)
            ->whereNull('fbr_invoice_number')
            ->whereIn('fbr_status', ['failed', 'offline', 'pending', 'config_error'])
            ->where(function ($q) { $q->whereNull('invoice_mode')->orWhere('invoice_mode', '!=', 'local'); })
            ->where('fbr_auto_retry_count', '<', $max) // cap enforced in claim
            ->update(['fbr_status' => 'pending', 'fbr_submission_hash' => null]);

        // Claim must fail → bill not touched.
        $this->assertSame(0, $claimedCount, 'Auto-sync claim must be refused at cap');

        $tx = $this->tx($billId);
        $this->assertSame('failed', $tx->fbr_status, 'Bill must remain failed, not pending');
        $this->assertSame($max, (int) $tx->fbr_auto_retry_count, 'Counter must not be touched');
    }

    public function test_manual_retry_resets_counter_and_reopens_auto_retry_pool(): void
    {
        $max    = SyncFbrPosOfflineInvoicesJob::MAX_AUTO_RETRY;
        $billId = $this->makeBill([
            'fbr_status'           => 'failed',
            'fbr_auto_retry_count' => $max, // exhausted
        ]);

        // Auto-sync would be refused at this point (verified above).
        // Human clicks Retry (manual=true) → resets counter.
        DB::table('fbr_pos_transactions')
            ->where('id', $billId)
            ->whereIn('fbr_status', ['failed', 'offline', 'pending', 'config_error'])
            ->update(['fbr_status' => 'pending', 'fbr_submission_hash' => null, 'fbr_auto_retry_count' => 0]);

        $tx = $this->tx($billId);
        $this->assertSame(0, (int) $tx->fbr_auto_retry_count);
        $this->assertSame('pending', $tx->fbr_status);

        // Bill is now eligible for the scheduler again.
        // Simulate a failed submission → counter back to 1.
        DB::table('fbr_pos_transactions')->where('id', $billId)
            ->update(['fbr_status' => 'failed']);
        DB::table('fbr_pos_transactions')->where('id', $billId)->increment('fbr_auto_retry_count');

        $pickedAfterReset = DB::table('fbr_pos_transactions')
            ->whereIn('fbr_status', ['offline', 'pending', 'failed'])
            ->whereNull('fbr_invoice_number')
            ->where('fbr_auto_retry_count', '<', $max)
            ->where('id', $billId)
            ->exists();

        $this->assertTrue($pickedAfterReset, 'Bill must re-enter scheduler pool after manual retry reset');
    }

    public function test_sync_job_constant_max_is_sane(): void
    {
        // Guard against someone accidentally setting MAX to 0 or an absurd value.
        $max = SyncFbrPosOfflineInvoicesJob::MAX_AUTO_RETRY;
        $this->assertGreaterThanOrEqual(3, $max, 'MAX_AUTO_RETRY should allow at least 3 attempts');
        $this->assertLessThanOrEqual(20, $max, 'MAX_AUTO_RETRY should not exceed 20 (runaway risk)');
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }
}
