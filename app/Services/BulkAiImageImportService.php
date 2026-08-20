<?php

namespace App\Services;

use App\Jobs\ProcessBulkAiImageJob;
use App\Models\BulkAiImageBatch;
use App\Models\BulkAiImageItem;
use App\Models\Company;
use App\Models\Invoice;
use App\Models\AiInvoiceParse;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * One independent queue item per uploaded invoice photo.
 *
 * This intentionally does not use InvoiceImportService's buyer grouping:
 * two photos for the same buyer are two source documents and therefore two
 * drafts. Source photos live on the private local disk and are never public.
 */
class BulkAiImageImportService
{
    public const MAX_IMAGES = 100;
    public const MAX_IMAGE_BYTES = 5 * 1024 * 1024;
    public const CHUNK_BYTES = 1024 * 1024;
    public const RETENTION_DAYS = 7;

    /** Same wording the workspace table shows, reused by the shareable report. */
    public const STATUS_LABELS = [
        'not_started' => 'Not started',
        'uploading' => 'Uploading',
        'queued' => 'Queued',
        'processing' => 'Reading',
        'ready' => 'Ready',
        'needs_review' => 'Needs review',
        'duplicate' => 'Duplicate',
        'failed' => 'Failed',
    ];

    private const EXTENSIONS = ['jpg', 'jpeg', 'png', 'webp'];

    /** A hand-off summary stays readable only while it stays short. */
    private const REPORT_MAX_NOTES = 3;
    private const REPORT_NOTE_CHARS = 160;

    public function quotaState(Company $company): array
    {
        $quota = AiInvoiceReaderService::monthlyQuota($company);
        $used = AiInvoiceReaderService::usedThisMonth($company->id);
        $reserved = Schema::hasTable('bulk_ai_image_items')
            ? BulkAiImageItem::where('company_id', $company->id)
                ->where('reservation_status', 'reserved')
                ->where('created_at', '>=', now()->startOfMonth())
                ->count()
            : 0;

        return [
            'quota' => $quota,
            'used' => $used,
            'reserved' => $reserved,
            'unlimited' => $quota === -1,
            'remaining' => $quota === -1 ? -1 : max(0, $quota - $used - $reserved),
        ];
    }

    /**
     * Reserve all credits before any photo is queued. The company row lock
     * makes two browser tabs competing for the final credits deterministic.
     *
     * @param array<int,array{name:string,size:int,type?:string}> $files
     */
    public function createBatch(Company $company, ?int $userId, array $files): BulkAiImageBatch
    {
        if (count($files) < 1 || count($files) > self::MAX_IMAGES) {
            throw new \InvalidArgumentException('Choose between 1 and ' . self::MAX_IMAGES . ' invoice photos.');
        }

        foreach ($files as $file) {
            $name = trim((string) ($file['name'] ?? ''));
            $size = (int) ($file['size'] ?? 0);
            $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
            if ($name === '' || !in_array($ext, self::EXTENSIONS, true)) {
                throw new \InvalidArgumentException('Only JPG, PNG, and WebP invoice photos are supported.');
            }
            if ($size < 1 || $size > self::MAX_IMAGE_BYTES) {
                throw new \InvalidArgumentException('Each invoice photo must be smaller than 5MB.');
            }
        }

        return DB::transaction(function () use ($company, $userId, $files) {
            Company::whereKey($company->id)->lockForUpdate()->first();
            $state = $this->quotaState($company->fresh());
            if (!$state['unlimited'] && $state['remaining'] < count($files)) {
                throw new \RuntimeException(
                    'Bulk AI allowance is not enough for this batch. '
                    . $state['remaining'] . ' source invoice(s) remain.'
                );
            }

            $batch = BulkAiImageBatch::create([
                'company_id' => $company->id,
                'user_id' => $userId,
                'batch_uuid' => (string) Str::uuid(),
                'status' => 'uploading',
                'total_images' => count($files),
                'reserved_credits' => count($files),
                'retention_until' => now()->addDays(self::RETENTION_DAYS),
            ]);

            foreach (array_values($files) as $position => $file) {
                BulkAiImageItem::create([
                    'batch_id' => $batch->id,
                    'company_id' => $company->id,
                    'source_uuid' => (string) Str::uuid(),
                    'position' => $position + 1,
                    'original_filename' => mb_substr(trim((string) $file['name']), 0, 255),
                    'mime_type' => $file['type'] ?? null,
                    'expected_bytes' => (int) $file['size'],
                    'status' => 'not_started',
                    'reservation_status' => 'reserved',
                ]);
            }

            return $batch;
        });
    }

