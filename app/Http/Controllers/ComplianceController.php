<?php

namespace App\Http\Controllers;

use App\Jobs\BuildAuditPackJob;
use App\Models\AuditLog;
use App\Models\AuditPack;
use App\Models\Company;
use App\Models\FbrLog;
use App\Models\Invoice;
use App\Services\AuditLogService;
use App\Services\AuditPackBuilderService;
use App\Services\IntegrityHashService;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class ComplianceController extends Controller
{
    protected const COMPLETED_STATUSES = ['locked', 'pending_verification'];

    public function index()
    {
        $companyId = (int) app('currentCompanyId');

        AuditPackBuilderService::cleanupExpired($companyId);

        $company = Company::find($companyId);

        $totalInvoices = Invoice::where('company_id', $companyId)->count();
        $completedInvoices = Invoice::where('company_id', $companyId)->whereIn('status', self::COMPLETED_STATUSES)->count();
        $firstInvoiceAt = Invoice::where('company_id', $companyId)->whereIn('status', self::COMPLETED_STATUSES)->min('created_at');
        $auditLogCount = AuditLog::where('company_id', $companyId)->count();
        $firstAuditLogAt = AuditLog::where('company_id', $companyId)->min('created_at');
        $fbrLogCount = FbrLog::whereIn('invoice_id', Invoice::where('company_id', $companyId)->select('id'))->count();

        // Integrity spot-check across the most recent completed invoices.
        $sampleLimit = 500;
        $sample = Invoice::where('company_id', $companyId)
            ->whereIn('status', self::COMPLETED_STATUSES)
            ->orderByDesc('id')
            ->take($sampleLimit)
            ->with('items')
            ->get();

        $integrity = [
            'checked' => $sample->count(),
            'passed' => 0,
            'failed' => 0,
            'missing' => 0,
            'failed_numbers' => [],
            'sampled' => $completedInvoices > $sampleLimit,
        ];

        foreach ($sample as $inv) {
            if (!$inv->integrity_hash) {
                $integrity['missing']++;
            } elseif ($inv->integrity_hash === IntegrityHashService::generate($inv)) {
                $integrity['passed']++;
            } else {
                $integrity['failed']++;
                if (count($integrity['failed_numbers']) < 10) {
                    $integrity['failed_numbers'][] = $inv->internal_invoice_number ?: ($inv->invoice_number ?: ('#' . $inv->id));
                }
            }
        }

        $packs = AuditPack::where('company_id', $companyId)->orderByDesc('id')->take(10)->get();
        $activePack = $packs->first(fn ($p) => $p->isActive());

        return view('compliance.index', [
            'company' => $company,
            'totalInvoices' => $totalInvoices,
            'completedInvoices' => $completedInvoices,
            'firstInvoiceAt' => $firstInvoiceAt ? Carbon::parse($firstInvoiceAt) : null,
            'auditLogCount' => $auditLogCount,
            'firstAuditLogAt' => $firstAuditLogAt ? Carbon::parse($firstAuditLogAt) : null,
            'fbrLogCount' => $fbrLogCount,
            'integrity' => $integrity,
            'packs' => $packs,
            'activePack' => $activePack,
            'maxInvoices' => AuditPackBuilderService::MAX_INVOICES,
            'retentionDays' => AuditPackBuilderService::RETENTION_DAYS,
        ]);
    }

    public function store(Request $request)
    {
        $companyId = (int) app('currentCompanyId');

        $data = $request->validate([
            'date_from' => 'required|date',
            'date_to' => 'required|date|after_or_equal:date_from',
        ], [
            'date_to.after_or_equal' => 'The "to" date must be on or after the "from" date.',
        ]);

        $from = Carbon::parse($data['date_from'])->toDateString();
        $to = Carbon::parse($data['date_to'])->toDateString();

        if (AuditPack::where('company_id', $companyId)->whereIn('status', AuditPack::ACTIVE_STATUSES)->exists()) {
            return back()->with('error', 'An Audit Pack is already being generated. Please wait for it to finish — if it appears stuck, delete it from the list below and try again.');
        }

        $count = Invoice::where('company_id', $companyId)
            ->whereIn('status', self::COMPLETED_STATUSES)
            ->whereDate('invoice_date', '>=', $from)
            ->whereDate('invoice_date', '<=', $to)
            ->count();

        if ($count === 0) {
            return back()->with('error', 'No completed invoices found in the selected date range.');
        }

        if ($count > AuditPackBuilderService::MAX_INVOICES) {
            return back()->with('error', 'This range contains ' . number_format($count) . ' invoices. A single Audit Pack supports up to ' . number_format(AuditPackBuilderService::MAX_INVOICES) . ' invoices — please split the range (for example, quarter by quarter).');
        }

        $pack = AuditPack::create([
            'company_id' => $companyId,
            'user_id' => auth()->id(),
            'date_from' => $from,
            'date_to' => $to,
            'status' => 'pending',
        ]);

        $pack->forceFill([
            'file_path' => 'audit-packs/company_' . $companyId . '/fbr-audit-pack-' . $pack->id . '.zip',
        ])->save();

        try {
            AuditLogService::log('audit_pack_requested', 'AuditPack', $pack->id, null, [
                'date_from' => $from,
                'date_to' => $to,
                'invoices' => $count,
            ], $companyId);
        } catch (\Throwable $e) {
            Log::warning('AuditPack request audit log failed', ['error' => $e->getMessage()]);
        }

        BuildAuditPackJob::dispatch($pack->id);

        return redirect()->route('compliance.index')->with('success', 'Audit Pack generation started for ' . number_format($count) . ' invoice(s). Stay on this page — the download button appears as soon as it is ready.');
    }

    public function status(AuditPack $pack)
    {
        $companyId = (int) app('currentCompanyId');
        abort_if((int) $pack->company_id !== $companyId, 403);

        // Worker-less fallback: if no queue worker has touched this pack recently,
        // advance one chunk inline so the pack still completes via polling alone.
        if ($pack->isActive() && $pack->updated_at && $pack->updated_at->lt(now()->subSeconds(15))) {
            try {
                @set_time_limit(150);
                @ini_set('memory_limit', '1024M');
                AuditPackBuilderService::processNextChunk($pack);
            } catch (\Throwable $e) {
                Log::warning('AuditPack inline chunk failed', ['pack_id' => $pack->id, 'error' => $e->getMessage()]);
            }
            $pack->refresh();
        }

        return response()->json([
            'id' => $pack->id,
            'status' => $pack->status,
            'progress' => (int) $pack->progress,
            'processed' => (int) $pack->processed_invoices,
            'total' => (int) $pack->total_invoices,
            'file_size' => $pack->file_size ? (int) $pack->file_size : null,
            'download_url' => $pack->status === 'ready' ? route('compliance.packs.download', $pack) : null,
            'error' => $pack->status === 'failed' ? 'Pack generation failed. Please try again — if it keeps failing, contact support.' : null,
        ]);
    }

    public function download(AuditPack $pack)
    {
        $companyId = (int) app('currentCompanyId');
        abort_if((int) $pack->company_id !== $companyId, 403);

        if ($pack->status !== 'ready' || !$pack->file_path) {
            return redirect()->route('compliance.index')->with('error', 'This Audit Pack is not ready yet.');
        }

        $abs = Storage::disk('local')->path($pack->file_path);
        if (!is_file($abs)) {
            return redirect()->route('compliance.index')->with('error', 'This Audit Pack file has expired. Please generate a new one.');
        }

        try {
            AuditLogService::log('audit_pack_downloaded', 'AuditPack', $pack->id, null, [
                'date_from' => $pack->date_from->toDateString(),
                'date_to' => $pack->date_to->toDateString(),
            ], $companyId);
        } catch (\Throwable $e) {
            Log::warning('AuditPack download audit log failed', ['error' => $e->getMessage()]);
        }

        $company = Company::find($companyId);
        $slug = $company ? trim(preg_replace('/[^A-Za-z0-9]+/', '-', $company->name), '-') : ('company-' . $companyId);
        $filename = 'FBR-Audit-Pack-' . $slug . '-' . $pack->date_from->format('Y-m-d') . '-to-' . $pack->date_to->format('Y-m-d') . '.zip';

        // Small files are returned from memory (proxy-safe — same approach as bulk
        // invoice ZIP downloads); very large ones stream from disk.
        $size = filesize($abs) ?: 0;
        if ($size > 0 && $size < 100 * 1024 * 1024) {
            return response(file_get_contents($abs), 200, [
                'Content-Type' => 'application/zip',
                'Content-Disposition' => 'attachment; filename="' . $filename . '"',
                'Content-Length' => (string) $size,
            ]);
        }

        return response()->download($abs, $filename, ['Content-Type' => 'application/zip']);
    }

    public function destroy(AuditPack $pack)
    {
        $companyId = (int) app('currentCompanyId');
        abort_if((int) $pack->company_id !== $companyId, 403);

        // Block deleting a pack that is genuinely mid-build; allow deleting
        // abandoned ones (no progress for longer than the stale-lock window).
        if ($pack->isActive() && $pack->updated_at && $pack->updated_at->gt(now()->subSeconds(AuditPackBuilderService::STALE_LOCK_SECONDS))) {
            return redirect()->route('compliance.index')->with('error', 'This Audit Pack is still being generated. Please wait a moment.');
        }

        if ($pack->file_path) {
            try {
                Storage::disk('local')->delete($pack->file_path);
            } catch (\Throwable $e) {
                Log::warning('AuditPack file delete failed', ['pack_id' => $pack->id, 'error' => $e->getMessage()]);
            }
        }

        $packId = $pack->id;
        $pack->delete();

        try {
            AuditLogService::log('audit_pack_deleted', 'AuditPack', $packId, null, null, $companyId);
        } catch (\Throwable $e) {
            Log::warning('AuditPack delete audit log failed', ['error' => $e->getMessage()]);
        }

        return redirect()->route('compliance.index')->with('success', 'Audit Pack deleted.');
    }
}
