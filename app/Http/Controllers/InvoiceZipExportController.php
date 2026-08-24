<?php

namespace App\Http\Controllers;

use App\Jobs\BuildInvoiceZipJob;
use App\Models\InvoiceZipExport;
use App\Services\InvoiceZipBuilderService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * Background ZIP downloads of invoice PDFs.
 *
 * The synchronous bulk download stays where it is for small, quick grabs;
 * this route exists for the "give me every draft I have, all fifty thousand
 * of them, so I can check them" case, which cannot finish inside a request.
 */
class InvoiceZipExportController extends Controller
{
    public function store(Request $request)
    {
        $validated = $request->validate([
            'scope' => 'nullable|in:draft,completed,all',
            'from' => 'nullable|date_format:Y-m-d',
            'to' => 'nullable|date_format:Y-m-d',
            'month' => 'nullable|date_format:Y-m',
            'fbr_status' => 'nullable|in:production,sandbox,validated,pending,failed',
            'doc_type' => 'nullable|in:Sale Invoice,Credit Note,Debit Note',
        ]);

        $from = $validated['from'] ?? null;
        $to = $validated['to'] ?? null;

        if (!empty($validated['month'])) {
            $from = $validated['month'] . '-01';
            $to = date('Y-m-t', strtotime($from));
        }

        if ($from && $to && strtotime($from) > strtotime($to)) {
            return back()->with('error', 'ZIP download: the "from" date must be on or before the "to" date.');
        }

        $export = InvoiceZipBuilderService::start(
            (int) app('currentCompanyId'),
            $request->user()?->id,
            [
                'scope' => $validated['scope'] ?? InvoiceZipBuilderService::SCOPE_COMPLETED,
                'from' => $from,
                'to' => $to,
                'fbr_status' => $validated['fbr_status'] ?? null,
                'doc_type' => $validated['doc_type'] ?? null,
            ]
        );

        // The status endpoint advances the build on its own if no worker is
        // listening, so a missing queue worker slows the export down rather
        // than stranding it.
        try {
            BuildInvoiceZipJob::dispatch($export->id);
        } catch (\Throwable $e) {
            Log::warning('Invoice ZIP: dispatch failed, falling back to poll-driven build', [
                'export_id' => $export->id,
                'error' => $e->getMessage(),
            ]);
        }

        return back()->with('invoice_zip_export_id', $export->id);
    }

    public function status(InvoiceZipExport $export)
    {
        $this->authorizeExport($export);

        // Worker-less fallback: if nothing has touched this export recently,
        // advance one chunk inline so polling alone still completes it.
        if ($export->isActive() && $export->updated_at && $export->updated_at->lt(now()->subSeconds(15))) {
            try {
                @set_time_limit(150);
                @ini_set('memory_limit', '1024M');
                InvoiceZipBuilderService::processNextChunk($export);
            } catch (\Throwable $e) {
                Log::warning('Invoice ZIP inline chunk failed', ['export_id' => $export->id, 'error' => $e->getMessage()]);
            }
            $export->refresh();
        }

        return response()->json([
            'id' => $export->id,
            'status' => $export->status,
            'progress' => (int) $export->progress,
            'processed' => (int) $export->processed_invoices,
            'total' => (int) $export->total_invoices,
            'failed' => (int) $export->failed_invoices,
            'size_capped' => (bool) $export->size_capped,
            'file_size' => $export->file_size ? (int) $export->file_size : null,
            'scope_label' => $export->scope_label,
            'download_url' => $export->isReady() ? route('invoices.zip-exports.download', $export) : null,
            'error' => $export->status === 'failed'
                ? ($export->error_message ?: 'ZIP build failed. Please try again.')
                : null,
        ]);
    }

    public function download(InvoiceZipExport $export)
    {
        $this->authorizeExport($export);
        abort_unless($export->isReady(), 404);

        $absolute = InvoiceZipBuilderService::absolutePath($export);
        abort_unless(is_file($absolute), 404);

        // Streamed from disk with a real Content-Length: a multi-gigabyte
        // archive can never be buffered into memory the way the old
        // synchronous download did.
        return response()->download($absolute, InvoiceZipBuilderService::downloadName($export), [
            'Content-Type' => 'application/zip',
        ]);
    }

    public function destroy(InvoiceZipExport $export)
    {
        $this->authorizeExport($export);
        InvoiceZipBuilderService::delete($export);

        return back()->with('success', 'ZIP download removed.');
    }

    private function authorizeExport(InvoiceZipExport $export): void
    {
        abort_if((int) $export->company_id !== (int) app('currentCompanyId'), 403);
    }
}
