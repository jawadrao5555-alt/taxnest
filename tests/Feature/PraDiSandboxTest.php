<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\PosDiSandboxSubmission;
use App\Models\PosDiSandboxProfile;
use App\Models\User;
use App\Services\FbrService;
use App\Services\PraDiSandboxService;
use App\Support\PraDiSandboxGate;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class PraDiSandboxTest extends TestCase
{
    private Company $company;
    private User $owner;
    private PraDiSandboxService $service;
    private const TOKEN = 'fictional-sandbox-token-for-local-tests';

    protected function setUp(): void
    {
        parent::setUp();
        Schema::dropAllTables();
        User::flushScopeColumnCache();
        config(['pra_di_sandbox.enabled' => true, 'pra_di_sandbox.hosted_enabled' => false, 'pra_di_sandbox.production_enabled' => true]);
        Schema::create('companies', function (Blueprint $t) {
            $t->id(); $t->string('name'); $t->string('product_type')->default('pos');
            $t->string('status')->default('approved'); $t->string('company_status')->default('active');
            $t->string('ntn')->default('1234567'); $t->string('province')->default('Punjab');
            $t->string('address')->default('Fictional test street'); $t->text('fbr_sandbox_token')->nullable();
            $t->text('fbr_production_token')->nullable(); $t->string('fbr_environment')->default('production');
            $t->string('fbr_sandbox_url')->nullable(); $t->string('fbr_connection_status')->nullable();
            $t->string('fbr_registration_no')->nullable(); $t->string('fbr_business_name')->nullable();
            $t->boolean('agent_enabled')->default(false);
            $t->softDeletes(); $t->timestamps();
        });
        Schema::create('users', function (Blueprint $t) {
            $t->id(); $t->unsignedBigInteger('company_id'); $t->string('name')->default('Fictional Owner');
            $t->string('role')->default('company_admin'); $t->string('pos_role')->default('pos_admin');
            $t->boolean('is_active')->default(true); $t->string('email')->nullable();
            $t->string('password')->nullable(); $t->rememberToken(); $t->timestamps();
        });
        Schema::create('pos_transactions', function (Blueprint $t) {
            $t->id(); $t->unsignedBigInteger('company_id'); $t->unsignedBigInteger('branch_id')->nullable();
            $t->string('invoice_number'); $t->string('status')->default('completed');
            $t->string('invoice_mode')->default('pra'); $t->string('transaction_type')->default('sale');
            $t->unsignedBigInteger('parent_transaction_id')->nullable();
            $t->decimal('subtotal', 12, 2)->default(100); $t->decimal('discount_amount', 12, 2)->default(0);
            $t->decimal('tax_amount', 12, 2)->default(16); $t->decimal('total_amount', 12, 2)->default(116);
            $t->string('payment_method'); $t->string('pra_status')->nullable(); $t->string('pra_invoice_number')->nullable();
            $t->boolean('is_archived')->default(false); $t->timestamps();
        });
        Schema::create('pos_transaction_items', function (Blueprint $t) {
            $t->id(); $t->unsignedBigInteger('transaction_id'); $t->string('item_name');
            $t->decimal('quantity', 12, 3); $t->decimal('taxable_base', 12, 2)->nullable();
            $t->decimal('exempt_base', 12, 2)->nullable(); $t->decimal('tax_amount', 12, 2)->nullable();
            $t->timestamps();
        });
        foreach (['invoices', 'inventory_movements', 'customer_ledger'] as $name) {
            Schema::create($name, fn (Blueprint $t) => $t->id());
        }
        (require database_path('migrations/2026_11_02_120000_create_pos_di_sandbox_submissions_table.php'))->up();
        (require database_path('migrations/2026_11_03_100000_create_pos_di_sandbox_profiles_table.php'))->up();
        (require database_path('migrations/2026_11_03_120000_add_environments_to_pos_di_reporting.php'))->up();
        (require database_path('migrations/2026_11_03_150000_create_pos_fbr_integration_evidence.php'))->up();
        $id = DB::table('companies')->insertGetId(['name' => 'Fictional Sandbox Shop',
            'fbr_sandbox_token' => Crypt::encryptString(self::TOKEN),
            'fbr_production_token' => Crypt::encryptString('NEVER-USE-THIS-PRODUCTION-TOKEN'),
            'fbr_sandbox_url' => 'https://invalid.example/override']);
        $this->company = Company::findOrFail($id);
        $uid = DB::table('users')->insertGetId(['company_id' => $id]);
        $this->owner = User::findOrFail($uid);
        $this->actingAs($this->owner, 'pos');
        $this->service = app(PraDiSandboxService::class);
    }

    private function bill(string $method = 'cash', array $override = []): int
    {
        $id = DB::table('pos_transactions')->insertGetId(array_merge([
            'company_id' => $this->company->id, 'invoice_number' => 'TEST-'.uniqid(),
            'payment_method' => $method, 'pra_status' => 'submitted', 'pra_invoice_number' => 'TEST-PRA-ONLY',
            'created_at' => now(), 'updated_at' => now(),
        ], $override));
        DB::table('pos_transaction_items')->insert(['transaction_id' => $id, 'item_name' => 'Fictional meal',
            'quantity' => 2, 'taxable_base' => 100, 'exempt_base' => 0, 'tax_amount' => 16]);
        return $id;
    }

    private function record(?int $id = null): PosDiSandboxSubmission
    {
        return $this->service->capture($this->company, $id ?? $this->bill(), $this->owner->id);
    }

    /** Hypothetical transport fixture, NOT a proposed provincial tax mapping. */
    private function payload(PosDiSandboxSubmission $record): array
    {
        $p = $this->service->template($record);
        $p['buyerProvince'] = 'Punjab';
        $p['buyerBusinessName'] = 'Synthetic buyer'; $p['buyerAddress'] = 'Synthetic address';
        if ($record->reportingEnvironment() === 'sandbox') $p['scenarioId'] = 'SN019';
        foreach ($p['items'] as &$line) {
            $line['hsCode'] = '9801.2000'; $line['uoM'] = 'Numbers, pieces, units';
            $line['rate'] = '0%'; $line['saleType'] = 'Services';
            $line['salesTaxApplicable'] = 0; $line['totalValues'] = $line['valueSalesExcludingST'];
        }
        return $p;
    }

    private function ack(): array
    {
        return ['invoiceNumber' => 'TEST-SBX-ACK-1', 'validationResponse' => [
            'statusCode' => '00', 'status' => 'Valid', 'invoiceStatuses' => [
                ['itemSNo' => '1', 'statusCode' => '00', 'status' => 'Valid', 'invoiceNo' => 'TEST-SBX-ACK-1-1'],
            ],
        ]];
    }

    public static function payments(): array
    {
        return [['cash'], ['debit_card'], ['credit'], ['split']];
    }

    #[DataProvider('payments')]
    public function test_same_original_sale_is_reported_once_without_financial_or_pra_mutation(string $payment): void
    {
        $id = $this->bill($payment);
        $before = (array) DB::table('pos_transactions')->find($id);
        $record = $this->record($id);
        $this->assertSame($record->id, $this->record($id)->id);
        $template = $this->service->template($record);
        $this->assertSame('', $template['items'][0]['saleType']);
        $this->assertSame('', $template['items'][0]['rate']);
        $this->assertNull($template['items'][0]['salesTaxApplicable']);
        Http::fake(['*' => Http::response($this->ack())]);
        $this->service->prepare($this->company->id, $record->id, $this->payload($record));
        $accepted = $this->service->submit($this->company->id, $record->id, $this->owner->id);
        $this->assertSame('accepted', $accepted->state);
        Http::assertSent(fn ($r) => $r->url() === 'https://gw.fbr.gov.pk/di_data/v1/di/postinvoicedata_sb'
            && $r->hasHeader('Authorization', 'Bearer '.self::TOKEN)
            && $r['items'][0]['salesTaxApplicable'] === 0 && $r['items'][0]['quantity'] === 2);
        $this->assertSame($before, (array) DB::table('pos_transactions')->find($id));
        foreach (['invoices', 'inventory_movements', 'customer_ledger'] as $table) $this->assertDatabaseCount($table, 0);
        $this->assertSame(1, PosDiSandboxSubmission::count());
        try { $this->service->submit($this->company->id, $record->id, $this->owner->id); $this->fail('Replay allowed'); }
        catch (HttpException $e) { $this->assertSame(409, $e->getStatusCode()); }
        Http::assertSentCount(1);
    }

    public function test_capture_is_frozen_and_survives_source_cleanup(): void
    {
        $record = $this->record();
        DB::table('pos_transactions')->where('id', $record->source_transaction_id)->update(['payment_method' => 'credit', 'total_amount' => 999]);
        $this->assertSame(116.0, (float) $this->record($record->source_transaction_id)->snapshot['total']);
        DB::table('pos_transaction_items')->delete(); DB::table('pos_transactions')->delete();
        $this->get(route('pos.di-sandbox.receipt', $record->id))->assertOk()->assertSee('116.00')->assertSee('TEST ONLY');
        $stored = DB::table('pos_di_sandbox_submissions')->find($record->id);
        $this->assertStringNotContainsString('Fictional meal', $stored->snapshot);
    }

    public static function independentPraStates(): array
    {
        return [['failed'], ['pending'], [null]];
    }

    #[DataProvider('independentPraStates')]
    public function test_fbr_acceptance_does_not_claim_pra_acceptance(?string $praState): void
    {
        $id = $this->bill('credit', ['pra_status' => $praState, 'pra_invoice_number' => null]);
        $before = (array) DB::table('pos_transactions')->find($id);
        $record = $this->record($id);
        $this->service->prepare($this->company->id, $record->id, $this->payload($record));
        Http::fake(['*' => Http::response($this->ack())]);
        $this->service->submit($this->company->id, $record->id, $this->owner->id);
        $this->assertSame($before, (array) DB::table('pos_transactions')->find($id));
        $this->get(route('pos.di-sandbox.receipt', $record->id))->assertOk()
            ->assertSee('TEST-SBX-ACK-1')->assertDontSee('alt="PRA source QR"', false);
    }

    public function test_owner_can_review_submit_and_print_through_the_http_routes(): void
    {
        $record = $this->record();
        $this->withSession(['_token' => 'local-test-csrf']);
        $this->post(route('pos.di-sandbox.prepare', $record->id), [
            '_token' => 'local-test-csrf', 'mapping_reviewed' => 1,
            'payload_json' => json_encode($this->payload($record)),
        ])->assertSessionHasNoErrors()->assertRedirect(route('pos.di-sandbox.show', $record->id));
        Http::assertNothingSent();
        Http::fake(['*' => Http::response($this->ack())]);
        $this->post(route('pos.di-sandbox.submit', $record->id), [
            '_token' => 'local-test-csrf', 'confirm_sandbox' => 1,
        ])->assertSessionHasNoErrors()->assertRedirect(route('pos.di-sandbox.show', $record->id));
        $this->get(route('pos.di-sandbox.show', $record->id))->assertOk()
            ->assertSee('data-sandbox-state="accepted"', false)->assertSee('116.00');
        Http::assertSentCount(1);
    }

    public function test_missing_sandbox_token_never_falls_back_to_production(): void
    {
        $record = $this->record();
        $this->service->prepare($this->company->id, $record->id, $this->payload($record));
        DB::table('companies')->where('id', $this->company->id)->update(['fbr_sandbox_token' => null]);
        try { $this->service->submit($this->company->id, $record->id, $this->owner->id); $this->fail('Production fallback allowed'); }
        catch (ValidationException $e) { $this->assertArrayHasKey('sandbox', $e->errors()); }
        $this->assertSame('draft', $record->fresh()->state);
        Http::assertNothingSent();
    }

    public function test_payload_hash_matches_the_stored_and_submitted_decimal_values(): void
    {
        $record = $this->record();
        $p = $this->payload($record);
        $p['items'][0]['quantity'] = 2.0;
        $p['items'][0]['valueSalesExcludingST'] = 100.0;
        $p['items'][0]['totalValues'] = 100.0;
        $p['items'][0]['salesTaxApplicable'] = 0.0;
        $saved = $this->service->prepare($this->company->id, $record->id, $p)->fresh();
        $this->assertSame(hash('sha256', json_encode($saved->payload, JSON_THROW_ON_ERROR)), $saved->payload_hash);
        Http::fake(['*' => Http::response($this->ack())]);
        $submitted = $this->service->submit($this->company->id, $record->id, $this->owner->id);
        Http::assertSent(fn ($request) => hash('sha256', json_encode($request->data(), JSON_THROW_ON_ERROR)) === $saved->payload_hash);
        $this->assertSame($saved->payload_hash, $submitted->attempts[0]['hash']);
    }

    public function test_blank_mapping_cannot_be_prepared_or_sent(): void
    {
        $record = $this->record();
        try { $this->service->prepare($this->company->id, $record->id, $this->service->template($record)); $this->fail('Blank mapping allowed'); }
        catch (ValidationException $e) { $this->assertArrayHasKey('items.0.rate', $e->errors()); }
        Http::assertNothingSent();
        $this->assertNull($record->fresh()->payload);
    }

    public static function mutations(): array
    {
        return [['quantity', 99], ['valueSalesExcludingST', 999], ['discount', 1]];
    }

    #[DataProvider('mutations')]
    public function test_source_values_cannot_be_reinterpreted(string $field, mixed $value): void
    {
        $record = $this->record(); $p = $this->payload($record); $p['items'][0][$field] = $value;
        $this->expectException(ValidationException::class);
        $this->service->prepare($this->company->id, $record->id, $p);
    }

    public function test_timeout_is_unknown_and_cannot_be_replayed_or_edited(): void
    {
        $record = $this->record(); $p = $this->payload($record);
        $this->service->prepare($this->company->id, $record->id, $p);
        Http::fake(fn () => throw new \Illuminate\Http\Client\ConnectionException('Do not log secrets'));
        $unknown = $this->service->submit($this->company->id, $record->id, $this->owner->id);
        $this->assertSame('verification_required', $unknown->state);
        $this->assertNull($unknown->sandbox_invoice_number);
        foreach (['prepare', 'submit'] as $action) {
            try {
                $action === 'prepare' ? $this->service->prepare($this->company->id, $record->id, $p)
                    : $this->service->submit($this->company->id, $record->id, $this->owner->id);
                $this->fail('Unknown outcome was replayable');
            } catch (HttpException $e) { $this->assertSame(409, $e->getStatusCode()); }
        }
        $html = view('pos.di-sandbox.receipt', ['record' => $unknown, 'width' => 58])->render();
        $this->assertStringNotContainsString('alt="FBR sandbox acknowledgement QR"', $html);
        $this->assertStringNotContainsString(self::TOKEN, json_encode($unknown->attempts));
    }

    public static function ambiguousResponses(): array
    {
        return [
            [['invoiceNumber' => 'REF-WITHOUT-VALIDATION']],
            [['validationResponse' => ['statusCode' => '00', 'status' => 'valid']]],
            [['invoiceNumber' => 'PARTIAL', 'validationResponse' => ['statusCode' => '01', 'status' => 'invalid']]],
            [['validationResponse' => ['statusCode' => '01', 'status' => 'invalid', 'errorCode' => '500']]],
            [['invoiceNumber' => 'BAD-ITEM', 'validationResponse' => ['statusCode' => '00', 'status' => 'valid', 'invoiceStatuses' => [['statusCode' => '01', 'status' => 'Invalid', 'itemSNo' => '1']]]]],
            [['invoiceNumber' => 'MISSING-ITEM', 'validationResponse' => ['statusCode' => '00', 'status' => 'valid', 'invoiceStatuses' => []]]],
            [['invoiceNumber' => 'WRONG-ITEM', 'validationResponse' => ['statusCode' => '00', 'status' => 'valid', 'invoiceStatuses' => [['statusCode' => '00', 'status' => 'Valid', 'itemSNo' => '2']]]]],
        ];
    }

    #[DataProvider('ambiguousResponses')]
    public function test_incomplete_or_partial_ack_is_not_acceptance(array $body): void
    {
        Http::fake(['*' => Http::response($body)]);
        $result = app(FbrService::class)->submitPosSandboxPayload(['items' => [[]]], self::TOKEN);
        $this->assertSame('verification_required', $result['state']);
        $this->assertNull($result['reference']);
    }

    public function test_explicit_rejection_preserves_history_and_requires_reprepare(): void
    {
        $record = $this->record(); $p = $this->payload($record);
        $this->service->prepare($this->company->id, $record->id, $p);
        Http::fake(['*' => Http::sequence()->push(['validationResponse' => ['statusCode' => '01', 'status' => 'Invalid', 'errorCode' => '0099']])->push($this->ack())]);
        $rejected = $this->service->submit($this->company->id, $record->id, $this->owner->id);
        $this->assertSame('rejected', $rejected->state);
        try { $this->service->submit($this->company->id, $record->id, $this->owner->id); $this->fail('Unreviewed retry'); }
        catch (HttpException $e) { $this->assertSame(409, $e->getStatusCode()); }
        $this->service->prepare($this->company->id, $record->id, $p);
        $accepted = $this->service->submit($this->company->id, $record->id, $this->owner->id);
        $this->assertCount(2, $accepted->attempts);
        $this->assertSame(['0099'], $accepted->attempts[0]['error_codes']);
        Http::assertSentCount(2);
    }

    public function test_duplicate_item_serials_with_different_padding_are_not_complete_acceptance(): void
    {
        $body = $this->ack();
        $body['validationResponse']['invoiceStatuses'][] = array_merge(
            $body['validationResponse']['invoiceStatuses'][0], ['itemSNo' => '01']
        );
        Http::fake(['*' => Http::response($body)]);
        $result = app(FbrService::class)->submitPosSandboxPayload(['items' => [[], []]], self::TOKEN);
        $this->assertSame('verification_required', $result['state']);
    }

    public function test_return_requires_own_accepted_sandbox_parent(): void
    {
        $sale = $this->record();
        $return = $this->record($this->bill('credit', ['transaction_type' => 'return', 'parent_transaction_id' => $sale->source_transaction_id]));
        $p = $this->payload($return); $p['invoiceRefNo'] = 'TEST-PRA-ONLY';
        try { $this->service->prepare($this->company->id, $return->id, $p); $this->fail('PRA reference used for FBR'); }
        catch (ValidationException $e) { $this->assertStringContainsString('SANDBOX reference', $e->getMessage()); }
        Http::fake(['*' => Http::response($this->ack())]);
        $this->service->prepare($this->company->id, $sale->id, $this->payload($sale));
        $this->service->submit($this->company->id, $sale->id, $this->owner->id);
        $p['invoiceRefNo'] = 'TEST-SBX-ACK-1';
        $this->service->prepare($this->company->id, $return->id, $p);
        $this->service->submit($this->company->id, $return->id, $this->owner->id);
        Http::assertSent(fn ($r) => $r['invoiceType'] === 'Debit Note' && $r['invoiceRefNo'] === 'TEST-SBX-ACK-1');
    }

    public function test_reentrant_submission_cannot_send_twice(): void
    {
        $record = $this->record();
        $this->service->prepare($this->company->id, $record->id, $this->payload($record));
        Http::fake(function () use ($record) {
            try { $this->service->submit($this->company->id, $record->id, $this->owner->id); $this->fail('Second claim'); }
            catch (HttpException $e) { $this->assertSame(409, $e->getStatusCode()); }
            return Http::response($this->ack());
        });
        $this->assertSame('accepted', $this->service->submit($this->company->id, $record->id, $this->owner->id)->state);
        Http::assertSentCount(1);
    }

    public function test_owner_routes_enforce_csrf_and_company_isolation(): void
    {
        $record = $this->record();
        $this->get('/pos/di-sandbox')->assertOk()->assertSee('Prepare a test bill');
        $this->postJson('/pos/di-sandbox/capture', ['transaction_id' => $record->source_transaction_id, 'fictional_data' => 1])->assertStatus(419);
        $this->withSession(['_token' => 'local-test-csrf'])->post('/pos/di-sandbox/capture', [
            '_token' => 'local-test-csrf', 'transaction_id' => $record->source_transaction_id, 'fictional_data' => 1,
        ])->assertRedirect(route('pos.di-sandbox.show', $record->id));
        $other = DB::table('companies')->insertGetId(['name' => 'Second Tenant']);
        $otherUser = DB::table('users')->insertGetId(['company_id' => $other]);
        $this->actingAs(User::findOrFail($otherUser), 'pos');
        $this->getJson(route('pos.di-sandbox.show', $record->id))->assertNotFound();
        $this->getJson(route('pos.di-sandbox.receipt', $record->id))->assertNotFound();
        $this->withSession(['_token' => 'local-test-csrf'])->postJson(route('pos.di-sandbox.submit', $record->id), ['_token' => 'local-test-csrf', 'confirm_sandbox' => 1])->assertNotFound();
        Http::assertNothingSent();
    }

    public function test_staff_and_di_product_cannot_use_the_workbench(): void
    {
        $this->owner->forceFill(['role' => 'employee', 'pos_role' => 'pos_cashier'])->saveQuietly();
        $this->get('/pos/di-sandbox')->assertForbidden();
        $this->owner->forceFill(['role' => 'company_admin', 'pos_role' => 'pos_admin'])->saveQuietly();
        $this->company->forceFill(['product_type' => 'di'])->saveQuietly();
        $this->get('/pos/di-sandbox')->assertRedirect('/login');
    }

    public function test_disabled_and_production_environments_cannot_open_or_send(): void
    {
        config(['pra_di_sandbox.enabled' => false]);
        $this->getJson('/pos/di-sandbox')->assertNotFound();
        config(['pra_di_sandbox.enabled' => true, 'app.env' => 'production']);
        $this->app->instance('env', 'production');
        $this->assertFalse(PraDiSandboxGate::available());
        $this->getJson('/pos/di-sandbox')->assertNotFound();
        $this->expectException(HttpException::class);
        app(FbrService::class)->submitPosSandboxPayload(['items' => []], self::TOKEN);
    }

    public function test_gate_refuses_a_non_disposable_database_even_with_the_flag_enabled(): void
    {
        config(['database.connections.sandbox_gate_probe' => [
            'driver' => 'sqlite', 'database' => '/tmp/not-an-in-memory-test.sqlite', 'prefix' => '',
        ], 'database.default' => 'sandbox_gate_probe']);
        $this->assertFalse(PraDiSandboxGate::available());
        $this->app->instance('env', 'local');
        config(['database.connections.remote_gate_probe' => [
            'driver' => 'mysql', 'database' => 'taxnest_dev', 'host' => 'db.invalid.example',
            'username' => 'fictional', 'password' => '', 'prefix' => '',
        ], 'database.default' => 'remote_gate_probe']);
        $this->assertFalse(PraDiSandboxGate::available());
        Http::assertNothingSent();
    }

    public function test_receipt_requires_acceptance_and_keeps_distinct_qrs_and_original_total(): void
    {
        $record = $this->record();
        $this->get(route('pos.di-sandbox.receipt', ['id' => $record->id, 'width' => 58]))->assertOk()
            ->assertSee('width:58mm', false)->assertSee('NOT A VALID TAX INVOICE')
            ->assertDontSee('alt="FBR sandbox acknowledgement QR"', false);
        $this->service->prepare($this->company->id, $record->id, $this->payload($record));
        Http::fake(['*' => Http::response($this->ack())]);
        $this->service->submit($this->company->id, $record->id, $this->owner->id);
        foreach ([58, 80] as $width) {
            $this->get(route('pos.di-sandbox.receipt', ['id' => $record->id, 'width' => $width]))->assertOk()
                ->assertSee('116.00')->assertSee('PRA Sales Tax')->assertSee('TEST-SBX-ACK-1')
                ->assertSee('alt="PRA source QR"', false)->assertSee('alt="FBR sandbox acknowledgement QR"', false);
        }
    }

    public function test_hold_and_missing_snapshots_are_refused_without_guessing(): void
    {
        try { $this->record($this->bill('cash', ['status' => 'hold'])); $this->fail('Hold reported'); }
        catch (HttpException $e) { $this->assertSame(422, $e->getStatusCode()); }
        $id = $this->bill(); DB::table('pos_transaction_items')->where('transaction_id', $id)->update(['taxable_base' => null]);
        $this->expectException(ValidationException::class);
        $this->record($id);
    }
    private function hostedPilot(): void
    {
        // Only the environment label changes: the disposable SQLite test DB and
        // Http::fake remain in use. No live data or credentials are involved.
        config(['pra_di_sandbox.hosted_enabled' => true]);
        $this->app->instance('env', 'production');
        $this->withSession(['_token' => 'pilot-csrf']);
    }

    private function enablePilot(): void
    {
        $this->post(route('pos.di-sandbox.setup.save'), [
            '_token' => 'pilot-csrf', 'enabled' => '1', 'confirm_sandbox_setup' => '1',
            'sandbox_token' => 'dedicated-fictional-pilot-token',
        ])->assertSessionHasNoErrors()->assertRedirect(route('pos.di-sandbox.setup'));
    }

    public function test_hosted_owner_can_configure_and_test_one_bill_without_changing_existing_fiscal_setup(): void
    {
        $before = (array) DB::table('companies')->find($this->company->id);
        $this->hostedPilot();
        $this->get(route('pos.di-sandbox.setup'))->assertOk()->assertSee('Disabled');
        $this->get(route('pos.di-sandbox.index'))->assertOk()->assertSee('DI submission is disabled');
        $this->enablePilot();
        $this->assertSame($before, (array) DB::table('companies')->find($this->company->id));
        $profile = PosDiSandboxProfile::firstOrFail();
        $this->assertSame('dedicated-fictional-pilot-token', $profile->sandbox_token);
        $this->assertStringNotContainsString('dedicated-fictional-pilot-token', json_encode($profile));
        $this->assertStringNotContainsString('dedicated-fictional-pilot-token', DB::table('pos_di_sandbox_profiles')->value('sandbox_token'));
        $this->get(route('pos.di-sandbox.setup'))->assertOk()->assertSee('Saved')->assertDontSee('dedicated-fictional-pilot-token');
        $this->get(route('pos.di-sandbox.index'))->assertOk()->assertSee('sandbox_copy');
        $id = $this->bill();
        $this->postJson(route('pos.di-sandbox.capture'), ['_token' => 'pilot-csrf', 'transaction_id' => $id])
            ->assertUnprocessable()->assertJsonValidationErrors('sandbox_copy');
        $this->post(route('pos.di-sandbox.capture'), [
            '_token' => 'pilot-csrf', 'transaction_id' => $id, 'sandbox_copy' => 1,
        ])->assertRedirect();
        Http::assertNothingSent();
        $record = PosDiSandboxSubmission::firstOrFail();
        $this->service->prepare($this->company->id, $record->id, $this->payload($record));
        Http::fake(['*' => Http::response($this->ack())]);
        $this->post(route('pos.di-sandbox.submit', $record->id), [
            '_token' => 'pilot-csrf', 'confirm_sandbox' => 1,
        ])->assertSessionHasNoErrors()->assertRedirect();
        Http::assertSentCount(1);
        Http::assertSent(fn ($r) => $r->url() === 'https://gw.fbr.gov.pk/di_data/v1/di/postinvoicedata_sb'
            && $r->hasHeader('Authorization', 'Bearer dedicated-fictional-pilot-token'));
        $this->get(route('pos.di-sandbox.receipt', $record->id))->assertOk()
            ->assertSee('TEST-SBX-ACK-1')->assertSee('NOT A VALID TAX INVOICE');
        foreach (['invoices', 'inventory_movements', 'customer_ledger'] as $table) $this->assertDatabaseCount($table, 0);
        $this->assertSame($before, (array) DB::table('companies')->find($this->company->id));
    }

    public function test_pilot_disable_retains_credential_and_evidence_but_blocks_submissions(): void
    {
        $this->hostedPilot(); $this->enablePilot();
        $record = $this->record();
        $this->service->prepare($this->company->id, $record->id, $this->payload($record));
        $this->post(route('pos.di-sandbox.setup.save'), [
            '_token' => 'pilot-csrf', 'enabled' => 0, 'sandbox_token' => '',
        ])->assertSessionHasNoErrors()->assertRedirect();
        $this->assertFalse(PosDiSandboxProfile::firstOrFail()->enabled);
        $this->assertSame('dedicated-fictional-pilot-token', PosDiSandboxProfile::firstOrFail()->sandbox_token);
        $this->assertDatabaseCount('pos_di_sandbox_submissions', 1);
        $this->postJson(route('pos.di-sandbox.submit', $record->id), [
            '_token' => 'pilot-csrf', 'confirm_sandbox' => 1,
        ])->assertNotFound();
        Http::assertNothingSent();
    }

    public function test_pilot_setup_requires_owner_csrf_consent_and_its_own_token_without_flashing_secrets(): void
    {
        $this->hostedPilot();
        $this->postJson(route('pos.di-sandbox.setup.save'), ['enabled' => 1])->assertStatus(419);
        $this->post(route('pos.di-sandbox.setup.save'), [
            '_token' => 'pilot-csrf', 'enabled' => 1, 'sandbox_token' => 'secret-must-not-flash',
        ])->assertSessionHasErrors('confirm_sandbox_setup')->assertSessionMissing('_old_input.sandbox_token');
        $this->post(route('pos.di-sandbox.setup.save'), [
            '_token' => 'pilot-csrf', 'enabled' => 1, 'confirm_sandbox_setup' => 1,
        ])->assertSessionHasErrors('sandbox_token');
        $this->assertDatabaseCount('pos_di_sandbox_profiles', 0);
        $this->owner->forceFill(['role' => 'employee', 'pos_role' => 'pos_cashier'])->saveQuietly();
        $this->getJson(route('pos.di-sandbox.setup'))->assertForbidden();
        $this->postJson(route('pos.di-sandbox.setup.save'), [
            '_token' => 'pilot-csrf', 'enabled' => 1, 'confirm_sandbox_setup' => 1, 'sandbox_token' => 'ignored',
        ])->assertForbidden();
        Http::assertNothingSent();
    }

    public function test_second_tenant_cannot_use_or_overwrite_first_tenants_pilot_profile(): void
    {
        $this->hostedPilot(); $this->enablePilot();
        $record = $this->record();
        $other = DB::table('companies')->insertGetId(['name' => 'Other fictional shop']);
        $uid = DB::table('users')->insertGetId(['company_id' => $other]);
        $this->actingAs(User::findOrFail($uid), 'pos');
        $this->get(route('pos.di-sandbox.setup'))->assertOk()->assertSee('Not configured');
        $this->getJson(route('pos.di-sandbox.show', $record->id))->assertNotFound();
        $this->post(route('pos.di-sandbox.setup.save'), [
            '_token' => 'pilot-csrf', 'enabled' => 0, 'company_id' => $this->company->id,
        ])->assertSessionHasNoErrors();
        $this->assertTrue(PosDiSandboxProfile::where('company_id', $this->company->id)->firstOrFail()->enabled);
        $this->assertFalse(PosDiSandboxProfile::where('company_id', $other)->firstOrFail()->enabled);
        Http::assertNothingSent();
    }

    public function test_hosted_transport_requires_company_context_and_global_kill_switch_stops_pilot(): void
    {
        $this->hostedPilot(); $this->enablePilot();
        try {
            app(FbrService::class)->submitPosSandboxPayload(['items' => [[]]], 'fictional');
            $this->fail('Hosted transport accepted a missing company context');
        } catch (HttpException $e) { $this->assertSame(404, $e->getStatusCode()); }
        config(['pra_di_sandbox.hosted_enabled' => false]);
        $this->assertFalse(PraDiSandboxGate::available($this->company->id));
        $this->getJson(route('pos.di-sandbox.setup'))->assertNotFound();
        Http::assertNothingSent();
    }

    public static function displayedSellerIds(): array
    {
        return [['1234567-8', '1234567'], ['12345-1234567-1', '1234512345671']];
    }

    #[DataProvider('displayedSellerIds')]
    public function test_seller_display_format_is_normalized_without_changing_company_identity(string $stored, string $expected): void
    {
        $this->company->forceFill(['ntn' => $stored])->saveQuietly();
        $record = $this->record();
        $payload = $this->payload($record);
        $this->assertSame($stored, $record->snapshot['seller_ntn']);
        $this->assertSame($expected, $payload['sellerNTNCNIC']);
        $this->service->prepare($this->company->id, $record->id, $payload);
        $this->assertSame($stored, $this->company->fresh()->ntn);
        Http::assertNothingSent();
    }

    public function test_malformed_seller_identifier_is_not_silently_truncated(): void
    {
        $this->company->forceFill(['ntn' => '123456789'])->saveQuietly();
        $record = $this->record();
        $this->expectException(ValidationException::class);
        $this->service->prepare($this->company->id, $record->id, $this->payload($record));
    }

    private function productionPilot(): void
    {
        $this->post(route('pos.di-sandbox.setup.save'), [
            '_token' => 'pilot-csrf', 'enabled' => 1, 'environment' => 'production',
            'production_token' => 'synthetic-live-token-never-used-on-network', 'confirm_production_setup' => 1,
        ])->assertSessionHasNoErrors()->assertRedirect();
    }

    public function test_production_setup_requires_its_own_token_and_explicit_onboarding_confirmation(): void
    {
        $this->hostedPilot(); $this->enablePilot();
        $this->post(route('pos.di-sandbox.setup.save'), [
            '_token' => 'pilot-csrf', 'enabled' => 1, 'environment' => 'production',
            'production_token' => 'secret-live-input', 'confirm_sandbox_setup' => 1,
        ])->assertSessionHasErrors('confirm_production_setup')->assertSessionMissing('_old_input.production_token');
        $this->assertSame('sandbox', PosDiSandboxProfile::first()->environment);
        $this->post(route('pos.di-sandbox.setup.save'), [
            '_token' => 'pilot-csrf', 'enabled' => 1, 'environment' => 'production', 'confirm_production_setup' => 1,
        ])->assertSessionHasErrors('production_token');
        $this->get(route('pos.di-sandbox.setup'))->assertOk(); // consume old validation flash
        $this->productionPilot();
        $profile = PosDiSandboxProfile::first();
        $this->assertSame('dedicated-fictional-pilot-token', $profile->sandbox_token);
        $this->assertNotNull($profile->production_confirmed_at);
        $this->assertSame($this->owner->id, $profile->production_confirmed_by);
        $this->assertStringNotContainsString('synthetic-live-token', json_encode($profile));
        $this->assertStringNotContainsString('synthetic-live-token', DB::table('pos_di_sandbox_profiles')->value('production_token'));
        $this->get(route('pos.di-sandbox.setup'))->assertOk()->assertDontSee('synthetic-live-token-never-used-on-network');
        Http::assertNothingSent();
    }

    public function test_same_source_has_separate_sandbox_and_production_records_and_correct_credentials(): void
    {
        $this->hostedPilot(); $this->enablePilot();
        $id = $this->bill(); $before = (array) DB::table('pos_transactions')->find($id);
        $sandbox = $this->record($id);
        $this->service->prepare($this->company->id, $sandbox->id, $this->payload($sandbox));
        Http::fake(function ($request) {
            $ack = $this->ack();
            if (!str_ends_with($request->url(), '_sb')) $ack['invoiceNumber'] = 'SYNTHETIC-PRODUCTION-ACK';
            return Http::response($ack);
        });
        $this->service->submit($this->company->id, $sandbox->id, $this->owner->id);
        $this->productionPilot();
        Http::assertSentCount(1); // changing settings does not send anything
        $live = $this->service->capture($this->company, $id, $this->owner->id, 'production');
        $this->assertNotSame($sandbox->id, $live->id);
        $this->assertNull($live->payload); // no promotion/copy of the test payload
        $this->assertSame($live->id, $this->service->capture($this->company, $id, $this->owner->id, 'production')->id);
        $this->assertArrayNotHasKey('scenarioId', $this->service->template($live));
        $this->assertSame('', $this->service->template($live)['buyerBusinessName']);
        $this->service->prepare($this->company->id, $live->id, $this->payload($live));
        $this->postJson(route('pos.di-sandbox.submit', $live->id), [
            '_token' => 'pilot-csrf', 'confirm_sandbox' => 1,
        ])->assertUnprocessable()->assertJsonValidationErrors('confirm_production');
        $this->post(route('pos.di-sandbox.submit', $live->id), [
            '_token' => 'pilot-csrf', 'confirm_production' => 1,
        ])->assertRedirect();
        Http::assertSent(fn ($r) => $r->url() === 'https://gw.fbr.gov.pk/di_data/v1/di/postinvoicedata'
            && $r->hasHeader('Authorization', 'Bearer synthetic-live-token-never-used-on-network')
            && !isset($r['scenarioId']));
        $this->assertSame('SYNTHETIC-PRODUCTION-ACK', $live->fresh()->production_invoice_number);
        $this->assertNull($live->fresh()->sandbox_invoice_number);
        $this->assertSame('TEST-SBX-ACK-1', $sandbox->fresh()->sandbox_invoice_number);
        $this->assertSame($before, (array) DB::table('pos_transactions')->find($id));
        foreach (['invoices', 'inventory_movements', 'customer_ledger'] as $table) $this->assertDatabaseCount($table, 0);
        $this->get(route('pos.di-sandbox.receipt', $live->id))->assertOk()->assertSee('SYNTHETIC-PRODUCTION-ACK')
            ->assertSee('FBR production acknowledgement QR')->assertDontSee('SANDBOX TEST COPY');
        $this->get(route('pos.di-sandbox.receipt', $sandbox->id))->assertOk()->assertSee('SANDBOX TEST COPY')
            ->assertDontSee('SYNTHETIC-PRODUCTION-ACK');
        try { $this->service->submit($this->company->id, $live->id, $this->owner->id); $this->fail('Live replay allowed'); }
        catch (HttpException $e) { $this->assertSame(409, $e->getStatusCode()); }
    }

    public function test_environment_switch_cannot_send_a_stale_sandbox_record_or_capture_from_stale_form(): void
    {
        $this->hostedPilot(); $this->enablePilot();
        $sandbox = $this->record();
        $this->service->prepare($this->company->id, $sandbox->id, $this->payload($sandbox));
        $this->productionPilot();
        $this->postJson(route('pos.di-sandbox.submit', $sandbox->id), [
            '_token' => 'pilot-csrf', 'confirm_sandbox' => 1,
        ])->assertNotFound();
        $this->postJson(route('pos.di-sandbox.capture'), [
            '_token' => 'pilot-csrf', 'transaction_id' => $sandbox->source_transaction_id,
            'environment' => 'sandbox', 'confirm_production_copy' => 1,
        ])->assertConflict();
        $this->assertDatabaseCount('pos_di_sandbox_submissions', 1);
        Http::assertNothingSent();
    }

    public function test_live_timeout_blocks_replay_and_disabled_reporting_preserves_receipt_access(): void
    {
        $this->hostedPilot(); $this->productionPilot();
        $live = $this->service->capture($this->company, $this->bill(), $this->owner->id, 'production');
        $this->service->prepare($this->company->id, $live->id, $this->payload($live));
        Http::fake(fn () => throw new \Illuminate\Http\Client\ConnectionException('synthetic timeout'));
        $this->service->submit($this->company->id, $live->id, $this->owner->id);
        $this->assertSame('verification_required', $live->fresh()->state);
        $this->get(route('pos.di-sandbox.receipt', $live->id))->assertOk()->assertSee('NOT ACCEPTED BY FBR')
            ->assertDontSee('FBR production acknowledgement QR');
        try { $this->service->submit($this->company->id, $live->id, $this->owner->id); $this->fail('Unknown replay allowed'); }
        catch (HttpException $e) { $this->assertSame(409, $e->getStatusCode()); }
        $this->post(route('pos.di-sandbox.setup.save'), ['_token' => 'pilot-csrf', 'enabled' => 0])
            ->assertSessionHasNoErrors();
        $this->get(route('pos.di-sandbox.receipt', $live->id))->assertOk();
        $this->assertSame('production', PosDiSandboxProfile::first()->environment);
    }

    public function test_production_returns_cannot_reference_a_sandbox_acknowledgement(): void
    {
        $this->hostedPilot(); $this->enablePilot();
        $sale = $this->record();
        $this->service->prepare($this->company->id, $sale->id, $this->payload($sale));
        Http::fake(function ($request) {
            $ack = $this->ack();
            if (!str_ends_with($request->url(), '_sb')) $ack['invoiceNumber'] = 'SYNTHETIC-PRODUCTION-PARENT';
            return Http::response($ack);
        });
        $this->service->submit($this->company->id, $sale->id, $this->owner->id);
        $this->productionPilot();
        $returnId = $this->bill('cash', ['transaction_type' => 'return', 'parent_transaction_id' => $sale->source_transaction_id]);
        $return = $this->service->capture($this->company, $returnId, $this->owner->id, 'production');
        $p = $this->payload($return); $p['invoiceRefNo'] = 'TEST-SBX-ACK-1';
        try { $this->service->prepare($this->company->id, $return->id, $p); $this->fail('Cross-environment reference accepted'); }
        catch (ValidationException $e) { $this->assertStringContainsString('PRODUCTION', $e->getMessage()); }
        $liveSale = $this->service->capture($this->company, $sale->source_transaction_id, $this->owner->id, 'production');
        $this->service->prepare($this->company->id, $liveSale->id, $this->payload($liveSale));
        $this->service->submit($this->company->id, $liveSale->id, $this->owner->id);
        $p['invoiceRefNo'] = 'SYNTHETIC-PRODUCTION-PARENT';
        $this->service->prepare($this->company->id, $return->id, $p);
        $this->assertSame('SYNTHETIC-PRODUCTION-PARENT', $return->fresh()->payload['invoiceRefNo']);
    }

    public function test_production_kill_switch_and_missing_production_token_never_fall_back_to_sandbox(): void
    {
        $this->hostedPilot(); $this->productionPilot();
        $record = $this->service->capture($this->company, $this->bill(), $this->owner->id, 'production');
        $this->service->prepare($this->company->id, $record->id, $this->payload($record));
        PosDiSandboxProfile::first()->update(['production_token' => null]);
        try { $this->service->submit($this->company->id, $record->id, $this->owner->id); $this->fail('Missing live token accepted'); }
        catch (ValidationException $e) { $this->assertArrayHasKey('production', $e->errors()); }
        config(['pra_di_sandbox.production_enabled' => false]);
        $this->assertFalse(PraDiSandboxGate::available($this->company->id, 'production'));
        Http::assertNothingSent();
    }

    public function test_production_payload_rejects_sandbox_scenario_field(): void
    {
        $this->hostedPilot(); $this->productionPilot();
        $record = $this->service->capture($this->company, $this->bill(), $this->owner->id, 'production');
        $p = $this->payload($record); $p['scenarioId'] = 'SN019';
        $this->expectException(ValidationException::class);
        $this->service->prepare($this->company->id, $record->id, $p);
    }

    private function unresolvedImsForPortal(): array
    {
        $this->imsProfile('production','fiscal_device');
        $svc=app(\App\Services\PosImsReportingService::class);
        $r=$svc->capture($this->company,$this->bill(),$this->owner->id,'production');
        $svc->prepare($this->company->id,$r->id,$this->imsPayload($r));
        $svc->submit($this->company->id,$r->id,$this->owner->id);
        $claim=$svc->claim($this->company->id,'synthetic-device');
        $r=$svc->callback($this->company->id,$r->id,'synthetic-device',$claim['claim'],null,'invalid_json_result');
        $p=$r->payload;
        return [$r,['_token'=>'portal-csrf','environment'=>'production','pos_id'=>$p['POSID'],
            'usin'=>$p['USIN'],'invoice_date'=>substr($p['DateTime'],0,10),'invoice_type'=>$p['InvoiceType'],
            'sale_value'=>$p['TotalSaleValue'],'tax_charged'=>$p['TotalTaxCharged'],
            'total_amount'=>$p['TotalBillAmount'],'quantity'=>$p['TotalQuantity'],
            'fiscal_number'=>'54321TEST12345678','evidence_note'=>'Fictional portal report checked against all frozen invoice fields.',
            'confirm_portal'=>1],$claim];
    }

    public function test_owner_portal_recovery_restores_receipt_reference_without_resending_or_changing_sale(): void
    {
        Http::preventStrayRequests();
        [$r,$evidence,$claim]=$this->unresolvedImsForPortal();
        $before=(array)DB::table('pos_transactions')->find($r->source_transaction_id);
        $this->assertSame('invalid_json_result',$r->attempts[0]['diagnostic']);
        $this->get(route('pos.tax-integrations.ims.show',$r->id))->assertOk()
            ->assertSee('Recover an invoice already visible in FBR')->assertSee('invalid_json_result');
        $url=route('pos.tax-integrations.ims.reconcile',$r->id);
        $this->postJson($url,$evidence)->assertStatus(419);
        $this->withSession(['_token'=>'portal-csrf'])->post($url,$evidence)->assertRedirect();
        $r->refresh();$this->assertSame('accepted',$r->state);
        $this->assertSame($evidence['fiscal_number'],$r->fiscal_number);
        $this->assertSame('owner_verified_fbr_portal',$r->attempts[1]['source']);
        $this->assertSame($this->owner->id,$r->attempts[1]['actor_id']);
        $this->assertSame($before,(array)DB::table('pos_transactions')->find($r->source_transaction_id));
        $this->get(route('pos.tax-integrations.ims.receipt',$r->id))->assertOk()->assertSee($evidence['fiscal_number']);
        $this->postJson($url,$evidence)->assertStatus(409);
        $svc=app(\App\Services\PosImsReportingService::class);
        $late=$svc->callback($this->company->id,$r->id,'synthetic-device',$claim['claim'],null);
        $this->assertSame('accepted',$late->state);$this->assertCount(2,$late->attempts);
        try {$svc->submit($this->company->id,$r->id,$this->owner->id);$this->fail('Accepted bill resent');}
        catch(HttpException $e){$this->assertSame(409,$e->getStatusCode());}
        Http::assertNothingSent();
    }

    public function test_portal_recovery_rejects_mismatched_evidence_staff_and_second_tenant(): void
    {
        Http::preventStrayRequests();[$r,$data]=$this->unresolvedImsForPortal();
        $url=route('pos.tax-integrations.ims.reconcile',$r->id);
        $this->withSession(['_token'=>'portal-csrf']);
        foreach(['pos_id'=>999,'usin'=>'WRONG-BILL','invoice_date'=>'2000-01-01','environment'=>'sandbox',
            'invoice_type'=>3,'sale_value'=>999,'tax_charged'=>999,'total_amount'=>999,'quantity'=>999] as $key=>$wrong) {
            \Illuminate\Support\Facades\Cache::flush(); // Each synthetic case has its own request budget.
            $this->postJson($url,array_replace($data,[$key=>$wrong]))->assertUnprocessable();
            $this->assertSame('verification_required',$r->fresh()->state);
        }
        $this->postJson($url,array_replace($data,['confirm_portal'=>0]))->assertUnprocessable();
        $this->owner->forceFill(['role'=>'employee','pos_role'=>'pos_cashier'])->saveQuietly();
        $this->postJson($url,$data)->assertForbidden();
        $other=DB::table('companies')->insertGetId(['name'=>'Second fictional shop']);
        $uid=DB::table('users')->insertGetId(['company_id'=>$other]);
        $this->actingAs(User::findOrFail($uid),'pos')->postJson($url,$data)->assertNotFound();
        Http::assertNothingSent();
    }

    public function test_portal_reference_cannot_be_reused_for_another_bill_or_applied_before_dispatch(): void
    {
        Http::preventStrayRequests();[$first,$firstData]=$this->unresolvedImsForPortal();
        $svc=app(\App\Services\PosImsReportingService::class);
        $svc->reconcilePortal($this->company->id,$first->id,$this->owner->id,$firstData);
        [$second,$secondData]=$this->unresolvedImsForPortal();
        try {$svc->reconcilePortal($this->company->id,$second->id,$this->owner->id,$secondData);$this->fail('Reference reused');}
        catch(HttpException $e){$this->assertSame(409,$e->getStatusCode());}
        $second->update(['state'=>'draft']);
        try {$svc->reconcilePortal($this->company->id,$second->id,$this->owner->id,$secondData);$this->fail('Draft marked accepted');}
        catch(HttpException $e){$this->assertSame(409,$e->getStatusCode());}
        $this->assertSame('draft',$second->fresh()->state);Http::assertNothingSent();
    }

    public function test_ims_callback_trims_status_keeps_safe_evidence_and_refuses_conflicting_codes(): void
    {
        $service=\App\Services\PosImsReportingService::class;
        $this->assertSame('local_accepted',$service::result(['Code'=>' 100 ','InvoiceNumber'=>'LOCAL-IMS-123456'],true)['state']);
        $this->assertSame('verification_required',$service::result(['Code'=>'100','code'=>'400','InvoiceNumber'=>'LOCAL-IMS-123456'],true)['state']);
        $this->imsProfile('sandbox','fiscal_device');$svc=app($service);
        $r=$svc->capture($this->company,$this->bill(),$this->owner->id,'sandbox');
        $svc->prepare($this->company->id,$r->id,$this->imsPayload($r));Http::fake();
        $svc->submit($this->company->id,$r->id,$this->owner->id);$claim=$svc->claim($this->company->id,'synthetic-device');
        $r=$svc->callback($this->company->id,$r->id,'synthetic-device',$claim['claim'],[
            'Code'=>' 100 ','InvoiceNumber'=>'LOCAL-IMS-123456','Token'=>'private-token',
            'BuyerCNIC'=>'private-identity','Response'=>'Private buyer details never retained'], 'response_received',['Code:string','InvoiceNumber:string','bad value with spaces']);
        $this->assertSame('local_accepted',$r->state);
        $this->assertSame(['Code'=>'100','InvoiceNumber'=>'LOCAL-IMS-123456'],$r->attempts[0]['acknowledgement']);
        $this->assertSame(['Code:string','InvoiceNumber:string'],$r->attempts[0]['response_fields']);
        $this->assertStringNotContainsString('private',json_encode($r->attempts));
        $this->get(route('pos.tax-integrations.ims.show',$r->id))->assertOk()->assertSee('Code: 100')->assertSee('LOCAL-IMS-123456');
        Http::assertNothingSent();
    }

    private function imsProfile(string $environment='sandbox', string $mode='cloud'): \App\Models\PosImsProfile
    {
        PosDiSandboxProfile::where('company_id',$this->company->id)->update(['enabled'=>false]);
        return \App\Models\PosImsProfile::updateOrCreate(['company_id'=>$this->company->id],[
            'enabled'=>true,'environment'=>$environment,'connection_mode'=>$mode,
            'sandbox_pos_id'=>'12345','production_pos_id'=>'54321','sandbox_token'=>'synthetic-ims-sandbox',
            'production_token'=>'synthetic-ims-production','production_confirmed_at'=>now(),
            'device_uid'=>'synthetic-device','local_ims_url'=>'http://127.0.0.1:8525/api/IMSFiscal/GetInvoiceNumberByModel']);
    }
    private function imsPayload($r): array
    {
        $p=app(\App\Services\PosImsReportingService::class)->template($r);
        foreach($p['Items'] as &$l){ $l['PCTCode']='12345678';$l['TaxRate']=18;$l['TaxCharged']=round($l['SaleValue']*.18,2);$l['TotalAmount']=$l['SaleValue']+$l['TaxCharged']; } unset($l);
        $p['TotalTaxCharged']=array_sum(array_column($p['Items'],'TaxCharged'));
        $p['TotalBillAmount']=$p['TotalSaleValue']+$p['TotalTaxCharged'];return $p;
    }
    public function test_di_configuration_has_both_saved_urls_identity_and_correct_navigation(): void
    {
        $this->get('/pos/di-sandbox/setup')->assertOk()->assertSee('Sandbox API endpoint')->assertSee('Production API endpoint')
            ->assertSee('FBR Registration No')->assertSee(route('pos.tax-integrations'),false)->assertDontSee('Back to receipt settings');
        $token='configuration-test-secret';
        $this->withSession(['_token'=>'csrf'])->post('/pos/di-sandbox/setup',[
            '_token'=>'csrf','enabled'=>1,'environment'=>'sandbox','confirm_sandbox_setup'=>1,
            'sandbox_token'=>$token,'sandbox_url'=>\App\Services\PosTaxIntegrationEndpoints::url('di','sandbox'),
            'production_url'=>\App\Services\PosTaxIntegrationEndpoints::url('di','production'),
            'fbr_registration_no'=>'7654321','fbr_business_name'=>'Synthetic federal identity',
        ])->assertRedirect('/pos/di-sandbox/setup');
        $profile=PosDiSandboxProfile::where('company_id',$this->company->id)->firstOrFail();
        $this->assertSame($token,$profile->sandbox_token);
        $this->assertSame('7654321',$this->company->fresh()->fbr_registration_no);
        $this->get('/pos/di-sandbox/setup')->assertOk()->assertDontSee($token);
        Http::fake();
        $this->withSession(['_token'=>'csrf'])->post('/pos/di-sandbox/check',['_token'=>'csrf'])->assertSessionHas('success');
        Http::assertNothingSent();
        $this->withSession(['_token'=>'csrf'])->post('/pos/di-sandbox/setup',[
            '_token'=>'csrf','enabled'=>0,'sandbox_url'=>'https://untrusted.invalid/leak','sandbox_token'=>'another-secret',
        ])->assertSessionHasErrors('sandbox_url');
        $this->assertSame($token,$profile->fresh()->sandbox_token);
        $this->assertSame('7654321',$this->company->fresh()->fbr_registration_no);
    }
    public function test_ims_manual_preparation_keeps_no_catalogue_code_compatibility_and_rejects_invalid_pct(): void
    {
        $this->imsProfile();
        $ims=app(\App\Services\PosImsReportingService::class);
        $record=$ims->capture($this->company,$this->bill(),$this->owner->id,'sandbox');
        $p=$this->imsPayload($record);
        $this->assertSame('00000000',$ims->template($record)['Items'][0]['PCTCode']);
        foreach ($p['Items'] as &$line) $line['PCTCode']='00000000'; unset($line);
        $ims->prepare($this->company->id,$record->id,$p);
        $this->assertSame('00000000',$record->fresh()->payload['Items'][0]['PCTCode']);
        $p['Items'][0]['PCTCode']='invalid';
        try {
            $ims->prepare($this->company->id,$record->id,$p);
            $this->fail('Malformed PCT accepted');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('Items.0.PCTCode',$e->errors());
        }
        Http::assertNothingSent();
    }

    public function test_ims_evidence_preserves_source_and_uses_only_ims_environment_credentials(): void
    {
        $this->imsProfile();$svc=app(\App\Services\PosImsReportingService::class);$bill=$this->bill('credit');
        $before=DB::table('pos_transactions')->where('id',$bill)->first();
        $r=$svc->capture($this->company,$bill,$this->owner->id,'sandbox');
        $this->assertSame($r->id,$svc->capture($this->company,$bill,$this->owner->id,'sandbox')->id);
        $this->assertNull($svc->template($r)['TotalTaxCharged']);
        $p=$this->imsPayload($r);$this->assertSame(1,$p['PaymentMode']);
        $svc->prepare($this->company->id,$r->id,$p);
        Http::fake(fn()=>Http::response(['Code'=>'100','InvoiceNumber'=>'TEST-IMS-123456'],200));
        $r=$svc->submit($this->company->id,$r->id,$this->owner->id);
        $this->assertSame('accepted',$r->state);
        Http::assertSent(fn($req)=>$req->url()===\App\Services\PosTaxIntegrationEndpoints::url('ims','sandbox') && $req->hasHeader('Authorization','Bearer synthetic-ims-sandbox') && !isset($req['scenarioId']));
        $this->get('/pos/tax-integrations/fbr-pos/'.$r->id.'/receipt?width=58')->assertOk()->assertSee('SANDBOX TEST COPY')->assertSee('TEST-IMS-123456')->assertSee('TEST-PRA-ONLY');
        $this->assertEquals($before,DB::table('pos_transactions')->where('id',$bill)->first());
        $this->assertSame(0,DB::table('invoices')->count());$this->assertSame(0,DB::table('inventory_movements')->count());$this->assertSame(0,DB::table('customer_ledger')->count());
        try{$svc->submit($this->company->id,$r->id,$this->owner->id);$this->fail('replay allowed');}catch(HttpException $e){$this->assertSame(409,$e->getStatusCode());}
        Http::assertSentCount(1);
    }
    public function test_ims_setup_encrypts_tokens_selects_one_federal_channel_and_preserves_pra_settings(): void
    {
        PosDiSandboxProfile::create(['company_id'=>$this->company->id,'enabled'=>true,'sandbox_token'=>'preserved-di','updated_by'=>$this->owner->id]);
        $original=$this->company->getAttributes();
        $url='/pos/tax-integrations/fbr-pos/setup';
        $data=['_token'=>'csrf','enabled'=>1,'environment'=>'sandbox','connection_mode'=>'cloud','sandbox_pos_id'=>'12345','sandbox_token'=>'private-ims-token','confirm_setup'=>1];
        $this->withSession(['_token'=>'csrf'])->post($url,$data)->assertRedirect('/pos/tax-integrations/fbr-pos');
        $p=\App\Models\PosImsProfile::where('company_id',$this->company->id)->firstOrFail();
        $this->assertSame('private-ims-token',$p->sandbox_token);
        $this->assertStringNotContainsString('private-ims-token',DB::table('pos_ims_profiles')->value('sandbox_token'));
        $this->assertArrayNotHasKey('sandbox_token',$p->toArray());
        $this->assertFalse(PosDiSandboxProfile::first()->enabled);$this->assertSame('preserved-di',PosDiSandboxProfile::first()->sandbox_token);
        $this->assertEquals($original,$this->company->fresh()->getAttributes());
        $this->get('/pos/tax-integrations/fbr-pos')->assertOk()->assertSee('Sandbox API endpoint')->assertDontSee('private-ims-token');
        $data['environment']='production';$data['production_pos_id']='54321';$data['production_token']='private-production';
        $this->withSession(['_token'=>'csrf'])->post($url,$data)->assertSessionHasErrors('confirm_production');
        $this->assertSame('sandbox',$p->fresh()->environment);
        $this->assertNull(session()->getOldInput('production_token'));
        $data['confirm_production']=1;
        $this->withSession(['_token'=>'csrf'])->post($url,$data)->assertRedirect('/pos/tax-integrations/fbr-pos');
        $this->assertSame('production',$p->fresh()->environment);
        $this->withSession(['_token'=>'csrf'])->post('/pos/di-sandbox/setup',['_token'=>'csrf','enabled'=>1,'environment'=>'sandbox','confirm_sandbox_setup'=>1])->assertRedirect('/pos/di-sandbox/setup');
        $this->assertFalse($p->fresh()->enabled);$this->assertSame('private-production',$p->fresh()->production_token);
    }
    public function test_ims_uncertain_result_and_cross_channel_switch_cannot_duplicate_the_same_bill(): void
    {
        $this->imsProfile('production');$svc=app(\App\Services\PosImsReportingService::class);$bill=$this->bill();
        $r=$svc->capture($this->company,$bill,$this->owner->id,'production');$svc->prepare($this->company->id,$r->id,$this->imsPayload($r));
        Http::fake(fn()=>Http::response('',503));$r=$svc->submit($this->company->id,$r->id,$this->owner->id);
        $this->assertSame('verification_required',$r->state);
        $this->get('/pos/tax-integrations/fbr-pos/'.$r->id.'/receipt')->assertOk()->assertSee('PRODUCTION PREVIEW')->assertDontSee('alt="FBR IMS acknowledgement QR"',false);
        \App\Models\PosImsProfile::query()->update(['enabled'=>false]);
        $this->withSession(['_token'=>'pilot-csrf']);$this->productionPilot();
        try{$this->service->capture($this->company,$bill,$this->owner->id,'production');$this->fail('cross-channel replay');}catch(HttpException $e){$this->assertSame(409,$e->getStatusCode());}
        Http::assertSentCount(1);
    }
    public function test_ims_local_device_claim_is_exclusive_and_callback_survives_disabling_reporting(): void
    {
        $p=$this->imsProfile('sandbox','fiscal_device');$svc=app(\App\Services\PosImsReportingService::class);$r=$svc->capture($this->company,$this->bill(),$this->owner->id,'sandbox');
        $svc->prepare($this->company->id,$r->id,$this->imsPayload($r));Http::fake();
        $svc->submit($this->company->id,$r->id,$this->owner->id);Http::assertNothingSent();
        $claim=$svc->claim($this->company->id,'synthetic-device');$this->assertSame($r->id,$claim['id']);
        $this->assertNull($svc->claim($this->company->id,'synthetic-device'));
        $p->update(['enabled'=>false]);
        $r=$svc->callback($this->company->id,$r->id,'synthetic-device',$claim['claim'],['Code'=>'100','InvoiceNumber'=>'LOCAL-IMS-123456']);
        $this->assertSame('local_accepted',$r->state);
        $same=$svc->callback($this->company->id,$r->id,'synthetic-device',$claim['claim'],['Code'=>'102']);
        $this->assertSame('LOCAL-IMS-123456',$same->fiscal_number);
        $this->get('/pos/tax-integrations/fbr-pos/'.$r->id.'/receipt')->assertOk()->assertSee('Central synchronization not independently verified.');
    }
    public function test_legacy_agent_cannot_claim_a_soap_record(): void
    {
        $p=$this->imsProfile('sandbox','fiscal_device');
        $p->update(['local_ims_url'=>'http://127.0.0.1:8524/TcpService/IMSHttp']);
        $svc=app(\App\Services\PosImsReportingService::class);
        $r=$svc->capture($this->company,$this->bill(),$this->owner->id,'sandbox');
        $svc->prepare($this->company->id,$r->id,$this->imsPayload($r));
        Http::fake();$svc->submit($this->company->id,$r->id,$this->owner->id);Http::assertNothingSent();
        $this->assertNull($svc->claim($this->company->id,'synthetic-device'));
        $this->assertSame('queued_agent',$r->fresh()->state);
        $claim=$svc->claim($this->company->id,'synthetic-device',true);
        $this->assertSame($r->id,$claim['id']);
        $this->assertSame('http://127.0.0.1:8524/TcpService/IMSHttp',$claim['endpoint']);
    }

    public function test_ims_rejects_wrong_tenant_device_stale_identity_and_unbalanced_mapping(): void
    {
        $p=$this->imsProfile();$svc=app(\App\Services\PosImsReportingService::class);
        $r=$svc->capture($this->company,$this->bill(),$this->owner->id,'sandbox');
        $payload=$this->imsPayload($r);$payload['Items'][0]['SaleValue']=99;
        try{$svc->prepare($this->company->id,$r->id,$payload);$this->fail('tampered base');}catch(ValidationException $e){$this->assertArrayHasKey('payload_json',$e->errors());}
        $svc->prepare($this->company->id,$r->id,$this->imsPayload($r));$p->update(['sandbox_pos_id'=>'999']);
        try{$svc->submit($this->company->id,$r->id,$this->owner->id);$this->fail('stale identity');}catch(HttpException $e){$this->assertSame(409,$e->getStatusCode());}
        $other=Company::create(['name'=>'Other synthetic tenant','ntn'=>'1111111']);$this->owner->update(['company_id'=>$other->id]);
        $this->getJson('/pos/tax-integrations/fbr-pos/'.$r->id)->assertNotFound();
        $this->owner->update(['company_id'=>$this->company->id,'role'=>'employee','pos_role'=>'pos_cashier']);
        $this->getJson('/pos/tax-integrations/fbr-pos')->assertForbidden();
        Http::assertNothingSent();
    }
    public function test_ims_returns_require_same_environment_original_acknowledgement(): void
    {
        $this->imsProfile();$svc=app(\App\Services\PosImsReportingService::class);$bill=$this->bill();
        $parent=$svc->capture($this->company,$bill,$this->owner->id,'sandbox');$parent->update(['state'=>'accepted','fiscal_number'=>'ORIGINAL-IMS-123456']);
        $ret=$svc->capture($this->company,$this->bill('cash',['transaction_type'=>'return','parent_transaction_id'=>$bill]),$this->owner->id,'sandbox');
        $p=$this->imsPayload($ret);$p['RefUSIN']='WRONG-123456';$p['Items'][0]['RefUSIN']=$p['RefUSIN'];
        try{$svc->prepare($this->company->id,$ret->id,$p);$this->fail('wrong parent');}catch(ValidationException $e){$this->assertArrayHasKey('payload_json',$e->errors());}
        $p['RefUSIN']=$parent->fiscal_number;$p['Items'][0]['RefUSIN']=$p['RefUSIN'];
        $this->assertSame('draft',$svc->prepare($this->company->id,$ret->id,$p)->state);
    }

    public function test_ims_connection_recovery_preserves_financial_snapshot_and_blocks_uncertain_replays(): void
    {
        $profile=$this->imsProfile();$svc=app(\App\Services\PosImsReportingService::class);
        $r=$svc->capture($this->company,$this->bill(),$this->owner->id,'sandbox');
        $s=$r->snapshot;$svc->prepare($this->company->id,$r->id,$this->imsPayload($r));
        Http::fake(fn()=>Http::response(['Code'=>'112','Response'=>'Synthetic explicit rejection'],200));
        $r=$svc->submit($this->company->id,$r->id,$this->owner->id);$this->assertSame('rejected',$r->state);
        $profile->update(['connection_mode'=>'fiscal_device']);
        $r=$svc->refreshConnection($this->company->id,$r->id,$this->owner->id);
        $this->assertSame($s,$r->snapshot);$this->assertSame('fiscal_device',$r->connection_mode);$this->assertNull($r->payload);
        $svc->prepare($this->company->id,$r->id,$this->imsPayload($r));$svc->submit($this->company->id,$r->id,$this->owner->id);
        // A queued, never dispatched request can be withdrawn for fresh review.
        $r=$svc->refreshConnection($this->company->id,$r->id,$this->owner->id);$this->assertSame('draft',$r->state);
        $svc->prepare($this->company->id,$r->id,$this->imsPayload($r));$svc->submit($this->company->id,$r->id,$this->owner->id);
        $claim=$svc->claim($this->company->id,'synthetic-device');
        try{$svc->refreshConnection($this->company->id,$r->id,$this->owner->id);$this->fail('uncertain replay allowed');}catch(HttpException $e){$this->assertSame(409,$e->getStatusCode());}
        $this->assertSame($claim['claim'],\App\Models\PosImsSubmission::findOrFail($r->id)->agent_claim);
        Http::assertSentCount(1);
    }

}
