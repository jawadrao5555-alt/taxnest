<?php

namespace App\Services;

use App\Models\Invoice;
use App\Models\InvoiceZipExport;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/**
 * Rendered invoice PDFs, kept on disk between downloads.
 *
 * Rendering is what makes a bulk download slow. Measured on the live server,
 * one invoice costs roughly two seconds of CPU while several renderers are
 * running, so a distributor's 6,000 filed invoices is about an hour of work —
 * an hour the shop paid again every single time it asked for the same
 * documents, none of which can change once they are filed.
 *
 * So a PDF is rendered once and kept. A cached file counts as current only
 * while it is newer than the invoice it was made from, which means an edited
 * invoice re-renders by itself and a stale document can never be handed back.
 *
 * The whole cache is small: every filed invoice on the platform is a few
 * hundred megabytes together, against an unlimited account quota.
 */
class InvoicePdfCacheService
{
    /**
     * When the buyer-facing template last changed in a way the buyer can see.
     *
     * A cached PDF is only checked against the invoice it was made from, so a
     * redesign of the document would otherwise keep being served from disk for
     * every invoice nobody has edited since — two shops could hold the same
     * invoice number in two different layouts. Bump this to the moment of the
     * change (unix seconds) whenever the printed document itself changes, and
     * every cached file older than it re-renders on its next download.
     *
     * Do NOT wire this to the Blade file's mtime: a deploy rewrites file times,
     * which would silently re-render a distributor's whole archive on releases
     * that never touched the invoice.
     *
     * 2026-08-26: head-office name/address removed from a branch invoice;
     * later the same day, the printed invoice number became the short own
     * number (D0036) instead of the long registration-prefixed one.
     */
    public const TEMPLATE_CHANGED_AT = 1787735912;

    public static function dir(int $companyId): string
    {
        return 'invoice-pdfs/company_' . $companyId;
    }

    public static function path(int $companyId, int $invoiceId): string
    {
        return Storage::disk('local')->path(self::dir($companyId) . '/' . $invoiceId . '.pdf');
    }

    /** The cached PDF for this invoice, or null when it is missing or stale. */
    public static function currentPath(Invoice $invoice): ?string
    {
        $path = self::path((int) $invoice->company_id, (int) $invoice->id);

        if (!@filesize($path)) {
            return null;
        }

        $changedAt = max(
            (int) ($invoice->updated_at?->getTimestamp() ?? 0),
            (int) ($invoice->created_at?->getTimestamp() ?? 0),
            self::TEMPLATE_CHANGED_AT
        );

        // Rendered before the invoice last changed: what is on disk shows the
        // old figures, and handing a shop a stale tax document is worse than
        // making it wait.
        return @filemtime($path) >= $changedAt ? $path : null;
    }

    /**
     * The cached PDF for this invoice, rendering it first if needed.
     *
     * The write is atomic: several workers fill this directory while a
     * download reads from it, and a half-written PDF must never be mistaken
     * for a finished one.
     *
     * @return array{path:string,size:int,rendered:bool}
     */
    public static function ensure(Invoice $invoice): array
    {
        $existing = self::currentPath($invoice);

        if ($existing !== null) {
            return ['path' => $existing, 'size' => (int) filesize($existing), 'rendered' => false];
        }

        Storage::disk('local')->makeDirectory(self::dir((int) $invoice->company_id));

        $path = self::path((int) $invoice->company_id, (int) $invoice->id);

        // Stamped with the moment the rendering began, not the moment it
        // finished. An invoice edited while its PDF was being drawn would
        // otherwise end up with a file newer than the edit — and that stale
        // rendering would be handed out as the shop's tax document forever.
        $readAt = time();
        $bytes = InvoicePdfService::renderBw($invoice)->output();
        $tmp = $path . '.' . getmypid() . '.part';

        if (@file_put_contents($tmp, $bytes) === false || !@rename($tmp, $path)) {
            @unlink($tmp);
            throw new \RuntimeException('Could not write the rendered invoice PDF to disk.');
        }

        @touch($path, $readAt);

        return ['path' => $path, 'size' => strlen($bytes), 'rendered' => true];
    }

    public static function forget(int $companyId, int $invoiceId): void
    {
        @unlink(self::path($companyId, $invoiceId));
    }

    /** @return array{files:int,bytes:int} */
    public static function usage(int $companyId): array
    {
        $files = 0;
        $bytes = 0;

        foreach (glob(Storage::disk('local')->path(self::dir($companyId)) . '/*.pdf') ?: [] as $file) {
            $files++;
            $bytes += (int) @filesize($file);
        }

        return ['files' => $files, 'bytes' => $bytes];
    }

    /**
     * Drop cached PDFs nobody has wanted for a long time.
     *
     * Nothing is lost by pruning — a pruned invoice simply renders again the
     * next time somebody asks for it.
     */
    public static function prune(int $days = 180): int
    {
        $cutoff = time() - ($days * 86400);
        $removed = 0;

        // A company with a prepared download may be streaming from these files
        // right now; deleting one mid-download breaks the archive in the
        // shop's hands. Their PDFs are left alone until the export expires.
        $busy = InvoiceZipExport::whereIn('status', ['queued', 'processing', 'ready'])
            ->pluck('company_id')
            ->unique()
            ->map(fn ($id) => 'company_' . $id)
            ->all();

        foreach (glob(Storage::disk('local')->path('invoice-pdfs') . '/company_*/*.pdf') ?: [] as $file) {
            if (in_array(basename(dirname($file)), $busy, true)) {
                continue;
            }

            if ((int) @filemtime($file) < $cutoff && @unlink($file)) {
                $removed++;
            }
        }

        if ($removed > 0) {
            Log::info('Invoice PDF cache pruned', ['files' => $removed, 'older_than_days' => $days]);
        }

        return $removed;
    }
}
