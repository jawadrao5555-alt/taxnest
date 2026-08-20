<?php

namespace Tests\Feature;

use App\Http\Controllers\AiInvoiceReaderController;
use App\Models\BulkAiImageBatch;
use App\Models\Company;
use App\Services\BulkAiImageImportService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Tests\TestCase;

/**
 * Task 1330: shareable Bulk AI Image Import review summary.
 *
 * Locks the hand-off rules:
 *   - one row per SOURCE photo with filename, status, concise notes and the
 *     linked draft number — including rows that produced no draft;
 *   - notes stay short (capped, de-duplicated, one line each) and a duplicate
 *     row names the photo it repeats;
 *   - the export NEVER carries the private source photo's storage path, uuid,
 *     or content hash;
 *   - the report is company scoped: another company's batch is a 404;
 *   - both formats are served (CSV stream + printable PDF).
 */
class BulkAiImageReviewReportTest extends TestCase
{
    private Company $company;
    private BulkAiImageBatch $batch;

    protected function setUp(): void
    {
        parent::setUp();

        Schema::dropAllTables();

        Schema::create('companies', function (Blueprint $t) {
            $t->id();
            $t->string('name');
            $t->string('ntn')->nullable();
            $t->string('address')->nullable();
            $t->string('phone')->nullable();
            $t->string('email')->nullable();
            $t->softDeletes();
            $t->timestamps();
        });
        Schema::create('invoices', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('company_id');
            $t->string('invoice_number')->nullable();
            $t->string('internal_invoice_number')->nullable();
            $t->string('fbr_invoice_number')->nullable();
            $t->string('share_uuid')->nullable();
            $t->string('status')->default('draft');
            $t->timestamps();
        });
        Schema::create('bulk_ai_image_batches', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('company_id');
            $t->unsignedBigInteger('user_id')->nullable();
            $t->string('batch_uuid');
            $t->string('status')->default('completed');
            $t->unsignedInteger('total_images')->default(0);
            $t->unsignedInteger('reserved_credits')->default(0);
            $t->timestamp('finished_at')->nullable();
            $t->timestamp('retention_until')->nullable();
            $t->string('annexure_status')->default('none');
            $t->string('annexure_filename')->nullable();
            $t->timestamps();
        });
        Schema::create('bulk_ai_image_items', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('batch_id');
            $t->unsignedBigInteger('company_id');
            $t->string('source_uuid');
            $t->unsignedInteger('position');
            $t->string('original_filename');
            $t->string('content_hash')->nullable();
            $t->string('storage_path')->nullable();
            $t->string('status')->default('not_started');
            $t->string('reservation_status')->default('reserved');
            $t->unsignedBigInteger('invoice_id')->nullable();
            $t->longText('warnings_json')->nullable();
            $t->longText('details_json')->nullable();
            $t->text('error')->nullable();
            $t->timestamp('processed_at')->nullable();
            $t->timestamp('source_deleted_at')->nullable();
            $t->timestamps();
        });

        $this->company = Company::create(['name' => 'Distributor Traders', 'ntn' => '1234567']);
        app()->instance('currentCompanyId', $this->company->id);

        $this->batch = BulkAiImageBatch::create([
            'company_id' => $this->company->id,
            'user_id' => 7,
            'batch_uuid' => 'batch-uuid-1',
            'status' => 'completed',
            'total_images' => 5,
            'finished_at' => now(),
        ]);

        $draftId = DB::table('invoices')->insertGetId([
            'company_id' => $this->company->id,
            'internal_invoice_number' => 'DI-00021',
            'status' => 'draft',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->item(1, 'invoice-01.jpg', 'ready', ['invoice_id' => $draftId]);
        $this->item(2, 'invoice-02.jpg', 'needs_review', ['warnings' => [
            'HS code missing on line 1.',
            'A line has no readable document price.',
            "Buyer NTN could not be\n  read from the photo.",
            'Annexure: manual product match review is required.',
            'Destination province missing.',
        ]]);
        $this->item(3, 'invoice-03.jpg', 'duplicate', ['details' => [
            'duplicate_of' => 1,
            'message' => 'The buyer, invoice number, and date repeat another source photo in this batch.',
        ]]);
        $this->item(4, '=2+3-invoice.jpg', 'failed', ['warnings' => ['The AI service did not respond. Please retry this photo.']]);
        $this->item(5, 'invoice-05.jpg', 'queued', ['processed_at' => null]);
    }

    private function item(int $position, string $filename, string $status, array $extra = []): void
    {
        DB::table('bulk_ai_image_items')->insert([
            'batch_id' => $this->batch->id,
            'company_id' => $this->company->id,
            'source_uuid' => 'source-uuid-' . $position,
            'position' => $position,
            'original_filename' => $filename,
            'content_hash' => 'contenthash' . $position,
            'storage_path' => 'private/ai-bulk/' . $this->company->id . '/' . $this->batch->id . '/source-uuid-' . $position . '/source.jpg',
            'status' => $status,
            'reservation_status' => 'consumed',
            'invoice_id' => $extra['invoice_id'] ?? null,
            'warnings_json' => isset($extra['warnings']) ? json_encode($extra['warnings']) : null,
            'details_json' => isset($extra['details']) ? json_encode($extra['details']) : null,
            'processed_at' => array_key_exists('processed_at', $extra) ? $extra['processed_at'] : now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function download(string $format = 'csv', ?int $batchId = null)
    {
        $batchId = $batchId ?? $this->batch->id;
        $request = Request::create('/invoices/ai-reader/bulk-images/' . $batchId . '/report', 'GET', ['format' => $format]);

        return (new AiInvoiceReaderController())->bulkReport($request, $batchId, app(BulkAiImageImportService::class));
    }

    private function csvBody(): string
    {
        $response = $this->download('csv');
        ob_start();
        $response->sendContent();

        return (string) ob_get_clean();
    }

    public function test_csv_lists_every_source_photo_with_status_and_linked_draft_number(): void
    {
        $csv = $this->csvBody();

        $this->assertStringContainsString('#,"Source file",Status,"Review notes","Draft invoice #","Processed at"', $csv);
        foreach (['invoice-01.jpg', 'invoice-02.jpg', 'invoice-03.jpg', 'invoice-05.jpg'] as $filename) {
            $this->assertStringContainsString($filename, $csv);
        }
        $this->assertStringContainsString('Ready', $csv);
        $this->assertStringContainsString('Needs review', $csv);
        $this->assertStringContainsString('Duplicate', $csv);
        $this->assertStringContainsString('Failed', $csv);
        $this->assertStringContainsString('Queued', $csv);
        $this->assertStringContainsString('DI-00021', $csv);

        // A user-supplied filename must never be handed to Excel as a formula.
        $this->assertStringContainsString("'=2+3-invoice.jpg", $csv);
    }

    public function test_csv_never_exposes_the_private_source_photo(): void
    {
        $csv = $this->csvBody();

        $this->assertStringNotContainsString('private/ai-bulk', $csv);
        $this->assertStringNotContainsString('source-uuid-', $csv);
        $this->assertStringNotContainsString('contenthash', $csv);
        $this->assertStringNotContainsString('batch-uuid-1', $csv);
    }

    public function test_notes_stay_concise_and_a_duplicate_names_the_photo_it_repeats(): void
    {
        $report = app(BulkAiImageImportService::class)->reviewReport($this->batch);
        $rows = collect($report['rows'])->keyBy('position');

        $this->assertSame([], $rows[1]['notes']);
        $this->assertCount(4, $rows[2]['notes']); // 3 notes + the "+N more" pointer
        $this->assertSame('+2 more note(s) — open the batch in TaxNest to see all.', $rows[2]['notes'][3]);
        $this->assertSame('Buyer NTN could not be read from the photo.', $rows[2]['notes'][2]);
        $this->assertStringContainsString('same as invoice-01.jpg', $rows[3]['notes'][0]);
        $this->assertSame('', $rows[3]['draft_number']);
        $this->assertSame('DI-00021', $rows[1]['draft_number']);
        $this->assertSame(
            ['ready' => 1, 'needs_review' => 1, 'duplicate' => 1, 'failed' => 1, 'pending' => 1],
            $report['counts']
        );
        $this->assertSame(4, $report['batch']['processed']);
    }

    public function test_another_companys_batch_is_not_downloadable(): void
    {
        $other = Company::create(['name' => 'Rival Traders']);
        $otherBatch = BulkAiImageBatch::create([
            'company_id' => $other->id,
            'batch_uuid' => 'batch-uuid-2',
            'status' => 'completed',
            'total_images' => 1,
        ]);

        $this->expectException(NotFoundHttpException::class);
        $this->download('csv', $otherBatch->id);
    }

    public function test_pdf_download_is_a_pdf_and_the_rendered_page_hides_the_private_photo(): void
    {
        $response = $this->download('pdf');

        $this->assertSame('application/pdf', $response->headers->get('Content-Type'));
        $this->assertStringContainsString('attachment', (string) $response->headers->get('Content-Disposition'));
        $this->assertStringContainsString('bulk-ai-review-batch-' . $this->batch->id, (string) $response->headers->get('Content-Disposition'));
        $this->assertStringStartsWith('%PDF', (string) $response->getContent());

        $html = view('invoice.ai-reader-bulk-report', [
            'company' => $this->company,
            'title' => 'Bulk AI Image Import Review',
            'report' => app(BulkAiImageImportService::class)->reviewReport($this->batch),
        ])->render();

        $this->assertStringContainsString('invoice-01.jpg', $html);
        $this->assertStringContainsString('DI-00021', $html);
        $this->assertStringContainsString('Needs review', $html);
        $this->assertStringNotContainsString('private/ai-bulk', $html);
        $this->assertStringNotContainsString('source-uuid-', $html);
    }

    public function test_report_route_is_registered_on_the_company_scoped_web_group(): void
    {
        $route = Route::getRoutes()->getByName('invoices.ai-reader.bulk.report');

        $this->assertNotNull($route);
        $this->assertSame('invoices/ai-reader/bulk-images/{batchId}/report', $route->uri());
        foreach (['auth', 'company', 'company.approval'] as $middleware) {
            $this->assertContains($middleware, $route->gatherMiddleware());
        }
    }
}
