<?php

namespace Tests\Feature;

use App\Exceptions\AiReaderException;
use App\Models\AiInvoiceParse;
use App\Models\Company;
use App\Services\AiInvoiceReaderService;
use App\Services\DiFeatureService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Task 142: AI Invoice Reader — PDF/Excel/photo se draft DI invoice.
 *
 * Locks the pipeline rules:
 *   - monthly quota by plan (internal unlimited, Premium generous, active
 *     trial small taste, everyone else default) and ONLY successful parses
 *     consume it;
 *   - mapExtraction resolves HS from the document, falls back to the
 *     company's own product list, and flags items that still need an HS
 *     (low confidence + warning) — it never guesses;
 *   - exempt/zero-rated schedules force tax to zero; missing tax amounts
 *     are derived from qty × price × rate;
 *   - parseUpload stores a success row with the mapped payload, and a
 *     failed row (which never counts toward quota) when the AI says the
 *     file is not an invoice;
 *   - a parse can only be linked to ONE invoice, by its own company.
 */
class AiInvoiceReaderTest extends TestCase
{
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
            $t->string('ntn')->nullable();
            $t->string('address')->nullable();
            $t->string('city')->nullable();
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

        Schema::create('invoices', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('company_id');
            $t->softDeletes();
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

        Schema::create('products', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('company_id');
            $t->string('name');
            $t->string('hs_code')->nullable();
            $t->string('pct_code')->nullable();
            $t->decimal('default_tax_rate', 8, 2)->nullable();
            $t->string('uom')->nullable();
            $t->string('schedule_type')->nullable();
            $t->boolean('is_active')->default(true);
            $t->timestamps();
        });

        Schema::create('global_hs_master', function (Blueprint $t) {
            $t->id();
            $t->string('hs_code');
            $t->string('description')->nullable();
            $t->string('schedule_type')->nullable();
            $t->decimal('tax_rate', 8, 2)->nullable();
            $t->string('pct_code')->nullable();
            $t->string('default_uom')->nullable();
            $t->string('sro_number')->nullable();
            $t->string('sro_item_serial_no')->nullable();
            $t->boolean('is_active')->default(true);
            $t->timestamps();
        });

        Schema::create('system_settings', function (Blueprint $t) {
            $t->id();
            $t->string('key')->unique();
            $t->text('value')->nullable();
            $t->timestamps();
        });

        // Tables GlobalHsService may touch on unmapped codes.
        Schema::create('hs_unmapped_log', function (Blueprint $t) {
            $t->id();
            $t->string('hs_code')->nullable();
            $t->unsignedBigInteger('company_id')->nullable();
            $t->timestamps();
        });

