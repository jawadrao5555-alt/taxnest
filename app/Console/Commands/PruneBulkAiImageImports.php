<?php

namespace App\Console\Commands;

use App\Models\BulkAiImageItem;
use App\Models\BulkAiImageBatch;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

class PruneBulkAiImageImports extends Command
{
    protected $signature = 'bulk-ai-images:prune {--days=7 : Keep private source photos for this many days after processing}';
    protected $description = 'Delete private source photos from completed Bulk AI Image Import results';

    public function handle(): int
    {
        $cutoff = now()->subDays(max(1, (int) $this->option('days')));
        $deleted = 0;
        $expired = 0;
        // An interrupted browser upload has no processed_at and used to pin a
        // reserved allowance forever. Expire it, release only its live
        // reservation, and remove every chunk/source directory.
        BulkAiImageItem::whereIn('status', ['not_started', 'uploading', 'queued'])
            ->where('updated_at', '<', $cutoff)
            ->orderBy('id')
            ->chunkById(200, function ($items) use (&$expired) {
                foreach ($items as $item) {
                    \Illuminate\Support\Facades\DB::transaction(function () use ($item, &$expired) {
                        $locked = BulkAiImageItem::whereKey($item->id)->lockForUpdate()->first();
                        if (!$locked || !in_array($locked->status, ['not_started', 'uploading', 'queued'], true)) {
                            return;
                        }
                        Storage::disk('local')->deleteDirectory('private/ai-bulk/' . $locked->company_id . '/' . $locked->batch_id . '/' . $locked->source_uuid);
                        if ($locked->reservation_status === 'reserved') {
                            $locked->batch()->decrement('reserved_credits');
                            $locked->reservation_status = 'released';
                        }
                        $locked->update([
                            'status' => 'failed',
                            'error' => 'Upload expired before processing. Start a new batch to upload this photo.',
                            'processed_at' => now(),
                            'source_deleted_at' => now(),
                        ]);
                        $expired++;
                    });
                }
            });
        BulkAiImageItem::whereIn('status', ['ready', 'needs_review', 'duplicate', 'failed'])
            ->whereNull('source_deleted_at')
            ->where('processed_at', '<', $cutoff)
            ->orderBy('id')
            ->chunkById(200, function ($items) use (&$deleted) {
                foreach ($items as $item) {
                    if ($item->storage_path) {
                        Storage::disk('local')->delete($item->storage_path);
                    }
                    $dir = $item->storage_path ? dirname($item->storage_path) . '/chunks' : null;
                    if ($dir) {
                        Storage::disk('local')->deleteDirectory($dir);
                    }
                    $item->update(['storage_path' => null, 'source_deleted_at' => now()]);
                    $deleted++;
                }
            });
        // Annexure rows are private, batch-scoped review detail just like the
        // source photo. Keep the audit trail, but remove the temporary product
        // master and all mapping/sample values once the batch expires.
        if (\Illuminate\Support\Facades\Schema::hasColumn('bulk_ai_image_batches', 'annexure_rows_json')) {
            BulkAiImageBatch::whereNotNull('retention_until')
                ->where('retention_until', '<', now())
                ->where(function ($query) {
                    $query->whereNotNull('annexure_rows_json')->orWhereNotNull('annexure_storage_path');
                })
                ->orderBy('id')
                ->chunkById(100, function ($batches) {
                    foreach ($batches as $batch) {
                        if ($batch->annexure_storage_path) {
                            Storage::disk('local')->delete($batch->annexure_storage_path);
                        }
                        Storage::disk('local')->deleteDirectory('private/ai-bulk/' . $batch->company_id . '/' . $batch->id . '/annexure');
                        $batch->update([
                            'annexure_storage_path' => null,
                            'annexure_headers_json' => null,
                            'annexure_samples_json' => null,
                            'annexure_rows_json' => null,
                            'annexure_mapping_json' => null,
                            'annexure_status' => $batch->annexure_status === 'none' ? 'none' : 'pruned',
                        ]);
                    }
                });
        }
        $this->info("Deleted {$deleted} expired Bulk AI source photo(s); released {$expired} abandoned upload(s).");
        return self::SUCCESS;
    }
}