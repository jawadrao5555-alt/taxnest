<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\PosTransaction;
use App\Models\PraLog;
use App\Services\PraIntegrationService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Half-submitted PRA bills (Task 1475).
 *
 * pra_status='submitted' with an empty pra_invoice_number is an impossible state
 * that live data proved reachable: 8 real bills carried it with ZERO pra_logs
 * rows, i.e. PRA had never been contacted. Both thermal receipts gate the Sahulat
 * QR on pra_status === 'submitted' AND pra_invoice_number, so such a bill falls
 * through to the local/menu-QR branch — the customer gets a receipt recorded as
 * reported to PRA that prints a MENU QR, and nothing on screen says so.
 *
 * Locks the invariant end to end:
 *   1. A PRA "success" (Code 100) with no invoice number is a FAILURE, not a
 *      submission — status, log row, error message and QR all agree.
 *   2. A blank/whitespace number counts as no number.
 *   3. A real number still stamps 'submitted' WITH its fiscal QR (control).
 *   4. An already-persisted number is still honoured when the response omits it.
 *   5. The model backstop catches ANY other Eloquent write path.
 *   6. Both receipt blades still require the number before printing a fiscal QR,
 *      so loosening the gate to a bare status check fails here.
 *
 * Pattern: APP_ENV=testing + sqlite :memory: + minimal Schema::create
 * (mirrors PosPraExemptZeroRatedTest).
 */
class PosPraHalfSubmittedGuardTest extends TestCase
{
    protected int $companyId;

    protected function setUp(): void
    {
        parent::setUp();

        Schema::dropAllTables();

        Schema::create('companies', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->boolean('pra_reporting_enabled')->default(false);
            $table->string('pra_connection_mode')->nullable();
            $table->string('pra_environment')->nullable();
            $table->string('pra_pos_id')->nullable();
            $table->text('pra_production_token')->nullable();
            $table->string('pra_proxy_url')->nullable();
            $table->boolean('agent_enabled')->default(false);
            $table->boolean('agent_submits_pra')->default(false);
            $table->boolean('pos_setup_completed')->default(true);
            // Agent heartbeat telemetry — the sweep under test lives in heartbeat().
            $table->timestamp('agent_last_seen')->nullable();
            $table->string('agent_version')->nullable();
            $table->boolean('fbr_pos_enabled')->default(false);
            $table->string('fbr_connection_mode')->nullable();
            $table->boolean('agent_offline_mode')->nullable();
            $table->timestamp('agent_snapshot_at')->nullable();
            $table->string('agent_update_target')->nullable();
            $table->string('agent_update_stage')->nullable();
            $table->text('agent_update_error')->nullable();
            $table->timestamp('agent_update_at')->nullable();
            $table->softDeletes();
            $table->timestamps();
        });

