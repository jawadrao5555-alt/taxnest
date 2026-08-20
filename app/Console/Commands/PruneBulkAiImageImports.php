<?php

namespace App\Console\Commands;

use App\Models\BulkAiImageItem;
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
        $this->info("Deleted {$deleted} expired Bulk AI source photo(s); released {$expired} abandoned upload(s).");
        return self::SUCCESS;
    }
}