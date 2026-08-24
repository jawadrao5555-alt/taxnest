<?php

namespace App\Jobs;

use App\Models\Company;
use App\Models\InvoiceImportBatch;
use App\Services\InvoiceImportService;
use App\Services\PlanLimitService;
use App\Services\SubscriptionAccessService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Background processor for a validated invoice import batch: creates draft
 * invoices per buyer group, updating live progress counters the UI polls.
 *
 * $tries = 1 on purpose — a retry after a mid-batch crash would duplicate
 * already-created invoices. Partial progress is preserved and reported.
 */
class ProcessInvoiceImportBatchJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $tries = 1;
    public $timeout = 1800;

    public function __construct(public int $batchId)
    {
    }

    public function handle(): void
    {
        // Atomic claim: only one worker may move queued -> processing.
        $claimed = InvoiceImportBatch::where('id', $this->batchId)
            ->where('status', 'queued')
            ->update(['status' => 'processing', 'started_at' => now()]);
        if (!$claimed) {
            return;
        }

        $batch = InvoiceImportBatch::find($this->batchId);
        if (!$batch) {
            return;
        }

        try {
            $company = Company::find($batch->company_id);
            if (!$company) {
                $this->markFailed($batch, 'Company not found.');
                return;
            }

            $validRows = array_values(array_filter($batch->rowsArray(), fn ($r) => !empty($r['valid'])));
            if (empty($validRows)) {
                $this->markFailed($batch, 'No valid rows to import.');
                return;
            }

            // Plan limit — mirrors the invoice-store middleware semantics:
            // lifetime/temporary/grace overrides bypass per-resource caps.
            $maxInvoices = null;
            $access = SubscriptionAccessService::hasAccess($company);
            if (!$access['allowed']) {
                $this->markFailed($batch, $access['reason'] ?? 'Subscription access denied.');
                return;
            }
            if (!in_array($access['override'] ?? null, ['lifetime', 'temporary', 'grace'], true)) {
                $check = PlanLimitService::canCreateInvoice($company->id);
                if (empty($check['allowed'])) {
                    $this->markFailed($batch, $check['reason'] ?? 'Invoice limit reached.');
                    return;
                }
                if (isset($check['remaining'])) {
                    $maxInvoices = (int) $check['remaining'];
                }
            }

            $service = new InvoiceImportService();

            $groupsDone = 0;
            $result = $service->createDraftsFromRows(
                $validRows,
                $company,
                $batch->user_id,
                'bulk_import',
                $maxInvoices,
                function (int $processedRows, int $created, int $failedRows) use ($batch, &$groupsDone) {
                    // Heartbeat every few groups keeps polling cheap but live.
                    $groupsDone++;
                    if ($groupsDone % 5 === 0) {
                        $batch->newQuery()->whereKey($batch->id)->update([
                            'processed_rows' => $processedRows,
                            'created_invoices' => $created,
                            'failed_rows' => $failedRows,
                            'updated_at' => now(),
                        ]);
                    }
                },
                $batch->id
            );

            $createdForSummary = array_slice($result['created'], 0, 300);
            $message = $result['created_count'] . ' draft invoice(s) created from ' . ($result['processed_rows'] - $result['failed_rows']) . ' row(s)';
            if ($result['failed_rows'] > 0) {
                $message .= '; ' . $result['failed_rows'] . ' row(s) failed';
            }

            $batch->newQuery()->whereKey($batch->id)->update([
                'status' => 'completed',
                'processed_rows' => $result['processed_rows'],
                'created_invoices' => $result['created_count'],
                'failed_rows' => $result['failed_rows'],
                'result_json' => json_encode([
                    'message' => $message,
                    'created' => $createdForSummary,
                    'created_total' => $result['created_count'],
                    'row_errors' => $result['row_errors'],
                ]),
                'finished_at' => now(),
                'updated_at' => now(),
            ]);
        } catch (\Throwable $e) {
            Log::error('Invoice import batch #' . $this->batchId . ' failed: ' . $e->getMessage());
            $this->markFailed($batch->fresh() ?? $batch, 'Import stopped unexpectedly: ' . $e->getMessage());
        }
    }

    public function failed(?\Throwable $exception): void
    {
        $batch = InvoiceImportBatch::find($this->batchId);
        if ($batch && in_array($batch->status, ['queued', 'processing'], true)) {
            $this->markFailed($batch, 'Import stopped unexpectedly: ' . ($exception?->getMessage() ?? 'unknown error'));
        }
    }

    private function markFailed(InvoiceImportBatch $batch, string $message): void
    {
        $batch->newQuery()->whereKey($batch->id)->update([
            'status' => 'failed',
            'error_message' => $message,
            'finished_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