        Schema::create('pos_transactions', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('company_id');
            $table->string('invoice_number');
            $table->string('business_date')->nullable();
            $table->string('status');
            $table->string('invoice_mode')->nullable();
            $table->string('pra_status')->nullable();
            $table->string('pra_response_code')->nullable();
            $table->string('pra_invoice_number')->nullable();
            $table->text('pra_error_message')->nullable();
            $table->text('pra_qr_code')->nullable();
            $table->boolean('is_archived')->default(false);
            $table->decimal('subtotal', 12, 2)->default(0);
            $table->decimal('tax_amount', 12, 2)->default(0);
            $table->decimal('exempt_amount', 12, 2)->default(0);
            $table->decimal('total_amount', 12, 2)->default(0);
            $table->string('payment_method')->nullable();
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

        $this->companyId = DB::table('companies')->insertGetId([
            'name' => 'Half Submitted Shop',
            'pra_reporting_enabled' => 1,
            'pra_connection_mode' => 'cloud',
            'pos_setup_completed' => true,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        app()->bind('currentCompanyId', fn () => $this->companyId);
    }

    // ── helpers ──────────────────────────────────────────────────────────────

    private function makeBill(array $header = []): PosTransaction
    {
        $id = DB::table('pos_transactions')->insertGetId(array_merge([
            'company_id' => $this->companyId,
            'invoice_number' => 'POS-2026-' . str_pad((string) random_int(1, 99999), 5, '0', STR_PAD_LEFT),
            'status' => 'completed',
            'invoice_mode' => 'pra',
            'pra_status' => 'pending',
            'subtotal' => 100, 'tax_amount' => 16, 'total_amount' => 116,
            'payment_method' => 'cash',
            'created_at' => now(), 'updated_at' => now(),
        ], $header));

        return PosTransaction::withoutGlobalScope('hide_archived')->findOrFail($id);
    }

    private function log(PosTransaction $txn): PraLog
    {
        return PraLog::create([
            'company_id' => $this->companyId,
            'transaction_id' => $txn->id,
            'request_payload' => ['Items' => []],
            'status' => 'pending',
        ]);
    }

    private function service(): PraIntegrationService
    {
        return new PraIntegrationService(Company::find($this->companyId));
    }

    private function fresh(PosTransaction $txn): object
    {
        return DB::table('pos_transactions')->where('id', $txn->id)->first();
    }

    // ── 1. success with no number is a failure ───────────────────────────────

    public function test_pra_success_without_a_fiscal_number_is_never_stamped_submitted(): void
    {
        $txn = $this->makeBill();

        $this->service()->storePraResponse(
            $this->log($txn), $txn, ['Code' => '100', 'Response' => 'Success'], '100', true, null
        );

        $row = $this->fresh($txn);
        $this->assertNotSame('submitted', $row->pra_status,
            'a bill with no fiscal number must never claim it was reported to PRA');
        $this->assertSame('failed', $row->pra_status);
        $this->assertNull($row->pra_invoice_number);
        $this->assertEmpty($row->pra_qr_code, 'no number means no fiscal QR can exist');
        $this->assertNotEmpty($row->pra_error_message, 'the cashier must be told, not left guessing');
    }

    public function test_the_pra_log_row_does_not_record_success_either(): void
    {
        $txn = $this->makeBill();
        $log = $this->log($txn);

        $this->service()->storePraResponse($log, $txn, ['Code' => '100'], '100', true, null);

        $this->assertSame('failed', $log->fresh()->status,
            'the audit log must agree with the bill — no phantom success');
    }

    // ── 2. blank / whitespace number ─────────────────────────────────────────

    public function test_a_blank_fiscal_number_counts_as_no_number(): void
    {
        $txn = $this->makeBill();

        $this->service()->storePraResponse($this->log($txn), $txn, ['Code' => '100'], '100', true, '   ');

        $row = $this->fresh($txn);
        $this->assertSame('failed', $row->pra_status);
        $this->assertNull($row->pra_invoice_number, 'whitespace must not be stored as a fiscal number');
    }

    // ── 3. control: a real submission still works ────────────────────────────

    public function test_a_real_fiscal_number_still_stamps_submitted_with_its_qr(): void
    {
        $txn = $this->makeBill();

        $this->service()->storePraResponse(
            $this->log($txn), $txn, ['Code' => '100'], '100', true, '250813ABCDE1234'
        );

        $row = $this->fresh($txn);
        $this->assertSame('submitted', $row->pra_status);
        $this->assertSame('250813ABCDE1234', $row->pra_invoice_number);
        $this->assertNotEmpty($row->pra_qr_code, 'a submitted bill must carry its Sahulat QR');
        $this->assertNull($row->pra_error_message);
    }

    // ── 4. number already on the row ─────────────────────────────────────────

    public function test_a_blank_incoming_number_never_erases_an_already_fiscalised_bill(): void
    {
        // The dangerous direction of the same bug: '   ' is not null, so a naive
        // `$incoming ?? $stored` would let the blank win and demote a bill PRA has
        // already accepted — destroying its fiscal number and its QR.
        $txn = $this->makeBill(['pra_status' => 'submitted', 'pra_invoice_number' => '250813ABCDE9999']);

        $this->service()->storePraResponse($this->log($txn), $txn, ['Code' => '100'], '100', true, '   ');

        $row = $this->fresh($txn);
        $this->assertSame('submitted', $row->pra_status, 'a fiscalised bill must not be demoted by a blank response');
        $this->assertSame('250813ABCDE9999', $row->pra_invoice_number);
        $this->assertNotEmpty($row->pra_qr_code);
    }

    public function test_an_already_persisted_number_survives_a_numberless_success(): void
    {
        // Re-confirmation from PRA that omits the number must not wipe a bill
        // that is genuinely fiscalised.
        $txn = $this->makeBill(['pra_status' => 'submitted', 'pra_invoice_number' => '250813ABCDE9999']);

        $this->service()->storePraResponse($this->log($txn), $txn, ['Code' => '100'], '100', true, null);

        $row = $this->fresh($txn);
        $this->assertSame('submitted', $row->pra_status);
        $this->assertSame('250813ABCDE9999', $row->pra_invoice_number);
        $this->assertNotEmpty($row->pra_qr_code);
    }

    // ── 5. model backstop for every other write path ─────────────────────────

    public function test_model_guard_blocks_a_create_that_flags_submitted_without_a_number(): void
    {
        $txn = PosTransaction::create([
            'company_id' => $this->companyId,
            'invoice_number' => 'POS-2026-00099',
            'status' => 'completed',
            'invoice_mode' => 'pra',
            'pra_status' => 'submitted',
            'subtotal' => 80, 'total_amount' => 80,
        ]);

        $this->assertNotSame('submitted', $this->fresh($txn)->pra_status,
            'no write path may create a bill flagged as reported without its PRA number');
    }

    public function test_model_guard_blocks_an_update_that_flags_submitted_without_a_number(): void
    {
        // This is the exact live bug: the old restaurant settle path re-stamped a
        // bill 'submitted' with 'pra_invoice_number' => ... ?? null.
        $txn = $this->makeBill();

        $txn->update([
            'pra_status' => 'submitted',
            'pra_invoice_number' => null,
            'pra_response_code' => null,
        ]);

        $row = $this->fresh($txn);
        $this->assertNotSame('submitted', $row->pra_status);
        $this->assertSame('failed', $row->pra_status);
        $this->assertNotEmpty($row->pra_error_message);
    }

    public function test_model_guard_leaves_a_properly_fiscalised_bill_alone(): void
    {
        $txn = $this->makeBill();

        $txn->update(['pra_status' => 'submitted', 'pra_invoice_number' => '250813ABCDE4321']);

        $row = $this->fresh($txn);
        $this->assertSame('submitted', $row->pra_status);
        $this->assertSame('250813ABCDE4321', $row->pra_invoice_number);
    }

    // ── 6. the desktop-agent lanes (query builder — no model events) ─────────

    private function agentRequest(string $uri, array $body): \Illuminate\Http\Request
    {
        $request = \Illuminate\Http\Request::create($uri, 'POST', $body);
        $request->attributes->set('agent_company', Company::find($this->companyId));

        return $request;
    }

    public function test_agent_submit_result_rejects_a_whitespace_fiscal_number(): void
    {
        // The agent lane writes with the query builder, so the model backstop never
        // sees it — and !empty('   ') is true, which is how a blank number could have
        // been stamped as a submission.
        $txn = $this->makeBill(['pra_status' => 'pending']);

        (new \App\Http\Controllers\AgentController())->submitResult($this->agentRequest(
            '/api/agent/submit-result',
            ['transaction_id' => $txn->id, 'success' => true, 'pra_invoice_number' => '   ']
        ));

        $row = $this->fresh($txn);
        $this->assertNotSame('submitted', $row->pra_status,
            'a blank number from the agent is not a submission');
        $this->assertSame('failed', $row->pra_status);
        $this->assertNotEmpty($row->pra_error_message);
    }

    public function test_agent_submit_result_still_accepts_a_real_fiscal_number(): void
    {
        $txn = $this->makeBill(['pra_status' => 'pending']);

        (new \App\Http\Controllers\AgentController())->submitResult($this->agentRequest(
            '/api/agent/submit-result',
            ['transaction_id' => $txn->id, 'success' => true, 'pra_invoice_number' => ' 250813ABCDE1234 ']
        ));

        $row = $this->fresh($txn);
        $this->assertSame('submitted', $row->pra_status);
        $this->assertSame('250813ABCDE1234', $row->pra_invoice_number, 'stored trimmed');
    }

    public function test_agent_heartbeat_self_heal_ignores_a_whitespace_fiscal_number(): void
    {
        // The sweep promotes rows that already carry a fiscal number. A
        // whitespace-only value must not qualify, or it recreates the exact state
        // this task removes — again via the query builder.
        $blank = $this->makeBill(['pra_status' => 'failed', 'pra_invoice_number' => '   ']);
        $real = $this->makeBill(['pra_status' => 'failed', 'pra_invoice_number' => '250813ABCDE1234']);

        (new \App\Http\Controllers\AgentController())->heartbeat(
            $this->agentRequest('/api/agent/heartbeat', ['version' => '1.9.0'])
        );

        $this->assertNotSame('submitted', $this->fresh($blank)->pra_status,
            'whitespace is not a fiscal number — the sweep must skip it');
        $this->assertSame('submitted', $this->fresh($real)->pra_status,
            'a genuinely fiscalised row must still be healed');
    }

    // ── 7. the receipt gate itself ───────────────────────────────────────────

    public function test_both_thermal_receipts_gate_the_fiscal_qr_on_status_and_number(): void
    {
        // The reason the live rows were invisible: the blades correctly refuse to
        // print a fiscal QR without a number, so the bill silently fell through to
        // the menu-QR branch. That gate must stay strict in BOTH templates — if a
        // future edit loosens it to a bare status check, the app would print a
        // fiscal QR for a bill PRA never issued a number for.
        foreach (['receipt_80mm', 'receipt_58mm'] as $template) {
            $blade = file_get_contents(resource_path("views/pos/receipts/{$template}.blade.php"));

            $statusChecks = preg_match_all("/pra_status\s*===?\s*'submitted'/", $blade);
            $guardedChecks = preg_match_all(
                "/pra_status\s*===?\s*'submitted'\s*&&\s*\\\$transaction->pra_invoice_number/",
                $blade
            );

            $this->assertGreaterThan(0, $statusChecks, "{$template}: fiscal QR gate not found");
            $this->assertSame($statusChecks, $guardedChecks,
                "{$template}: every 'submitted' check must also require pra_invoice_number");
        }
    }
}