    public function batchForCompany(int $batchId, int $companyId): ?BulkAiImageBatch
    {
        return BulkAiImageBatch::where('company_id', $companyId)->find($batchId);
    }

    public function itemForCompany(int $batchId, int $itemId, int $companyId): ?BulkAiImageItem
    {
        return BulkAiImageItem::where('company_id', $companyId)
            ->where('batch_id', $batchId)
            ->find($itemId);
    }

    public function chunkPath(BulkAiImageItem $item, int $chunk): string
    {
        return 'private/ai-bulk/' . $item->company_id . '/' . $item->batch_id
            . '/' . $item->source_uuid . '/chunks/' . $chunk . '.part';
    }

    public function storeChunk(BulkAiImageItem $item, UploadedFile $chunk, int $index, int $totalChunks): array
    {
        if ($index < 0 || $totalChunks < 1 || $index >= $totalChunks) {
            throw new \InvalidArgumentException('Invalid upload chunk.');
        }
        if ((int) $item->total_chunks && (int) $item->total_chunks !== $totalChunks) {
            throw new \InvalidArgumentException('This upload was started with different chunk information.');
        }
        if ($item->status === 'processing' || in_array($item->status, ['ready', 'needs_review'], true)) {
            throw new \RuntimeException('This source photo has already been processed.');
        }

        $item->update([
            'status' => 'uploading',
            'total_chunks' => $totalChunks,
            'mime_type' => $item->mime_type ?: ($chunk->getMimeType() ?: 'image/jpeg'),
        ]);
        $relative = $this->chunkPath($item, $index);
        Storage::disk('local')->put($relative, (string) file_get_contents($chunk->getRealPath()));

        return ['chunk' => $index, 'total_chunks' => $totalChunks];
    }

    public function completeUpload(BulkAiImageItem $item): array
    {
        if (($item->batch->annexure_status ?? 'none') === 'mapping_pending') {
            throw new \InvalidArgumentException('Confirm the Annexure column mapping before uploading invoice photos.');
        }
        $totalChunks = (int) $item->total_chunks;
        if ($totalChunks < 1) {
            throw new \InvalidArgumentException('No upload chunks were received.');
        }

        $relative = 'private/ai-bulk/' . $item->company_id . '/' . $item->batch_id . '/' . $item->source_uuid;
        $final = $relative . '/source.' . strtolower(pathinfo($item->original_filename, PATHINFO_EXTENSION));
        $bytes = 0;
        $hash = hash_init('sha256');
        Storage::disk('local')->makeDirectory($relative);
        $out = fopen(Storage::disk('local')->path($final), 'wb');
        try {
            for ($i = 0; $i < $totalChunks; $i++) {
                $chunkRelative = $this->chunkPath($item, $i);
                if (!Storage::disk('local')->exists($chunkRelative)) {
                    throw new \InvalidArgumentException('An upload chunk is missing. Please retry the upload.');
                }
                $part = (string) Storage::disk('local')->get($chunkRelative);
                $bytes += strlen($part);
                hash_update($hash, $part);
                fwrite($out, $part);
            }
        } finally {
            fclose($out);
        }
        Storage::disk('local')->deleteDirectory($relative . '/chunks');

        if ($bytes < 1 || $bytes > self::MAX_IMAGE_BYTES || $bytes !== (int) $item->expected_bytes) {
            Storage::disk('local')->delete($final);
            throw new \InvalidArgumentException('The uploaded photo size does not match the selected file.');
        }

        $contentHash = hash_final($hash);
        $duplicate = BulkAiImageItem::where('batch_id', $item->batch_id)
            ->where('id', '!=', $item->id)
            ->where('content_hash', $contentHash)
            ->first();
        if ($duplicate) {
            Storage::disk('local')->delete($final);
            $this->releaseReservation($item, 'duplicate');
            return $this->finishItem($item, 'duplicate', [
                'duplicate_of' => $duplicate->id,
                'message' => 'This photo is an exact duplicate of another photo in this batch.',
            ]);
        }

        $item->update([
            'storage_path' => $final,
            'uploaded_bytes' => $bytes,
            'content_hash' => $contentHash,
            'status' => 'queued',
        ]);
        $item->batch()->update(['status' => 'queued']);
        ProcessBulkAiImageJob::dispatch($item->id);

        return ['item_id' => $item->id, 'status' => 'queued'];
    }

