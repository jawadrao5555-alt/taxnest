<?php

namespace Tests\Feature;

use App\Http\Controllers\InvoiceImportController;
use App\Models\Company;
use App\Models\InvoiceImportBatch;
use App\Services\AiImportAssistService;
use App\Services\DiFeatureService;
use App\Services\InvoiceImportService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Task 1238: AI assist for the DI bulk invoice Excel import.
 *
 * Locks the safety rules around the AI helpers:
 *   - mapping suggestions are sanitized: only real file headers (verbatim),
 *     each header used once, unknown fields dropped, fields the user already
 *     resolved never touched, enum defaults snapped to canonical values or
 *     dropped (never guessed);
 *   - row-fix suggestions are sanitized: unknown rows/fields dropped, no-op
 *     "fixes" dropped, values trimmed;
 *   - each successful call records an ai_invoice_parses success row
 *     (import_map / import_fix) so it counts against the SAME monthly quota
 *     as the AI Invoice Reader;
 *   - the downloadable error report gains an ai_suggestion column ONLY when
 *     suggestions were stored on the batch, and stays byte-identical in shape
 *     otherwise;
 *   - the retention pruner clears stored suggestions with the other heavy JSON;
 *   - the apply step is NOT a general row-edit API: it needs the ai_reader
 *     plan gate, accepts ONLY values the server itself stored as suggestions,
 *     refuses batches that already started processing (status guard +
 *     compare-and-swap), and re-runs the standard validation over ALL rows.
 */
class AiImportAssistTest extends TestCase
{
    private Company $company;

    protected function setUp(): void
    {
        parent::setUp();

        DiFeatureService::flushGateCaches();

        Schema::dropAllTables();

        Schema::create('companies', function (Blueprint $t) {
            $t->id();
            $t->string('name');
            $t->string('product_type')->default('di');
            $t->boolean('is_internal_account')->default(false);
            $t->string('status')->default('active');
            $t->string('company_status')->default('active');
            $t->string('province')->nullable();
            $t->decimal('standard_tax_rate', 5, 2)->nullable();
            $t->softDeletes();
            $t->timestamps();
        });

        Schema::create('pricing_plans', function (Blueprint $t) {
            $t->id();
            $t->string('name');
            $t->string('product_type')->default('di');
            $t->boolean('is_trial')->default(false);
            $t->integer('invoice_limit')->default(-1);
            $t->timestamps();
        });

        Schema::create('subscriptions', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('company_id');
            $t->unsignedBigInteger('pricing_plan_id')->nullable();
            $t->boolean('active')->default(true);
            $t->date('start_date')->nullable();
            $t->date('end_date')->nullable();
            $t->timestamp('trial_ends_at')->nullable();
            $t->string('override_type')->default('none');
            $t->timestamp('override_until')->nullable();
            $t->timestamp('override_granted_at')->nullable();
            $t->integer('free_invoice_limit')->nullable();
            $t->timestamps();
        });

        // Tables validateRows() touches during HS resolution.
        Schema::create('global_hs_master', function (Blueprint $t) {
            $t->id();
            $t->string('hs_code', 20)->unique();
            $t->string('description', 500)->nullable();
            $t->string('pct_code', 30)->nullable();
            $t->string('schedule_type', 30)->default('standard');
            $t->decimal('tax_rate', 5, 2)->default(18.00);
            $t->string('default_uom', 100)->nullable();
            $t->boolean('sro_required')->default(false);
            $t->string('sro_number', 100)->nullable();
            $t->string('sro_item_serial_no', 100)->nullable();
            $t->boolean('mrp_required')->default(false);
            $t->string('sector_tag', 100)->nullable();
            $t->decimal('risk_weight', 5, 2)->default(0);
            $t->string('mapping_status', 20)->default('Mapped');
            $t->unsignedBigInteger('created_by')->nullable();
            $t->unsignedBigInteger('updated_by')->nullable();
            $t->timestamps();
        });

