<?php

namespace Tests\Feature;

use App\Http\Controllers\AiInvoiceReaderController;
use App\Models\BulkAiImageBatch;
use App\Models\Company;
use App\Services\BulkAiImageImportService;
use App\Services\DiFeatureService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Tests\TestCase;

/**
 * Task 1342: reopen a past Bulk AI Image Import batch.
 *
 * Locks the rules the history list depends on:
 *   - the list is COMPANY scoped and newest first;
 *   - counts are recomputed from the stored items, never read from the
 *     cached columns on the batch row (those stop at the last browser poll,
 *     so a closed tab would list stale numbers);
 *   - a batch whose private source photos were already pruned still reports
 *     its review data, flagged as "photos removed";
 *   - the workspace reopens a past batch by id, and another company's batch
 *     id is a 404 — never a silent empty workspace.
 */
class BulkAiImageBatchHistoryTest extends TestCase
{
    private Company $company;

    protected function setUp(): void
    {
        parent::setUp();

        Schema::dropAllTables();

        Schema::create('companies', function (Blueprint $t) {
            $t->id();
            $t->string('name');
            $t->string('product_type')->nullable();
            $t->boolean('is_internal_account')->default(false);
            $t->softDeletes();
            $t->timestamps();
        });
        Schema::create('ai_invoice_parses', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('company_id');
            $t->string('status')->default('success');
            $t->timestamps();
        });
        Schema::create('bulk_ai_image_batches', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('company_id');
            $t->unsignedBigInteger('user_id')->nullable();
            $t->string('batch_uuid');
            $t->string('status')->default('queued');
            $t->unsignedInteger('total_images')->default(0);
            $t->unsignedInteger('processed_images')->default(0);
            $t->unsignedInteger('ready_images')->default(0);
            $t->unsignedInteger('needs_review_images')->default(0);
            $t->unsignedInteger('duplicate_images')->default(0);
            $t->unsignedInteger('failed_images')->default(0);
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

        // is_internal_account keeps the 'ai_reader' gate open without the
        // whole subscription stack — the gate itself is Task 135's contract.
        $this->company = Company::create(['name' => 'Distributor Traders', 'is_internal_account' => true]);
        app()->instance('currentCompanyId', $this->company->id);
        DiFeatureService::flushGateCaches();
    }

