<?php

namespace Tests\Feature;

use App\Models\BulkAiImageBatch;
use App\Models\Company;
use App\Models\Product;
use App\Services\AnnexureProductService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Http\UploadedFile;
use Tests\TestCase;

class AnnexureProductServiceTest extends TestCase
{
    private AnnexureProductService $service;
    private Company $company;

    protected function setUp(): void
    {
        parent::setUp();
        Schema::dropAllTables();
        Schema::create('companies', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->softDeletes();
            $table->timestamps();
        });
        Schema::create('products', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('company_id');
            $table->string('name');
            $table->string('barcode', 80)->nullable();
            $table->string('sku', 80)->nullable();
            $table->string('hs_code');
            $table->string('pct_code')->nullable();
            $table->decimal('default_tax_rate', 5, 2)->default(18);
            $table->string('tax_type')->default('taxable');
            $table->string('uom')->default('PCS');
            $table->string('schedule_type')->nullable();
            $table->string('sro_reference')->nullable();
            $table->string('serial_number')->nullable();
            $table->decimal('mrp', 14, 2)->nullable();
            $table->decimal('default_price', 15, 2)->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });
        Schema::create('bulk_ai_image_batches', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('company_id');
            $table->unsignedBigInteger('user_id')->nullable();
            $table->string('batch_uuid');
            $table->string('status')->default('uploading');
            $table->unsignedInteger('total_images')->default(0);
            $table->unsignedInteger('reserved_credits')->default(0);
            $table->timestamp('retention_until')->nullable();
            $table->string('annexure_status')->default('ready');
            $table->string('annexure_filename')->nullable();
            $table->string('annexure_storage_path')->nullable();
            $table->longText('annexure_rows_json')->nullable();
            $table->longText('annexure_headers_json')->nullable();
            $table->longText('annexure_samples_json')->nullable();
            $table->longText('annexure_mapping_json')->nullable();
            $table->timestamp('annexure_uploaded_at')->nullable();
            $table->timestamps();
        });
        Schema::create('annexure_product_audits', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('company_id');
            $table->unsignedBigInteger('batch_id');
            $table->unsignedBigInteger('product_id')->nullable();
            $table->unsignedBigInteger('user_id')->nullable();
            $table->string('action');
            $table->string('decision')->nullable();
            $table->unsignedInteger('annexure_row')->nullable();
            $table->string('idempotency_key');
            $table->longText('previous_values_json')->nullable();
            $table->longText('approved_values_json')->nullable();
            $table->timestamps();
            $table->unique(['company_id', 'batch_id', 'idempotency_key']);
        });

        $this->company = Company::create(['name' => 'Annexure Co']);
        app()->instance('currentCompanyId', $this->company->id);
        $this->service = app(AnnexureProductService::class);
    }

    public function test_it_matches_barcode_before_conservative_name_and_flags_ambiguity(): void
    {
        $rows = [
            $this->entry(2, ['name' => 'Cooking Oil 1L', 'barcode' => '890-123']),
            $this->entry(3, ['name' => 'Premium Soap Bar']),
            $this->entry(4, ['name' => 'Premium Soap Bar']),
        ];

        $matches = $this->service->matchLines([
            ['description' => 'Whatever is printed', 'barcode' => '890123'],
            ['description' => 'Premium Soap Bar'],
            ['description' => 'Unknown detergent'],
        ], $rows);

        $this->assertSame('matched', $matches[0]['status']);
        $this->assertSame('identifier', $matches[0]['match_type']);
        $this->assertSame(2, $matches[0]['source_row']);
        $this->assertSame('ambiguous', $matches[1]['status']);
        $this->assertSame('missing', $matches[2]['status']);
    }

    public function test_new_product_uses_annexure_default_price_and_repeat_is_idempotent(): void
    {
        $batch = $this->batch([$this->entry(2)]);

        $first = $this->service->saveCatalogDecision($batch, $this->company, 99, [
            'annexure_row' => 2, 'action' => 'create', 'price_decision' => 'keep_current',
        ]);
        $repeat = $this->service->saveCatalogDecision($batch, $this->company, 99, [
            'annexure_row' => 2, 'action' => 'create', 'price_decision' => 'keep_current',
        ]);

        $product = Product::findOrFail($first['product_id']);
        $this->assertSame('Cooking Oil 1L', $product->name);
        $this->assertSame(135.5, (float) $product->default_price);
        $this->assertTrue($repeat['idempotent']);
        $this->assertSame(1, \App\Models\AnnexureProductAudit::count());
    }

    public function test_existing_price_changes_only_for_explicit_update_catalog_choice(): void
    {
        $product = Product::create($this->productValues(['default_price' => 100]));
        $batch = $this->batch([$this->entry(2, ['default_price' => '120'])]);

        $this->service->saveCatalogDecision($batch, $this->company, 99, [
            'annexure_row' => 2, 'action' => 'update', 'product_id' => $product->id, 'price_decision' => 'keep_current',
        ]);
        $this->assertSame(100.0, (float) $product->fresh()->default_price);

        $this->service->saveCatalogDecision($batch, $this->company, 99, [
            'annexure_row' => 2, 'action' => 'update', 'product_id' => $product->id, 'price_decision' => 'update_catalog',
        ]);
        $this->assertSame(120.0, (float) $product->fresh()->default_price);
    }

    public function test_batch_only_price_choice_records_reference_without_changing_catalog_price(): void
    {
        $product = Product::create($this->productValues(['default_price' => 100]));
        $batch = $this->batch([$this->entry(2, ['default_price' => '120'])]);

        $result = $this->service->saveCatalogDecision($batch, $this->company, 99, [
            'annexure_row' => 2, 'action' => 'update', 'product_id' => $product->id, 'price_decision' => 'batch_only',
        ]);

        $this->assertSame(100.0, (float) $product->fresh()->default_price);
        $audit = \App\Models\AnnexureProductAudit::findOrFail($result['audit_id']);
        $this->assertSame('120', (string) $audit->approved_values_json['annexure_default_price_for_batch_only']);
    }

    public function test_reversal_never_overwrites_a_later_catalog_decision(): void
    {
        $product = Product::create($this->productValues(['default_price' => 100]));
        $firstBatch = $this->batch([$this->entry(2, ['default_price' => '120'])]);
        $first = $this->service->saveCatalogDecision($firstBatch, $this->company, 99, [
            'annexure_row' => 2, 'action' => 'update', 'product_id' => $product->id, 'price_decision' => 'update_catalog',
        ]);
        $laterBatch = $this->batch([$this->entry(2, ['default_price' => '130'])]);
        $this->service->saveCatalogDecision($laterBatch, $this->company, 99, [
            'annexure_row' => 2, 'action' => 'update', 'product_id' => $product->id, 'price_decision' => 'update_catalog',
        ]);

        $this->expectException(\InvalidArgumentException::class);
        $this->service->reverseCatalogDecision($firstBatch, $this->company, 99, $first['audit_id']);
    }

    public function test_reversal_refuses_to_overwrite_a_manual_catalog_edit(): void
    {
        $product = Product::create($this->productValues(['default_price' => 100]));
        $batch = $this->batch([$this->entry(2, ['default_price' => '120'])]);
        $audit = $this->service->saveCatalogDecision($batch, $this->company, 99, [
            'annexure_row' => 2, 'action' => 'update', 'product_id' => $product->id, 'price_decision' => 'update_catalog',
        ]);
        $product->update(['default_price' => 130]);

        $this->expectException(\InvalidArgumentException::class);
        $this->service->reverseCatalogDecision($batch, $this->company, 99, $audit['audit_id']);
    }

    public function test_mapping_keeps_original_positions_when_a_spreadsheet_has_blank_columns(): void
    {
        $batch = $this->batch([]);
        $file = tempnam(sys_get_temp_dir(), 'annexure_');
        file_put_contents($file, "Product Name,,HS Code,Default Price\nOil 1L,,15179090,135.50\n");
        try {
            $this->service->upload($batch, new UploadedFile($file, 'master.csv', 'text/csv', null, true));
            $result = $this->service->applyMapping($batch->fresh(), [
                'name' => 'Product Name', 'hs_code' => 'HS Code', 'default_price' => 'Default Price',
            ]);
        } finally {
            @unlink($file);
        }

        $this->assertSame(1, $result['valid_count']);
        $this->assertSame('15179090', $result['rows'][0]['hs_code']);
        $this->assertSame('135.50', $result['rows'][0]['default_price']);
    }

    public function test_catalog_updates_cannot_target_another_company_product(): void
    {
        $other = Company::create(['name' => 'Other distributor']);
        $foreignProduct = Product::withoutGlobalScopes()->create($this->productValues(['company_id' => $other->id]));
        $batch = $this->batch([$this->entry(2)]);

        $this->expectException(\InvalidArgumentException::class);
        $this->service->saveCatalogDecision($batch, $this->company, 99, [
            'annexure_row' => 2, 'action' => 'update', 'product_id' => $foreignProduct->id, 'price_decision' => 'keep_current',
        ]);
    }

    private function batch(array $rows): BulkAiImageBatch
    {
        return BulkAiImageBatch::create([
            'company_id' => $this->company->id,
            'batch_uuid' => (string) \Illuminate\Support\Str::uuid(),
            'annexure_status' => 'ready',
            'annexure_rows_json' => json_encode($rows),
        ]);
    }

    private function entry(int $row, array $overrides = []): array
    {
        return array_merge([
            'source_row' => $row, 'name' => 'Cooking Oil 1L', 'barcode' => '', 'sku' => '',
            'hs_code' => '15179090', 'pct_code' => '1517.9090', 'uom' => 'PCS',
            'default_tax_rate' => '18', 'tax_type' => 'taxable', 'schedule_type' => 'standard',
            'sro_reference' => '', 'serial_number' => '', 'mrp' => '', 'default_price' => '135.50',
            'valid' => true, 'errors' => [],
        ], $overrides);
    }

    private function productValues(array $overrides = []): array
    {
        return array_merge([
            'company_id' => $this->company->id, 'name' => 'Cooking Oil 1L', 'hs_code' => '15179090',
            'pct_code' => '1517.9090', 'uom' => 'PCS', 'default_tax_rate' => 18,
            'tax_type' => 'taxable', 'schedule_type' => 'standard', 'default_price' => 135.5,
        ], $overrides);
    }
}