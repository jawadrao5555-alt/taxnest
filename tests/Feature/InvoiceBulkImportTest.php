<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Services\InvoiceImportService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx as XlsxWriter;
use Tests\TestCase;

/**
 * TASK #137: DI bulk import — .xlsx parsing, shared row-level FBR
 * pre-validation, SRO/MRP auto-fill and draft creation.
 *
 * Locks the invariants that make bulk import safe:
 *   - Code columns (NTN, CNIC, HS) survive Excel float/scientific mangling.
 *   - Template sample rows are skipped; row cap enforced with friendly error.
 *   - Validation matches FBR submit-time rules (exempt tax=0, credit note
 *     reference, one schedule type per buyer group, SRO/serial/MRP presence).
 *   - Drafts are created per buyer group with resolved SRO/serial/MRP so the
 *     existing FBR submit flow does not block them.
 */
class InvoiceBulkImportTest extends TestCase
{
    private InvoiceImportService $service;
    private Company $company;

    protected function setUp(): void
    {
        parent::setUp();

        Schema::dropAllTables();

        Schema::create('companies', function (Blueprint $t) {
            $t->id();
            $t->string('name');
            $t->string('fbr_registration_no')->nullable();
            $t->unsignedInteger('next_invoice_number')->default(1);
            $t->string('province')->nullable();
            $t->decimal('standard_tax_rate', 5, 2)->nullable();
            $t->softDeletes();
            $t->timestamps();
        });

        Schema::create('branches', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('company_id')->index();
            $t->string('name');
            $t->string('code', 20)->nullable();
            $t->string('address')->nullable();
            $t->string('city', 100)->nullable();
            $t->string('province')->nullable();
            $t->boolean('is_head_office')->default(false);
            $t->boolean('is_active')->default(true);
            $t->timestamps();
        });

