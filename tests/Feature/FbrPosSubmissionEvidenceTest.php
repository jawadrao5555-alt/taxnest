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
        $this->assertSame('verification_pending', $bill->fbr_status);
        $this->assertNull($bill->fbr_invoice_number);
    }

    public function test_unknown_transport_outcome_is_held_while_connection_refusal_remains_retryable(): void
    {
        foreach (['timeout of 30000ms exceeded', 'ECONNRESET socket hang up'] as $error) {
            $bill = $this->bill();
            $result = $this->submit($bill, ['success' => false, 'offline' => true, 'error' => $error]);
            $this->assertTrue($result['verification_pending'] ?? false);
            $this->assertSame('verification_pending', $bill->fresh()->fbr_status);
            $this->assertNull($bill->fresh()->fbr_invoice_number);
            $result = (new \App\Services\FbrService)->submitFbrPosTransaction($bill->fresh());
            $this->assertSame('blocked', $result['status']);
        }
        $bill = $this->bill();
        $this->submit($bill, ['success' => false, 'offline' => true, 'error' => 'ECONNREFUSED']);
        $this->assertSame('offline', $bill->fresh()->fbr_status);
    }

    public function test_unknown_acknowledgements_and_claimed_callbacks_remain_held_on_replay(): void
    {
        foreach ([['Code' => 100], ['Code' => 100, 'InvoiceNumber' => 'invalid prose'], ['message' => 'upstream unavailable']] as $response) {
            $bill = $this->bill();
            $claim = \App\Services\FbrPosAgentClaim::acquire($bill);
            $wrong = $this->submit($bill, ['success' => false, 'outcome_unknown' => true,
                'submission_claim' => str_repeat('0', 64), 'response' => $response]);
            $this->assertSame('stale_submission_claim', $wrong['error']);
            $this->assertSame('pending', $bill->fresh()->fbr_status);
            $result = $this->submit($bill, ['success' => false, 'submission_claim' => $claim,
                'pra_invoice_number' => 'invalid prose', 'response' => $response]);
            $this->assertTrue($result['verification_pending'] ?? false);
            $this->assertSame('verification_pending', $bill->fresh()->fbr_status);
            $this->assertNull($bill->fresh()->fbr_invoice_number);
            $this->submit($bill, ['success' => false, 'offline' => true, 'error' => 'ECONNREFUSED']);
            $this->assertSame('verification_pending', $bill->fresh()->fbr_status);
            $this->assertNull(\App\Services\FbrPosAgentClaim::acquire($bill->fresh()));
            $this->assertFalse(\App\Services\FbrPosAgentClaim::prepareRetry($bill->fresh()));
            $this->assertSame('blocked', (new \App\Services\FbrService)->submitFbrPosTransaction($bill->fresh())['status']);
        }
        Http::assertNothingSent();
    }

    public function test_new_agent_can_report_an_unknown_outcome_without_error_text(): void
    {
        $bill = $this->bill();
        $result = $this->submit($bill, ['success' => false, 'outcome_unknown' => true]);
        $this->assertTrue($result['verification_pending'] ?? false);
        $this->assertSame('verification_pending', $bill->fresh()->fbr_status);
        $this->assertNull($bill->fresh()->fbr_invoice_number);
    }

    public function test_stale_instances_cannot_dispatch_the_same_bill_twice_and_expiry_never_retries(): void
    {
        (require database_path('migrations/2026_10_12_100002_fbr_pos_agent_claim_timestamp.php'))->up();
        $bill = $this->bill();
        $otherReader = $bill->fresh();
        $claim = \App\Services\FbrPosAgentClaim::acquire($bill);
        $this->assertNotNull($claim);
        $this->assertNull(\App\Services\FbrPosAgentClaim::acquire($otherReader));
        $this->assertFalse(\App\Services\FbrPosAgentClaim::prepareRetry($otherReader));
        $this->assertSame($claim, $bill->fresh()->fbr_submission_hash);
        $this->travel(4)->minutes();
        \App\Services\FbrPosAgentClaim::quarantineExpired($this->companyId);
        $this->assertSame('verification_pending', $bill->fresh()->fbr_status);
        $this->assertNull(\App\Services\FbrPosAgentClaim::acquire($bill->fresh()));
        $this->assertFalse(\App\Services\FbrPosAgentClaim::prepareRetry($bill->fresh()));
    }

    public function test_legacy_claim_without_timestamp_cannot_be_cleared_for_blind_retry(): void
    {
        (require database_path('migrations/2026_10_12_100002_fbr_pos_agent_claim_timestamp.php'))->up();
        $bill=$this->bill();$bill->forceFill(['fbr_submission_hash'=>'legacy-dispatch'])->save();
        $this->assertTrue(\App\Services\FbrPosAgentClaim::active($bill));
        $this->assertFalse(\App\Services\FbrPosAgentClaim::prepareRetry($bill));
        $this->travel(4)->minutes();\App\Services\FbrPosAgentClaim::quarantineExpired($this->companyId);
        $this->assertSame('verification_pending',$bill->fresh()->fbr_status);
    }

    public function test_atomic_claim_rechecks_current_assigned_device_after_rebinding(): void
    {
        Schema::table('fbr_pos_transactions', function (Blueprint $t) { $t->string('fbr_device_uid_snapshot')->nullable(); });
        $bill = $this->bill();
        $bill->forceFill(['fbr_device_uid_snapshot' => 'OLD-PC'])->save();
        $stale = $bill->fresh();
        $bill->forceFill(['fbr_device_uid_snapshot' => 'REPLACEMENT'])->save();
        $this->assertNull(\App\Services\FbrPosAgentClaim::acquire($stale, 'OLD-PC'));
        $this->assertNull(\App\Services\FbrPosAgentClaim::acquire($stale));
        $this->assertNotNull(\App\Services\FbrPosAgentClaim::acquire($stale, 'REPLACEMENT'));
    }

    public function test_claim_scope_excludes_provisionals_submitted_and_other_tenant_expiry(): void
    {
        (require database_path('migrations/2026_10_12_100002_fbr_pos_agent_claim_timestamp.php'))->up();
        $this->assertNull(\App\Services\FbrPosAgentClaim::acquire($this->bill(['invoice_mode' => 'local'])));
        $this->assertNull(\App\Services\FbrPosAgentClaim::acquire($this->bill(['fbr_invoice_number' => '900000000099'])));
        $bill = $this->bill(['company_id' => 92]);
        \App\Services\FbrPosAgentClaim::acquire($bill);
        $this->travel(4)->minutes();
        \App\Services\FbrPosAgentClaim::quarantineExpired($this->companyId);
        $this->assertSame('pending', $bill->fresh()->fbr_status);
    }

    public function test_claim_callbacks_preserve_legacy_support_and_refuse_wrong_claim(): void
    {
        $bill = $this->bill();
        \App\Services\FbrPosAgentClaim::acquire($bill);
        $wrong = $this->submit($bill, ['success' => true, 'submission_claim' => str_repeat('0', 64),
            'pra_invoice_number' => '900000000001', 'response' => ['Code' => 100, 'InvoiceNumber' => '900000000001']]);
        $this->assertSame('stale_submission_claim', $wrong['error']);
        $this->assertNull($bill->fresh()->fbr_invoice_number);
        // An older supported agent omits the additive claim token. It can
        // still acknowledge the one bill the server exclusively dispatched.
        $this->submit($bill, ['success' => true, 'pra_invoice_number' => '900000000001',
            'response' => ['Code' => 100, 'InvoiceNumber' => '900000000001']]);
        $this->assertSame('submitted', $bill->fresh()->fbr_status);
    }

    public function test_message_or_mismatched_number_is_quarantined_even_with_code_100(): void
    {
        foreach ([
            ['Invoice generated successfully', ['Code' => 100, 'Response' => 'Invoice generated successfully']],
            ['900000000001', ['Code' => 100, 'InvoiceNumber' => '900000000002']],
        ] as [$number, $response]) {
            $bill = $this->bill();
            $result = $this->submit($bill, ['success' => true, 'pra_invoice_number' => $number, 'response' => $response]);
            $this->assertTrue($result['verification_pending'] ?? false);
            $this->assertNull($bill->fresh()->fbr_invoice_number);
            $this->assertSame('verification_pending', $bill->fresh()->fbr_status);
        }
    }

    public function test_supported_alternate_and_legacy_number_fields_remain_accepted(): void
    {
        foreach (['FBRInvoiceNumber', 'invoiceNumber', 'Response'] as $field) {
            $bill = $this->bill();
            $number = '123456ABC20260001';
            $this->submit($bill, ['success' => true, 'pra_invoice_number' => $number,
                'response' => ['Code' => '100', $field => $number]]);
            $this->assertSame($number, $bill->fresh()->fbr_invoice_number);
            $this->assertSame('submitted', $bill->fresh()->fbr_status);
        }
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

    public function test_fbr_fiscal_heartbeat_identifies_ims_without_changing_routing_or_legacy_fields(): void
    {
        $company = $this->company();
        $before = $company->getAttributes();
        $other = Company::query()->forceCreate([
            'name' => 'Other Fictional FBR Shop', 'product_type' => 'fbrpos',
            'fbr_pos_enabled' => true, 'fbr_connection_mode' => 'fiscal_device',
            'fbr_pos_id' => '777777', 'agent_enabled' => true,
        ]);
        foreach ([$company, $other] as $current) {
            $request = Request::create('/api/agent/heartbeat', 'POST', ['version' => '1.13.23']);
            $request->attributes->set('agent_company', $current);
            $data = (new AgentController)->heartbeat($request)->getData(true);
            $this->assertSame($current->id, $data['company']['id']);
            $this->assertSame($current->name, $data['company']['name']);
            $this->assertSame($current->fbr_pos_id, $data['company']['pra_pos_id']);
            $this->assertSame($current->fbr_pos_environment, $data['company']['pra_environment']);
            $this->assertSame('fbrpos', $data['company']['product_type'] ?? null);
            $this->assertSame('fiscal_device', $data['company']['fbr_connection_mode'] ?? null);
            $this->assertSame('cloud', $data['company']['pra_connection_mode'] ?? null);
            $this->assertArrayNotHasKey('fbr_pos_token', $data['company']);
            $this->assertArrayNotHasKey('agent_api_key', $data['company']);
        }
        $company->refresh();
        foreach (['product_type', 'fbr_pos_enabled', 'fbr_connection_mode', 'fbr_pos_id'] as $field) {
            $this->assertSame($before[$field], $company->getAttributes()[$field]);
        }
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

    public function test_reviewed_number_only_is_bound_to_original_claim_device_environment_and_exact_shape(): void
    {
        (require database_path('migrations/2026_11_17_060000_bind_standalone_dispatch_evidence.php'))->up();
        $proofMigration = glob(database_path('migrations/*create_reviewed_device_proofs*'))[0];
        (require $proofMigration)->up();
        Schema::table('fbr_pos_transactions',function(Blueprint $t){$t->string('fbr_pos_id_snapshot')->nullable();$t->timestamp('fbr_agent_claimed_at')->nullable();});
        foreach (['accepted','no-proof','revoked','foreign-number','extra-field','no-transport','wrong-device','missing-claim','stale','sandbox'] as $case) {
            DB::table('reviewed_device_proofs')->delete();
            $proofId=DB::table('reviewed_device_proofs')->insertGetId(['company_id'=>$this->companyId,'device_uid'=>'LAB-PC','pos_id'=>'654321','environment'=>'production','action'=>'approve','actor_id'=>1,'evidence_reference'=>'SYNTHETIC-REVIEW','evidence_sha256'=>str_repeat('a',64),'review_note'=>'Synthetic only','created_at'=>now()]);
            $claim=bin2hex(random_bytes(32));
            $bill=$this->bill(['fbr_submission_hash'=>$claim,'fbr_pos_id_snapshot'=>'654321','fbr_requested_environment_snapshot'=>$case==='sandbox'?'sandbox':'production','fbr_agent_claimed_at'=>$case==='stale'?now()->subMinutes(4):now()]);
            app(FbrPosSubmissionEvidenceService::class)->recordDispatch($this->company(),$bill,'1.0','LAB-PC',$claim);
            if($case==='no-proof') DB::table('reviewed_device_proofs')->delete();
            if($case==='revoked') DB::table('reviewed_device_proofs')->insert(['company_id'=>$this->companyId,'device_uid'=>'LAB-PC','pos_id'=>'654321','environment'=>'production','action'=>'revoke','actor_id'=>1,'evidence_reference'=>'SYNTHETIC-REVOKE','evidence_sha256'=>str_repeat('b',64),'review_note'=>'Revoked','revokes_id'=>$proofId,'created_at'=>now()]);
            $number=$case==='foreign-number'?'123456ABC20260001':'654321ABC20260001';
            $response=['FBRInvoiceNumber'=>$number];if($case==='extra-field')$response['Message']='ok';
            $body=['success'=>false,'outcome_unknown'=>true,'submission_claim'=>$claim,'device_uid'=>$case==='wrong-device'?'OTHER-PC':'LAB-PC','pra_invoice_number'=>$number,'response'=>$response,'ims_diagnostic'=>$case==='no-transport'?null:'response_received','ims_response_fields'=>['FBRInvoiceNumber:string']];
            if($case==='missing-claim')unset($body['submission_claim']);
            $this->submit($bill,$body);
            $bill->refresh();
            $this->assertSame($case==='accepted'?'submitted':($case==='wrong-device'?'pending':'verification_pending'),$bill->fbr_status,$case);
            $this->assertSame($case==='accepted'?$number:null,$bill->fbr_invoice_number,$case);
            if($case==='accepted') {
                $this->assertSame('NUMBER_ONLY_REVIEWED',$bill->fbr_response_code);
                $this->submit($bill,$body);
                $row=DB::table(FbrPosSubmissionEvidenceService::TABLE)->where('transaction_id',$bill->id)->first();
                $this->assertNull($row->response_code);$this->assertSame('unknown',$row->central_verification_state);
            }
        }
    }

    public function test_missing_or_switched_original_environment_holds_before_poll_dispatch(): void
    {
        (require database_path('migrations/2026_11_17_060000_bind_standalone_dispatch_evidence.php'))->up();
        $created=FbrPosTransaction::forceCreate(['company_id'=>$this->companyId,'invoice_number'=>'NEW-FROZEN','invoice_mode'=>'local','fbr_status'=>'local']);
        $this->assertSame('production',$created->fbr_requested_environment_snapshot);
        DB::table('companies')->where('id',$this->companyId)->update(['fbr_pos_environment'=>'sandbox']);
        $this->assertSame('production',$created->fresh()->fbr_requested_environment_snapshot);
        DB::table('companies')->where('id',$this->companyId)->update(['fbr_pos_environment'=>'production']);
        foreach ([null,'sandbox'] as $environment) {
            $bill=$this->bill(['fbr_requested_environment_snapshot'=>$environment]);
            $request=Request::create('/api/agent/pending-invoices','GET',['device_uid'=>'LAB-PC']);
            $method=new \ReflectionMethod(AgentController::class,'fbrPendingInvoices');
            $data=$method->invoke(new AgentController,$this->company(),$request)->getData(true);
            $this->assertSame(0,$data['count']);
            $this->assertSame('verification_pending',$bill->fresh()->fbr_status);
            $this->assertNull($bill->fresh()->fbr_submission_hash);
        }
        $bill=$this->bill(['fbr_requested_environment_snapshot'=>'sandbox']);
        $result=app(\App\Services\FbrService::class)->submitFbrPosTransaction($bill);
        $this->assertSame('blocked',$result['status']);
        $this->assertSame('verification_pending',$bill->fresh()->fbr_status);
    }

    public function test_dispatch_and_callback_do_not_rewrite_original_requested_environment(): void
    {
        $bill=$this->bill();$service=app(FbrPosSubmissionEvidenceService::class);
        $service->recordDispatch($this->company(),$bill);
        DB::table('companies')->where('id',$this->companyId)->update(['fbr_pos_environment'=>'sandbox']);
        $service->recordDispatch($this->company(),$bill);
        $service->recordResult($this->company(),$bill,null,null,null,'verification_pending');
        $this->assertSame('production',DB::table(FbrPosSubmissionEvidenceService::TABLE)->where('transaction_id',$bill->id)->value('requested_environment'));
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
