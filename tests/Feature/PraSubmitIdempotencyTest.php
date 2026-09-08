<?php

namespace Tests\Feature;

use App\Http\Controllers\AgentController;
use App\Models\Company;
use App\Models\PosTransaction;
use App\Models\PraLog;
use App\Services\PraIntegrationService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * PRA DOUBLE-SUBMIT IDEMPOTENCY (remediation of the PR #27 audit HIGH finding).
 *
 * PraIntegrationService::sendInvoice used to guard against resubmission with
 * read-then-act checks and then POST to PRA. Two senders racing on one bill
 * (SyncPosOfflineInvoicesJob + an F11 retry, restaurant settle + a replayed
 * POST, desktop agent + the server) could both pass the checks and both post —
 * two fiscal numbers for one sale. The agent's result writer likewise wrote
 * whatever number it was handed, even over a number already on the row.
 *
 * Locked here:
 *   1. A contended per-bill lock makes sendInvoice back off WITHOUT touching
 *      PRA (no PraLog row = no HTTP leg) and without changing pra_status, so a
 *      normal retry a moment later still works.
 *   2. A bill that already carries a fiscal number is refused — including when
 *      the CALLER's model instance is stale and the number landed behind its
 *      back (the in-lock fresh re-read is what catches the race loser).
 *   3. The agent lane never overwrites a stored fiscal number with a different
 *      one, nor demotes a fiscalised row on a late failure result; it acks
 *      idempotently so the agent drops the item.
 *   4. Fiscal Device companies still never reach the HTTP leg (queued for the
 *      desktop agent), lock or no lock.
 *   5. The lock is released after every call.
 *
 * "PRA was not contacted" is asserted through pra_logs: sendInvoice writes the
 * PraLog row immediately before curl, so zero rows = the HTTP leg never ran.
 * The service uses raw curl (not the Http facade), so Http::fake cannot be
 * used here; the sandbox company below would hit the network if a guard were
 * lost, which is exactly the failure these tests must surface.
 *
 * Pattern: APP_ENV=testing + sqlite :memory: + minimal Schema::create
 * (mirrors PosPraHalfSubmittedGuardTest); CACHE_STORE=array (phpunit.xml).
 */
class PraSubmitIdempotencyTest extends TestCase
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
            $table->timestamp('agent_last_seen')->nullable();
            $table->string('agent_version')->nullable();
            $table->boolean('fbr_pos_enabled')->default(false);
            $table->string('fbr_connection_mode')->nullable();
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
            $table->string('submission_hash')->nullable();
            $table->boolean('is_archived')->default(false);
            $table->decimal('subtotal', 12, 2)->default(0);
            $table->decimal('tax_amount', 12, 2)->default(0);
            $table->decimal('exempt_amount', 12, 2)->default(0);
            $table->decimal('total_amount', 12, 2)->default(0);
            $table->string('payment_method')->nullable();
            $table->timestamps();
        });

        // The agent poll builds each pending bill's payload from its lines.
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
            'name' => 'Race Shop',
            'pra_reporting_enabled' => 1,
            'pra_connection_mode' => 'cloud',
            'pra_environment' => 'sandbox',
            'pos_setup_completed' => true,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        app()->bind('currentCompanyId', fn () => $this->companyId);

        Cache::flush();
    }

    protected function tearDown(): void
    {
        Cache::flush();
        parent::tearDown();
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

        DB::table('pos_transaction_items')->insert([
            'transaction_id' => $id,
            'item_type' => 'product',
            'item_name' => 'Chai',
            'quantity' => 1,
            'unit_price' => 100,
            'subtotal' => 100,
            'is_tax_exempt' => false,
            'tax_rate' => 16,
            'tax_amount' => 16,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        return PosTransaction::withoutGlobalScope('hide_archived')->findOrFail($id);
    }

    private function service(): PraIntegrationService
    {
        return new PraIntegrationService(Company::find($this->companyId));
    }

    private function fresh(PosTransaction $txn): object
    {
        return DB::table('pos_transactions')->where('id', $txn->id)->first();
    }

    private function agentRequest(array $body): Request
    {
        $request = Request::create('/api/agent/submit-result', 'POST', $body);
        $request->attributes->set('agent_company', Company::find($this->companyId));

        return $request;
    }

    // ── 1. contended lock → back off, no HTTP, state retryable ───────────────

    public function test_a_contended_submit_lock_backs_off_without_contacting_pra(): void
    {
        $txn = $this->makeBill();

        // Another sender holds this bill's lock (mid-flight HTTP call).
        $other = Cache::lock(PraIntegrationService::submitLockKey($txn), 90);
        $this->assertTrue($other->get(), 'test precondition: the lock must be ours');

        $result = $this->service()->sendInvoice($txn);

        $this->assertFalse($result['success']);
        $this->assertTrue($result['in_progress'] ?? false, 'the caller must be told the bill is already being submitted');
        $this->assertSame('IN_PROGRESS', $result['response_code']);
        $this->assertSame(0, PraLog::count(), 'no PraLog row = the HTTP leg never ran');

        $row = $this->fresh($txn);
        $this->assertSame('pending', $row->pra_status, 'a contended call must not change the bill state');
        $this->assertNull($row->pra_invoice_number);
        $this->assertNull($row->pra_error_message);

        $other->release();
    }

    public function test_the_lock_is_released_after_a_refused_call(): void
    {
        $txn = $this->makeBill(['pra_status' => 'submitted', 'pra_invoice_number' => '250813ABCDE0001']);

        $this->service()->sendInvoice($txn);

        $probe = Cache::lock(PraIntegrationService::submitLockKey($txn), 5);
        $this->assertTrue($probe->get(), 'the per-bill lock must be free again after sendInvoice returns');
        $probe->release();
    }

    // ── 2. already fiscalised → refused, even through a stale instance ───────

    public function test_a_bill_that_already_has_a_fiscal_number_is_refused(): void
    {
        $txn = $this->makeBill(['pra_status' => 'submitted', 'pra_invoice_number' => '250813ABCDE0001']);

        $result = $this->service()->sendInvoice($txn);

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('250813ABCDE0001', $result['message']);
        $this->assertSame(0, PraLog::count(), 'PRA must not be contacted for a fiscalised bill');
        $this->assertSame('250813ABCDE0001', $this->fresh($txn)->pra_invoice_number);
    }

    public function test_a_stale_instance_sees_the_concurrent_winner_and_does_not_resubmit(): void
    {
        // THE race: this caller loaded the bill while it was still pending...
        $stale = $this->makeBill();
        $this->assertNull($stale->pra_invoice_number);

        // ...and a concurrent sender finished first (query-builder write, so
        // the in-memory instance knows nothing about it).
        DB::table('pos_transactions')->where('id', $stale->id)->update([
            'pra_status' => 'submitted',
            'pra_invoice_number' => '250813ABCDE7777',
        ]);

        $result = $this->service()->sendInvoice($stale);

        $this->assertFalse($result['success']);
        $this->assertTrue($result['already_submitted'] ?? false);
        $this->assertSame(0, PraLog::count(), 'the loser must not post the bill a second time');
        $this->assertSame('250813ABCDE7777', $this->fresh($stale)->pra_invoice_number, 'the winner\'s number stands');
        $this->assertSame('250813ABCDE7777', $stale->pra_invoice_number, 'the caller\'s instance adopts the winner\'s state');
        $this->assertSame('submitted', $stale->pra_status);
        $this->assertFalse($stale->isDirty(), 'adopted attributes are synced, not left as pending writes');
    }

    public function test_a_provisional_bill_that_turned_local_behind_the_caller_is_refused(): void
    {
        $stale = $this->makeBill();
        DB::table('pos_transactions')->where('id', $stale->id)->update(['pra_status' => 'local']);

        $result = $this->service()->sendInvoice($stale);

        $this->assertFalse($result['success']);
        $this->assertSame(0, PraLog::count());
        $this->assertSame('local', $this->fresh($stale)->pra_status);
    }

    // ── 3. agent lane: never overwrite a stored fiscal number ────────────────

    public function test_agent_result_with_a_different_fiscal_number_does_not_overwrite_a_fiscalised_bill(): void
    {
        $txn = $this->makeBill([
            'pra_status' => 'submitted',
            'pra_invoice_number' => '250813ABCDE0001',
            'pra_response_code' => '100',
        ]);

        $response = (new AgentController())->submitResult($this->agentRequest([
            'transaction_id' => $txn->id,
            'success' => true,
            'pra_invoice_number' => '250813ABCDE0002',
            'response' => ['Code' => '100'],
        ]));

        $this->assertSame(200, $response->getStatusCode(), 'the agent must get an ack so it drops the item');
        $data = $response->getData(true);
        $this->assertTrue($data['ok']);
        $this->assertTrue($data['already_submitted']);
        $this->assertSame('250813ABCDE0001', $data['pra_invoice_number'], 'the ack carries the STORED number');

        $row = $this->fresh($txn);
        $this->assertSame('250813ABCDE0001', $row->pra_invoice_number, 'a second, different fiscal number must never replace the first');
        $this->assertSame('submitted', $row->pra_status);
    }

    public function test_agent_result_with_the_same_fiscal_number_is_an_idempotent_ack(): void
    {
        $txn = $this->makeBill(['pra_status' => 'submitted', 'pra_invoice_number' => '250813ABCDE0001']);

        $response = (new AgentController())->submitResult($this->agentRequest([
            'transaction_id' => $txn->id,
            'success' => true,
            'pra_invoice_number' => '250813ABCDE0001',
        ]));

        $this->assertTrue($response->getData(true)['already_submitted']);
        $this->assertSame('250813ABCDE0001', $this->fresh($txn)->pra_invoice_number);
    }

    public function test_agent_late_failure_result_does_not_demote_a_fiscalised_bill(): void
    {
        // A stale queue entry reporting failure for a bill the server (or an
        // earlier agent post) already fiscalised must not turn it 'failed'.
        $txn = $this->makeBill(['pra_status' => 'submitted', 'pra_invoice_number' => '250813ABCDE0001']);

        $response = (new AgentController())->submitResult($this->agentRequest([
            'transaction_id' => $txn->id,
            'success' => false,
            'error' => 'ECONNREFUSED localhost:8524',
        ]));

        $this->assertTrue($response->getData(true)['already_submitted']);
        $row = $this->fresh($txn);
        $this->assertSame('submitted', $row->pra_status, 'a fiscalised bill must never be demoted by a late failure');
        $this->assertSame('250813ABCDE0001', $row->pra_invoice_number);
        $this->assertNull($row->pra_error_message);
    }

    public function test_agent_result_still_fiscalises_a_pending_bill(): void
    {
        // Control: the guard must not block the normal first result.
        $txn = $this->makeBill(['pra_status' => 'pending']);

        $response = (new AgentController())->submitResult($this->agentRequest([
            'transaction_id' => $txn->id,
            'success' => true,
            'pra_invoice_number' => '250813ABCDE1234',
        ]));

        $this->assertArrayNotHasKey('already_submitted', $response->getData(true));
        $row = $this->fresh($txn);
        $this->assertSame('submitted', $row->pra_status);
        $this->assertSame('250813ABCDE1234', $row->pra_invoice_number);
    }

    public function test_agent_pending_poll_never_hands_out_a_fiscalised_bill(): void
    {
        DB::table('companies')->where('id', $this->companyId)->update([
            'pra_connection_mode' => 'fiscal_device',
            'agent_enabled' => true,
            'agent_submits_pra' => true,
        ]);
        // A 'failed' row that nevertheless carries a number (the half state the
        // heartbeat sweep heals) must not be re-offered to the agent — that
        // would be a second submission of a fiscalised sale.
        $this->makeBill(['pra_status' => 'failed', 'pra_invoice_number' => '250813ABCDE0001']);
        $pending = $this->makeBill(['pra_status' => 'pending']);

        $request = Request::create('/api/agent/pending-invoices', 'GET');
        $request->attributes->set('agent_company', Company::find($this->companyId));
        $data = (new AgentController())->pendingInvoices($request)->getData(true);

        $this->assertSame([$pending->id], array_column($data['invoices'], 'transaction_id'));
    }

    // ── 4. fiscal device: never the HTTP leg, lock or no lock ────────────────

    public function test_fiscal_device_company_is_queued_for_the_agent_and_never_hits_http(): void
    {
        DB::table('companies')->where('id', $this->companyId)->update(['pra_connection_mode' => 'fiscal_device']);
        $txn = $this->makeBill(['pra_status' => 'failed']);

        $result = $this->service()->sendInvoice($txn);

        $this->assertFalse($result['success']);
        $this->assertTrue($result['queued_for_agent']);
        $this->assertSame('QUEUED', $result['response_code']);
        $this->assertSame(0, PraLog::count(), 'fiscal device mode must never reach the PRA HTTP leg');
        $this->assertSame('pending', $this->fresh($txn)->pra_status);
    }

    public function test_fiscal_device_queueing_is_not_blocked_by_a_held_submit_lock(): void
    {
        // The agent hand-off happens BEFORE the lock: a held lock (from some
        // server-side sender that should not exist in this mode) must not
        // stop the bill from being queued for the shop PC.
        DB::table('companies')->where('id', $this->companyId)->update(['pra_connection_mode' => 'fiscal_device']);
        $txn = $this->makeBill(['pra_status' => 'failed']);
        $other = Cache::lock(PraIntegrationService::submitLockKey($txn), 90);
        $this->assertTrue($other->get());

        $result = $this->service()->sendInvoice($txn);

        $this->assertTrue($result['queued_for_agent'] ?? false);
        $this->assertSame('pending', $this->fresh($txn)->pra_status);
        $this->assertSame(0, PraLog::count());

        $other->release();
    }
}