    /** @param array<int,array{0:string,1:bool}> $items status + "source photo pruned" */
    private function batch(int $companyId, array $items, array $attributes = []): BulkAiImageBatch
    {
        $batch = BulkAiImageBatch::create(array_merge([
            'company_id' => $companyId,
            'batch_uuid' => 'batch-' . uniqid(),
            'status' => 'queued',
            'total_images' => count($items),
        ], $attributes));

        foreach ($items as $i => [$status, $pruned]) {
            DB::table('bulk_ai_image_items')->insert([
                'batch_id' => $batch->id,
                'company_id' => $companyId,
                'source_uuid' => 'source-' . $batch->id . '-' . $i,
                'position' => $i + 1,
                'original_filename' => 'photo-' . ($i + 1) . '.jpg',
                'storage_path' => $pruned ? null : 'private/ai-bulk/x',
                'status' => $status,
                'reservation_status' => 'consumed',
                'processed_at' => in_array($status, ['queued', 'processing'], true) ? null : now(),
                'source_deleted_at' => $pruned ? now() : null,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        return $batch;
    }

    private function service(): BulkAiImageImportService
    {
        return app(BulkAiImageImportService::class);
    }

    public function test_history_lists_only_this_companys_batches_newest_first(): void
    {
        $older = $this->batch($this->company->id, [['ready', false]]);
        $newer = $this->batch($this->company->id, [['ready', false], ['failed', false]]);

        $rival = Company::create(['name' => 'Rival Traders']);
        $this->batch($rival->id, [['ready', false]]);

        $listed = $this->service()->historyForCompany($this->company->id);

        $this->assertSame(2, $listed->total());
        $this->assertSame([$newer->id, $older->id], collect($listed->items())->pluck('id')->all());
    }

    public function test_counts_come_from_the_stored_items_not_the_stale_batch_columns(): void
    {
        // Tab closed after the first poll: the cached columns froze at 1 of 4.
        $batch = $this->batch($this->company->id, [
            ['ready', false], ['needs_review', false], ['duplicate', false], ['failed', false],
        ], ['processed_images' => 1, 'ready_images' => 1, 'status' => 'queued']);

        $summary = $this->service()->historySummaries([$batch])[$batch->id];

        $this->assertSame(4, $summary['total']);
        $this->assertSame(4, $summary['processed']);
        $this->assertSame(
            ['ready' => 1, 'needs_review' => 1, 'duplicate' => 1, 'failed' => 1, 'pending' => 0],
            $summary['counts']
        );
        $this->assertSame('completed', $summary['state']);
        $this->assertSame('Completed', $summary['state_label']);
        $this->assertFalse($summary['photos_removed']);
    }

    public function test_a_half_run_batch_reads_in_progress_and_an_empty_one_never_finished(): void
    {
        $running = $this->batch($this->company->id, [['ready', false], ['queued', false]]);
        // Photos were chosen but the upload never reached the server.
        $abandoned = $this->batch($this->company->id, [], ['total_images' => 3]);

        $summaries = $this->service()->historySummaries([$running, $abandoned]);

        $this->assertSame('in_progress', $summaries[$running->id]['state']);
        $this->assertSame(1, $summaries[$running->id]['counts']['pending']);
        $this->assertSame(1, $summaries[$running->id]['processed']);

        $this->assertSame('unfinished', $summaries[$abandoned->id]['state']);
        $this->assertSame('Never finished', $summaries[$abandoned->id]['state_label']);
        $this->assertSame(3, $summaries[$abandoned->id]['total']);
        $this->assertSame(0, $summaries[$abandoned->id]['processed']);
    }

    public function test_a_pruned_batch_still_reports_its_review_data(): void
    {
        $batch = $this->batch($this->company->id, [['ready', true], ['needs_review', true]], [
            'status' => 'completed',
            'retention_until' => now()->subDay(),
        ]);

        $summary = $this->service()->historySummaries([$batch])[$batch->id];

        $this->assertTrue($summary['photos_removed']);
        $this->assertSame(2, $summary['processed']);
        $this->assertSame(1, $summary['counts']['ready']);
        $this->assertSame(1, $summary['counts']['needs_review']);

        // The stored review rows survive the photo purge, so the summary
        // download stays available for a reopened batch.
        $report = $this->service()->reviewReport($batch->fresh());
        $this->assertCount(2, $report['rows']);
    }

    public function test_reopening_a_past_batch_bakes_its_id_into_the_workspace(): void
    {
        $batch = $this->batch($this->company->id, [['ready', false]]);

        $request = Request::create('/invoices/ai-reader/bulk-images', 'GET', ['batch' => $batch->id]);
        $view = (new AiInvoiceReaderController())->bulk($request, $this->service());

        $this->assertSame('invoice.ai-reader-bulk', $view->name());
        $this->assertSame($batch->id, $view->getData()['openBatchId']);

        // No ?batch= is still the plain "start a new batch" workspace.
        $fresh = (new AiInvoiceReaderController())->bulk(
            Request::create('/invoices/ai-reader/bulk-images'),
            $this->service()
        );
        $this->assertNull($fresh->getData()['openBatchId']);
    }

    public function test_another_companys_batch_cannot_be_reopened(): void
    {
        $rival = Company::create(['name' => 'Rival Traders']);
        $rivalBatch = $this->batch($rival->id, [['ready', false]]);

        $request = Request::create('/invoices/ai-reader/bulk-images', 'GET', ['batch' => $rivalBatch->id]);

        $this->expectException(NotFoundHttpException::class);
        (new AiInvoiceReaderController())->bulk($request, $this->service());
    }

    public function test_history_page_hands_the_view_a_summary_for_every_listed_batch(): void
    {
        $this->batch($this->company->id, [['ready', false]]);
        $this->batch($this->company->id, [['failed', false]]);

        $view = (new AiInvoiceReaderController())->bulkHistory($this->service());
        $data = $view->getData();

        $this->assertSame('invoice.ai-reader-bulk-history', $view->name());
        $this->assertTrue($data['allowed']);
        $this->assertSame(2, $data['batches']->total());
        foreach ($data['batches']->items() as $batch) {
            $this->assertArrayHasKey($batch->id, $data['summaries']);
        }
    }

    public function test_history_route_is_registered_on_the_company_scoped_web_group(): void
    {
        $route = Route::getRoutes()->getByName('invoices.ai-reader.bulk.history');

        $this->assertNotNull($route);
        $this->assertSame('invoices/ai-reader/bulk-images/history', $route->uri());
        foreach (['auth', 'company', 'company.approval'] as $middleware) {
            $this->assertContains($middleware, $route->gatherMiddleware());
        }
    }
}
