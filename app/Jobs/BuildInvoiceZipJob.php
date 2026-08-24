<?php

namespace App\Jobs;

use App\Models\InvoiceZipExport;
use App\Services\InvoiceZipBuilderService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

/**
 * Assembles a bulk invoice-PDF ZIP in resumable chunks.
 *
 * Mirrors BuildAuditPackJob: each invocation works for at most ~60 seconds
 * then re-dispatches itself, so a 50,000-invoice export never holds a single
 * queue attempt open past the database driver's retry_after window. If no
 * queue worker is running at all, the status polling endpoint advances the
 * very same chunk pipeline inline, so the export still finishes.
 */
class BuildInvoiceZipJob implements ShouldQueue
{
    use Queueable;

    public $tries = 3;
    public $timeout = 300;

    public function __construct(public int $exportId)
    {
    }

    public function handle(): void
    {
        $export = InvoiceZipExport::find($this->exportId);
        if (!$export || !$export->isActive()) {
            return;
        }

        $deadline = time() + 60;

        while (time() < $deadline) {
            $state = InvoiceZipBuilderService::processNextChunk($export);

            if ($state === 'done') {
                return;
            }

            if ($state === 'busy') {
                // Another process (poll fallback or a parallel worker) holds the claim.
                Log::info('BuildInvoiceZipJob: export busy, retrying shortly', ['export_id' => $this->exportId]);
                self::dispatch($this->exportId)->delay(now()->addSeconds(30));
                return;
            }

            $export->refresh();
            if (!$export->isActive()) {
                return;
            }
        }

        // Time budget spent — continue in a fresh job so this attempt stays short.
        self::dispatch($this->exportId);
    }
}