        Schema::create('hs_unmapped_log', function (Blueprint $t) {
            $t->id();
            $t->string('hs_code', 20);
            $t->unsignedBigInteger('company_id');
            $t->unsignedBigInteger('invoice_id')->nullable();
            $t->integer('frequency_count')->default(1);
            $t->timestamp('first_seen_at')->nullable();
            $t->timestamp('last_seen_at')->nullable();
            $t->timestamps();
        });

        Schema::create('hs_usage_patterns', function (Blueprint $t) {
            $t->id();
            $t->string('hs_code', 20)->index();
            $t->string('schedule_type', 50)->nullable();
            $t->decimal('tax_rate', 5, 2)->nullable();
            $t->string('sro_schedule_no', 100)->nullable();
            $t->string('sro_item_serial_no', 100)->nullable();
            $t->boolean('mrp_required')->default(false);
            $t->string('sale_type', 100)->nullable();
            $t->integer('success_count')->default(0);
            $t->integer('rejection_count')->default(0);
            $t->decimal('confidence_score', 5, 2)->default(0);
            $t->string('admin_status', 20)->default('auto');
            $t->timestamp('last_used_at')->nullable();
            $t->string('integrity_hash', 64)->nullable();
            $t->timestamps();
        });

        Schema::create('ai_invoice_parses', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('company_id');
            $t->unsignedBigInteger('user_id')->nullable();
            $t->string('status', 20)->default('failed');
            $t->string('source_type', 10)->nullable();
            $t->string('original_filename')->nullable();
            $t->longText('payload_json')->nullable();
            $t->text('error')->nullable();
            $t->string('model', 60)->nullable();
            $t->unsignedInteger('total_tokens')->nullable();
            $t->unsignedBigInteger('invoice_id')->nullable();
            $t->timestamps();
        });

        Schema::create('invoice_import_batches', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('company_id')->index();
            $t->unsignedBigInteger('user_id')->nullable();
            $t->string('original_filename')->nullable();
            $t->string('source_format', 10)->default('xlsx');
            $t->string('status', 30)->default('validated')->index();
            $t->unsignedInteger('total_rows')->default(0);
            $t->unsignedInteger('valid_rows')->default(0);
            $t->unsignedInteger('invalid_rows')->default(0);
            $t->unsignedInteger('processed_rows')->default(0);
            $t->unsignedInteger('created_invoices')->default(0);
            $t->unsignedInteger('failed_rows')->default(0);
            $t->longText('rows_json')->nullable();
            $t->longText('result_json')->nullable();
            $t->longText('ai_suggestions_json')->nullable();
            $t->text('error_message')->nullable();
            $t->timestamp('started_at')->nullable();
            $t->timestamp('finished_at')->nullable();
            $t->timestamp('pruned_at')->nullable();
            $t->timestamps();
        });

        config(['services.openai.key' => 'test-key-123']);

        $this->company = Company::create([
            'name' => 'AI Assist Co',
            'province' => 'Punjab',
            'standard_tax_rate' => 18.0,
        ]);
    }

    private function fakeOpenAi(array $content): void
    {
        Http::fake([
            'api.openai.com/*' => Http::response([
                'choices' => [['message' => ['content' => json_encode($content)]]],
                'usage' => ['total_tokens' => 321],
            ], 200),
        ]);
    }

    // ------------------------------------------------------------------
    // Mapping suggestions
    // ------------------------------------------------------------------

    public function test_mapping_suggestions_sanitized_and_usage_recorded(): void
    {
        $headers = ['Customer', 'NTN #', 'Qty', 'Rate', 'Amount'];

        $this->fakeOpenAi([
            'mapping' => [
                'buyer_name' => 'Customer',        // valid
                'buyer_ntn' => 'ntn #',            // valid via normalized match — must come back VERBATIM
                'quantity' => 'Qty',               // valid
                'price' => 'Quantity Col',         // invented header — dropped
                'tax' => 'Qty',                    // header already used — dropped
                'not_a_field' => 'Amount',         // unknown field — dropped
                'description' => 'Customer',       // header already used — dropped
            ],
            'defaults' => [
                'document_type' => 'sale invoice',        // snaps to canonical
                'destination_province' => 'KPK',          // alias — snaps to canonical
                'schedule_type' => 'Standard Deluxe',     // not a valid enum — dropped
                'buyer_name' => 'Walk-in',                // field got a mapping — dropped
                'tax_rate' => ' 18 ',                     // free default — trimmed
                'bogus_field' => 'x',                     // unknown field — dropped
            ],
            'note' => 'Check the Amount column.',
        ]);

        $result = AiImportAssistService::suggestMapping($headers, [['Ali Traders', '1234567', '5', '100', '500']], $this->company);

        $this->assertSame([
            'buyer_name' => 'Customer',
            'buyer_ntn' => 'NTN #',
            'quantity' => 'Qty',
        ], $result['mapping']);

        $this->assertSame([
            'document_type' => 'Sale Invoice',
            'destination_province' => 'Khyber Pakhtunkhwa',
            'tax_rate' => '18',
        ], $result['defaults']);

        $this->assertSame('Check the Amount column.', $result['note']);

        // Quota bookkeeping: one success row, import_map source, fits varchar(10).
        $usage = DB::table('ai_invoice_parses')->get();
        $this->assertCount(1, $usage);
        $this->assertSame('success', $usage[0]->status);
        $this->assertSame('import_map', $usage[0]->source_type);
        $this->assertSame(321, (int) $usage[0]->total_tokens);
    }

    public function test_mapping_suggestions_never_touch_user_resolved_fields(): void
    {
        $headers = ['Customer', 'Qty'];

        $this->fakeOpenAi([
            'mapping' => [
                'buyer_name' => 'Customer',  // user already mapped buyer_name — dropped
                'quantity' => 'Qty',
            ],
            'defaults' => [
                'document_type' => 'Sale Invoice', // user already defaulted it — dropped
            ],
        ]);

        $result = AiImportAssistService::suggestMapping(
            $headers,
            [],
            $this->company,
            ['buyer_name' => 'Customer'],
            ['document_type' => 'Credit Note']
        );

        $this->assertSame(['quantity' => 'Qty'], $result['mapping']);
        $this->assertSame([], $result['defaults']);
    }

    // ------------------------------------------------------------------
    // Row-fix suggestions
    // ------------------------------------------------------------------

    public function test_row_fix_suggestions_sanitized_and_usage_recorded(): void
    {
        $this->fakeOpenAi([
            'rows' => [
                [
                    'row' => 3,
                    'fixes' => [
                        'destination_province' => 'Punjab',   // real fix
                        'buyer_ntn' => '1234567',             // real fix (was formatted)
                        'buyer_name' => 'Ali Traders',        // same as current value — no-op, dropped
                        'invented_field' => 'x',              // unknown field — dropped
                    ],
                    'note' => 'Province spelling corrected.',
                ],
                ['row' => 99, 'fixes' => ['tax' => '0'], 'note' => 'not in batch'], // unknown row — dropped
                ['row' => 4, 'fixes' => [], 'note' => 'nothing'],                   // empty fixes — dropped
            ],
        ]);

        $failing = [
            ['row' => 3, 'data' => ['buyer_name' => 'Ali Traders', 'buyer_ntn' => '12-34567', 'destination_province' => 'Punjb'], 'errors' => ['Invalid province.']],
            ['row' => 4, 'data' => ['buyer_name' => 'B'], 'errors' => ['Missing quantity.']],
        ];

        $result = AiImportAssistService::suggestRowFixes($failing, ['Ali Traders' => 'standard'], $this->company, 'sales.xlsx');

        $this->assertCount(1, $result);
        $this->assertSame(3, $result[0]['row']);
        $this->assertSame('Province spelling corrected.', $result[0]['note']);
        $this->assertSame([
            ['field' => 'destination_province', 'value' => 'Punjab', 'old' => 'Punjb'],
            ['field' => 'buyer_ntn', 'value' => '1234567', 'old' => '12-34567'],
        ], $result[0]['fixes']);

        $usage = DB::table('ai_invoice_parses')->get();
        $this->assertCount(1, $usage);
        $this->assertSame('success', $usage[0]->status);
        $this->assertSame('import_fix', $usage[0]->source_type);
    }

    // ------------------------------------------------------------------
    // Error report suggestion column
    // ------------------------------------------------------------------

    private function makeBatch(?array $aiSuggestions): InvoiceImportBatch
    {
        return InvoiceImportBatch::create([
            'company_id' => $this->company->id,
            'status' => 'validated',
            'total_rows' => 2,
            'valid_rows' => 1,
            'invalid_rows' => 1,
            'rows_json' => json_encode([
                ['row' => 2, 'data' => ['buyer_name' => 'OK Buyer'], 'valid' => true, 'errors' => []],
                ['row' => 3, 'data' => ['buyer_name' => 'Bad Buyer', 'destination_province' => 'Punjb'], 'valid' => false, 'errors' => ['Invalid province.']],
            ]),
            'ai_suggestions_json' => $aiSuggestions === null ? null : json_encode($aiSuggestions),
        ]);
    }

    private function readReport(InvoiceImportBatch $batch): array
    {
        $response = (new InvoiceImportService())->errorReportResponse($batch);
        ob_start();
        $response->sendContent();
        $bin = ob_get_clean();

        $path = tempnam(sys_get_temp_dir(), 'rep') . '.xlsx';
        file_put_contents($path, $bin);
        $sheet = \PhpOffice\PhpSpreadsheet\IOFactory::load($path)->getActiveSheet()->toArray();
        @unlink($path);

        return $sheet;
    }

    public function test_error_report_has_ai_column_only_when_suggestions_stored(): void
    {
        // Without suggestions: header shape unchanged (row_number..errors).
        $plain = $this->readReport($this->makeBatch(null));
        $this->assertSame('errors', end($plain[0]));
        $this->assertNotContains('ai_suggestion', $plain[0]);

        // With suggestions: ai_suggestion column appended, hint rendered per row.
        $withAi = $this->readReport($this->makeBatch([
            '3' => ['fixes' => [['field' => 'destination_province', 'value' => 'Punjab', 'old' => 'Punjb']], 'note' => 'Spelling.'],
        ]));
        $this->assertSame('ai_suggestion', end($withAi[0]));
        $failedRow = $withAi[1];
        $this->assertSame('destination_province -> Punjab | Spelling.', end($failedRow));
    }

    public function test_pruner_clears_ai_suggestions_json(): void
    {
        $batch = $this->makeBatch(['3' => ['fixes' => [], 'note' => 'x']]);
        $batch->update(['status' => 'completed', 'finished_at' => now()->subDays(60)]);
        InvoiceImportBatch::whereKey($batch->id)->update(['updated_at' => now()->subDays(60)]);

        $this->artisan('import-batches:prune', ['--days' => 30])->assertSuccessful();

        $fresh = $batch->fresh();
        $this->assertNull($fresh->rows_json);
        $this->assertNull($fresh->ai_suggestions_json);
    }

    // ------------------------------------------------------------------
    // Apply step: entitlement, stored-suggestions-only, status race
    // ------------------------------------------------------------------

    private function givePlan(string $planName): void
    {
        DiFeatureService::flushGateCaches();
        $planId = DB::table('pricing_plans')->insertGetId([
            'name' => $planName,
            'product_type' => 'di',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('subscriptions')->insert([
            'company_id' => $this->company->id,
            'pricing_plan_id' => $planId,
            'active' => true,
            'start_date' => now()->subMonth()->toDateString(),
            'end_date' => now()->addMonth()->toDateString(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /** Full valid template row so the re-validation pass has real data. */
    private function fullRow(array $overrides = []): array
    {
        return array_merge([
            'buyer_name' => 'Ali Traders',
            'buyer_ntn' => '7654321',
            'buyer_cnic' => '',
            'buyer_address' => '12 Test Street, Lahore',
            'destination_province' => 'Punjab',
            'document_type' => 'Sale Invoice',
            'hs_code' => '15179090',
            'description' => 'Cooking Oil',
            'quantity' => '10',
            'price' => '100',
            'tax' => '180',
            'schedule_type' => 'standard',
            'tax_rate' => '18',
            'mrp' => '',
            'sro_schedule_no' => '',
            'sro_serial_no' => '',
            'reference_invoice_number' => '',
        ], $overrides);
    }

    /** Batch with one bad-province row + a stored AI suggestion fixing it. */
    private function makeFixableBatch(): InvoiceImportBatch
    {
        return InvoiceImportBatch::create([
            'company_id' => $this->company->id,
            'status' => 'validated',
            'total_rows' => 2,
            'valid_rows' => 1,
            'invalid_rows' => 1,
            'rows_json' => json_encode([
                ['row' => 2, 'data' => $this->fullRow(), 'valid' => true, 'errors' => []],
                ['row' => 3, 'data' => $this->fullRow(['destination_province' => 'Punjb']), 'valid' => false, 'errors' => ['Invalid province.']],
            ]),
            'ai_suggestions_json' => json_encode([
                '3' => ['fixes' => [['field' => 'destination_province', 'value' => 'Punjab', 'old' => 'Punjb']], 'note' => 'Spelling.'],
            ]),
        ]);
    }

    private function callApply(int $batchId, array $fixes)
    {
        app()->instance('currentCompanyId', $this->company->id);
        $request = Request::create('/invoices/import/' . $batchId . '/apply-fixes', 'POST', ['fixes' => $fixes]);

        return (new InvoiceImportController())->applyRowFixes($request, $batchId);
    }

    public function test_apply_fixes_requires_ai_reader_plan(): void
    {
        $this->givePlan('Retail'); // not Premium — ai_reader gate closed
        $batch = $this->makeFixableBatch();

        $response = $this->callApply($batch->id, [['row' => 3, 'fields' => ['destination_province' => 'Punjab']]]);

        $this->assertSame(403, $response->getStatusCode());
        $this->assertSame('Punjb', json_decode($batch->fresh()->rows_json, true)[1]['data']['destination_province']);
    }

    public function test_apply_fixes_accepts_only_server_stored_suggestion_values(): void
    {
        $this->givePlan('Premium');
        $batch = $this->makeFixableBatch();

        // Not the stored value / not a suggested field — must be refused, not applied.
        $response = $this->callApply($batch->id, [
            ['row' => 3, 'fields' => ['destination_province' => 'Sindh', 'buyer_ntn' => '9999999']],
            ['row' => 2, 'fields' => ['price' => '1']],
        ]);

        $this->assertSame(422, $response->getStatusCode());
        $rows = json_decode($batch->fresh()->rows_json, true);
        $this->assertSame('100', $rows[0]['data']['price']);
        $this->assertSame('Punjb', $rows[1]['data']['destination_province']);
    }

    public function test_apply_fixes_refused_once_processing_claimed_the_batch(): void
    {
        $this->givePlan('Premium');
        $batch = $this->makeFixableBatch();
        $batch->update(['status' => 'queued']); // process() already claimed it

        $response = $this->callApply($batch->id, [['row' => 3, 'fields' => ['destination_province' => 'Punjab']]]);

        $this->assertSame(409, $response->getStatusCode());
        $this->assertSame('Punjb', json_decode($batch->fresh()->rows_json, true)[1]['data']['destination_province']);
    }

    public function test_apply_fixes_applies_stored_suggestion_and_revalidates_all_rows(): void
    {
        $this->givePlan('Premium');
        $batch = $this->makeFixableBatch();

        $response = $this->callApply($batch->id, [['row' => 3, 'fields' => ['destination_province' => 'Punjab']]]);

        $this->assertSame(200, $response->getStatusCode());
        $payload = $response->getData(true);
        $this->assertSame(1, $payload['applied']);
        $this->assertSame(2, $payload['valid_count']);
        $this->assertSame(0, $payload['error_count']);
        $this->assertNull($payload['error_report_url']);

        $fresh = $batch->fresh();
        $this->assertSame('validated', $fresh->status);
        $this->assertSame(2, (int) $fresh->valid_rows);
        $rows = json_decode($fresh->rows_json, true);
        $this->assertSame('Punjab', $rows[1]['data']['destination_province']);
        $this->assertTrue((bool) $rows[1]['valid']);
        $this->assertSame([], $rows[1]['errors']);
    }
}