        Schema::create('invoices', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('company_id');
            $t->string('invoice_number')->nullable();
            $t->string('internal_invoice_number')->nullable();
            $t->string('fbr_invoice_number')->nullable();
            $t->string('buyer_name')->nullable();
            $t->string('buyer_ntn')->nullable();
            $t->string('buyer_cnic')->nullable();
            $t->string('buyer_address', 500)->nullable();
            $t->string('buyer_registration_type')->nullable();
            $t->decimal('total_amount', 15, 2)->default(0);
            $t->decimal('total_value_excluding_st', 15, 2)->default(0);
            $t->decimal('total_sales_tax', 15, 2)->default(0);
            $t->decimal('wht_rate', 8, 2)->default(0);
            $t->decimal('wht_amount', 15, 2)->default(0);
            $t->decimal('net_receivable', 15, 2)->default(0);
            $t->string('status')->default('draft');
            $t->string('fbr_status')->nullable();
            $t->string('document_type')->nullable();
            $t->unsignedBigInteger('branch_id')->nullable();
            $t->string('reference_invoice_number')->nullable();
            $t->string('destination_province')->nullable();
            $t->string('supplier_province')->nullable();
            $t->date('invoice_date')->nullable();
            $t->string('share_uuid')->nullable();
            $t->timestamps();
        });

        Schema::create('invoice_items', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('invoice_id');
            $t->string('hs_code')->nullable();
            $t->string('schedule_type')->nullable();
            $t->string('pct_code')->nullable();
            $t->decimal('tax_rate', 8, 2)->nullable();
            $t->string('sro_schedule_no')->nullable();
            $t->string('serial_no')->nullable();
            $t->decimal('mrp', 15, 2)->nullable();
            $t->string('default_uom')->nullable();
            $t->string('sale_type')->nullable();
            $t->boolean('st_withheld_at_source')->default(false);
            $t->decimal('petroleum_levy', 15, 2)->nullable();
            $t->string('description', 500)->nullable();
            $t->decimal('quantity', 15, 4)->default(0);
            $t->decimal('price', 15, 2)->default(0);
            $t->decimal('tax', 15, 2)->default(0);
            $t->timestamps();
        });

        Schema::create('invoice_activity_logs', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('invoice_id');
            $t->unsignedBigInteger('company_id');
            $t->unsignedBigInteger('user_id')->nullable();
            $t->string('action');
            $t->text('changes_json')->nullable();
            $t->string('ip_address')->nullable();
            $t->timestamps();
        });

        Schema::create('audit_logs', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('company_id')->nullable();
            $t->unsignedBigInteger('user_id')->nullable();
            $t->string('action');
            $t->string('entity_type')->nullable();
            $t->unsignedBigInteger('entity_id')->nullable();
            $t->text('old_values')->nullable();
            $t->text('new_values')->nullable();
            $t->string('ip_address')->nullable();
            $t->string('sha256_hash')->nullable();
            $t->timestamps();
        });

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

        $this->service = new InvoiceImportService();
        $this->company = Company::create([
            'name' => 'Import Test Co',
            'fbr_registration_no' => '1234567',
            'next_invoice_number' => 1,
            'province' => 'Punjab',
            'standard_tax_rate' => 18.0,
        ]);
    }

    // ------------------------------------------------------------------
    // Helpers
    // ------------------------------------------------------------------

    private function row(array $overrides = []): array
    {
        return array_merge([
            'buyer_name' => 'Buyer One',
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

    private function parsedRows(array $rows): array
    {
        $out = [];
        foreach ($rows as $i => $row) {
            $out[] = ['row' => $i + 2, 'data' => $row];
        }
        return $out;
    }

    private function prepareMappingWorkflow(): void
    {
        foreach (['create_invoice_import_mappings', 'create_invoice_import_batches'] as $name) {
            $files = glob(database_path('migrations/*' . $name . '_table.php'));
            (require $files[0])->up();
        }
        app()->instance('currentCompanyId', $this->company->id);
        \Illuminate\Support\Facades\Route::post('/_test/import-upload', [\App\Http\Controllers\InvoiceImportController::class, 'upload']);
        \Illuminate\Support\Facades\Route::post('/_test/import-map', [\App\Http\Controllers\InvoiceImportController::class, 'applyMapping']);
    }

    private function mappingPreset(int $companyId, string $name, array $mapping): \App\Models\InvoiceImportMapping
    {
        return \App\Models\InvoiceImportMapping::create([
            'company_id' => $companyId, 'name' => $name,
            'mapping_json' => json_encode($mapping),
            'defaults_json' => json_encode(['buyer_address' => 'Local test address', 'destination_province' => 'Punjab']),
        ]);
    }

    private function mappingUpload(string $csv = "Outlet,Qty,Item,HS,Rate,GST\nAlpha,2,Beef,02023000,100,36\n"): \Illuminate\Testing\TestResponse
    {
        return $this->post('/_test/import-upload', [
            'import_file' => \Illuminate\Http\UploadedFile::fake()->createWithContent('local-test.csv', $csv),
        ], ['Accept' => 'application/json']);
    }

    public function test_smart_mapping_recommends_only_current_company_and_preserves_defaults(): void
    {
        $this->prepareMappingWorkflow();
        config(['features.enable_smart_di_import_mapping' => true]);
        $foreign = $this->mappingPreset($this->company->id + 1, 'Foreign', ['buyer_name' => 'Outlet']);
        $own = $this->mappingPreset($this->company->id, 'Own', ['buyer_name' => 'Outlet', 'quantity' => 'Qty']);
        $response = $this->mappingUpload()->assertOk()->assertJsonPath('recommended_preset_id', $own->id)
            ->assertJsonPath('smart_mapping_enabled', true)->assertJsonCount(1, 'presets')
            ->assertJsonPath('presets.0.defaults.destination_province', 'Punjab');
        $this->assertSame($own->id, $response->json('presets.0.id'));
        $this->assertNotSame($foreign->id, $response->json('presets.0.id'));
        $this->assertSame(0, \App\Models\InvoiceImportBatch::count());
        $this->assertSame(0, Invoice::count());
        $this->postJson('/_test/import-map', ['mapping_token' => $response->json('mapping_token'), 'preset_id' => $foreign->id])
            ->assertNotFound();
        // A token belongs to its company even when the preset ID is omitted.
        app()->instance('currentCompanyId', $this->company->id + 1);
        Company::create(['id' => $this->company->id + 1, 'name' => 'Second test tenant']);
        $this->postJson('/_test/import-map', ['mapping_token' => $response->json('mapping_token'), 'mapping' => ['buyer_name' => 'Outlet']])
            ->assertStatus(422);
    }

    public function test_multiple_matching_presets_require_selection_and_flag_off_keeps_legacy_response(): void
    {
        $this->prepareMappingWorkflow();
        $this->mappingPreset($this->company->id, 'One', ['buyer_name' => 'Outlet']);
        $this->mappingPreset($this->company->id, 'Two', ['buyer_name' => 'Outlet']);
        config(['features.enable_smart_di_import_mapping' => true]);
        $this->mappingUpload()->assertOk()->assertJsonPath('recommended_preset_id', null)
            ->assertJsonPath('format_match_reason', 'ambiguous_presets');
        config(['features.enable_smart_di_import_mapping' => false]);
        $this->mappingUpload()->assertOk()->assertJsonPath('format_match_reason', 'disabled')
            ->assertJsonPath('smart_mapping_enabled', false)->assertJsonPath('presets.0.matches', true)
            ->assertJsonPath('presets.0.mapping', null);
    }

    public function test_reviewed_manual_correction_uses_existing_validation_and_does_not_overwrite_preset(): void
    {
        $this->prepareMappingWorkflow();
        config(['features.enable_smart_di_import_mapping' => true]);
        $saved = $this->mappingPreset($this->company->id, 'Saved', ['quantity' => 'Old quantity']);
        $upload = $this->mappingUpload()->assertOk()->assertJsonPath('recommended_preset_id', null)
            ->assertJsonPath('format_match_reason', 'no_exact_match');
        $this->postJson('/_test/import-map', ['mapping_token' => $upload->json('mapping_token'), 'preset_id' => $saved->id])
            ->assertStatus(422);
        $mapping = ['buyer_name' => 'Outlet', 'quantity' => 'Qty', 'description' => 'Item',
            'hs_code' => 'HS', 'price' => 'Rate', 'tax' => 'GST'];
        $response = $this->postJson('/_test/import-map', [
            'mapping_token' => $upload->json('mapping_token'), 'mapping' => $mapping,
            'defaults' => ['buyer_address' => 'Local test address', 'destination_province' => 'Punjab', 'document_type' => 'Sale Invoice'],
        ]);
        $this->assertSame(200, $response->status(), $response->getContent());
        $response->assertJsonPath('total', 1)->assertJsonStructure(['batch_id', 'preview', 'valid_count', 'error_count']);
        $this->assertSame(['quantity' => 'Old quantity'], $saved->fresh()->mappingArray());
        $this->assertSame('validated', \App\Models\InvoiceImportBatch::first()->status);
        $this->assertSame(0, Invoice::count(), 'Mapping confirmation creates a preview batch, never invoices or FBR submissions');
    }

    public function test_duplicate_mapped_headers_block_preset_apply_and_keep_upload_for_correction(): void
    {
        $this->prepareMappingWorkflow();
        config(['features.enable_smart_di_import_mapping' => true]);
        $preset = $this->mappingPreset($this->company->id, 'Duplicate', ['buyer_name' => 'Outlet']);
        $upload = $this->mappingUpload("Outlet,out-let,Qty\nAlpha,Beta,2\n")->assertOk()
            ->assertJsonPath('recommended_preset_id', null)->assertJsonPath('presets.0.matches', false);
        $payload = ['mapping_token' => $upload->json('mapping_token'), 'preset_id' => $preset->id];
        $first = $this->postJson('/_test/import-map', $payload)->assertStatus(422);
        $this->assertStringContainsString('duplicate headers', $first->json('error'));
        $this->postJson('/_test/import-map', $payload)->assertStatus(422)->assertJsonPath('error', $first->json('error'));
        $this->assertSame(0, \App\Models\InvoiceImportBatch::count());
    }

    public function test_daily_exports_reuse_company_preset_after_header_reordering_and_punctuation_changes(): void
    {
        $this->prepareMappingWorkflow();
        config(['features.enable_smart_di_import_mapping' => true]);
        $preset = $this->mappingPreset($this->company->id, 'Daily export', [
            'buyer_name' => 'Outlet', 'quantity' => 'Qty', 'description' => 'Item',
            'hs_code' => 'HS', 'price' => 'Rate', 'tax' => 'GST',
            'source_invoice_number' => 'Source Bill', 'invoice_date' => 'Supply Date',
        ]);
        $preset->update(['defaults_json' => json_encode([
            'buyer_address' => 'Local test address', 'destination_province' => 'Punjab', 'document_type' => 'Sale Invoice',
        ])]);
        $upload = $this->mappingUpload("Supply-Date,GST,Rate,HS,Item,Qty,Outlet,Source-Bill\n2026-10-10,36,100,02023000,Beef,2,Alpha,000123\n")
            ->assertOk()->assertJsonPath('recommended_preset_id', $preset->id);
        $this->postJson('/_test/import-map', [
            'mapping_token' => $upload->json('mapping_token'), 'preset_id' => $preset->id,
        ])->assertOk()->assertJsonPath('total', 1)
            ->assertJsonPath('preview.0.data.source_invoice_number', '000123')
            ->assertJsonPath('preview.0.data.invoice_date', '2026-10-10');
        $this->assertSame(0, Invoice::count());
        $this->assertSame('Source Bill', $preset->fresh()->mappingArray()['source_invoice_number']);
    }

    public function test_reused_source_column_blocks_manual_mapping_and_preserves_hold_for_correction(): void
    {
        $this->prepareMappingWorkflow();
        config(['features.enable_smart_di_import_mapping' => true]);
        $upload = $this->mappingUpload()->assertOk();
        $mapping = ['buyer_name' => 'Outlet', 'quantity' => 'Qty', 'description' => 'Item',
            'hs_code' => 'HS', 'price' => 'Rate', 'tax' => 'rate'];
        $payload = [
            'mapping_token' => $upload->json('mapping_token'), 'mapping' => $mapping,
            'defaults' => ['buyer_address' => 'Local test address', 'destination_province' => 'Punjab', 'document_type' => 'Sale Invoice'],
        ];
        $response = $this->postJson('/_test/import-map', $payload)->assertStatus(422);
        $this->assertStringContainsString('mapped to both price and tax', $response->json('error'));
        $this->assertSame(0, \App\Models\InvoiceImportBatch::count());
        $payload['mapping']['tax'] = 'GST';
        $this->postJson('/_test/import-map', $payload)->assertOk()->assertJsonPath('total', 1);
        $this->assertSame(0, Invoice::count());
    }

    private function writeXlsx(array $header, array $rows): string
    {
        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        foreach ($header as $c => $col) {
            $sheet->setCellValue([$c + 1, 1], $col);
        }
        foreach ($rows as $r => $row) {
            foreach ($row as $c => $value) {
                if (is_array($value)) {
                    // ['explicit_string', 'value'] — force a string cell
                    $sheet->setCellValueExplicit([$c + 1, $r + 2], $value[1], DataType::TYPE_STRING);
                } else {
                    $sheet->setCellValue([$c + 1, $r + 2], $value);
                }
            }
        }
        $path = tempnam(sys_get_temp_dir(), 'imp') . '.xlsx';
        (new XlsxWriter($spreadsheet))->save($path);
        $spreadsheet->disconnectWorksheets();
        return $path;
    }

    // ------------------------------------------------------------------
    // Parsing: xlsx code-column survival
    // ------------------------------------------------------------------

    public function test_xlsx_roundtrip_preserves_code_columns(): void
    {
        $header = array_merge(InvoiceImportService::REQUIRED_COLUMNS, InvoiceImportService::OPTIONAL_COLUMNS);
        $byName = array_flip($header);

        $row = array_fill(0, count($header), '');
        $row[$byName['buyer_name']] = 'Codes Co';
        $row[$byName['buyer_ntn']] = 8901234567890;            // numeric cell → float in PhpSpreadsheet
        $row[$byName['buyer_cnic']] = '4.22011234567E+12';     // scientific-notation string (CSV → Excel damage)
        $row[$byName['buyer_address']] = 'Addr 1';
        $row[$byName['destination_province']] = 'Punjab';
        $row[$byName['document_type']] = 'Sale Invoice';
        $row[$byName['hs_code']] = ['explicit_string', '02023000']; // leading zero must survive
        $row[$byName['description']] = 'Frozen beef';
        $row[$byName['quantity']] = 5;
        $row[$byName['price']] = 100;
        $row[$byName['tax']] = 0;
        $row[$byName['schedule_type']] = 'exempt';
        $row[$byName['tax_rate']] = 0;

        $path = $this->writeXlsx($header, [$row]);
        $parsed = $this->service->parseFile($path, 'xlsx');
        @unlink($path);

        $this->assertArrayNotHasKey('error', $parsed, json_encode($parsed));
        $this->assertCount(1, $parsed['rows']);
        $data = $parsed['rows'][0]['data'];

        $this->assertSame('8901234567890', $data['buyer_ntn'], 'float NTN must come back as digit string');
        $this->assertSame('4220112345670', $data['buyer_cnic'], 'scientific notation must be restored to digits');
        $this->assertSame('02023000', $data['hs_code'], 'leading zero HS code must survive');
    }

    public function test_template_headers_complete_and_samples_skipped(): void
    {
        $response = $this->service->templateResponse();
        ob_start();
        $response->sendContent();
        $content = ob_get_clean();

        $path = tempnam(sys_get_temp_dir(), 'tpl') . '.xlsx';
        file_put_contents($path, $content);

        $parsed = $this->service->parseFile($path, 'xlsx');
        @unlink($path);

        // All 3 sample rows are recognized and skipped → "no data rows" error,
        // NOT a missing-columns error (which would mean a broken header).
        $this->assertArrayHasKey('error', $parsed);
        $this->assertStringContainsString('No data rows', $parsed['error']);
    }

    public function test_row_cap_returns_friendly_error(): void
    {
        $header = implode(',', InvoiceImportService::REQUIRED_COLUMNS);
        $lines = [$header];
        for ($i = 0; $i < 8; $i++) {
            $lines[] = "Buyer {$i},,,Addr,Punjab,Sale Invoice,15179090,Item,1,100,18,standard,18";
        }
        $path = tempnam(sys_get_temp_dir(), 'cap') . '.csv';
        file_put_contents($path, implode("\n", $lines));

        $parsed = $this->service->parseFile($path, 'csv', 5);
        @unlink($path);

        $this->assertArrayHasKey('error', $parsed);
        $this->assertStringContainsString('more than 5', $parsed['error']);
    }

    public function test_csv_with_semicolon_delimiter_parses(): void
    {
        $header = implode(';', InvoiceImportService::REQUIRED_COLUMNS);
        $line = 'Buyer X;;;Addr 9;Punjab;Sale Invoice;15179090;Item;2;50;18;standard;18';
        $path = tempnam(sys_get_temp_dir(), 'semi') . '.csv';
        file_put_contents($path, $header . "\n" . $line);

        $parsed = $this->service->parseFile($path, 'csv');
        @unlink($path);

        $this->assertArrayNotHasKey('error', $parsed, json_encode($parsed));
        $this->assertSame('Buyer X', $parsed['rows'][0]['data']['buyer_name']);
    }

    // ------------------------------------------------------------------
    // Validation rules
    // ------------------------------------------------------------------

    public function test_validation_rules_matrix(): void
    {
        $result = $this->service->validateRows($this->parsedRows([
            $this->row(['buyer_name' => '']),                                   // 0: missing buyer
            $this->row(['destination_province' => 'KPK']),                      // 1: alias → canonical
            $this->row(['schedule_type' => 'exempt', 'tax' => '100', 'tax_rate' => '0', 'buyer_name' => 'Ex Co']), // 2: exempt with tax
            $this->row(['document_type' => 'Credit Note', 'buyer_name' => 'CN Co']),  // 3: credit note without reference
            $this->row(['buyer_ntn' => '12345']),                               // 4: short NTN
            $this->row(['quantity' => '0', 'buyer_name' => 'Qty Co']),          // 5: zero qty
            $this->row(['tax' => '500', 'buyer_name' => 'Mismatch Co']),        // 6: tax ≠ rate×base (expected 180)
        ]), $this->company);

        $rows = $result['rows'];

        $this->assertFalse($rows[0]['valid']);
        $this->assertStringContainsString('buyer_name', implode(' ', $rows[0]['errors']));

        $this->assertTrue($rows[1]['valid'], implode('; ', $rows[1]['errors']));
        $this->assertSame('Khyber Pakhtunkhwa', $rows[1]['data']['destination_province']);

        $this->assertFalse($rows[2]['valid']);
        $this->assertStringContainsString('Exempt items must have tax = 0', implode(' ', $rows[2]['errors']));

        $this->assertFalse($rows[3]['valid']);
        $this->assertStringContainsString('reference_invoice_number', implode(' ', $rows[3]['errors']));

        $this->assertFalse($rows[4]['valid']);
        $this->assertStringContainsString('buyer_ntn', implode(' ', $rows[4]['errors']));

        $this->assertFalse($rows[5]['valid']);
        $this->assertStringContainsString('quantity', implode(' ', $rows[5]['errors']));

        $this->assertFalse($rows[6]['valid']);
        $this->assertStringContainsString('does not match tax_rate', implode(' ', $rows[6]['errors']));
    }

    public function test_credit_note_with_existing_reference_is_valid(): void
    {
        Invoice::withoutGlobalScopes()->create([
            'company_id' => $this->company->id,
            'invoice_number' => '1234567DI00001',
            'internal_invoice_number' => '1234567DI00001',
            'buyer_name' => 'Original Buyer',
            'status' => 'submitted',
        ]);

        $result = $this->service->validateRows($this->parsedRows([
            $this->row([
                'document_type' => 'Credit Note',
                'reference_invoice_number' => '1234567DI00001',
            ]),
        ]), $this->company);

        $this->assertTrue($result['rows'][0]['valid'], implode('; ', $result['rows'][0]['errors']));
        $this->assertSame(1, $result['valid_count']);
    }

    public function test_mixed_schedule_types_in_one_buyer_group_invalid(): void
    {
        $result = $this->service->validateRows($this->parsedRows([
            $this->row(['schedule_type' => 'standard']),
            $this->row(['schedule_type' => 'exempt', 'tax' => '0', 'tax_rate' => '0', 'hs_code' => '02023000']),
        ]), $this->company);

        $this->assertSame(0, $result['valid_count']);
        foreach ($result['rows'] as $row) {
            $this->assertStringContainsString('Mixed schedule types', implode(' ', $row['errors']));
        }
    }

    public function test_preflagged_rows_pass_through(): void
    {
        $result = $this->service->validateRows([
            ['row' => 2, 'data' => [], 'errors' => ['Column count mismatch. Expected 13, got 4']],
            ['row' => 3, 'data' => $this->row()],
        ], $this->company);

        $this->assertFalse($result['rows'][0]['valid']);
        $this->assertStringContainsString('Column count mismatch', $result['rows'][0]['errors'][0]);
        $this->assertTrue($result['rows'][1]['valid']);
    }

    // ------------------------------------------------------------------
    // Auto-fill: SRO / serial / MRP
    // ------------------------------------------------------------------

    public function test_exempt_row_autofills_sro_and_serial(): void
    {
        $result = $this->service->validateRows($this->parsedRows([
            $this->row([
                'schedule_type' => 'exempt',
                'hs_code' => '02023000',
                'tax' => '0',
                'tax_rate' => '0',
            ]),
        ]), $this->company);

        $row = $result['rows'][0];
        $this->assertTrue($row['valid'], implode('; ', $row['errors']));
        $this->assertNotSame('', $row['data']['sro_schedule_no'], 'exempt SRO must be auto-filled');
    }

    public function test_reduced_row_autofills_sro_and_serial(): void
    {
        $result = $this->service->validateRows($this->parsedRows([
            $this->row([
                'schedule_type' => 'reduced',
                'hs_code' => '48191000',
                'tax' => '10',
                'tax_rate' => '1',
            ]),
        ]), $this->company);

        $row = $result['rows'][0];
        $this->assertTrue($row['valid'], implode('; ', $row['errors']));
        $this->assertNotSame('', $row['data']['sro_schedule_no'], 'reduced SRO must be auto-filled');
        $this->assertNotSame('', $row['data']['sro_serial_no'], 'reduced serial must be auto-filled');
    }

    public function test_third_schedule_mrp_defaults_to_price(): void
    {
        $result = $this->service->validateRows($this->parsedRows([
            $this->row([
                'schedule_type' => '3rd_schedule',
                'hs_code' => '85171100',
                'quantity' => '2',
                'price' => '40000',
                'tax' => '13600',
                'tax_rate' => '17',
                'mrp' => '',
            ]),
        ]), $this->company);

        $row = $result['rows'][0];
        $this->assertTrue($row['valid'], implode('; ', $row['errors']));
        $this->assertSame('40000', $row['data']['mrp'], '3rd schedule MRP must default to price');
    }

    public function test_third_schedule_explicit_mrp_controls_supplied_and_derived_tax_rate(): void
    {
        foreach (['18', ''] as $rate) {
            $result = $this->service->validateRows($this->parsedRows([
                $this->row(['schedule_type' => '3rd_schedule', 'hs_code' => '85171100',
                    'quantity' => '12', 'price' => '100', 'tax' => '324', 'tax_rate' => $rate, 'mrp' => '150']),
            ]), $this->company);
            $row = $result['rows'][0];
            $this->assertTrue($row['valid'], implode('; ', $row['errors']));
            $this->assertSame('18', $row['data']['tax_rate']);
            $this->assertSame('150', $row['data']['mrp']);
            $this->assertSame('100', $row['data']['price']);
        }
    }

    // ------------------------------------------------------------------
    // Draft creation
    // ------------------------------------------------------------------

    // ------------------------------------------------------------------
    // Task 404: plain 'Services' (SN019) — importer acceptance + province rate
    // ------------------------------------------------------------------

    public function test_services_row_accepted_and_defaults_to_company_province_rate(): void
    {
        // Company province = Punjab (setUp) → services default 16%
        $rows = $this->parsedRows([$this->row([
            'hs_code' => '98159000',
            'description' => 'Consultancy services',
            'schedule_type' => 'services',
            'price' => '0', // subtotal 0 → tax_rate falls back to the schedule/province default
            'tax_rate' => '',
            'tax' => '0',
        ])]);
        $result = $this->service->validateRows($rows, $this->company);
        $this->assertSame([], $result['rows'][0]['errors']);
        $this->assertSame('services', $result['rows'][0]['data']['schedule_type']);
        $this->assertSame('Services', $result['rows'][0]['data']['_sale_type']);
        $this->assertEquals(16, (float) $result['rows'][0]['data']['tax_rate']);
    }

    public function test_services_row_defaults_to_sindh_rate_for_sindh_company(): void
    {
        $sindhCo = Company::create([
            'name' => 'Sindh Services Co',
            'fbr_registration_no' => '7654321',
            'next_invoice_number' => 1,
            'province' => 'Sindh',
            'standard_tax_rate' => 18.0,
        ]);
        $rows = $this->parsedRows([$this->row([
            'hs_code' => '98159000',
            'description' => 'Consultancy services',
            'schedule_type' => 'services',
            'price' => '0', // subtotal 0 → tax_rate falls back to the schedule/province default
            'tax_rate' => '',
            'tax' => '0',
        ])]);
        $result = $this->service->validateRows($rows, $sindhCo);
        $this->assertSame('services', $result['rows'][0]['data']['schedule_type']);
        $this->assertEquals(15, (float) $result['rows'][0]['data']['tax_rate']);
    }

    public function test_services_row_keeps_explicit_rate(): void
    {
        // Punjab company but user explicitly enters 15% — user rate wins.
        $rows = $this->parsedRows([$this->row([
            'hs_code' => '98159000',
            'description' => 'Consultancy services',
            'schedule_type' => 'services',
            'tax_rate' => '15',
            'tax' => '150',
        ])]);
        $result = $this->service->validateRows($rows, $this->company);
        $this->assertEquals(15, (float) $result['rows'][0]['data']['tax_rate']);
    }

    public function test_fed_services_row_accepted(): void
    {
        $rows = $this->parsedRows([$this->row([
            'hs_code' => '98159000',
            'description' => 'FED services',
            'schedule_type' => 'fed_services',
            'tax_rate' => '16',
            'tax' => '160',
        ])]);
        $result = $this->service->validateRows($rows, $this->company);
        $this->assertSame('fed_services', $result['rows'][0]['data']['schedule_type']);
        $this->assertSame('Services (FED in ST Mode)', $result['rows'][0]['data']['_sale_type']);
    }

    public function test_create_drafts_groups_rows_and_persists_resolved_fields(): void
    {
        $result = $this->service->validateRows($this->parsedRows([
            $this->row(['description' => 'Item A1']),
            $this->row(['description' => 'Item A2', 'hs_code' => '48191000', 'tax' => '180']),
            $this->row([
                'buyer_name' => 'Buyer Two',
                'buyer_ntn' => '',
                'buyer_cnic' => '4220112345671',
                'schedule_type' => 'exempt',
                'hs_code' => '02023000',
                'tax' => '0',
                'tax_rate' => '0',
                'description' => 'Item B1',
            ]),
        ]), $this->company);

        $this->assertSame(3, $result['valid_count'], json_encode($result['rows']));

        $validRows = array_values(array_filter($result['rows'], fn ($r) => $r['valid']));
        $outcome = $this->service->createDraftsFromRows($validRows, $this->company, null, 'bulk_import');

        $this->assertSame(2, $outcome['created_count'], json_encode($outcome['row_errors']));
        $this->assertSame(0, $outcome['failed_rows']);
        $this->assertSame(3, $outcome['processed_rows']);

        $invoices = Invoice::withoutGlobalScopes()->orderBy('id')->get();
        $this->assertCount(2, $invoices);

        $invA = $invoices[0];
        $this->assertSame('D0001', $invA->invoice_number);
        $this->assertSame('draft', $invA->status);
        $this->assertNull($invA->fbr_status);
        $this->assertSame('Registered', $invA->buyer_registration_type);
        $this->assertEqualsWithDelta(2000.0, (float) $invA->total_value_excluding_st, 0.01);
        $this->assertEqualsWithDelta(360.0, (float) $invA->total_sales_tax, 0.01);
        $this->assertEqualsWithDelta(2360.0, (float) $invA->total_amount, 0.01);

        $invB = $invoices[1];
        $this->assertSame('D0002', $invB->invoice_number);
        $this->assertSame('4220112345671', $invB->buyer_cnic);

        $itemsA = InvoiceItem::where('invoice_id', $invA->id)->get();
        $this->assertCount(2, $itemsA);

        $itemsB = InvoiceItem::where('invoice_id', $invB->id)->get();
        $this->assertCount(1, $itemsB);
        $this->assertNotNull($itemsB[0]->sro_schedule_no, 'resolved SRO must be persisted');
        $this->assertSame('exempt', $itemsB[0]->schedule_type);

        $this->assertSame(2, DB::table('invoice_activity_logs')->count());
        $this->assertSame(2, DB::table('audit_logs')->where('action', 'invoice_created')->count());
    }

    public function test_create_drafts_respects_max_invoices_cap(): void
    {
        $result = $this->service->validateRows($this->parsedRows([
            $this->row(['buyer_name' => 'Cap One']),
            $this->row(['buyer_name' => 'Cap Two']),
            $this->row(['buyer_name' => 'Cap Three']),
        ]), $this->company);

        $validRows = array_values(array_filter($result['rows'], fn ($r) => $r['valid']));
        $outcome = $this->service->createDraftsFromRows($validRows, $this->company, null, 'bulk_import', 1);

        $this->assertSame(1, $outcome['created_count']);
        $this->assertSame(2, $outcome['failed_rows']);
        $this->assertCount(2, $outcome['row_errors']);
        $this->assertStringContainsString('Invoice limit reached', $outcome['row_errors'][0]['errors'][0]);
        $this->assertCount(1, Invoice::withoutGlobalScopes()->get());
    }

    // ------------------------------------------------------------------
    // TASK #1230: DMS-export column mapping
    // ------------------------------------------------------------------

    public function test_parse_file_captures_headers_on_mismatch_only_when_asked(): void
    {
        $header = ['Customer Name', 'Party Address', 'HS Code', 'Product Name', 'Qty', 'Rate', 'GST Amount'];
        $path = $this->writeXlsx($header, [
            ['Alpha Store', 'Addr 1', ['explicit_string', '15179090'], 'Oil', 5, 100, 90],
        ]);

        // Without the flag: same missing-columns error as before.
        $plain = $this->service->parseFile($path, 'xlsx');
        $this->assertArrayHasKey('error', $plain);
        $this->assertStringContainsString('Missing required columns', $plain['error']);

        // With the flag: needs_mapping + the original header strings.
        $captured = $this->service->parseFile($path, 'xlsx', InvoiceImportService::MAX_ROWS, true);
        $this->assertTrue($captured['needs_mapping'] ?? false);
        $this->assertSame($header, $captured['headers']);
        @unlink($path);
    }

    public function test_template_matching_file_skips_mapping_even_with_capture_flag(): void
    {
        $header = array_merge(InvoiceImportService::REQUIRED_COLUMNS, InvoiceImportService::OPTIONAL_COLUMNS);
        $row = $this->row();
        $path = $this->writeXlsx($header, [array_values(array_merge(array_fill_keys($header, ''), $row))]);

        $parsed = $this->service->parseFile($path, 'xlsx', InvoiceImportService::MAX_ROWS, true);
        $this->assertArrayNotHasKey('needs_mapping', $parsed);
        $this->assertArrayHasKey('rows', $parsed);
        $this->assertCount(1, $parsed['rows']);
        @unlink($path);
    }

    public function test_suggest_mapping_uses_aliases_and_fuzzy_matching(): void
    {
        $headers = [
            'Customer Name',   // alias -> buyer_name
            'NTN No',          // alias -> buyer_ntn
            'Party Address',   // fuzzy -> buyer_address
            'HS Code',         // exact field name
            'Product Name',    // alias -> description
            'Qty',             // alias -> quantity
            'Rate',            // alias -> price
            'GST Amount',      // alias -> tax
            'Route Code',      // DMS noise — must stay unmapped
        ];

        $suggestions = $this->service->suggestMapping($headers);

        $this->assertSame('Customer Name', $suggestions['buyer_name'] ?? null);
        $this->assertSame('NTN No', $suggestions['buyer_ntn'] ?? null);
        $this->assertSame('Party Address', $suggestions['buyer_address'] ?? null);
        $this->assertSame('HS Code', $suggestions['hs_code'] ?? null);
        $this->assertSame('Product Name', $suggestions['description'] ?? null);
        $this->assertSame('Qty', $suggestions['quantity'] ?? null);
        $this->assertSame('Rate', $suggestions['price'] ?? null);
        $this->assertSame('GST Amount', $suggestions['tax'] ?? null);
        $this->assertNotContains('Route Code', $suggestions);

        // Each header can only feed one field.
        $this->assertSame(count($suggestions), count(array_unique($suggestions)));
    }

    public function test_parse_file_with_mapping_remaps_defaults_and_keeps_codes_string_safe(): void
    {
        $header = ['Customer Name', 'NTN No', 'HS Code', 'Product Name', 'Qty', 'Rate', 'GST Amount'];
        $path = $this->writeXlsx($header, [
            ['Alpha Store', 7654321, ['explicit_string', '02023000'], 'Frozen beef', 5, 100, 90],
            ['', '', '', '', '', '', ''], // fully empty in MAPPED columns → skipped despite defaults
            ['Beta Mart', 8901234567890, 15179090, 'Cooking Oil', '2', '250.5', '90.18'],
        ]);

        $mapping = [
            'buyer_name' => 'Customer Name',
            'buyer_ntn' => 'ntn no', // case-insensitive source resolution
            'hs_code' => 'HS Code',
            'description' => 'Product Name',
            'quantity' => 'Qty',
            'price' => 'Rate',
            'tax' => 'GST Amount',
        ];
        $defaults = [
            'buyer_address' => 'Main Bazaar, Lahore',
            'destination_province' => 'Punjab',
            'document_type' => 'Sale Invoice',
            'buyer_name' => 'MUST BE IGNORED', // default for a MAPPED field is ignored
        ];

        $parsed = $this->service->parseFileWithMapping($path, 'xlsx', $mapping, $defaults);
        $this->assertArrayNotHasKey('error', $parsed);
        $this->assertCount(2, $parsed['rows'], 'blank line must not become a phantom row via defaults');

        $rowA = $parsed['rows'][0]['data'];
        $this->assertSame('Alpha Store', $rowA['buyer_name']);
        $this->assertSame('7654321', $rowA['buyer_ntn']);
        $this->assertSame('02023000', $rowA['hs_code'], 'leading zero must survive the remap');
        $this->assertSame('Main Bazaar, Lahore', $rowA['buyer_address']);
        $this->assertSame('Punjab', $rowA['destination_province']);
        $this->assertSame('Sale Invoice', $rowA['document_type']);

        $rowB = $parsed['rows'][1]['data'];
        $this->assertSame('8901234567890', $rowB['buyer_ntn'], 'numeric cell must not become 8.9E+12');

        // Every template key is present so validation/preview see full rows.
        foreach (array_merge(InvoiceImportService::REQUIRED_COLUMNS, InvoiceImportService::OPTIONAL_COLUMNS) as $col) {
            $this->assertArrayHasKey($col, $rowA);
        }
        $this->assertSame('', $rowA['schedule_type'], 'unmapped optional fields stay blank');

        // Remapped rows flow into the normal validation untouched.
        $result = $this->service->validateRows($parsed['rows'], $this->company);
        $this->assertSame(2, $result['total']);
        @unlink($path);
    }

    public function test_parse_file_with_mapping_requires_column_or_default_for_required_fields(): void
    {
        $header = ['Customer Name', 'Qty'];
        $path = $this->writeXlsx($header, [['Alpha', 5]]);

        $parsed = $this->service->parseFileWithMapping($path, 'xlsx', [
            'buyer_name' => 'Customer Name',
            'quantity' => 'Qty',
        ], []);

        $this->assertArrayHasKey('error', $parsed);
        $this->assertStringContainsString('no mapped column and no fixed value', $parsed['error']);
        $this->assertStringContainsString('hs_code', $parsed['error']);
        @unlink($path);
    }

    public function test_parse_file_with_mapping_names_missing_source_columns(): void
    {
        $header = ['Customer Name', 'Qty'];
        $path = $this->writeXlsx($header, [['Alpha', 5]]);

        $parsed = $this->service->parseFileWithMapping($path, 'xlsx', [
            'buyer_name' => 'Customer Name',
            'quantity' => 'Sold Units', // not in this file — preset from a different export
        ], []);

        $this->assertArrayHasKey('error', $parsed);
        $this->assertStringContainsString("'Sold Units'", $parsed['error']);
        $this->assertStringContainsString('quantity', $parsed['error']);
        @unlink($path);
    }

    // ------------------------------------------------------------------
    // Branch resolution (Aug 2026)
    //
    // A distributor working in two cities trades under a different name in
    // each, and the day-end sheet only carries the city. Every draft has to
    // land on the right branch without anyone typing a branch id by hand.
    // ------------------------------------------------------------------

    /** @return array{0: \App\Models\Branch, 1: \App\Models\Branch} */
    private function makeBranches(): array
    {
        $head = \App\Models\Branch::create([
            'company_id' => $this->company->id,
            'name' => 'Al Rehman Traders',
            'city' => 'Lahore',
            'is_head_office' => true,
        ]);
        $other = \App\Models\Branch::create([
            'company_id' => $this->company->id,
            'name' => 'Al Haq Distributors',
            'city' => 'Multan',
            'is_head_office' => false,
        ]);

        return [$head, $other];
    }

    public function test_branch_column_resolves_from_the_city(): void
    {
        [, $other] = $this->makeBranches();

        $data = $this->row(['branch' => 'Multan']);
        $errors = $this->service->validateRow($data, $this->company);

        $this->assertSame([], $errors);
        $this->assertSame($other->id, $data['_branch_id']);
    }

    public function test_branch_column_resolves_from_the_name_ignoring_case_and_spacing(): void
    {
        [$head] = $this->makeBranches();

        $data = $this->row(['branch' => '  AL   REHMAN   traders ']);
        $errors = $this->service->validateRow($data, $this->company);

        $this->assertSame([], $errors);
        $this->assertSame($head->id, $data['_branch_id']);
    }

    public function test_blank_branch_falls_back_to_the_head_office(): void
    {
        [$head] = $this->makeBranches();

        $data = $this->row(['branch' => '']);
        $errors = $this->service->validateRow($data, $this->company);

        $this->assertSame([], $errors);
        $this->assertSame($head->id, $data['_branch_id']);
    }

    public function test_unknown_branch_is_rejected_and_names_the_real_ones(): void
    {
        $this->makeBranches();

        $data = $this->row(['branch' => 'Peshawar']);
        $errors = $this->service->validateRow($data, $this->company);

        $this->assertNotEmpty($errors);
        $this->assertStringContainsString('Peshawar', $errors[0]);
        $this->assertStringContainsString('Multan', $errors[0]);
        $this->assertNull($data['_branch_id']);
    }

    public function test_two_branches_sharing_a_city_are_reported_as_ambiguous(): void
    {
        $this->makeBranches();
        \App\Models\Branch::create([
            'company_id' => $this->company->id,
            'name' => 'Al Rehman Sabzi Mandi',
            'city' => 'Multan',
        ]);

        $data = $this->row(['branch' => 'Multan']);
        $errors = $this->service->validateRow($data, $this->company);

        $this->assertNotEmpty($errors);
        $this->assertStringContainsString('more than one branch', $errors[0]);
        $this->assertNull($data['_branch_id']);

        // The exact branch name is the way out of the ambiguity.
        $byName = $this->row(['branch' => 'Al Rehman Sabzi Mandi']);
        $this->assertSame([], $this->service->validateRow($byName, $this->company));
    }

    public function test_same_buyer_billed_from_two_branches_stays_two_invoices(): void
    {
        $this->makeBranches();

        $lahore = $this->row(['branch' => 'Lahore']);
        $multan = $this->row(['branch' => 'Multan']);
        $this->service->validateRow($lahore, $this->company);
        $this->service->validateRow($multan, $this->company);

        $this->assertNotSame(
            $this->service->groupKey($lahore),
            $this->service->groupKey($multan),
        );
    }

    public function test_created_drafts_carry_the_branch_resolved_from_the_city(): void
    {
        [$head, $multan] = $this->makeBranches();

        $result = $this->service->validateRows($this->parsedRows([
            $this->row(['description' => 'Lahore sale', 'branch' => 'Lahore']),
            $this->row(['description' => 'Multan sale', 'branch' => 'Multan']),
        ]), $this->company);

        $this->assertSame(2, $result['valid_count'], json_encode($result['rows']));

        $validRows = array_values(array_filter($result['rows'], fn ($r) => $r['valid']));
        $outcome = $this->service->createDraftsFromRows($validRows, $this->company, null, 'bulk_import');

        // Same buyer, same day, two cities → two invoices, not one merged draft.
        $this->assertSame(2, $outcome['created_count'], json_encode($outcome['row_errors']));

        $branchIds = Invoice::withoutGlobalScopes()->orderBy('id')->pluck('branch_id')->all();
        $this->assertEqualsCanonicalizing([$head->id, $multan->id], $branchIds);
    }

    public function test_a_company_with_no_branches_still_imports(): void
    {
        $data = $this->row(['branch' => '']);
        $errors = $this->service->validateRow($data, $this->company);

        $this->assertSame([], $errors);
        $this->assertNull($data['_branch_id']);
    }

    private function smartTables(): void
    {
        Schema::create('invoice_import_batches', function (Blueprint $t) {
            $t->id(); $t->unsignedBigInteger('company_id'); $t->unsignedBigInteger('user_id')->nullable();
            $t->string('original_filename'); $t->string('source_format'); $t->string('status');
            foreach (['total_rows', 'valid_rows', 'invalid_rows', 'processed_rows', 'created_invoices', 'failed_rows'] as $field) { $t->integer($field)->default(0); }
            $t->longText('rows_json')->nullable(); $t->longText('result_json')->nullable(); $t->text('error_message')->nullable();
            $t->timestamp('started_at')->nullable(); $t->timestamp('finished_at')->nullable(); $t->timestamps();
        });
        (require base_path('database/migrations/2026_10_12_000001_create_smart_di_import_safeguards.php'))->up();
        config(['features.enable_smart_di_import_safeguards' => true]);
    }

    private function verifiedSmartMappings(int $companyId): void
    {
        foreach ([
            'sku' => ['description' => 'Verified beverage', 'hs_code' => '22021010', 'uom' => 'Liter', 'litres_per_case' => '6', 'schedule_type' => 'standard', 'tax_rate' => '18'],
            'buyer' => ['buyer_name' => 'Fictional buyer', 'buyer_address' => 'Test address', 'destination_province' => 'Punjab', 'registration_type' => 'Unregistered'],
        ] as $kind => $details) {
            \App\Models\InvoiceImportVerifiedMapping::create(['company_id' => $companyId, 'source_system' => 'test-voyage', 'kind' => $kind, 'source_code' => $kind === 'sku' ? 'TEST-SKU' : 'TEST-OUTLET', 'version' => 1, 'details' => $details, 'evidence' => 'Fictional checked test evidence', 'verified_by' => 99, 'verified_at' => now()]);
        }
    }

    private function smartRow(array $changes = []): array
    {
        return $this->row(array_merge(['source_invoice_number' => 'TEST-BILL-001', 'invoice_date' => '2026-01-01', 'source_sku' => 'TEST-SKU', 'source_outlet' => 'TEST-OUTLET', 'quantity' => '2', 'source_gross' => '1300.00', 'source_discount' => '100.00', 'source_vat' => '216.00', 'source_net' => '1416.00', 'invoice_gross' => '1300.00', 'invoice_discount' => '100.00', 'invoice_vat' => '216.00', 'invoice_net' => '1416.00'], $changes));
    }

    private function smartReview(array $rows, array $decisions = []): array
    {
        return (new \App\Services\SmartInvoiceImportReview())->review($this->parsedRows($rows), $this->company, 'test-voyage', $decisions);
    }

    private function approvedSmartBatch(array $review): \App\Models\InvoiceImportBatch
    {
        $json = json_encode($review['rows']);
        $review['approved_hash'] = hash('sha256', $json);
        return \App\Models\InvoiceImportBatch::create(['company_id' => $this->company->id, 'original_filename' => 'fictional.csv', 'source_format' => 'csv', 'smart_source_system' => 'test-voyage', 'status' => 'validated', 'rows_json' => $json, 'smart_review' => $review, 'total_rows' => count($review['rows']), 'valid_rows' => count(array_filter($review['rows'], fn ($r) => $r['valid'])), 'invalid_rows' => 0, 'approved_by' => 99, 'approved_at' => now()]);
    }

    public function test_smart_verified_conversion_preserves_source_vat_and_original_identity(): void
    {
        $this->smartTables(); $this->verifiedSmartMappings($this->company->id);
        $review = $this->smartReview([$this->smartRow()]);
        $this->assertSame([], $review['rows'][0]['errors']);
        $this->assertTrue($review['rows'][0]['valid']);
        $this->assertEquals(12, $review['rows'][0]['data']['quantity']);
        $this->assertEquals(100, $review['rows'][0]['data']['price']);
        $this->assertEquals(216, $review['rows'][0]['data']['tax']);
        $this->assertSame('Liter', $review['rows'][0]['data']['_default_uom']);
        $batch = $this->approvedSmartBatch($review);
        $result = $this->service->createDraftsFromRows($review['rows'], $this->company, null, 'bulk_import', null, null, $batch->id);
        $this->assertSame(1, $result['created_count']);
        $invoice = Invoice::firstOrFail();
        $this->assertSame('draft', $invoice->status);
        $this->assertNull($invoice->fbr_invoice_number);
        $this->assertSame('Unregistered', $invoice->buyer_registration_type);
        $this->assertEquals(1416, $invoice->total_amount);
        $receipt = \App\Models\InvoiceImportSourceReceipt::firstOrFail();
        $this->assertSame('TEST-BILL-001', $receipt->source_invoice_number);
        $this->assertSame($this->smartRow(), $receipt->audit[0]['original_data']);
    }

    public function test_smart_unknown_sku_buyer_and_other_tenant_mapping_block_every_line_of_invoice(): void
    {
        $this->smartTables(); $this->verifiedSmartMappings($this->company->id + 1);
        $review = $this->smartReview([$this->smartRow()]);
        $this->assertFalse($review['rows'][0]['valid']);
        $this->assertStringContainsString('Unknown SKU', implode(' ', $review['rows'][0]['errors']));
        $this->assertStringContainsString('Unknown outlet', implode(' ', $review['rows'][0]['errors']));
        $this->assertSame(0, Invoice::count());
    }

    public function test_smart_one_bad_line_blocks_the_entire_source_invoice(): void
    {
        $this->smartTables(); $this->verifiedSmartMappings($this->company->id);
        $good = $this->smartRow(['invoice_gross' => '2600', 'invoice_discount' => '200', 'invoice_vat' => '432', 'invoice_net' => '2832']);
        $bad = array_merge($good, ['source_sku' => 'UNKNOWN']);
        $review = $this->smartReview([$good, $bad]);
        $this->assertFalse($review['rows'][0]['valid']);
        $this->assertFalse($review['rows'][1]['valid']);
    }

    public function test_smart_packaging_exclusion_preserves_all_source_totals_and_requires_a_reason(): void
    {
        $this->smartTables(); $this->verifiedSmartMappings($this->company->id);
        $good = $this->smartRow(['invoice_gross' => '1400', 'invoice_discount' => '100', 'invoice_vat' => '216', 'invoice_net' => '1516']);
        $pack = array_merge($good, ['description' => 'Empty bottle deposit', 'source_sku' => 'EMPTY', 'source_gross' => '100', 'source_discount' => '0', 'source_vat' => '0', 'source_net' => '100']);
        $this->assertFalse($this->smartReview([$good, $pack])['rows'][0]['valid']);
        $review = $this->smartReview([$good, $pack], ['3' => 'Checked returnable packaging deposit']);
        $this->assertTrue($review['rows'][0]['valid']);
        $this->assertTrue($review['rows'][1]['excluded']);
        $this->assertSame(151600, $review['invoices'][0]['source_totals']['net']);
        $this->assertSame(141600, $review['invoices'][0]['draft_totals']['net']);
        $this->assertSame(10000, $review['invoices'][0]['excluded_totals']['net']);
    }

    public function test_smart_cci_deposit_pairs_require_explicit_review_and_preserve_draft_audit(): void
    {
        $this->smartTables(); $this->verifiedSmartMappings($this->company->id);
        $good = $this->smartRow();
        $deposit = array_merge($good, ['description' => 'COKE 250 ML IMPLIED EMP 24B RGB', 'source_sku' => 'PACK',
            'quantity' => '2', 'source_gross' => '100', 'source_discount' => '0', 'source_vat' => '0', 'source_net' => '100']);
        $returned = array_merge($deposit, ['quantity' => '-2', 'source_gross' => '-100', 'source_net' => '-100']);
        $this->assertFalse($this->smartReview([$good, $deposit, $returned])['rows'][0]['valid']);
        $one = $this->smartReview([$good, $deposit, $returned], ['3' => 'Reviewed empty bottle charge']);
        $this->assertFalse($one['rows'][0]['valid'], 'The negative deposit still needs its own explicit reason');
        $review = $this->smartReview([$good, $deposit, $returned], ['3' => 'Reviewed empty bottle charge', '4' => 'Reviewed empty bottle return']);
        $this->assertTrue($review['rows'][0]['valid'], json_encode($review['invoices'][0]['errors']));
        foreach ([1, 2] as $i) {
            $this->assertSame('packaging_review', $review['rows'][$i]['classification']);
            $this->assertTrue($review['rows'][$i]['excluded']);
        }
        $this->assertSame(0, $review['invoices'][0]['excluded_totals']['net']);
        $this->assertSame(141600, $review['invoices'][0]['source_totals']['net']);
        $this->assertSame(141600, $review['invoices'][0]['draft_totals']['net']);
        $batch = $this->approvedSmartBatch($review);
        $created = $this->service->createDraftsFromRows(array_values(array_filter($review['rows'], fn ($row) => $row['valid'])), $this->company, null, 'bulk_import', null, null, $batch->id);
        $this->assertSame(1, $created['created_count']);
        $this->assertSame(1, InvoiceItem::count(), 'Packaging is audited, never invoiced as a beverage');
        $receipt = \App\Models\InvoiceImportSourceReceipt::firstOrFail();
        $this->assertCount(3, $receipt->audit);
        $this->assertSame('-100', $receipt->audit[2]['original_data']['source_net']);
        $this->assertNull(Invoice::firstOrFail()->fbr_invoice_number);
    }

    public function test_smart_negative_damage_missing_identity_and_invoice_mismatch_cannot_be_approved(): void
    {
        $this->smartTables(); $this->verifiedSmartMappings($this->company->id);
        foreach ([['quantity' => '-2'], ['description' => 'Damaged stock'], ['source_invoice_number' => ''], ['invoice_net' => '1416.01'], ['source_vat' => ''], ['invoice_date' => '']] as $changes) {
            $review = $this->smartReview([$this->smartRow($changes)]);
            $this->assertFalse($review['rows'][0]['valid'], json_encode($changes));
            $this->assertNotEmpty($review['invoices'][0]['errors']);
        }
    }

    public function test_smart_draft_retry_reuses_receipt_and_locked_invoice_without_mutation(): void
    {
        $this->smartTables(); $this->verifiedSmartMappings($this->company->id);
        $review = $this->smartReview([$this->smartRow()]); $batch = $this->approvedSmartBatch($review);
        $create = fn () => $this->service->createDraftsFromRows($review['rows'], $this->company, null, 'bulk_import', null, null, $batch->id);
        $this->assertSame(1, $create()['created_count']);
        Invoice::firstOrFail()->update(['status' => 'locked', 'fbr_invoice_number' => 'TEST-FBR-RECEIPT']);
        $this->assertSame(1, $create()['created_count']);
        $this->assertSame(1, Invoice::count());
        $this->assertSame(1, \App\Models\InvoiceImportSourceReceipt::count());
        $this->assertSame('locked', Invoice::first()->status);
        $this->assertSame('TEST-FBR-RECEIPT', Invoice::first()->fbr_invoice_number);
        $duplicate = $this->smartReview([$this->smartRow()]);
        $this->assertStringContainsString('already imported', implode(' ', $duplicate['rows'][0]['errors']));
    }

    public function test_smart_unapproved_or_tampered_snapshot_cannot_create_a_draft(): void
    {
        $this->smartTables(); $this->verifiedSmartMappings($this->company->id);
        $review = $this->smartReview([$this->smartRow()]); $batch = $this->approvedSmartBatch($review);
        $changed = $review['rows']; $changed[0]['data']['price'] = '1';
        $result = $this->service->createDraftsFromRows($changed, $this->company, null, 'bulk_import', null, null, $batch->id);
        $this->assertSame(0, $result['created_count']);
        $this->assertSame(0, Invoice::count());
        $this->assertSame(0, \App\Models\InvoiceImportSourceReceipt::count());
        $batch->update(['approved_at' => null]);
        $result = $this->service->createDraftsFromRows($review['rows'], $this->company, null, 'bulk_import', null, null, $batch->id);
        $this->assertSame(0, $result['created_count']);
    }

    public function test_smart_same_buyer_same_day_original_bills_remain_separate_and_tenant_scoped(): void
    {
        $this->smartTables(); $this->verifiedSmartMappings($this->company->id);
        $review = $this->smartReview([$this->smartRow(), $this->smartRow(['source_invoice_number' => 'TEST-BILL-002'])]);
        $this->assertCount(2, $review['invoices']);
        $batch = $this->approvedSmartBatch($review);
        $result = $this->service->createDraftsFromRows($review['rows'], $this->company, null, 'bulk_import', null, null, $batch->id);
        $this->assertSame(2, $result['created_count']);
        $other = Company::create(['name' => 'Second fictional company', 'province' => 'Punjab', 'standard_tax_rate' => 18, 'next_invoice_number' => 1]);
        $this->verifiedSmartMappings($other->id);
        $otherReview = (new \App\Services\SmartInvoiceImportReview())->review($this->parsedRows([$this->smartRow()]), $other, 'test-voyage');
        $this->assertTrue($otherReview['rows'][0]['valid']);
        $this->assertNotSame($review['rows'][0]['source_fingerprint'], $otherReview['rows'][0]['source_fingerprint']);
    }

    public function test_smart_receipt_uniqueness_is_database_enforced_and_rolls_back_failed_draft(): void
    {
        $this->smartTables(); $this->verifiedSmartMappings($this->company->id);
        $review = $this->smartReview([$this->smartRow()]); $batch = $this->approvedSmartBatch($review);
        Schema::drop('invoice_items');
        $out = $this->service->createDraftsFromRows($review['rows'], $this->company, null, 'bulk_import', null, null, $batch->id);
        $this->assertSame(0, $out['created_count']);
        $this->assertSame(0, Invoice::count());
        $this->assertSame(0, \App\Models\InvoiceImportSourceReceipt::count());
    }

    public function test_smart_lossy_unit_price_conversion_requires_review_instead_of_rounding(): void
    {
        $this->smartTables(); $this->verifiedSmartMappings($this->company->id);
        $review = $this->smartReview([$this->smartRow(['source_gross' => '1300.01', 'source_net' => '1416.01', 'invoice_gross' => '1300.01', 'invoice_net' => '1416.01'])]);
        $this->assertStringContainsString('price precision', implode(' ', $review['rows'][0]['errors']));
    }

    public function test_smart_missing_verified_hs_and_mrp_never_use_source_or_price_fallback(): void
    {
        $this->smartTables(); $this->verifiedSmartMappings($this->company->id);
        $mapping = \App\Models\InvoiceImportVerifiedMapping::where('kind', 'sku')->firstOrFail();
        $d = $mapping->details;
        unset($d['hs_code']); $d['schedule_type'] = '3rd_schedule';
        $mapping->update(['details' => $d]);
        $review = $this->smartReview([$this->smartRow(['mrp' => '500', 'hs_code' => '15179090'])]);
        $this->assertFalse($review['rows'][0]['valid']);
        $errors = implode(' ', $review['rows'][0]['errors']);
        $this->assertStringContainsString('missing hs_code', $errors);
        $this->assertStringContainsString('mrp required', $errors);
    }

    public function test_smart_cross_batch_overlap_and_database_unique_receipt_cannot_repeat_drafts(): void
    {
        $this->smartTables(); $this->verifiedSmartMappings($this->company->id);
        $review = $this->smartReview([$this->smartRow()]);
        $batch = $this->approvedSmartBatch($review);
        $out = $this->service->createDraftsFromRows($review['rows'], $this->company, null, 'bulk_import', null, null, $batch->id);
        $this->assertSame(1, $out['created_count']);
        $second = $this->approvedSmartBatch($review);
        $repeat = $this->service->createDraftsFromRows($review['rows'], $this->company, null, 'bulk_import', null, null, $second->id);
        $this->assertSame(0, $repeat['created_count']);
        $receipt = \App\Models\InvoiceImportSourceReceipt::firstOrFail();
        try {
            \App\Models\InvoiceImportSourceReceipt::create($receipt->only(['company_id', 'source_system', 'source_invoice_number', 'supply_date', 'fingerprint', 'content_hash', 'import_batch_id', 'invoice_id', 'audit']));
            $this->fail('Database accepted duplicate source fingerprint.');
        } catch (\Illuminate\Database\QueryException $e) {
            $this->assertStringContainsString('UNIQUE', strtoupper($e->getMessage()));
        }
        $this->assertSame(1, Invoice::count());
        $this->assertSame(1, \App\Models\InvoiceImportSourceReceipt::count());
    }

    public function test_smart_partial_draft_retry_reuses_completed_groups_even_when_new_invoice_quota_is_zero(): void
    {
        $this->smartTables(); $this->verifiedSmartMappings($this->company->id);
        $review = $this->smartReview([$this->smartRow(), $this->smartRow(['source_invoice_number' => 'TEST-BILL-002'])]);
        $batch = $this->approvedSmartBatch($review);
        $partial = $this->service->createDraftsFromRows($review['rows'], $this->company, null, 'bulk_import', 1, null, $batch->id);
        $this->assertSame(1, $partial['created_count']);
        $this->assertSame(1, $partial['failed_rows']);
        $retry = $this->service->createDraftsFromRows($review['rows'], $this->company, null, 'bulk_import', 0, null, $batch->id);
        $this->assertSame(1, $retry['created_count']);
        $this->assertSame(1, Invoice::count());
        $resumed = $this->service->createDraftsFromRows($review['rows'], $this->company, null, 'bulk_import', 1, null, $batch->id);
        $this->assertSame(2, $resumed['created_count']);
        $this->assertSame(0, $resumed['failed_rows']);
        $this->assertSame(2, Invoice::count());
    }

    public function test_smart_process_rejects_unapproved_batches_and_other_company_context(): void
    {
        $this->smartTables(); $this->verifiedSmartMappings($this->company->id);
        $batch = $this->approvedSmartBatch($this->smartReview([$this->smartRow()]));
        app()->instance('currentCompanyId', $this->company->id + 1);
        $controller = new \App\Http\Controllers\InvoiceImportController($this->service);
        $response = $controller->process(\Illuminate\Http\Request::create('/invoices/import/' . $batch->id . '/process', 'POST'), $batch->id);
        $this->assertSame(404, $response->status());
        app()->instance('currentCompanyId', $this->company->id);
        $batch->update(['approved_at' => null]);
        $response = $controller->process(\Illuminate\Http\Request::create('/invoices/import/' . $batch->id . '/process', 'POST'), $batch->id);
        $this->assertSame(422, $response->status());
        $this->assertSame(0, Invoice::count());
    }

    public function test_smart_local_existing_payload_must_preserve_exact_source_vat_and_third_schedule_value(): void
    {
        $this->smartTables(); $this->verifiedSmartMappings($this->company->id);
        \Illuminate\Support\Facades\Http::preventStrayRequests();
        $review = $this->smartReview([$this->smartRow(['source_vat' => '216.01', 'source_net' => '1416.01', 'invoice_vat' => '216.01', 'invoice_net' => '1416.01'])]);
        $this->assertFalse($review['rows'][0]['valid']);
        $this->assertStringContainsString('Existing DI payload salesTaxApplicable', implode(' ', $review['rows'][0]['errors']));
        $mapping = \App\Models\InvoiceImportVerifiedMapping::where('kind', 'sku')->firstOrFail();
        $details = $mapping->details; $details['schedule_type'] = '3rd_schedule'; $details['mrp'] = '150';
        $mapping->update(['details' => $details]);
        $third = $this->smartReview([$this->smartRow()]);
        $this->assertFalse($third['rows'][0]['valid']);
        // Deployed DI uses the transaction sale value as the sales base, with
        // third-schedule VAT computed from MRP. Source VAT 216 must still be
        // blocked: 12 converted units x MRP 150 x 18% = 324.
        $errors = implode(' ', $third['rows'][0]['errors']);
        $this->assertStringContainsString('quantity x MRP', $errors);
        $this->assertStringContainsString('expected ~324.00', $errors);
        $this->assertStringNotContainsString('Existing DI payload valueSalesExcludingST', $errors);
        $consistent = $this->smartReview([$this->smartRow([
            'source_vat' => '324.00', 'source_net' => '1524.00',
            'invoice_vat' => '324.00', 'invoice_net' => '1524.00',
        ])]);
        $this->assertTrue($consistent['rows'][0]['valid'], implode(' ', $consistent['rows'][0]['errors']));
        $this->assertEquals(324, $consistent['rows'][0]['data']['tax']);
        $this->assertEquals(1200, (float) $consistent['rows'][0]['data']['price'] * (float) $consistent['rows'][0]['data']['quantity']);
        \Illuminate\Support\Facades\Http::assertNothingSent();
        $this->assertSame(0, Invoice::count());
    }

    public function test_smart_controller_refresh_resolves_previously_unknown_mappings_and_reviews_financial_allocation(): void
    {
        $this->smartTables();
        $raw = $this->smartRow(['source_vat' => '', 'source_discount' => '', 'source_net' => '']);
        $initial = $this->smartReview([$raw]);
        $batch = \App\Models\InvoiceImportBatch::create(['company_id' => $this->company->id, 'original_filename' => 'fictional.csv', 'source_format' => 'csv', 'smart_source_system' => 'test-voyage', 'status' => 'needs_review', 'rows_json' => json_encode($initial['rows']), 'smart_review' => $initial]);
        $this->verifiedSmartMappings($this->company->id);
        $user = new \App\Models\User(); $user->forceFill(['id' => 99, 'company_id' => $this->company->id, 'role' => 'company_admin']);
        $this->actingAs($user);
        app()->instance('currentCompanyId', $this->company->id);
        $request = \Illuminate\Http\Request::create('/invoices/import/' . $batch->id . '/source-review', 'POST', ['amounts' => ['2' => ['discount' => '100', 'vat' => '216', 'net' => '1416', 'evidence' => 'Verified synthetic VAT allocation']]]);
        (new \App\Http\Controllers\SmartInvoiceImportController())->review($request, $batch->id);
        $batch->refresh();
        $this->assertSame('validated', $batch->status);
        $this->assertSame([], $batch->smart_review['invoices'][0]['errors']);
        $this->assertSame(1, $batch->valid_rows);
        $this->assertNull($batch->approved_at);
        $this->assertSame('', $batch->rowsArray()[0]['original_data']['_financial_original']['source_vat']);
        $this->assertSame('216', $batch->rowsArray()[0]['original_data']['source_vat']);
        $this->assertSame(99, $batch->rowsArray()[0]['original_data']['_financial_review']['user_id']);
    }

    public function test_smart_template_parser_retains_unmapped_source_cells_and_rejects_duplicate_headers(): void
    {
        $headers = array_merge(InvoiceImportService::REQUIRED_COLUMNS, InvoiceImportService::OPTIONAL_COLUMNS, ['unmapped_evidence']);
        $data = $this->smartRow(); $data['unmapped_evidence'] = 'Source-only evidence';
        $path = tempnam(sys_get_temp_dir(), 'smartcsv');
        $file = fopen($path, 'w'); fputcsv($file, $headers); fputcsv($file, array_map(fn ($h) => $data[$h] ?? '', $headers)); fclose($file);
        try {
            $out = $this->service->parseFile($path, 'csv', InvoiceImportService::MAX_ROWS, true, true);
            $this->assertArrayNotHasKey('error', $out);
            $this->assertSame('Source-only evidence', end($out['rows'][0]['data']['_source_cells']));
            $this->assertSame($headers, $out['rows'][0]['data']['_source_headers']);
            $file = fopen($path, 'w'); fputcsv($file, array_merge($headers, ['source_sku'])); fputcsv($file, array_fill(0, count($headers) + 1, 'x')); fclose($file);
            $bad = $this->service->parseFile($path, 'csv', InvoiceImportService::MAX_ROWS, true, true);
            $this->assertStringContainsString('Duplicate source headers', $bad['error']);
        } finally { @unlink($path); }
    }
}
