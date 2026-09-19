<?php

namespace Tests\Feature;

use App\Http\Controllers\AgentController;
use App\Models\Company;
use App\Models\FbrPosTransaction;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class FbrPosCallbackDiagnosticsTest extends TestCase
{
    private int $companyId;

    protected function setUp(): void
    {
        parent::setUp();
        Schema::dropAllTables();

        Schema::create('companies', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->boolean('fbr_pos_enabled')->default(true);
            $table->string('fbr_connection_mode')->default('fiscal_device');
            $table->string('fbr_pos_environment')->default('production');
            $table->boolean('agent_enabled')->default(true);
            $table->timestamp('agent_last_seen')->nullable();
            $table->string('agent_version')->nullable();
            $table->softDeletes();
            $table->timestamps();
        });

        Schema::create('fbr_pos_transactions', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('company_id');
            $table->string('invoice_number');
            $table->string('fbr_status')->nullable();
            $table->string('fbr_invoice_number')->nullable();
            $table->string('fbr_response_code')->nullable();
            $table->text('fbr_response')->nullable();
            $table->string('fbr_submission_hash')->nullable();
            $table->string('invoice_mode')->nullable();
            $table->timestamps();
        });

        Schema::create('fbr_pos_callback_diagnostics', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('company_id');
            $table->unsignedBigInteger('transaction_id')->nullable();
            $table->string('source', 40);
            $table->string('agent_version', 32)->nullable();
            $table->string('ims_version', 64)->nullable();
            $table->string('requested_environment', 20)->nullable();
            $table->string('client_environment', 20)->nullable();
            $table->timestamp('callback_received_at');
            $table->boolean('success')->default(false);
            $table->boolean('offline')->nullable();
            $table->string('response_code', 64)->nullable();
            $table->string('invoice_number_field', 64)->nullable();
            $table->string('central_sync_status', 80)->nullable();
            $table->string('central_reference', 160)->nullable();
            $table->json('response_diagnostics')->nullable();
            $table->text('error_message')->nullable();
            $table->timestamps();
        });

        $this->companyId = DB::table('companies')->insertGetId([
            'name' => 'Diagnostic Test Shop',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function transaction(array $values = []): FbrPosTransaction
    {
        $id = DB::table('fbr_pos_transactions')->insertGetId(array_merge([
            'company_id' => $this->companyId,
            'invoice_number' => 'FPOS-TEST-0001',
            'fbr_status' => 'pending',
            'created_at' => now(),
            'updated_at' => now(),
        ], $values));

        return FbrPosTransaction::findOrFail($id);
    }

    private function dispatchCallback(FbrPosTransaction $transaction, array $body): void
    {
        $request = Request::create('/api/agent/submit-result', 'POST', array_merge([
            'transaction_id' => $transaction->id,
            'success' => true,
            'pra_invoice_number' => 'FBR-LOCAL-001',
            'response' => [
                'InvoiceNumber' => 'FBR-LOCAL-001',
                'Code' => '100',
                'Response' => 'Success',
            ],
            'version' => '1.13.6',
            'ims_version' => '3.2.1',
            'requested_environment' => 'production',
            'environment' => 'production',
        ], $body));
        $request->attributes->set('agent_company', Company::findOrFail($this->companyId));

        (new AgentController())->submitResult($request);
    }

    public function test_success_callback_persists_only_safe_diagnostics_and_does_not_claim_central_verification(): void
    {
        $transaction = $this->transaction();
        $this->dispatchCallback($transaction, [
            'response' => [
                'InvoiceNumber' => 'FBR-LOCAL-001',
                'Code' => '100',
                'Response' => 'Success',
                'customerName' => 'Must not persist',
                'Authorization' => 'Bearer secret-token',
            ],
        ]);

        $diagnostic = DB::table('fbr_pos_callback_diagnostics')->first();
        $this->assertSame('production', $diagnostic->client_environment);
        $this->assertSame('production', $diagnostic->requested_environment);
        $this->assertSame('1.13.6', $diagnostic->agent_version);
        $this->assertSame('3.2.1', $diagnostic->ims_version);
        $this->assertSame('InvoiceNumber', $diagnostic->invoice_number_field);
        $this->assertSame('100', $diagnostic->response_code);
        $this->assertNull($diagnostic->central_sync_status);
        $this->assertNull($diagnostic->central_reference);
        $this->assertStringNotContainsString('customerName', (string) $diagnostic->response_diagnostics);
        $this->assertStringNotContainsString('secret-token', (string) $diagnostic->response_diagnostics);
        $this->assertStringNotContainsString('FBR-LOCAL-001', (string) $diagnostic->response_diagnostics);
        $this->assertSame('submitted', DB::table('fbr_pos_transactions')->value('fbr_status'));
    }

    public function test_generic_sync_status_is_not_treated_as_central_evidence(): void
    {
        $transaction = $this->transaction();
        $this->dispatchCallback($transaction, [
            'response' => [
                'InvoiceNumber' => 'FBR-LOCAL-001',
                'Code' => '100',
                'SyncStatus' => 'success',
            ],
        ]);

        $diagnostic = DB::table('fbr_pos_callback_diagnostics')->first();
        $this->assertNull($diagnostic->central_sync_status);
        $this->assertNull($diagnostic->central_reference);
        $this->assertStringNotContainsString('SyncStatus', (string) $diagnostic->response_diagnostics);
    }

    public function test_response_fallback_fiscal_number_is_redacted_from_diagnostics(): void
    {
        $transaction = $this->transaction();
        $this->dispatchCallback($transaction, [
            'response' => [
                'Code' => '100',
                'Response' => 'FBR-LOCAL-001',
            ],
        ]);

        $diagnostic = DB::table('fbr_pos_callback_diagnostics')->first();
        $storedTransaction = DB::table('fbr_pos_transactions')->first();

        $this->assertStringNotContainsString('FBR-LOCAL-001', (string) $diagnostic->response_diagnostics);
        $this->assertStringNotContainsString('FBR-LOCAL-001', (string) $storedTransaction->fbr_response);
        $this->assertSame('FBR-LOCAL-001', $storedTransaction->fbr_invoice_number);
    }

    public function test_old_agent_payload_without_new_diagnostics_remains_accepted(): void
    {
        $transaction = $this->transaction();
        $this->dispatchCallback($transaction, [
            'version' => null,
            'ims_version' => null,
            'requested_environment' => null,
            'environment' => null,
        ]);

        $diagnostic = DB::table('fbr_pos_callback_diagnostics')->first();
        $this->assertNull($diagnostic->agent_version);
        $this->assertNull($diagnostic->ims_version);
        $this->assertNull($diagnostic->requested_environment);
        $this->assertNull($diagnostic->client_environment);
        $this->assertSame('submitted', DB::table('fbr_pos_transactions')->value('fbr_status'));
    }

    public function test_duplicate_callback_cannot_change_receipt_qr_number_or_status_but_is_audited(): void
    {
        $transaction = $this->transaction([
            'fbr_status' => 'submitted',
            'fbr_invoice_number' => 'FBR-ORIGINAL-001',
        ]);

        $this->dispatchCallback($transaction, [
            'pra_invoice_number' => 'FBR-CONFLICTING-999',
            'response' => ['InvoiceNumber' => 'FBR-CONFLICTING-999', 'Code' => '100'],
        ]);

        $row = DB::table('fbr_pos_transactions')->first();
        $this->assertSame('submitted', $row->fbr_status);
        $this->assertSame('FBR-ORIGINAL-001', $row->fbr_invoice_number);
        $this->assertSame(1, DB::table('fbr_pos_callback_diagnostics')->count());

        $receipt = file_get_contents(resource_path('views/fbr-pos/receipt.blade.php'));
        $this->assertStringContainsString('$qrData = $transaction->fbr_invoice_number', $receipt);
        $this->assertStringContainsString('FBR: {{ $transaction->fbr_invoice_number }}', $receipt);
    }

    public function test_historical_submitted_or_numbered_invoices_are_never_returned_for_retry(): void
    {
        $this->transaction([
            'fbr_status' => 'submitted',
            'fbr_invoice_number' => 'FBR-ORIGINAL-001',
        ]);
        $request = Request::create('/api/agent/pending-invoices', 'GET');
        $request->attributes->set('agent_company', Company::findOrFail($this->companyId));

        $response = (new AgentController())->pendingInvoices($request);
        $payload = $response->getData(true);

        $this->assertSame(0, $payload['count']);
        $this->assertSame([], $payload['invoices']);
        $this->assertSame('fiscal_device', $payload['pra_mode']);
        $this->assertSame('production', $payload['pra_environment']);
    }

    public function test_failure_callback_is_persisted_without_raw_error_or_payload(): void
    {
        $transaction = $this->transaction();
        $this->dispatchCallback($transaction, [
            'success' => false,
            'pra_invoice_number' => null,
            'offline' => false,
            'error' => 'Invalid credentials; Authorization: Bearer secret-token',
            'response' => [
                'Code' => '900901',
                'Errors' => ['customerPhone' => '03001234567'],
            ],
        ]);

        $diagnostic = DB::table('fbr_pos_callback_diagnostics')->first();
        $this->assertSame('900901', $diagnostic->response_code);
        $this->assertStringNotContainsString('secret-token', (string) $diagnostic->error_message);
        $this->assertStringNotContainsString('03001234567', (string) $diagnostic->response_diagnostics);
        $this->assertSame('failed', DB::table('fbr_pos_transactions')->value('fbr_status'));
    }
}