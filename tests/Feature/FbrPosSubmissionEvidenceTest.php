<?php

namespace Tests\Feature;

use App\Http\Controllers\AgentController;
use App\Http\Controllers\FbrPosController;
use App\Models\Company;
use App\Models\FbrPosTransaction;
use App\Services\FbrPosSubmissionEvidenceService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Lab 1/2 contract for local FBR IMS evidence.
 *
 * These tests never contact FBR, Tax Asaan, SMS or a shop-PC IMS. They prove
 * the server accepts only an explicit local Code 100 + number, keeps all
 * ambiguous results out of submitted, and stores credential-free evidence.
 */
class FbrPosSubmissionEvidenceTest extends TestCase
{
    private int $companyId;

    protected function setUp(): void
    {
        parent::setUp();
        Schema::dropAllTables();
        $this->buildSchema();
        Http::fake();

        $this->companyId = DB::table('companies')->insertGetId([
            'name' => 'Fictional Shoe Lab',
            'product_type' => 'fbrpos',
            'fbr_pos_enabled' => true,
            'fbr_reporting_enabled' => true,
            'fbr_connection_mode' => 'fiscal_device',
            'fbr_pos_environment' => 'production',
            'fbr_pos_id' => '654321',
            'agent_enabled' => true,
            'agent_version' => '1.13.4',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function test_explicit_code_100_and_number_is_locally_accepted_but_central_stays_unknown(): void
    {
        $bill = $this->bill();
        $result = $this->submit($bill, [
            'success' => true,
            'pra_invoice_number' => '900000000001',
            'response' => ['InvoiceNumber' => '900000000001', 'Code' => 100, 'Response' => 'Generated'],
            'agent_version' => '1.13.4',
        ]);

        $this->assertTrue($result['ok']);
        $bill->refresh();
        $this->assertSame('submitted', $bill->fbr_status);
        $this->assertSame('100', $bill->fbr_response_code);
        $this->assertSame('900000000001', $bill->fbr_invoice_number);

        $evidence = DB::table(FbrPosSubmissionEvidenceService::TABLE)->where('transaction_id', $bill->id)->first();
        $this->assertSame('local_accepted', $evidence->result_state);
        $this->assertSame('unknown', $evidence->local_environment_proof);
        $this->assertSame('unknown', $evidence->central_verification_state);
        $this->assertSame('***0001', $evidence->fiscal_number_mask);
        $this->assertNotNull($evidence->response_hash);
    }

    #[DataProvider('ambiguousSuccessProvider')]
    public function test_ambiguous_success_never_defaults_to_100_or_stamps_a_fiscal_number($response, ?string $expectedCode): void
    {
        $bill = $this->bill();
        $result = $this->submit($bill, [
            'success' => true,
            'pra_invoice_number' => '900000000099',
            'response' => $response,
        ]);

        $this->assertTrue($result['verification_pending']);
        $bill->refresh();
        $this->assertSame('verification_pending', $bill->fbr_status);
        $this->assertNull($bill->fbr_invoice_number);
        $this->assertSame($expectedCode, $bill->fbr_response_code);

        $evidence = DB::table(FbrPosSubmissionEvidenceService::TABLE)->where('transaction_id', $bill->id)->first();
        $this->assertSame('verification_pending', $evidence->result_state);
        $this->assertSame($expectedCode, $evidence->response_code);
        $this->assertSame('unknown', $evidence->central_verification_state);
    }

    public static function ambiguousSuccessProvider(): array
    {
        return [
            'missing code' => [['InvoiceNumber' => '900000000099'], null],
            'malformed response' => ['not-json-object', null],
            'wrong code' => [['Code' => '111', 'InvoiceNumber' => '900000000099'], '111'],
        ];
    }

    public function test_success_without_a_number_fails_closed(): void
    {
        $bill = $this->bill();
        $result = $this->submit($bill, ['success' => true, 'response' => ['Code' => '100']]);

        $this->assertTrue($result['ok']);
        $bill->refresh();
        $this->assertSame('failed', $bill->fbr_status);
        $this->assertNull($bill->fbr_invoice_number);
    }

    public function test_safe_dispatch_telemetry_has_no_full_posid_payload_or_local_url(): void
    {
        $bill = $this->bill();
        app(FbrPosSubmissionEvidenceService::class)->recordDispatch($this->company(), $bill, '1.13.4');

        $row = (array) DB::table(FbrPosSubmissionEvidenceService::TABLE)->where('transaction_id', $bill->id)->first();
        $this->assertSame('local_fiscal_device', $row['channel']);
        $this->assertSame('local_fbr_ims', $row['endpoint_class']);
        $this->assertSame('production', $row['requested_environment']);
        $this->assertSame('unknown', $row['local_environment_proof']);
        $this->assertSame('***21', $row['pos_id_mask']);
        $this->assertArrayNotHasKey('payload', $row);
        $this->assertArrayNotHasKey('endpoint', $row);
        $this->assertStringNotContainsString('654321', json_encode($row));
        $this->assertStringNotContainsString('localhost', json_encode($row));
    }

    public function test_posid_drift_is_visible_without_exposing_the_identity(): void
    {
        $bill = $this->bill();
        app(FbrPosSubmissionEvidenceService::class)->recordDispatch($this->company(), $bill, '1.13.4');
        DB::table('companies')->where('id', $this->companyId)->update(['fbr_pos_id' => '777777']);

        $diagnostics = app(FbrPosSubmissionEvidenceService::class)->diagnostics($this->company());
        $this->assertSame('drift', $diagnostics['pos_id_state']);
        $this->assertSame('***21', $diagnostics['pos_id_mask']);
        $this->assertSame('unknown', $diagnostics['local_environment_proof']);
        $this->assertSame('unknown', $diagnostics['central_verification_state']);
    }

    public function test_heartbeat_self_heals_only_explicit_code_100_history(): void
    {
        $accepted = $this->bill([
            'fbr_status' => 'failed',
            'fbr_invoice_number' => '900000000010',
            'fbr_response_code' => '100',
        ]);
        $ambiguous = $this->bill([
            'fbr_status' => 'failed',
            'fbr_invoice_number' => '900000000011',
            'fbr_response_code' => null,
        ]);
        app(FbrPosSubmissionEvidenceService::class)->recordResult(
            $this->company(), $ambiguous, ['InvoiceNumber' => '900000000011'], null,
            '900000000011', 'verification_pending', '1.13.4'
        );

        $request = Request::create('/api/agent/heartbeat', 'POST', ['version' => '1.13.4']);
        $request->attributes->set('agent_company', $this->company());
        $data = (new AgentController)->heartbeat($request)->getData(true);

        $this->assertSame(1, $data['healed']);
        $this->assertSame('submitted', $accepted->fresh()->fbr_status);
        $this->assertSame('failed', $ambiguous->fresh()->fbr_status);
    }

    public function test_late_conflicting_callback_never_overwrites_an_accepted_number(): void
    {
        $bill = $this->bill([
            'fbr_status' => 'submitted',
            'fbr_invoice_number' => '900000000020',
            'fbr_response_code' => '100',
        ]);
        $result = $this->submit($bill, [
            'success' => true,
            'pra_invoice_number' => '900000000021',
            'response' => ['Code' => '100'],
        ]);

        $this->assertTrue($result['already_submitted']);
        $this->assertSame('900000000020', $bill->fresh()->fbr_invoice_number);
        $this->assertSame('***0020', DB::table(FbrPosSubmissionEvidenceService::TABLE)
            ->where('transaction_id', $bill->id)->value('fiscal_number_mask'));
    }

    public function test_manual_retry_refuses_a_verification_hold(): void
    {
        $bill = $this->bill(['fbr_status' => 'verification_pending']);
        app()->instance('currentCompanyId', $this->companyId);

        $response = (new FbrPosController)->retryFbr($bill->id);

        $this->assertTrue($response->isRedirect(route('fbrpos.show', $bill->id)));
        $this->assertSame('verification_pending', $bill->fresh()->fbr_status);
        $this->assertNotNull(session('error'));
    }

    private function submit(FbrPosTransaction $bill, array $body): array
    {
        $request = Request::create('/api/agent/submit-result', 'POST', $body + [
            'transaction_id' => $bill->id,
        ]);
        $request->attributes->set('agent_company', $this->company());

        return (new AgentController)->submitResult($request)->getData(true);
    }

    private function bill(array $overrides = []): FbrPosTransaction
    {
        $id = DB::table('fbr_pos_transactions')->insertGetId(array_merge([
            'company_id' => $this->companyId,
            'invoice_number' => 'LAB-'.random_int(1000, 9999),
            'invoice_mode' => 'fbr',
            'fbr_status' => 'pending',
            'total_amount' => 116,
            'created_at' => now(),
            'updated_at' => now(),
        ], $overrides));

        return FbrPosTransaction::findOrFail($id);
    }

    private function company(): Company
    {
        return Company::findOrFail($this->companyId);
    }

    private function buildSchema(): void
    {
        Schema::create('companies', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('product_type')->nullable();
            $table->boolean('fbr_pos_enabled')->default(false);
            $table->boolean('fbr_reporting_enabled')->default(false);
            $table->string('fbr_connection_mode')->nullable();
            $table->string('fbr_pos_environment')->nullable();
            $table->string('fbr_pos_id')->nullable();
            $table->boolean('agent_enabled')->default(false);
            $table->timestamp('agent_last_seen')->nullable();
            $table->string('agent_version')->nullable();
            $table->boolean('agent_core_enabled')->default(false);
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('fbr_pos_transactions', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('company_id');
            $table->string('invoice_number');
            $table->string('invoice_mode')->nullable();
            $table->string('fbr_status')->nullable();
            $table->string('fbr_invoice_number')->nullable();
            $table->string('fbr_response_code')->nullable();
            $table->json('fbr_response')->nullable();
            $table->string('fbr_submission_hash')->nullable();
            $table->text('fbr_error_message')->nullable();
            $table->decimal('total_amount', 12, 2)->default(0);
            $table->timestamps();
        });

        $migration = require database_path('migrations/2026_09_15_000000_create_fbr_pos_submission_evidence.php');
        $migration->up();
    }
}
