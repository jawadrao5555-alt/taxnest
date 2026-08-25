<?php

namespace App\Services;

use App\Models\Invoice;
use App\Models\InvoiceZipExport;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;
use ZipStream\CompressionMethod;
use ZipStream\ZipStream;
use Illuminate\Support\Str;

/**
 * Builds a ZIP of invoice PDFs in resumable chunks.
 *
 * The older bulk download assembled the whole archive inside one HTTP request,
 * which forced a hard cap of 500 invoices and split anything bigger into
 * batch links the user had to click one by one. A shop that wants to eyeball
 * every draft it has ever imported needs tens of thousands in a single file,
 * so the work moved here: claim, render a chunk, save progress, repeat —
 * driven either by the queue worker or by the page's own status polling.
 *
 * Two properties matter more than speed, because the archive exists to be
 * TRUSTED as a complete record:
 *
 *   - The invoice set is frozen at initialization (a fixed id ceiling walked
 *     by keyset cursor). Invoices created or deleted during a build that runs
 *     for many minutes cannot shift the pages underneath it.
 *   - A claim is a lease with a token. Every state write is conditional on
 *     still holding it, so a chunk that outlives the stale window cannot have
 *     its progress overwritten by — or overwrite — the worker that took over.
 *
 * Two ceilings protect the box, and both are reported honestly to the user
 * rather than silently truncating: STALE_LOCK_SECONDS bounds a wedged claim,
 * and MAX_BYTES bounds the archive so one export cannot eat the disk quota.
 */
class InvoiceZipBuilderService
{
    /**
     * Invoices rendered per claim.
     *
     * A chunk no longer touches the archive — it only writes loose PDFs — so
     * this is bounded by the lock window alone, not by how big the ZIP has
     * grown.
     */
    public const CHUNK_SIZE = 50;

    /** A claim older than this is treated as abandoned (worker died mid-chunk). */
    public const STALE_LOCK_SECONDS = 300;

    /** Nothing here is precious — it can always be rebuilt — so it is deleted fast. */
    public const RETENTION_HOURS = 24;

    /** Hard disk ceiling for one archive. The live server's quota is not generous. */
    public const MAX_BYTES = 2147483648; // 2 GiB

    /** Beyond this many render failures we stop listing individual ids. */
    public const MAX_TRACKED_FAILURES = 2000;

    public const SCOPE_DRAFT = 'draft';
    public const SCOPE_COMPLETED = 'completed';
    public const SCOPE_ALL = 'all';

    /**
     * Start a new export. Any earlier export for the same company is dropped
     * first: keeping several multi-gigabyte archives per shop is exactly how
     * the disk fills up, and the user only ever wants the latest one.
     */
    public static function start(int $companyId, ?int $userId, array $filters): InvoiceZipExport
    {
        self::purgeForCompany($companyId);

        return InvoiceZipExport::create([
            'company_id' => $companyId,
            'user_id' => $userId,
            'filters' => $filters,
            'scope_label' => self::describe($filters),
            'status' => 'pending',
        ]);
    }

    /**
     * The invoice set this export covers.
     *
     * Replayed from the stored filters on every chunk so a resumed build can
     * never quietly change its own scope. CompanyScope is a no-op inside a
     * queue job, so the company filter is always explicit here.
     */
    public static function invoiceQuery(InvoiceZipExport $export)
    {
        $filters = $export->filters ?? [];

        $query = Invoice::withoutGlobalScopes()->where('company_id', $export->company_id);

        $scope = $filters['scope'] ?? self::SCOPE_COMPLETED;
        if ($scope === self::SCOPE_DRAFT) {
            $query->where('status', 'draft');
        } elseif ($scope === self::SCOPE_COMPLETED) {
            $query->whereIn('status', ['locked', 'pending_verification']);
        }

        if (!empty($filters['from'])) {
            $query->whereDate('invoice_date', '>=', $filters['from']);
        }
        if (!empty($filters['to'])) {
            $query->whereDate('invoice_date', '<=', $filters['to']);
        }
        if (!empty($filters['fbr_status'])) {
            $query->where('fbr_status', $filters['fbr_status']);
        }
        if (!empty($filters['doc_type'])) {
            $query->where('document_type', $filters['doc_type']);
        }

        return $query;
    }

