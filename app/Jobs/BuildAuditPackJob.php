<?php

namespace App\Jobs;

use App\Models\AuditPack;
use App\Services\AuditPackBuilderService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

/**
 * Assembles an FBR Audit Pack ZIP in resumable chunks.
 *
 * Each job invocation processes chunks for at most ~60 seconds, then re-dispatches
 * itself. This keeps every queue attempt well under the database driver's
 * retry_after window (90s) so a long pack can never be double-delivered
 * mid-flight. If the queue worker is not running at all, the Compliance page's
 * status polling endpoint advances the same chunk pipeline inline.
 */
class BuildAuditPackJob implements ShouldQueue
{
    use Queueable;

    public $tries = 3;
    public $timeout = 300;

    public function __construct(public int $packId)
    {
        // Same reason as BuildInvoiceZipJob: archive building needs the PHP zip
        // extension, which the default queue worker's binary may not have.
        $this->onQueue('zip');
    }

    public function handle(): void
    {
        $pack = AuditPack::find($this->packId);
        if (!$pack || !$pack->isActive()) {
            return;
        }

        // Leave the pack alone rather than failing it from a process that
        // could never have built it.
        if (!class_exists(\ZipArchive::class)) {
            Log::warning('BuildAuditPackJob: no zip extension in this PHP build, leaving the pack alone', [
                'pack_id' => $this->packId,
                'php' => PHP_VERSION,
            ]);
            return;
        }

        $deadline = time() + 60;

        while (time() < $deadline) {
            $state = AuditPackBuilderService::processNextChunk($pack);

            if ($state === 'done') {
                return;
            }

            if ($state === 'busy') {
                // Another process (poll fallback or a parallel worker) holds the claim.
                Log::info('BuildAuditPackJob: pack busy, retrying shortly', ['pack_id' => $this->packId]);
                self::dispatch($this->packId)->delay(now()->addSeconds(30));
                return;
            }

            $pack->refresh();
            if (!$pack->isActive()) {
                return;
            }
        }

        // Time budget spent — continue in a fresh job so this attempt stays short.
        self::dispatch($this->packId);
    }
}