    public function retry(BulkAiImageItem $item): void
    {
        DB::transaction(function () use ($item) {
            $fresh = BulkAiImageItem::whereKey($item->id)->lockForUpdate()->firstOrFail();
            if ($fresh->status !== 'failed') {
                throw new \RuntimeException('Only failed photos can be retried.');
            }
            if ($fresh->invoice_id) {
                throw new \RuntimeException('This photo already has a draft. Open that draft instead of retrying it.');
            }
            $company = Company::findOrFail($fresh->company_id);
            Company::whereKey($company->id)->lockForUpdate()->first();
            $state = $this->quotaState($company->fresh());
            if (!$state['unlimited'] && $state['remaining'] < 1) {
                throw new \RuntimeException('No Bulk AI allowance remains for a retry.');
            }
            $fresh->update([
                'status' => 'queued',
                'reservation_status' => 'reserved',
                'error' => null,
                'warnings_json' => null,
                'details_json' => null,
                'parse_id' => null,
                'retry_count' => (int) $fresh->retry_count + 1,
                'processed_at' => null,
            ]);
            $fresh->batch()->increment('reserved_credits');
            ProcessBulkAiImageJob::dispatch($fresh->id);
        });
    }

    public function processItem(int $itemId): void
    {
        $claimed = BulkAiImageItem::whereKey($itemId)->where('status', 'queued')
            ->update(['status' => 'processing', 'updated_at' => now()]);
        if (!$claimed) {
            return;
        }
        $item = BulkAiImageItem::find($itemId);
        if (!$item || !$item->storage_path || !Storage::disk('local')->exists($item->storage_path)) {
            $this->markFailed($itemId, 'The private source photo is missing. Please upload it again.');
            return;
        }

        try {
            $company = Company::findOrFail($item->company_id);
            $path = Storage::disk('local')->path($item->storage_path);
            $upload = new UploadedFile($path, $item->original_filename, $item->mime_type ?: 'image/jpeg', null, true);
            $parse = AiInvoiceReaderService::parseUpload($upload, $company, $item->batch->user_id);
            $item->update(['parse_id' => $parse->id, 'reservation_status' => 'consumed']);
            $item->batch()->decrement('reserved_credits');

            $payload = (array) $parse->payload_json;
            $annexureMatches = $this->applyAnnexureReference($item->batch, $payload);
            $rows = $this->rowsFromPayload($payload);
            $validated = (new InvoiceImportService())->validateRows($rows, $company);
            $validationErrors = [];
            foreach ($validated['rows'] as $row) {
                $validationErrors = array_merge($validationErrors, (array) ($row['errors'] ?? []));
            }
            foreach ($payload['items'] ?? [] as $line) {
                if ((float) ($line['price'] ?? 0) <= 0) {
                    $validationErrors[] = 'A line has no readable document price. Enter the printed price before submitting.';
                }
            }

            $sourceKey = $this->sourceDocumentKey($payload);
            $other = $this->duplicateDetails($item, $sourceKey);
            if ($other) {
                $this->finishItem($item, 'duplicate', [
                    'duplicate_of' => $other->id,
                    'message' => 'The buyer, invoice number, and date repeat another source photo in this batch.',
                ]);
                $this->releaseReservation($item, 'duplicate');
                return;
            }

            $warnings = array_values(array_unique(array_merge(
                (array) ($payload['warnings'] ?? []),
                $validationErrors,
                array_values(array_filter(array_map(
                    fn ($match) => in_array($match['status'] ?? '', ['missing', 'ambiguous', 'conflict'], true)
                        ? 'Annexure: ' . ($match['explanation'] ?? 'manual product match review is required.')
                        : null,
                    $annexureMatches
                )))
            )));
            if (!empty($validationErrors) || empty($validated['rows']) || $validated['valid_count'] !== count($validated['rows'])) {
                $this->finishItem($item, 'needs_review', [
                    'validation_errors' => $validationErrors,
                    'source_document_key' => $sourceKey,
                    'mapping' => $this->mappingDetails($payload),
                    'annexure_matches' => $annexureMatches,
                    'annexure_status' => $item->batch->annexure_status,
                ], $warnings);
                return;
            }

            $details = [
                'source_document_key' => $sourceKey,
                'mapping' => $this->mappingDetails($payload),
                'annexure_matches' => $annexureMatches,
                'annexure_status' => $item->batch->annexure_status,
                'validation_errors' => [],
            ];
            // Keep draft creation, immutable source-item linkage, and terminal
            // status in one DB transaction. If a worker dies, the entire unit
            // rolls back instead of a retry creating a second draft.
            $created = DB::transaction(function () use ($item, $company, $parse, $payload, $validated, $details, $warnings) {
                $locked = BulkAiImageItem::whereKey($item->id)->lockForUpdate()->firstOrFail();
                if ($locked->invoice_id) {
                    return ['id' => (int) $locked->invoice_id];
                }
                Company::whereKey($company->id)->lockForUpdate()->firstOrFail();
                $cap = \App\Services\PlanLimitService::canCreateInvoice($company->id);
                if (empty($cap['allowed'])) {
                    return ['cap_error' => $cap['reason'] ?? 'Invoice plan limit reached.'];
                }
                $result = (new InvoiceImportService())->createDraftsFromRows(
                    $validated['rows'], $company, $locked->batch->user_id, 'bulk_ai_image', 1
                );
                $draft = $result['created'][0] ?? null;
                if (!$draft) {
                    throw new \RuntimeException('The extracted invoice could not be saved as a draft.');
                }
                if (!empty($payload['document']['original_date']) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $payload['document']['original_date'])) {
                    Invoice::whereKey($draft['id'])->where('company_id', $company->id)
                        ->update(['invoice_date' => $payload['document']['original_date']]);
                }
                $parse->newQuery()->whereKey($parse->id)->whereNull('invoice_id')->update(['invoice_id' => $draft['id']]);
                $locked->update([
                    'status' => empty($warnings) ? 'ready' : 'needs_review',
                    'details_json' => json_encode($details, JSON_UNESCAPED_UNICODE),
                    'warnings_json' => json_encode($warnings, JSON_UNESCAPED_UNICODE),
                    'invoice_id' => $draft['id'],
                    'processed_at' => now(),
                ]);
                return $draft;
            });
            if (!empty($created['cap_error'])) {
                $this->finishItem($item, 'needs_review', array_merge($details, ['validation_errors' => [$created['cap_error']]]), array_merge($warnings, [$created['cap_error']]));
            }
        } catch (\Throwable $e) {
            $this->markFailed($itemId, $e->getMessage());
        }
    }

    public function markFailed(int $itemId, string $message): void
    {
        $item = BulkAiImageItem::find($itemId);
        if (!$item || !in_array($item->status, ['queued', 'processing', 'uploading'], true)) {
            return;
        }
        $this->releaseReservation($item, 'released');
        $this->finishItem($item, 'failed', [], [mb_substr($message, 0, 500)]);
    }

    public function statusPayload(BulkAiImageBatch $batch): array
    {
        $items = $batch->items()->orderBy('position')->get();
        $counts = $items->groupBy('status')->map->count();
        $processed = $items->whereIn('status', ['ready', 'needs_review', 'duplicate', 'failed'])->count();
        $batch->update([
            'processed_images' => $processed,
            'ready_images' => (int) ($counts['ready'] ?? 0),
            'needs_review_images' => (int) ($counts['needs_review'] ?? 0),
            'duplicate_images' => (int) ($counts['duplicate'] ?? 0),
            'failed_images' => (int) ($counts['failed'] ?? 0),
            'status' => $processed >= $batch->total_images ? 'completed' : $batch->status,
            'finished_at' => $processed >= $batch->total_images ? ($batch->finished_at ?: now()) : null,
        ]);

        return [
            'batch_id' => $batch->id,
            'status' => $batch->fresh()->status,
            'total' => $batch->total_images,
            'processed' => $processed,
            'counts' => $counts->all(),
            'items' => $items->map(fn ($i) => [
                'id' => $i->id,
                'position' => $i->position,
                'filename' => $i->original_filename,
                'status' => $i->status,
                'warnings' => $i->warningsArray(),
                'details' => $i->detailsArray(),
                'error' => $i->error,
                'invoice_id' => $i->invoice_id,
                'invoice_url' => $i->invoice_id ? '/invoice/' . $i->invoice_id . '/edit' : null,
                'retryable' => $i->status === 'failed' && $i->source_deleted_at === null,
            ])->values()->all(),
            'annexure' => [
                'status' => $batch->annexure_status ?: 'none',
                'filename' => $batch->annexure_filename,
                'headers' => $batch->annexureHeadersArray(),
                'samples' => $batch->annexureSamplesArray(),
                'mapping' => $batch->annexureMappingArray(),
                'rows' => array_values(array_filter($batch->annexureRowsArray(), fn ($row) => !empty($row['valid']))),
            ],
            'annexure_audits' => app(AnnexureProductService::class)->auditTrail($batch, Company::findOrFail($batch->company_id)),
        ];
    }

    /**
     * Shareable hand-off summary of a batch: one row per SOURCE photo with the
     * status, the concise notes a second reviewer needs, and the draft it
     * produced. Built from stored review data only — the private source photo
     * (storage path, source uuid, bytes, hash) never leaves the server.
     */
    public function reviewReport(BulkAiImageBatch $batch): array
    {
        $items = $batch->items()->orderBy('position')->get();
        $filenames = $items->pluck('original_filename', 'id')->all();
        $invoiceIds = $items->pluck('invoice_id')->filter()->unique()->values()->all();
        $drafts = $invoiceIds
            ? Invoice::where('company_id', $batch->company_id)->whereIn('id', $invoiceIds)->get()->keyBy('id')
            : collect();

        $counts = ['ready' => 0, 'needs_review' => 0, 'duplicate' => 0, 'failed' => 0, 'pending' => 0];
        $rows = [];
        foreach ($items as $item) {
            $status = (string) $item->status;
            $counts[array_key_exists($status, $counts) ? $status : 'pending']++;
            $draft = $item->invoice_id ? $drafts->get($item->invoice_id) : null;
            $rows[] = [
                'position' => (int) $item->position,
                'filename' => (string) $item->original_filename,
                'status' => $status,
                'status_label' => self::STATUS_LABELS[$status] ?? $status,
                'notes' => $this->reportNotes($item, $filenames),
                'draft_number' => $draft ? (string) $draft->display_invoice_number : '',
                'processed_at' => $item->processed_at?->format('Y-m-d H:i') ?? '',
            ];
        }

        return [
            'batch' => [
                'id' => (int) $batch->id,
                'status' => (string) $batch->status,
                'status_label' => $batch->status === 'completed' ? 'Completed' : 'In progress',
                'total' => (int) $batch->total_images,
                'processed' => $counts['ready'] + $counts['needs_review'] + $counts['duplicate'] + $counts['failed'],
                'started_at' => $batch->created_at?->format('d M Y, h:i A') ?? '',
                'finished_at' => $batch->finished_at?->format('d M Y, h:i A') ?? '',
                'annexure_filename' => (string) ($batch->annexure_filename ?: ''),
            ],
            'counts' => $counts,
            'rows' => $rows,
        ];
    }

    public function reviewReportFilename(BulkAiImageBatch $batch, string $extension): string
    {
        return 'bulk-ai-review-batch-' . $batch->id . '-' . now()->format('Ymd-His') . '.' . $extension;
    }

    public function reviewReportCsv(BulkAiImageBatch $batch): \Symfony\Component\HttpFoundation\StreamedResponse
    {
        $report = $this->reviewReport($batch);

        return response()->streamDownload(function () use ($report) {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF"); // UTF-8 BOM so Excel keeps non-Latin filenames readable
            fputcsv($out, ['#', 'Source file', 'Status', 'Review notes', 'Draft invoice #', 'Processed at']);
            foreach ($report['rows'] as $row) {
                fputcsv($out, [
                    $row['position'],
                    $this->csvSafe($row['filename']),
                    $row['status_label'],
                    $this->csvSafe(implode(' | ', $row['notes'])),
                    $this->csvSafe($row['draft_number']),
                    $row['processed_at'],
                ]);
            }
            fclose($out);
        }, $this->reviewReportFilename($batch, 'csv'), ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    /**
     * Notes are the whole point of the hand-off, so they must stay short:
     * every stored warning (plus the duplicate/failure reason) trimmed to one
     * line, de-duplicated, and capped with a "+N more" pointer back to the
     * workspace.
     */
    private function reportNotes(BulkAiImageItem $item, array $filenames): array
    {
        $notes = [];
        if ($item->status === 'duplicate') {
            $details = $item->detailsArray();
            $note = (string) ($details['message'] ?? 'This photo repeats another source invoice in this batch.');
            $original = $filenames[$details['duplicate_of'] ?? 0] ?? null;
            $notes[] = $original ? rtrim($note, '.') . ' — same as ' . $original . '.' : $note;
        }
        foreach (array_merge($item->warningsArray(), array_filter([$item->error])) as $note) {
            $notes[] = (string) $note;
        }

        $notes = array_values(array_unique(array_filter(array_map(
            fn ($note) => trim(mb_substr((string) preg_replace('/\s+/u', ' ', (string) $note), 0, self::REPORT_NOTE_CHARS)),
            $notes
        ))));
        $extra = count($notes) - self::REPORT_MAX_NOTES;

        return $extra > 0
            ? array_merge(
                array_slice($notes, 0, self::REPORT_MAX_NOTES),
                ['+' . $extra . ' more note(s) — open the batch in TaxNest to see all.']
            )
            : $notes;
    }

    /** Source filenames are user-supplied: never let a spreadsheet read one as a formula. */
    private function csvSafe(string $value): string
    {
        return $value !== '' && str_contains("=+-@\t\r", $value[0]) ? "'" . $value : $value;
    }

    /**
     * Applies only missing compliance profile data. The source invoice's
     * quantity, price, tax, and totals are intentionally not touched.
     */
    private function applyAnnexureReference(BulkAiImageBatch $batch, array &$payload): array
    {
        $lines = (array) ($payload['items'] ?? []);
        if (($batch->annexure_status ?? 'none') !== 'ready') {
            return array_map(fn ($line, $index) => [
                'line_index' => $index, 'status' => 'not_available', 'match_type' => null,
                'confidence' => 0, 'explanation' => 'No Annexure was attached to this batch.',
                'source_row' => null, 'entry' => null,
            ], $lines, array_keys($lines));
        }
        $matches = app(AnnexureProductService::class)->matchLines($lines, $batch->annexureRowsArray());
        foreach ($matches as $index => $match) {
            if (($match['status'] ?? '') !== 'matched' || empty($match['entry'])) {
                continue;
            }
            $entry = $match['entry'];
            $line = &$payload['items'][$index];
            foreach ([
                'hs_code' => 'hs_code', 'pct_code' => 'pct_code', 'uom' => 'uom',
                'default_tax_rate' => 'tax_rate', 'schedule_type' => 'schedule_type',
                'sro_reference' => 'sro_schedule_no', 'serial_number' => 'serial_no',
                'mrp' => 'mrp',
            ] as $from => $to) {
                if (($line[$to] ?? '') === '' && ($entry[$from] ?? '') !== '') {
                    $line[$to] = $entry[$from];
                }
            }
            $line['annexure_match'] = [
                'source_row' => $match['source_row'],
                'match_type' => $match['match_type'],
                'confidence' => $match['confidence'],
            ];
            $annexurePrice = $entry['default_price'] ?? '';
            $profilePrice = $line['profile_default_price'] ?? null;
            if ($line['product_id'] ?? null) {
                $matches[$index]['price_conflict'] = $annexurePrice !== '' && $profilePrice !== null
                    && round((float) $annexurePrice, 2) !== round((float) $profilePrice, 2);
                $matches[$index]['catalog_price'] = $profilePrice;
                $matches[$index]['annexure_price'] = $annexurePrice === '' ? null : (float) $annexurePrice;
                $matches[$index]['price_options'] = ['keep_current', 'update_catalog', 'batch_only'];
            }
            unset($line);
        }
        return $matches;
    }

    private function rowsFromPayload(array $payload): array
    {
        $buyer = (array) ($payload['buyer'] ?? []);
        $document = (array) ($payload['document'] ?? []);
        $rows = [];
        foreach ((array) ($payload['items'] ?? []) as $index => $item) {
            $rows[] = [
                'row' => $index + 1,
                'data' => [
                    'buyer_name' => $buyer['name'] ?? '',
                    'buyer_ntn' => $buyer['ntn'] ?? '',
                    'buyer_cnic' => $buyer['cnic'] ?? '',
                    'buyer_address' => $buyer['address'] ?? '',
                    'destination_province' => $document['destination_province'] ?? '',
                    'document_type' => $document['document_type'] ?? 'Sale Invoice',
                    'reference_invoice_number' => $document['reference_invoice_number'] ?? '',
                    'hs_code' => $item['hs_code'] ?? '',
                    'description' => $item['description'] ?? '',
                    'quantity' => $item['quantity'] ?? '',
                    'price' => $item['price'] ?? '',
                    'tax' => $item['tax'] ?? '',
                    'schedule_type' => $item['schedule_type'] ?? '',
                    'tax_rate' => $item['tax_rate'] ?? '',
                    'mrp' => $item['mrp'] ?? '',
                    'sro_schedule_no' => $item['sro_schedule_no'] ?? '',
                    'sro_serial_no' => $item['serial_no'] ?? '',
                ],
            ];
        }
        return $rows;
    }

    private function sourceDocumentKey(array $payload): string
    {
        $buyer = strtolower(trim((string) ($payload['buyer']['name'] ?? '')));
        $doc = (array) ($payload['document'] ?? []);
        $number = strtolower(trim((string) ($doc['original_invoice_number'] ?? '')));
        $date = trim((string) ($doc['original_date'] ?? ''));
        return ($buyer !== '' && ($number !== '' || $date !== '')) ? $buyer . '|' . $number . '|' . $date : '';
    }

    private function duplicateDetails(BulkAiImageItem $item, string $key): ?BulkAiImageItem
    {
        if ($key === '') {
            return null;
        }
        $others = BulkAiImageItem::where('batch_id', $item->batch_id)
            ->where('id', '!=', $item->id)
            ->whereNotNull('details_json')
            ->get();
        return $others->first(fn ($other) => ($other->detailsArray()['source_document_key'] ?? '') === $key);
    }

    private function mappingDetails(array $payload): array
    {
        return array_map(fn ($item) => [
            'description' => $item['description'] ?? '',
            'product_id' => $item['product_id'] ?? null,
            'match_type' => $item['product_match_type'] ?? null,
            'match_confidence' => $item['product_match_confidence'] ?? null,
            'profile_tax_rate' => $item['profile_tax_rate'] ?? null,
            'profile_hs_code' => $item['profile_hs_code'] ?? null,
            'profile_default_price' => $item['profile_default_price'] ?? null,
            'barcode' => $item['barcode'] ?? '',
            'sku' => $item['sku'] ?? '',
            'annexure_match' => $item['annexure_match'] ?? null,
        ], (array) ($payload['items'] ?? []));
    }

    private function releaseReservation(BulkAiImageItem $item, string $status): void
    {
        if ($item->reservation_status === 'reserved') {
            BulkAiImageItem::whereKey($item->id)->update([
                'reservation_status' => $status,
                'updated_at' => now(),
            ]);
            $item->batch()->decrement('reserved_credits');
        }
    }

    private function finishItem(BulkAiImageItem $item, string $status, array $details = [], array $warnings = [], ?int $invoiceId = null): array
    {
        $item->update([
            'status' => $status,
            'details_json' => json_encode($details, JSON_UNESCAPED_UNICODE),
            'warnings_json' => json_encode(array_values(array_unique($warnings)), JSON_UNESCAPED_UNICODE),
            'invoice_id' => $invoiceId ?: $item->invoice_id,
            'processed_at' => now(),
        ]);
        return ['item_id' => $item->id, 'status' => $status];
    }
}