    /** The frozen slice of that set this export is committed to packing. */
    protected static function snapshotQuery(InvoiceZipExport $export)
    {
        return self::invoiceQuery($export)->where('id', '<=', (int) $export->max_invoice_id);
    }

    /**
     * Take the lease for one chunk of work.
     *
     * @return string|null the lease token, or null if someone else holds it
     */
    public static function claim(InvoiceZipExport $export): ?string
    {
        $token = (string) Str::uuid();
        $stale = now()->subSeconds(self::STALE_LOCK_SECONDS);

        $taken = InvoiceZipExport::where('id', $export->id)
            ->whereIn('status', InvoiceZipExport::ACTIVE_STATUSES)
            ->where(function ($q) use ($stale) {
                $q->whereNull('locked_at')->orWhere('locked_at', '<', $stale);
            })
            ->update(['locked_at' => now(), 'lock_token' => $token]) === 1;

        return $taken ? $token : null;
    }

    /** Release the lease — but only if we still hold this exact one. */
    public static function release(InvoiceZipExport $export, ?string $token = null): void
    {
        $query = InvoiceZipExport::where('id', $export->id);
        if ($token !== null) {
            $query->where('lock_token', $token);
        }
        $query->update(['locked_at' => null, 'lock_token' => null]);
    }

    /** Do we still hold this exact lease? */
    protected static function stillOwns(InvoiceZipExport $export, string $token): bool
    {
        return InvoiceZipExport::where('id', $export->id)
            ->where('lock_token', $token)
            ->exists();
    }

    /**
     * Write under the lease, and report whether we still hold it.
     *
     * The guarded UPDATE goes FIRST, because its own outcome is the answer
     * whenever it is unambiguous: MySQL reports the rows it actually CHANGED,
     * so 1 means the write landed and the lease is ours. Only 0 is ambiguous —
     * either the values were already identical (finalize re-stamping locked_at
     * inside the same second it claimed) or another worker took the lease
     * after it went stale — and that is settled with a SELECT.
     *
     * Both orderings matter and each has broken this feature once:
     *   - Trusting the count alone read "unchanged" as "lease lost", so
     *     finalize() bailed one step short and every MySQL build froze at 95%
     *     while this SQLite test suite — which counts MATCHED rows — stayed green.
     *   - Checking ownership BEFORE the write would let a worker whose stale
     *     lease was taken over in the gap carry on writing the same archive.
     */
    protected static function ownedWrite(InvoiceZipExport $export, string $token, array $attrs): bool
    {
        $changed = InvoiceZipExport::where('id', $export->id)
            ->where('lock_token', $token)
            ->update($attrs);

        return $changed === 1 || self::stillOwns($export, $token);
    }

    /** Extend the lease mid-chunk. False means we lost it and must stop writing. */
    protected static function renewLease(InvoiceZipExport $export, string $token): bool
    {
        return self::ownedWrite($export, $token, ['locked_at' => now()]);
    }

    /**
     * Persist progress, but only while we still own the build.
     *
     * @return bool false if the lease was lost (the caller must stop)
     */
    protected static function writeState(InvoiceZipExport $export, string $token, array $attrs): bool
    {
        if (!self::ownedWrite($export, $token, $attrs)) {
            Log::warning('Invoice ZIP: lease lost, discarding progress write', ['export_id' => $export->id]);
            return false;
        }

        $export->forceFill($attrs);

        return true;
    }

