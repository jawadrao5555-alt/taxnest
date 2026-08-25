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
        // Building needs the PHP zip extension, and a host's CLI build can
        // differ from the one serving the site — here the default queue
        // worker's binary has no zip at all. Its own queue lets a zip-capable
        // worker take these jobs without moving every other job, and every
        // extension they rely on, onto a different PHP build.
        $this->onQueue('zip');
    }

    public function handle(): void
    {
        $export = InvoiceZipExport::find($this->exportId);
        if (!$export || !$export->isActive()) {
            return;
        }

        // Nothing to gain by claiming work this process cannot do: leave the
        // export untouched and active so a capable worker — or the polling
        // fallback running under the web SAPI — can still finish it.
        if (!class_exists(\ZipArchive::class)) {
            Log::warning('BuildInvoiceZipJob: no zip extension in this PHP build, leaving the export alone', [
                'export_id' => $this->exportId,
                'php' => PHP_VERSION,
            ]);
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
