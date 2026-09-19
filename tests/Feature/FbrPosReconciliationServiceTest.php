<?php

namespace Tests\Feature;

use App\Services\FbrPosReconciliationService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class FbrPosReconciliationServiceTest extends TestCase
{
    private int $companyId;

    protected function setUp(): void
    {
        parent::setUp();
        Schema::dropAllTables();

        Schema::create('companies', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('product_type')->default('fbrpos');
            $table->string('fbr_pos_environment')->default('production');
            $table->string('fbr_connection_mode')->default('fiscal_device');
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
            $table->timestamps();
        });

        Schema::create('fbr_pos_logs', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('company_id');
            $table->unsignedBigInteger('transaction_id')->nullable();
            $table->json('request_payload')->nullable();
            $table->json('response_payload')->nullable();
            $table->string('response_code')->nullable();
            $table->string('status')->default('failed');
            $table->string('environment', 20)->nullable();
            $table->text('error_message')->nullable();
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
            'name' => 'FBR Diagnostic Shop',
            'agent_last_seen' => now(),
            'agent_version' => '1.13.6',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function transaction(array $values = []): int
    {
        return DB::table('fbr_pos_transactions')->insertGetId(array_merge([
            'company_id' => $this->companyId,
            'invoice_number' => 'FPOS-LOCAL-000123',
            'fbr_status' => 'submitted',
            'fbr_invoice_number' => 'FBR-LOCAL-000123',
            'fbr_response_code' => '100',
            'created_at' => now(),
            'updated_at' => now(),
        ], $values));
    }

    private function diagnostic(int $transactionId, array $values = []): void
    {
        DB::table('fbr_pos_callback_diagnostics')->insert(array_merge([
            'company_id' => $this->companyId,
            'transaction_id' => $transactionId,
            'source' => 'fiscal_device_agent',
            'agent_version' => '1.13.6',
            'ims_version' => '3.2.1',
            'requested_environment' => 'production',
            'client_environment' => 'production',
            'callback_received_at' => now(),
            'success' => true,
            'response_code' => '100',
            'invoice_number_field' => 'InvoiceNumber',
            'created_at' => now(),
            'updated_at' => now(),
        ], $values));
    }

    public function test_code_100_and_fiscal_number_are_local_only_and_masked(): void
    {
        $id = $this->transaction();
        $this->diagnostic($id);

        $data = app(FbrPosReconciliationService::class)->diagnose($this->companyId);

        $this->assertSame('Unknown', $data['central']['label']);
        $this->assertSame('3.2.1', $data['latest_callback']['ims_version']);
        $this->assertSame('Accepted locally', $data['transactions'][0]['local_acceptance'] ? 'Accepted locally' : 'Not accepted');
        $this->assertSame('FB…0123', $data['transactions'][0]['fbr_number']);
        $this->assertStringNotContainsString('FBR-LOCAL-000123', json_encode($data));
    }

    public function test_central_is_confirmed_only_when_callback_returns_genuine_status(): void
    {
        $id = $this->transaction();
        $this->diagnostic($id, ['central_sync_status' => 'confirmed']);

        $data = app(FbrPosReconciliationService::class)->diagnose($this->companyId);

        $this->assertSame('Confirmed', $data['central']['label']);
        $this->assertSame('Confirmed', $data['transactions'][0]['central_verdict']);
    }

    public function test_uploaded_or_failed_callback_never_claims_central_confirmation(): void
    {
        $id = $this->transaction();
        $this->diagnostic($id, ['central_sync_status' => 'uploaded']);

        $data = app(FbrPosReconciliationService::class)->diagnose($this->companyId);
        $this->assertSame('Unknown', $data['central']['label']);

        DB::table('fbr_pos_callback_diagnostics')->where('transaction_id', $id)->update([
            'central_sync_status' => 'confirmed',
            'success' => false,
            'offline' => true,
        ]);

        $data = app(FbrPosReconciliationService::class)->diagnose($this->companyId);
        $this->assertSame('Unknown', $data['central']['label']);
        $this->assertSame('Unknown', $data['transactions'][0]['central_verdict']);
    }

    public function test_environment_mismatch_and_offline_are_explicit(): void
    {
        DB::table('companies')->where('id', $this->companyId)->update([
            'agent_last_seen' => now()->subHours(4),
        ]);
        $id = $this->transaction();
        $this->diagnostic($id, ['client_environment' => 'test']);

        $data = app(FbrPosReconciliationService::class)->diagnose($this->companyId);

        $this->assertTrue($data['environment']['mismatch']);
        $this->assertFalse($data['agent']['online']);
        $this->assertStringContainsString('offline', strtolower(implode(' ', $data['warnings'])));
    }

    public function test_current_company_environment_remains_authoritative_over_stale_callback_request(): void
    {
        $id = $this->transaction();
        $this->diagnostic($id, [
            'requested_environment' => 'test',
            'client_environment' => 'production',
        ]);

        $data = app(FbrPosReconciliationService::class)->diagnose($this->companyId);

        $this->assertSame('Production', $data['environment']['requested']);
        $this->assertSame('Production', $data['environment']['client_reported']);
        $this->assertFalse($data['environment']['mismatch']);
    }

    public function test_direct_900901_warning_is_hard_and_payloads_are_not_exposed(): void
    {
        DB::table('fbr_pos_logs')->insert([
            'company_id' => $this->companyId,
            'response_code' => '900901',
            'status' => 'failed',
            'environment' => 'production',
            'error_message' => 'Authorization Bearer should-never-be-shown',
            'request_payload' => json_encode(['token' => 'secret-token']),
            'response_payload' => json_encode(['Code' => '900901']),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $this->transaction();

        $data = app(FbrPosReconciliationService::class)->diagnose($this->companyId);

        $warning = 'FBR direct credentials rejected — do not use direct fallback until credentials are corrected.';
        $this->assertSame($warning, $data['direct_credentials']['warning']);
        $this->assertStringNotContainsString('secret-token', json_encode($data));
        $this->assertStringNotContainsString('Bearer', json_encode($data));
    }

    public function test_stored_production_900901_warning_survives_current_environment_switch(): void
    {
        DB::table('companies')->where('id', $this->companyId)->update([
            'fbr_pos_environment' => 'sandbox',
        ]);
        DB::table('fbr_pos_logs')->insert([
            'company_id' => $this->companyId,
            'response_code' => '900901',
            'status' => 'failed',
            'environment' => 'production',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $this->transaction();

        $data = app(FbrPosReconciliationService::class)->diagnose($this->companyId);

        $this->assertTrue($data['direct_credentials']['production_900901_seen']);
        $this->assertSame(
            'FBR direct credentials rejected — do not use direct fallback until credentials are corrected.',
            $data['direct_credentials']['warning']
        );
        $this->assertSame('Stored on direct attempt', $data['direct_credentials']['environment_evidence']);
    }
}