    /**
     * @return string 'done' (ready or failed), 'continue' (more work), 'busy' (claimed elsewhere)
     */
    public static function processNextChunk(InvoiceZipExport $export): string
    {
        // A CLI worker can be a different PHP build than the one serving the
        // site, and on cPanel it routinely is: the queue cron's binary had no
        // zip extension while the site's own PHP did. Such a process must step
        // ASIDE rather than claim the export and kill it with a fatal error —
        // the polling fallback runs under the web SAPI and can still finish.
        // If the WEB process is the one without zip we fall through, because
        // then nothing here can build an archive and initialize() should say so.
        if (PHP_SAPI === 'cli' && !class_exists(\ZipArchive::class)) {
            Log::warning('Invoice ZIP: no zip extension in this CLI build, leaving the export for a capable process', [
                'export_id' => $export->id,
                'php' => PHP_VERSION,
            ]);
            return 'busy';
        }

        $token = self::claim($export);
        if ($token === null) {
            return 'busy';
        }

        try {
            $export->refresh();

            if (!$export->isActive()) {
                return 'done';
            }

            if ($export->status === 'pending') {
                self::initialize($export, $token);
                return 'continue';
            }

            $walkedEverything = (int) $export->cursor_id >= (int) $export->max_invoice_id;

            if (!$walkedEverything && !$export->size_capped) {
                self::processPdfChunk($export, $token);
                return 'continue';
            }

            self::finalize($export, $token);
            return 'done';
        } catch (\Throwable $e) {
            Log::error('Invoice ZIP build failed', ['export_id' => $export->id, 'error' => $e->getMessage()]);
            self::writeState($export, $token, [
                'status' => 'failed',
                'error_message' => mb_substr('FATAL: ' . $e->getMessage(), 0, 2000),
            ]);
            return 'done';
        } finally {
            self::release($export, $token);
        }
    }

    protected static function initialize(InvoiceZipExport $export, string $token): void
    {
        if (!class_exists(\ZipArchive::class)) {
            self::writeState($export, $token, [
                'status' => 'failed',
                'error_message' => 'This server is missing the PHP zip extension, so ZIP downloads cannot be built.',
            ]);
            return;
        }

        // Freeze the set here: everything at or below this id, and nothing
        // created after the user pressed the button.
        $maxId = (int) (self::invoiceQuery($export)->max('id') ?? 0);
        $total = $maxId > 0
            ? (int) self::invoiceQuery($export)->where('id', '<=', $maxId)->count()
            : 0;

        if ($total === 0) {
            self::writeState($export, $token, [
                'status' => 'failed',
                'error_message' => 'No invoices matched these filters.',
            ]);
            return;
        }

        // No archive path: the ZIP is assembled while it streams to the
        // browser, so there is no file to write. Older rows that still carry
        // one keep downloading from disk.
        self::writeState($export, $token, [
            'status' => 'processing',
            'max_invoice_id' => $maxId,
            'cursor_id' => 0,
            'total_invoices' => $total,
            'processed_invoices' => 0,
            'failed_invoices' => 0,
            'progress' => 1,
        ]);
    }

    /**
     * Render part of the export ahead of the packer.
     *
     * Rendering is nearly all of the work and every PDF is independent, so
     * helpers fill the staging directory in parallel, each taking the ids that
     * fall in its own slot. They deliberately touch nothing but the
     * filesystem: the cursor, the progress and the archive stay with the
     * single lease-holding chunk loop. A helper that dies, or never runs at
     * all, therefore costs speed and nothing else — the chunk loop still
     * renders anything it finds missing.
     *
     * @return int invoices rendered by this pass
     */
    public static function prerenderSlice(InvoiceZipExport $export, int $slot, int $slots, int $deadline): int
    {
        $rendered = 0;
        $lastId = 0;

        while (time() < $deadline) {
            $invoices = self::snapshotQuery($export)
                ->where('id', '>', $lastId)
                ->when($slots > 1, fn ($q) => $q->whereRaw('(id % ?) = ?', [$slots, $slot]))
                ->with(['items', 'company', 'branch'])
                ->orderBy('id')
                ->take(self::CHUNK_SIZE)
                ->get();

            if ($invoices->isEmpty()) {
                break;
            }

            foreach ($invoices as $invoice) {
                try {
                    InvoicePdfCacheService::ensure($invoice);
                    $rendered++;
                } catch (\Throwable $e) {
                    // The chunk loop will try this one again and record it as a
                    // real failure if it still cannot be rendered.
                    Log::warning('Invoice ZIP: pre-render skipped one invoice', [
                        'export_id' => $export->id,
                        'invoice_id' => $invoice->id,
                        'error' => $e->getMessage(),
                    ]);
                }
            }

            $lastId = (int) $invoices->last()->id;

            if (!$export->fresh()?->isActive()) {
                break;
            }
        }

        return $rendered;
    }

