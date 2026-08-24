<?php

namespace App\Services;

use App\Models\Invoice;
use App\Models\InvoiceZipExport;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
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
    /** Invoices rendered per claim. Small enough that a chunk always fits in the lock window. */
    public const CHUNK_SIZE = 25;

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

    /** Extend the lease mid-chunk. False means we lost it and must stop writing. */
    protected static function renewLease(InvoiceZipExport $export, string $token): bool
    {
        return InvoiceZipExport::where('id', $export->id)
            ->where('lock_token', $token)
            ->update(['locked_at' => now()]) === 1;
    }

    /**
     * Persist progress, but only while we still own the build.
     *
     * @return bool false if the lease was lost (the caller must stop)
     */
    protected static function writeState(InvoiceZipExport $export, string $token, array $attrs): bool
    {
        $written = InvoiceZipExport::where('id', $export->id)
            ->where('lock_token', $token)
            ->update($attrs) === 1;

        if ($written) {
            $export->forceFill($attrs);
        } else {
            Log::warning('Invoice ZIP: lease lost, discarding progress write', ['export_id' => $export->id]);
        }

        return $written;
    }

    /**
     * @return string 'done' (ready or failed), 'continue' (more work), 'busy' (claimed elsewhere)
     */
    public static function processNextChunk(InvoiceZipExport $export): string
    {
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

        $path = $export->file_path
            ?: 'invoice-zips/company_' . $export->company_id . '/invoices-' . $export->id . '.zip';

        Storage::disk('local')->makeDirectory(dirname($path));

        self::writeState($export, $token, [
            'status' => 'processing',
            'file_path' => $path,
            'max_invoice_id' => $maxId,
            'cursor_id' => 0,
            'total_invoices' => $total,
            'processed_invoices' => 0,
            'failed_invoices' => 0,
            'progress' => 1,
        ]);
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
            // Walked the whole frozen range — whatever was packed IS the archive.
            self::writeState($export, $token, ['cursor_id' => (int) $export->max_invoice_id]);
            return;
        }

        // Take the lease right up to the write so the window in which another
        // worker could be touching this same archive stays as small as possible.
        if (!self::renewLease($export, $token)) {
            return;
        }

        $absolute = self::absolutePath($export);

        $zip = new \ZipArchive();
        if ($zip->open($absolute, \ZipArchive::CREATE) !== true) {
            throw new \RuntimeException('Could not open the ZIP archive for writing.');
        }

        $failedIds = $export->failed_ids ?? [];

        foreach ($invoices as $invoice) {
            try {
                $pdf = InvoicePdfService::renderBw($invoice);
                $zip->addFromString(self::entryName($zip, $invoice), $pdf->output());
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

        $zip->close();

        $processed = (int) $export->processed_invoices + $invoices->count();
        $total = max(1, (int) $export->total_invoices);
        $size = @filesize($absolute) ?: 0;

        self::writeState($export, $token, [
            'cursor_id' => (int) $invoices->last()->id,
            'processed_invoices' => $processed,
            'failed_invoices' => count($failedIds),
            'failed_ids' => $failedIds,
            'file_size' => $size,
            // Never report 100% until finalize actually says so.
            'progress' => max(1, min(99, (int) floor($processed / $total * 95))),
            // Stopping on the disk ceiling is a real outcome, not a silent trim.
            'size_capped' => $size >= self::MAX_BYTES,
        ]);
    }

    /**
     * Unique name inside the archive.
     *
     * Two invoices can legitimately carry the same visible number (a draft that
     * was never numbered, a re-issued document), and a duplicate entry would
     * overwrite the earlier PDF — the one thing a verification archive must
     * never do. The archive itself is asked whether the name is taken.
     */
    protected static function entryName(\ZipArchive $zip, Invoice $invoice): string
    {
        $base = $invoice->fbr_invoice_number
            ?: ($invoice->internal_invoice_number
                ?: ($invoice->invoice_number ?: ('invoice-' . $invoice->id)));

        $safe = preg_replace('/[^A-Za-z0-9._-]+/', '_', (string) $base);
        $prefix = $invoice->status === 'draft' ? 'drafts/' : '';
        $name = $prefix . $safe . '.pdf';

        if ($zip->locateName($name) !== false) {
            $name = $prefix . $safe . '__' . $invoice->id . '.pdf';
        }

        return $name;
    }

    protected static function finalize(InvoiceZipExport $export, string $token): void
    {
        $absolute = self::absolutePath($export);

        if (!self::renewLease($export, $token)) {
            return;
        }

        $zip = new \ZipArchive();
        if ($zip->open($absolute, \ZipArchive::CREATE) === true) {
            $zip->addFromString('_manifest.csv', self::manifest($export));

            if ($export->size_capped) {
                $zip->addFromString('_READ-ME.txt', self::cappedNotice($export));
            }

            $zip->close();
        }

        clearstatcache(true, $absolute);

        $attrs = [
            'status' => 'ready',
            'progress' => 100,
            'file_size' => @filesize($absolute) ?: $export->file_size,
            'completed_at' => now(),
        ];

        // A build that walked its whole range packed exactly what it packed —
        // invoices deleted mid-build must not leave the UI reading "9,998 of
        // 10,000" forever. A size-capped build keeps the real total, because
        // there genuinely are invoices it did not reach.
        if (!$export->size_capped) {
            $attrs['total_invoices'] = (int) $export->processed_invoices;
        }

        self::writeState($export, $token, $attrs);
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