        config(['services.openai.key' => 'test-key-123']);
    }

    // ---------------------------------------------------------------- helpers

    private function makeCompany(string $planName = 'Premium', array $subAttrs = [], array $companyAttrs = []): Company
    {
        $companyId = DB::table('companies')->insertGetId(array_merge([
            'name' => 'Test Co',
            'product_type' => 'di',
            'created_at' => now(),
            'updated_at' => now(),
        ], $companyAttrs));

        $planId = DB::table('pricing_plans')->insertGetId([
            'name' => $planName,
            'product_type' => 'di',
            'is_trial' => $planName === 'Trial',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('subscriptions')->insert(array_merge([
            'company_id' => $companyId,
            'pricing_plan_id' => $planId,
            'active' => true,
            'start_date' => now()->subMonth()->toDateString(),
            'end_date' => now()->addMonth()->toDateString(),
            'created_at' => now(),
            'updated_at' => now(),
        ], $subAttrs));

        return Company::findOrFail($companyId);
    }

    private function seedHsMaster(): void
    {
        DB::table('global_hs_master')->insert([
            [
                'hs_code' => '84713010', 'description' => 'Laptops', 'schedule_type' => 'standard',
                'tax_rate' => 18, 'pct_code' => '8471.3010', 'default_uom' => 'Numbers, pieces, units',
                'sro_number' => null, 'sro_item_serial_no' => null, 'is_active' => 1,
                'created_at' => now(), 'updated_at' => now(),
            ],
            [
                'hs_code' => '31021000', 'description' => 'Urea fertilizer', 'schedule_type' => 'exempt',
                'tax_rate' => 0, 'pct_code' => '3102.1000', 'default_uom' => 'Kg',
                'sro_number' => 'SRO 321', 'sro_item_serial_no' => '12',
                'is_active' => 1, 'created_at' => now(), 'updated_at' => now(),
            ],
        ]);
    }

    private function fakeOpenAi(array $extraction): void
    {
        Http::fake([
            'api.openai.com/*' => Http::response([
                'choices' => [['message' => ['content' => json_encode($extraction)]]],
                'usage' => ['total_tokens' => 777],
            ], 200),
        ]);
    }

    private function baseExtraction(array $overrides = []): array
    {
        return array_merge([
            'is_invoice' => true,
            'buyer' => [
                'name' => 'Bismillah Traders',
                'ntn' => 'NTN # 1234567-8',
                'cnic' => '',
                'address' => 'Shop 4, Karachi',
                'phone' => '0300-1234567',
                'confidence' => 'high',
            ],
            'document' => [
                'invoice_number' => 'SUP-001',
                'invoice_date' => '2025-06-01',
                'document_type' => 'Sale Invoice',
                'destination_province' => 'Sindh',
            ],
            'items' => [[
                'description' => 'Laptop Computer Core i5',
                'hs_code' => '8471.3010',
                'quantity' => 2,
                'uom' => 'Pcs',
                'unit_price' => 100000,
                'tax_rate' => 18,
                'tax_amount' => 36000,
                'confidence' => 'high',
            ]],
            'totals' => ['subtotal' => 200000, 'tax' => 36000, 'grand_total' => 236000],
            'warnings' => [],
        ], $overrides);
    }

    // ------------------------------------------------------------------ quota

    public function test_monthly_quota_by_plan(): void
    {
        $premium = $this->makeCompany('Premium');
        $this->assertSame(AiInvoiceReaderService::QUOTA_PREMIUM, AiInvoiceReaderService::monthlyQuota($premium));

        $trial = $this->makeCompany('Trial', ['trial_ends_at' => now()->addDays(10)]);
        $this->assertSame(AiInvoiceReaderService::QUOTA_TRIAL, AiInvoiceReaderService::monthlyQuota($trial));

        $retail = $this->makeCompany('Retail');
        $this->assertSame(AiInvoiceReaderService::QUOTA_DEFAULT, AiInvoiceReaderService::monthlyQuota($retail));

        $internal = $this->makeCompany('Retail', [], ['is_internal_account' => true]);
        $this->assertSame(-1, AiInvoiceReaderService::monthlyQuota($internal));
    }

    public function test_used_this_month_counts_only_successful_parses(): void
    {
        $company = $this->makeCompany('Premium');

        AiInvoiceParse::create(['company_id' => $company->id, 'status' => 'success', 'source_type' => 'pdf']);
        AiInvoiceParse::create(['company_id' => $company->id, 'status' => 'failed', 'source_type' => 'pdf', 'error' => 'x']);
        $old = AiInvoiceParse::create(['company_id' => $company->id, 'status' => 'success', 'source_type' => 'pdf']);
        $old->created_at = now()->subMonths(2);
        $old->save();

        // Another company's parses never leak in.
        $other = $this->makeCompany('Premium');
        AiInvoiceParse::create(['company_id' => $other->id, 'status' => 'success', 'source_type' => 'pdf']);

        $this->assertSame(1, AiInvoiceReaderService::usedThisMonth($company->id));

        $state = AiInvoiceReaderService::quotaState($company);
        $this->assertFalse($state['unlimited']);
        $this->assertSame(1, $state['used']);
        $this->assertSame(AiInvoiceReaderService::QUOTA_PREMIUM, $state['quota']);
        $this->assertSame(AiInvoiceReaderService::QUOTA_PREMIUM - 1, $state['remaining']);
    }

    // --------------------------------------------------------- mapExtraction

    public function test_map_extraction_resolves_document_hs_product_fallback_and_flags_missing(): void
    {
        $company = $this->makeCompany('Premium');
        $this->seedHsMaster();

        DB::table('products')->insert([
            'company_id' => $company->id,
            'name' => 'Portland Cement Bag',
            'hs_code' => '25232900',
            'pct_code' => '2523.2900',
            'default_tax_rate' => 18,
            'uom' => 'Bag',
            'schedule_type' => 'standard',
            'is_active' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $raw = $this->baseExtraction([
            'items' => [
                [ // resolved straight from the printed HS code
                    'description' => 'Laptop Computer Core i5',
                    'hs_code' => '8471.3010',
                    'quantity' => 2, 'uom' => 'Pcs', 'unit_price' => 100000,
                    'tax_rate' => 18, 'tax_amount' => 36000, 'confidence' => 'high',
                ],
                [ // no printed HS — must come from the company's product list
                    'description' => 'Portland Cement 50kg Bag',
                    'hs_code' => '',
                    'quantity' => 40, 'uom' => 'Bag', 'unit_price' => 1250,
                    'tax_rate' => 18, 'tax_amount' => null, 'confidence' => 'high',
                ],
                [ // nothing anywhere — needs_hs + low confidence + warning
                    'description' => 'Local Delivery Charges',
                    'hs_code' => '',
                    'quantity' => 1, 'uom' => 'Job', 'unit_price' => 5000,
                    'tax_rate' => null, 'tax_amount' => null, 'confidence' => 'high',
                ],
            ],
        ]);

        $mapped = AiInvoiceReaderService::mapExtraction($raw, $company);

        $this->assertSame('Bismillah Traders', $mapped['buyer']['name']);
        $this->assertSame('1234567-8', $mapped['buyer']['ntn'], 'NTN must be cleaned to digits+dash');
        $this->assertSame('Sindh', $mapped['document']['destination_province']);
        $this->assertSame('Sale Invoice', $mapped['document']['document_type']);

        [$laptop, $cement, $delivery] = $mapped['items'];

        $this->assertSame('84713010', preg_replace('/\D/', '', $laptop['hs_code']));
        $this->assertSame('document', $laptop['hs_source']);
        $this->assertSame('standard', $laptop['schedule_type']);
        $this->assertFalse($laptop['needs_hs']);

        $this->assertSame('25232900', preg_replace('/\D/', '', $cement['hs_code']));
        $this->assertSame('product', $cement['hs_source']);
        $this->assertFalse($cement['needs_hs']);
        // tax derived from qty × price × rate when the document omitted it
        $this->assertEqualsWithDelta(40 * 1250 * 0.18, (float) $cement['tax'], 0.01);

        $this->assertSame('', $delivery['hs_code']);
        $this->assertSame('none', $delivery['hs_source']);
        $this->assertTrue($delivery['needs_hs']);
        $this->assertSame('low', $delivery['ai_confidence']);

        $this->assertTrue(
            collect($mapped['warnings'])->contains(fn ($w) => str_contains($w, 'No HS code')),
            'missing-HS warning expected'
        );
    }

    public function test_map_extraction_keeps_printed_mrp_and_blanks_bad_values(): void
    {
        $company = $this->makeCompany('Premium');

        $raw = [
            'is_invoice' => true,
            'buyer' => ['name' => 'Test Buyer'],
            'document' => ['document_type' => 'Sale Invoice'],
            'items' => [
                ['description' => 'Cola 1.5L', 'quantity' => 10, 'unit_price' => 150, 'mrp' => 180],
                ['description' => 'Chips',     'quantity' => 5,  'unit_price' => 50,  'mrp' => -3],
                ['description' => 'Biscuits',  'quantity' => 2,  'unit_price' => 90],
            ],
        ];

        $mapped = AiInvoiceReaderService::mapExtraction($raw, $company);
        [$cola, $chips, $biscuits] = $mapped['items'];

        $this->assertSame(180.0, $cola['mrp'], 'printed MRP must ride into the prefill');
        $this->assertSame('', $chips['mrp'], 'negative MRP must be discarded');
        $this->assertSame('', $biscuits['mrp'], 'absent MRP stays blank');
    }

    public function test_map_extraction_exempt_schedule_forces_zero_tax(): void
    {
        $company = $this->makeCompany('Premium');
        $this->seedHsMaster();

        $raw = $this->baseExtraction([
            'items' => [[
                'description' => 'Urea Fertilizer 50kg',
                'hs_code' => '3102.1000',
                'quantity' => 10, 'uom' => 'Kg', 'unit_price' => 3000,
                'tax_rate' => 18, 'tax_amount' => 5400, // document wrongly charged tax
                'confidence' => 'high',
            ]],
        ]);

        $mapped = AiInvoiceReaderService::mapExtraction($raw, $company);
        $item = $mapped['items'][0];

        $this->assertSame('exempt', $item['schedule_type']);
        $this->assertSame(0.0, (float) $item['tax_rate']);
        $this->assertSame(0.0, (float) $item['tax']);
    }

    public function test_map_extraction_credit_note_maps_reference_and_warns(): void
    {
        $company = $this->makeCompany('Premium');
        $this->seedHsMaster();

        $raw = $this->baseExtraction([
            'document' => [
                'invoice_number' => 'CN-55',
                'invoice_date' => '2025-06-01',
                'document_type' => 'Credit Note',
                'destination_province' => 'Sindh',
            ],
        ]);

        $mapped = AiInvoiceReaderService::mapExtraction($raw, $company);

        $this->assertSame('Credit Note', $mapped['document']['document_type']);
        $this->assertNotSame('', (string) $mapped['document']['reference_invoice_number'], 'credit/debit note must carry a reference prefill');
        $this->assertTrue(
            collect($mapped['warnings'])->contains(fn ($w) => stripos($w, 'note') !== false),
            'credit-note warning expected'
        );
    }

    // ------------------------------------------------------------ parseUpload

    public function test_parse_upload_success_stores_mapped_payload(): void
    {
        $company = $this->makeCompany('Premium');
        $this->seedHsMaster();
        $this->fakeOpenAi($this->baseExtraction());

        $file = UploadedFile::fake()->createWithContent(
            'old-invoice.csv',
            "Invoice,SUP-001\nBuyer,Bismillah Traders\nLaptop Computer Core i5,2,100000,36000\nTotal,236000\n"
        );

        $parse = AiInvoiceReaderService::parseUpload($file, $company, null);

        $this->assertSame('success', $parse->status);
        $this->assertSame('csv', $parse->source_type);
        $this->assertSame(777, $parse->total_tokens);
        $this->assertNull($parse->invoice_id);
        $this->assertSame('Bismillah Traders', $parse->payload_json['buyer']['name']);
        $this->assertCount(1, $parse->payload_json['items']);
        $this->assertSame(1, AiInvoiceReaderService::usedThisMonth($company->id));
    }

    public function test_parse_upload_not_an_invoice_fails_friendly_and_never_counts(): void
    {
        $company = $this->makeCompany('Premium');
        $this->fakeOpenAi(['is_invoice' => false]);

        $file = UploadedFile::fake()->createWithContent(
            'shopping.csv',
            "weekly grocery list\nmilk, eggs, bread and some butter for the house\n"
        );

        try {
            AiInvoiceReaderService::parseUpload($file, $company, null);
            $this->fail('AiReaderException expected');
        } catch (AiReaderException $e) {
            $this->assertStringContainsString('invoice', strtolower($e->getMessage()));
        }

        $this->assertSame(1, AiInvoiceParse::where('company_id', $company->id)->where('status', 'failed')->count());
        $this->assertSame(0, AiInvoiceReaderService::usedThisMonth($company->id));
    }

    public function test_parse_upload_api_error_stores_failed_row(): void
    {
        $company = $this->makeCompany('Premium');
        Http::fake(['api.openai.com/*' => Http::response(['error' => ['message' => 'rate limit']], 429)]);

        $file = UploadedFile::fake()->createWithContent(
            'inv.csv',
            "Invoice,SUP-002\nBuyer,Someone\nWidget,1,500,90\nTotal,590\n"
        );

        try {
            AiInvoiceReaderService::parseUpload($file, $company, null);
            $this->fail('AiReaderException expected');
        } catch (AiReaderException $e) {
            // friendly message, not a raw API error dump
            $this->assertStringNotContainsString('429', $e->getMessage());
        }

        $row = AiInvoiceParse::where('company_id', $company->id)->first();
        $this->assertSame('failed', $row->status);
        $this->assertNotNull($row->error);
        $this->assertSame(0, AiInvoiceReaderService::usedThisMonth($company->id));
    }

    public function test_item_cap_truncates_and_warns(): void
    {
        $company = $this->makeCompany('Premium');
        $this->seedHsMaster();

        $items = [];
        for ($i = 0; $i < AiInvoiceReaderService::MAX_ITEMS + 5; $i++) {
            $items[] = [
                'description' => "Item {$i}",
                'hs_code' => '8471.3010',
                'quantity' => 1, 'uom' => 'Pcs', 'unit_price' => 100,
                'tax_rate' => 18, 'tax_amount' => 18, 'confidence' => 'high',
            ];
        }

        $mapped = AiInvoiceReaderService::mapExtraction($this->baseExtraction(['items' => $items]), $company);

        $this->assertCount(AiInvoiceReaderService::MAX_ITEMS, $mapped['items']);
        $this->assertTrue(
            collect($mapped['warnings'])->contains(fn ($w) => str_contains($w, (string) AiInvoiceReaderService::MAX_ITEMS)),
            'item-cap warning expected'
        );
    }

    // ----------------------------------------------- scanned PDF multi-page

    /** Build an image-only (no extractable text) PDF with $pages pages. */
    private function makeScannedPdf(int $pages): UploadedFile
    {
        $html = '';
        for ($i = 1; $i <= $pages; $i++) {
            $im = imagecreatetruecolor(300, 400);
            $white = imagecolorallocate($im, 255, 255, 255);
            imagefill($im, 0, 0, $white);
            imagestring($im, 5, 40, 40, "PAGE {$i}", imagecolorallocate($im, 0, 0, 0));
            ob_start();
            imagejpeg($im);
            $b64 = base64_encode((string) ob_get_clean());
            imagedestroy($im);
            $html .= '<div style="page-break-after:always"><img src="data:image/jpeg;base64,' . $b64 . '"></div>';
        }

        $dompdf = new \Dompdf\Dompdf();
        $dompdf->loadHtml($html);
        $dompdf->render();

        $path = tempnam(sys_get_temp_dir(), 'aitest_') . '.pdf';
        file_put_contents($path, $dompdf->output());

        return new UploadedFile($path, 'scanned.pdf', 'application/pdf', null, true);
    }

    private function requireRasterizer(): void
    {
        if (trim((string) @shell_exec('command -v pdftoppm 2>/dev/null')) === ''
            && trim((string) @shell_exec('command -v gs 2>/dev/null')) === '') {
            $this->markTestSkipped('no PDF rasterizer (pdftoppm/gs) available');
        }
    }

    public function test_scanned_pdf_sends_capped_pages_in_one_vision_request_and_warns(): void
    {
        $this->requireRasterizer();
        $company = $this->makeCompany('Premium');
        $this->seedHsMaster();
        $this->fakeOpenAi($this->baseExtraction());

        $file = $this->makeScannedPdf(AiInvoiceReaderService::MAX_SCAN_PAGES + 2);
        $parse = AiInvoiceReaderService::parseUpload($file, $company, null);

        // Exactly ONE API request carrying MAX_SCAN_PAGES images.
        $recorded = Http::recorded();
        $this->assertCount(1, $recorded);
        $content = $recorded[0][0]->data()['messages'][1]['content'];
        $images = array_values(array_filter($content, fn ($p) => ($p['type'] ?? '') === 'image_url'));
        $this->assertCount(AiInvoiceReaderService::MAX_SCAN_PAGES, $images);
        foreach ($images as $img) {
            $this->assertStringStartsWith('data:image/jpeg;base64,', $img['image_url']['url']);
        }
        // Prompt tells the model these are consecutive pages of one document.
        $this->assertStringContainsString('consecutive pages', $content[0]['text']);

        // Page-cap warning surfaces in the stored payload.
        $this->assertSame('success', $parse->status);
        $this->assertTrue(
            collect($parse->payload_json['warnings'])->contains(fn ($w) => str_contains($w, 'first ' . AiInvoiceReaderService::MAX_SCAN_PAGES . ' pages')),
            'page-cap warning expected in payload'
        );
    }

    public function test_scanned_pdf_within_cap_sends_all_pages_without_cap_warning(): void
    {
        $this->requireRasterizer();
        $company = $this->makeCompany('Premium');
        $this->seedHsMaster();
        $this->fakeOpenAi($this->baseExtraction());

        $file = $this->makeScannedPdf(2);
        $parse = AiInvoiceReaderService::parseUpload($file, $company, null);

        $content = Http::recorded()[0][0]->data()['messages'][1]['content'];
        $images = array_values(array_filter($content, fn ($p) => ($p['type'] ?? '') === 'image_url'));
        $this->assertCount(2, $images);

        $this->assertFalse(
            collect($parse->payload_json['warnings'])->contains(fn ($w) => str_contains($w, 'pages were read')),
            'no page-cap warning expected when all pages fit'
        );
    }

    // ------------------------------------------------------------- linking

    public function test_parse_links_to_one_invoice_only_and_only_same_company(): void
    {
        $company = $this->makeCompany('Premium');
        $other = $this->makeCompany('Premium');

        $parse = AiInvoiceParse::create([
            'company_id' => $company->id, 'status' => 'success', 'source_type' => 'pdf',
        ]);

        // The exact linking query InvoiceController::store uses.
        $link = fn (int $parseId, int $companyId, int $invoiceId) => AiInvoiceParse::where('id', $parseId)
            ->where('company_id', $companyId)
            ->whereNull('invoice_id')
            ->update(['invoice_id' => $invoiceId]);

        $this->assertSame(0, $link($parse->id, $other->id, 999), 'cross-company link must be a no-op');
        $this->assertSame(1, $link($parse->id, $company->id, 101));
        $this->assertSame(0, $link($parse->id, $company->id, 202), 'a parse must never be re-linked');
        $this->assertSame(101, $parse->fresh()->invoice_id);
    }
}