    protected static function processPdfChunk(InvoiceZipExport $export, string $token): void
    {
        @set_time_limit(300);
        @ini_set('memory_limit', '1024M');

        $invoices = self::snapshotQuery($export)
            ->where('id', '>', (int) $export->cursor_id)
            // 'branch' is eager-loaded because production disables lazy loading
            // and the PDF prints the branch trading name.
            ->with(['items', 'company', 'branch'])
            ->orderBy('id')
            ->take(self::CHUNK_SIZE)
            ->get();

        if ($invoices->isEmpty()) {
            // Walked the whole frozen range — whatever was staged IS the archive.
            self::writeState($export, $token, ['cursor_id' => (int) $export->max_invoice_id]);
            return;
        }

        // Take the lease right up to the write so the window in which another
        // worker could be touching this same build stays as small as possible.
        if (!self::renewLease($export, $token)) {
            return;
        }

        $failedIds = $export->failed_ids ?? [];
        $staged = (int) $export->file_size;

        foreach ($invoices as $invoice) {
            try {
                $staged += InvoicePdfCacheService::ensure($invoice)['size'];
            } catch (\Throwable $e) {
                if (count($failedIds) < self::MAX_TRACKED_FAILURES) {
                    $failedIds[] = (int) $invoice->id;
                }
                Log::warning('Invoice ZIP: one invoice failed to render', [
                    'export_id' => $export->id,
                    'invoice_id' => $invoice->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        $processed = (int) $export->processed_invoices + $invoices->count();
        $total = max(1, (int) $export->total_invoices);

        self::writeState($export, $token, [
            'cursor_id' => (int) $invoices->last()->id,
            'processed_invoices' => $processed,
            'failed_invoices' => count($failedIds),
            'failed_ids' => $failedIds,
            // Rendered bytes, which is what the archive will weigh: PDFs barely
            // deflate. Replaced with the real file size once it is packed.
            'file_size' => $staged,
            // Never report 100% until finalize actually says so.
            'progress' => max(1, min(95, (int) floor($processed / $total * 95))),
            // Stopping on the disk ceiling is a real outcome, not a silent trim.
            'size_capped' => $staged >= self::MAX_BYTES,
        ]);
    }

    /**
     * Unique name inside the archive.
     *
     * Two invoices can legitimately carry the same visible number (a draft that
     * was never numbered, a re-issued document), and a duplicate entry would
     * overwrite the earlier PDF — the one thing a verification archive must
     * never do. Names are handed out from one pass over the invoices, so the
     * running set of names already used is the authority.
     *
     * @param array<string,bool> $used names already placed in this archive
     */
    protected static function entryName(array &$used, Invoice $invoice): string
    {
        $base = $invoice->fbr_invoice_number
            ?: ($invoice->internal_invoice_number
                ?: ($invoice->invoice_number ?: ('invoice-' . $invoice->id)));

        $safe = preg_replace('/[^A-Za-z0-9._-]+/', '_', (string) $base);
        $prefix = $invoice->status === 'draft' ? 'drafts/' : '';
        $name = $prefix . $safe . '.pdf';

        if (isset($used[$name])) {
            $name = $prefix . $safe . '__' . $invoice->id . '.pdf';
        }

        $used[$name] = true;

        return $name;
    }

    /**
     * Finish the preparation.
     *
     * There is deliberately no archive file here any more. Writing one
     * measured 0.45 MB/s on the live server, so packing a 250 MB download
     * cost roughly nine minutes of pure disk write before the shop could
     * start downloading it — and then the very same bytes had to be read back
     * out to serve it. The PDFs are already on disk, so the ZIP is assembled
     * as it streams to the browser instead: the download starts in about a
     * second, no byte is written twice, and asking for the same archive again
     * costs nothing at all.
     */
    protected static function finalize(InvoiceZipExport $export, string $token): void
    {
        @set_time_limit(300);

        if (!self::renewLease($export, $token)) {
            return;
        }

        [$ready, $bytes, $missing, $missingCount] = self::inventory($export);

        // A download containing nothing but a manifest is worse than an
        // error: the shop opens it and believes that is all it has.
        if ($ready === 0) {
            self::writeState($export, $token, [
                'status' => 'failed',
                'error_message' => 'The rendered invoices went missing before the download could be prepared. Please run it again.',
            ]);
            return;
        }

        $attrs = [
            'status' => 'ready',
            'progress' => 100,
            'processed_invoices' => $ready,
            'file_size' => $bytes,
            'completed_at' => now(),
        ];

        // Invoices whose PDF never made it are counted as failures — every
        // one of them, even when the id list itself is capped. A missing tax
        // document must never be hidden by quietly shrinking the total.
        if ($missingCount > 0) {
            $failedIds = array_values(array_unique(array_merge($export->failed_ids ?? [], $missing)));
            $attrs['failed_ids'] = array_slice($failedIds, 0, self::MAX_TRACKED_FAILURES);
            $attrs['failed_invoices'] = max($missingCount, count($failedIds));
        }

        // The total is what the frozen set still holds: an invoice deleted
        // mid-build genuinely is not there any more, so it must not sit in the
        // total forever — but one that exists without a PDF stays counted, and
        // shows up as a failure. A size-capped build keeps its own total,
        // because there really are invoices it never reached.
        if (!$export->size_capped) {
            $attrs['total_invoices'] = $ready + $missingCount;
        }

        self::writeState($export, $token, $attrs);
    }

    /**
     * What this download holds right now: how many PDFs are on disk, what
     * they weigh, and which invoices are still missing one.
     *
     * @return array{0:int,1:int,2:array<int,int>,3:int}
     */
    protected static function inventory(InvoiceZipExport $export): array
    {
        $ready = 0;
        $bytes = 0;
        $missing = [];
        $missingCount = 0;

        self::snapshotQuery($export)
            ->where('id', '<=', (int) $export->cursor_id)
            ->orderBy('id')
            ->select(['id', 'company_id', 'created_at', 'updated_at'])
            ->chunk(1000, function ($rows) use (&$ready, &$bytes, &$missing, &$missingCount) {
                foreach ($rows as $invoice) {
                    $path = InvoicePdfCacheService::currentPath($invoice);

                    if ($path === null) {
                        $missingCount++;
                        if (count($missing) < self::MAX_TRACKED_FAILURES) {
                            $missing[] = (int) $invoice->id;
                        }
                        continue;
                    }

                    $ready++;
                    $bytes += (int) filesize($path);
                }
            });

        return [$ready, $bytes, $missing, $missingCount];
    }

    /**
     * Send the archive to the browser, assembling it as it goes.
     *
     * Nothing is stored: entries are written straight into the response from
     * the cached PDFs, uncompressed, because PDFs barely deflate and CPU is
     * the scarce resource on this server. The download therefore begins
     * within a second and can be repeated as often as the shop likes.
     */
    public static function stream(InvoiceZipExport $export): StreamedResponse
    {
        return response()->streamDownload(
            fn () => self::writeArchive($export),
            self::downloadName($export),
            [
                'Content-Type' => 'application/zip',
                // Nothing in front of us may hold the response back waiting
                // for a complete body.
                'X-Accel-Buffering' => 'no',
            ]
        );
    }

    /**
     * Write the archive to an output stream, defaulting to the response.
     *
     * @param resource|null $outputStream
     */
    public static function writeArchive(InvoiceZipExport $export, $outputStream = null): void
    {
        // A quarter-gigabyte download over a slow line takes far longer than
        // any page is allowed to run.
        @set_time_limit(0);

        $zip = new ZipStream(
            outputStream: $outputStream,
            defaultCompressionMethod: CompressionMethod::STORE,
            sendHttpHeaders: false,
            flushOutput: $outputStream === null,
        );

        $zip->addFile('_manifest.csv', self::manifest($export));

        if ($export->size_capped) {
            $zip->addFile('_READ-ME.txt', self::cappedNotice($export));
        }

        $used = [];
        $packed = 0;
        $omitted = [];
        $omittedIds = [];

        self::snapshotQuery($export)
            ->where('id', '<=', (int) $export->cursor_id)
            ->orderBy('id')
            ->select([
                'id', 'company_id', 'created_at', 'updated_at', 'status',
                'fbr_invoice_number', 'internal_invoice_number', 'invoice_number',
            ])
            ->chunk(500, function ($rows) use ($export, $zip, &$used, &$packed, &$omitted, &$omittedIds) {
                foreach ($rows as $invoice) {
                    $path = InvoicePdfCacheService::currentPath($invoice);

                    if ($path === null) {
                        // Edited since the download was prepared. Render it
                        // now rather than quietly leave a filed document out
                        // of the shop's archive.
                        $path = self::renderMissing((int) $invoice->id);
                    }

                    $label = self::invoiceLabel($invoice);

                    if ($path === null) {
                        $omitted[] = $label;
                        $omittedIds[] = (int) $invoice->id;
                        continue;
                    }

                    try {
                        $zip->addFileFromPath(self::entryName($used, $invoice), $path);
                        $packed++;
                    } catch (\Throwable $e) {
                        // The file went away between the check and the read.
                        // One missing invoice must not kill the download — but
                        // it does get named in the archive.
                        $omitted[] = $label;
                        $omittedIds[] = (int) $invoice->id;
                        Log::warning('Invoice ZIP: an invoice dropped out while streaming', [
                            'export_id' => $export->id,
                            'invoice_id' => $invoice->id,
                            'error' => $e->getMessage(),
                        ]);
                    }
                }
            });

        // Whatever could not be included is named inside the archive itself,
        // so nobody has to compare six thousand files against a manifest to
        // discover something is missing.
        if ($omitted !== []) {
            $zip->addFile('_MISSING.txt', self::missingNotice($omitted));
        }

        $zip->finish();

        // What actually went down the wire is the honest record — an invoice
        // that failed while it was being prepared but renders fine now is in
        // the shop's hands, and the panel should stop calling it a failure.
        InvoiceZipExport::where('id', $export->id)->update([
            'processed_invoices' => $packed,
            'failed_invoices' => count($omittedIds),
            'failed_ids' => array_slice($omittedIds, 0, self::MAX_TRACKED_FAILURES),
        ]);
    }

    /** How an invoice is named to a human reading the notice file. */
    protected static function invoiceLabel(Invoice $invoice): string
    {
        return (string) ($invoice->fbr_invoice_number
            ?: $invoice->internal_invoice_number
            ?: $invoice->invoice_number
            ?: ('invoice #' . $invoice->id));
    }

    /** @param array<int,string> $omitted */
    protected static function missingNotice(array $omitted): string
    {
        return "These invoices could not be included in this download:\r\n\r\n"
            . implode("\r\n", $omitted)
            . "\r\n\r\nThey are the only ones missing — everything else in the manifest is here.\r\n"
            . "Open each of them from the invoice list and download it on its own, or build the ZIP again.\r\n";
    }

    /** Last-resort render for one invoice mid-download; null if it cannot be made. */
    protected static function renderMissing(int $invoiceId): ?string
    {
        try {
            $full = Invoice::withoutGlobalScopes()
                ->with(['items', 'company', 'branch'])
                ->find($invoiceId);

            return $full ? InvoicePdfCacheService::ensure($full)['path'] : null;
        } catch (\Throwable $e) {
            Log::warning('Invoice ZIP: could not render an invoice while streaming', [
                'invoice_id' => $invoiceId,
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * Index of everything in the archive, chunked so a 50,000-row manifest
     * never has to exist as 50,000 Eloquent models at once. Invoices that
     * failed to render are excluded — the manifest must not list a PDF the
     * archive does not contain.
     */
    protected static function manifest(InvoiceZipExport $export): string
    {
        $csv = "Internal No,FBR No,Date,Buyer,NTN,Subtotal,Sales Tax,WHT,Total,Status\n";

        $query = self::snapshotQuery($export)
            ->where('id', '<=', (int) $export->cursor_id)
            ->orderBy('id')
            ->select([
                'id', 'internal_invoice_number', 'fbr_invoice_number', 'invoice_date',
                'buyer_name', 'buyer_ntn', 'total_value_excluding_st', 'total_amount',
                'total_sales_tax', 'wht_amount', 'status',
            ]);

        $failedIds = $export->failed_ids ?? [];
        if ($failedIds !== []) {
            $query->whereNotIn('id', $failedIds);
        }

        $query->chunk(500, function ($rows) use (&$csv) {
            foreach ($rows as $inv) {
                $exTax = $inv->total_value_excluding_st
                    ?? ((float) $inv->total_amount - (float) $inv->total_sales_tax);

                $csv .= implode(',', [
                    '"' . ($inv->internal_invoice_number ?? '') . '"',
                    '"' . ($inv->fbr_invoice_number ?? '') . '"',
                    '"' . ($inv->invoice_date ?? '') . '"',
                    '"' . str_replace('"', '""', (string) ($inv->buyer_name ?? '')) . '"',
                    '"' . ($inv->buyer_ntn ?? '') . '"',
                    number_format((float) $exTax, 2, '.', ''),
                    number_format((float) $inv->total_sales_tax, 2, '.', ''),
                    number_format((float) $inv->wht_amount, 2, '.', ''),
                    number_format((float) $inv->total_amount, 2, '.', ''),
                    '"' . $inv->status . '"',
                ]) . "\n";
            }
        });

        return $csv;
    }

    protected static function cappedNotice(InvoiceZipExport $export): string
    {
        return "This archive stopped early.\n\n"
            . "It reached the " . round(self::MAX_BYTES / 1073741824, 1) . " GB size limit after "
            . number_format($export->processed_invoices) . " of "
            . number_format($export->total_invoices) . " invoices.\n\n"
            . "The invoices it does contain are complete and correct. To get the rest,\n"
            . "run the download again one month at a time using the date filters.\n";
    }

    public static function absolutePath(InvoiceZipExport $export): string
    {
        return Storage::disk('local')->path($export->file_path);
    }

    public static function downloadName(InvoiceZipExport $export): string
    {
        $company = \App\Models\Company::withoutGlobalScopes()->find($export->company_id);
        $slug = $company ? preg_replace('/[^A-Za-z0-9._-]+/', '_', $company->name) : 'company';
        $scope = ($export->filters['scope'] ?? self::SCOPE_COMPLETED);

        return "invoices_{$slug}_{$scope}_" . ($export->created_at?->format('Ymd-Hi') ?? date('Ymd-Hi')) . '.zip';
    }

    /** Human sentence describing what was asked for — shown back on the page. */
    public static function describe(array $filters): string
    {
        $scope = $filters['scope'] ?? self::SCOPE_COMPLETED;
        $label = match ($scope) {
            self::SCOPE_DRAFT => 'Draft invoices',
            self::SCOPE_ALL => 'All invoices',
            default => 'Completed invoices',
        };

        if (!empty($filters['from']) || !empty($filters['to'])) {
            $label .= ' from ' . ($filters['from'] ?: 'the beginning') . ' to ' . ($filters['to'] ?: 'today');
        }
        if (!empty($filters['doc_type'])) {
            $label .= ' — ' . $filters['doc_type'];
        }
        if (!empty($filters['fbr_status'])) {
            $label .= ' — FBR ' . $filters['fbr_status'];
        }

        return $label;
    }

    public static function purgeForCompany(int $companyId): void
    {
        InvoiceZipExport::where('company_id', $companyId)->get()->each(fn ($e) => self::delete($e));
    }

    public static function purgeExpired(): int
    {
        $cutoff = now()->subHours(self::RETENTION_HOURS);
        $removed = 0;

        InvoiceZipExport::where('created_at', '<', $cutoff)->get()->each(function ($export) use (&$removed) {
            self::delete($export);
            $removed++;
        });

        return $removed;
    }

    public static function delete(InvoiceZipExport $export): void
    {
        if ($export->file_path) {
            try {
                Storage::disk('local')->delete($export->file_path);
            } catch (\Throwable $e) {
                Log::warning('Invoice ZIP delete failed', ['export_id' => $export->id, 'error' => $e->getMessage()]);
            }
        }

        $export->delete();
    }
}
