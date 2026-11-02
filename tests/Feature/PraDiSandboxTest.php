<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\PosDiSandboxSubmission;
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
        config(['pra_di_sandbox.enabled' => true]);
        Schema::create('companies', function (Blueprint $t) {
            $t->id(); $t->string('name'); $t->string('product_type')->default('pos');
            $t->string('status')->default('approved'); $t->string('company_status')->default('active');
            $t->string('ntn')->default('1234567'); $t->string('province')->default('Punjab');
            $t->string('address')->default('Fictional test street'); $t->text('fbr_sandbox_token')->nullable();
            $t->text('fbr_production_token')->nullable(); $t->string('fbr_environment')->default('production');
            $t->string('fbr_sandbox_url')->nullable(); $t->string('fbr_connection_status')->nullable();
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
        $p['buyerProvince'] = 'Punjab'; $p['scenarioId'] = 'SN019';
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
}
