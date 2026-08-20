<?php

namespace App\Jobs;

use App\Services\BulkAiImageImportService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class ProcessBulkAiImageJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $tries = 1;
    public $timeout = 180;

    public function __construct(public int $itemId)
    {
    }

    public function handle(BulkAiImageImportService $service): void
    {
        $service->processItem($this->itemId);
    }

    public function failed(?\Throwable $exception): void
    {
        app(BulkAiImageImportService::class)->markFailed(
            $this->itemId,
            'The background reader stopped unexpectedly. Please retry this photo.'
        );
    }
}