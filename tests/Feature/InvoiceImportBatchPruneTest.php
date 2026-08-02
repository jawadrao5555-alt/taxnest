<?php

namespace Tests\Feature;

use App\Models\InvoiceImportBatch;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * TASK #181: retention pruning of DI bulk import batches.
 *
 * Locks the invariants:
 *   - import-batches:prune NULLs rows_json/result_json of terminal
 *     (completed/failed) batches older than the window, sets pruned_at,
 *     and keeps summary counts.
 *   - Recent batches and already-pruned batches are untouched (idempotent).
 *   - Abandoned non-terminal batches (validated/queued/processing) older
 *     than the window are pruned too.
 *   - isPruned() reflects both the pruned_at flag and the legacy
 *     NULL-JSON-with-rows shape.
 */
class InvoiceImportBatchPruneTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Schema::dropAllTables();

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
            $t->text('error_message')->nullable();
            $t->timestamp('started_at')->nullable();
            $t->timestamp('finished_at')->nullable();
            $t->timestamp('pruned_at')->nullable();
            $t->timestamps();
        });
    }

    private function makeBatch(array $attrs = []): InvoiceImportBatch
    {
        $batch = InvoiceImportBatch::create(array_merge([
            'company_id' => 1,
            'status' => 'completed',
            'total_rows' => 5,
            'valid_rows' => 4,
            'invalid_rows' => 1,
            'rows_json' => json_encode([['row' => 2, 'data' => [], 'valid' => false, 'errors' => ['x']]]),
            'result_json' => json_encode(['message' => 'done']),
            'finished_at' => now()->subDays(40),
        ], $attrs));

        // create() overrides updated_at — force it when the test needs age.
        if (isset($attrs['updated_at'])) {
            InvoiceImportBatch::whereKey($batch->id)->update(['updated_at' => $attrs['updated_at']]);
        }

        return $batch->fresh();
    }

    public function test_old_terminal_batches_are_pruned_and_summary_kept(): void
    {
        $old = $this->makeBatch(['status' => 'completed', 'finished_at' => now()->subDays(31)]);
        $failed = $this->makeBatch(['status' => 'failed', 'finished_at' => now()->subDays(90)]);
        $recent = $this->makeBatch(['status' => 'completed', 'finished_at' => now()->subDays(5)]);

        $this->artisan('import-batches:prune')->assertSuccessful();

        $old->refresh();
        $failed->refresh();
        $recent->refresh();

        $this->assertNull($old->rows_json);
        $this->assertNull($old->result_json);
        $this->assertNotNull($old->pruned_at);
        $this->assertTrue($old->isPruned());
        // Summary counts survive for the history page.
        $this->assertSame(5, (int) $old->total_rows);
        $this->assertSame(1, (int) $old->invalid_rows);

        $this->assertNull($failed->rows_json);
        $this->assertNotNull($failed->pruned_at);

        $this->assertNotNull($recent->rows_json);
        $this->assertNull($recent->pruned_at);
        $this->assertFalse($recent->isPruned());
    }

    public function test_terminal_batch_without_finished_at_uses_updated_at(): void
    {
        $old = $this->makeBatch(['finished_at' => null, 'updated_at' => now()->subDays(45)]);
        $fresh = $this->makeBatch(['finished_at' => null]); // updated_at = now

        $this->artisan('import-batches:prune')->assertSuccessful();

        $this->assertNotNull($old->fresh()->pruned_at);
        $this->assertNull($fresh->fresh()->pruned_at);
    }

    public function test_abandoned_non_terminal_batches_are_pruned(): void
    {
        $abandoned = $this->makeBatch(['status' => 'validated', 'finished_at' => null, 'updated_at' => now()->subDays(35)]);
        $active = $this->makeBatch(['status' => 'validated', 'finished_at' => null]);

        $this->artisan('import-batches:prune')->assertSuccessful();

        $this->assertNotNull($abandoned->fresh()->pruned_at);
        $this->assertNull($active->fresh()->pruned_at);
    }

    public function test_prune_is_idempotent(): void
    {
        $old = $this->makeBatch(['finished_at' => now()->subDays(40)]);

        $this->artisan('import-batches:prune')->assertSuccessful();
        $firstPrunedAt = $old->fresh()->pruned_at;

        $this->artisan('import-batches:prune')
            ->expectsOutputToContain('Pruned heavy JSON from 0 import batch(es)')
            ->assertSuccessful();

        $this->assertEquals($firstPrunedAt, $old->fresh()->pruned_at);
    }

    public function test_days_option_overrides_window(): void
    {
        $batch = $this->makeBatch(['finished_at' => now()->subDays(10)]);

        $this->artisan('import-batches:prune', ['--days' => 7])->assertSuccessful();

        $this->assertNotNull($batch->fresh()->pruned_at);
    }

    public function test_is_pruned_detects_legacy_null_json_shape(): void
    {
        $batch = $this->makeBatch([
            'rows_json' => null,
            'result_json' => null,
            'total_rows' => 5,
        ]);

        $this->assertTrue($batch->isPruned());
    }
}
