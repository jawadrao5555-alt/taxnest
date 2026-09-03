<?php

namespace App\Http\Controllers;

use App\Models\PosTransaction;
use App\Models\PosDayCloseReport;
use App\Models\User;
use App\Services\BranchContextService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Symfony\Component\HttpFoundation\StreamedResponse;

class PosArchiveController extends Controller
{
    /**
     * Every archived bill this portal may show — the SINGLE choke point for the
     * list, its totals, the bill page and the CSV export, so the screen and the
     * export can never disagree (Task 1361).
     *
     * Branch scoping rides here too: an archived bill carries real money, so a
     * branch's audit login must not see (or export) another branch's history.
     * applyToQuery is a no-op for a single-branch shop and for the company-wide
     * view, and legacy pre-branch rows (branch_id NULL) always stay visible.
     */
    private function baseQuery(int $companyId)
    {
        $query = PosTransaction::withoutGlobalScope('hide_archived')
            ->where('company_id', $companyId)
            ->where('is_archived', true)
            ->where(function ($m) { $m->where('invoice_mode', 'pra')->orWhereNull('invoice_mode'); });

        app(BranchContextService::class)->applyToQuery($query, 'branch_id');

        return $query;
    }

    /**
     * Archive Portal — only accessible by users with pos_role = 'archive_viewer'.
     * Shows archived local/provisional bills that were moved here at day-close.
     * Completely isolated from normal POS UI; cashiers/admins cannot see this data.
     */
    public function index(Request $request)
    {
        $companyId = app('currentCompanyId');

        $query = $this->baseQuery($companyId);

        if ($q = trim((string) $request->input('q', ''))) {
            $query->where(function ($w) use ($q) {
                $w->where('invoice_number', 'like', "%{$q}%")
                  ->orWhere('customer_name', 'like', "%{$q}%")
                  ->orWhere('customer_phone', 'like', "%{$q}%");
            });
        }
        // Business-day filters (owner rule 26 Jul 2026): the archive is where
        // day-close-washed bills land, so its date filters must match the
        // Z-report's business-day buckets or counts would disagree.
        if ($from = $request->input('from')) {
            $query->where('business_date', '>=', $from);
        }
        if ($to = $request->input('to')) {
            $query->where('business_date', '<=', $to);
        }
        if ($cashier = $request->input('cashier')) {
            $query->where('created_by', $cashier);
        }
        if ($reportId = $request->input('report')) {
            $query->where('archived_by_report_id', $reportId);
        }

        $stats = [
            'total' => (clone $query)->count(),
            'sum' => (clone $query)->sum('total_amount'),
        ];

        $bills = $query->with(['creator', 'items'])
            ->orderBy('archived_at', 'desc')
            ->orderBy('id', 'desc')
            ->paginate(50)
            ->withQueryString();

        $cashiers = User::where('company_id', $companyId)
            ->whereIn('pos_role', ['pos_admin', 'pos_cashier'])
            ->orderBy('name')
            ->get(['id', 'name']);

        $reports = PosDayCloseReport::where('company_id', $companyId)
            // Multiple closes can share one business date (stream/branch or a
            // corrected close). Keep the archive filter in true newest-first
            // sequence instead of leaving equal dates to database row order.
            ->orderByDesc('report_date')
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->take(60)
            ->get(['id', 'report_number', 'report_date']);

        return view('pos.archive.index', compact('bills', 'stats', 'cashiers', 'reports'));
    }

    public function show($id)
    {
        $companyId = app('currentCompanyId');
        $bill = $this->baseQuery($companyId)
            ->with(['items', 'creator', 'company'])
            ->findOrFail($id);

        return view('pos.archive.show', compact('bill'));
    }

    public function exportCsv(Request $request): StreamedResponse
    {
        $companyId = app('currentCompanyId');
        $query = $this->baseQuery($companyId);

        if ($from = $request->input('from')) {
            $query->where('business_date', '>=', $from);
        }
        if ($to = $request->input('to')) {
            $query->where('business_date', '<=', $to);
        }

        $bills = $query->with('creator')->orderBy('archived_at', 'desc')->get();
        $filename = 'archived-local-bills-' . now()->format('Ymd-His') . '.csv';

        return response()->streamDownload(function () use ($bills) {
            $out = fopen('php://output', 'w');
            fputcsv($out, [
                'Invoice #', 'Created At', 'Cashier', 'Customer', 'Phone',
                'Subtotal', 'Discount', 'Tax', 'Total',
                'Payment Method', 'PRA Status', 'Archived At', 'Day-Close Report ID',
            ]);
            foreach ($bills as $b) {
                fputcsv($out, [
                    $b->invoice_number,
                    $b->created_at?->format('Y-m-d H:i:s'),
                    $b->creator->name ?? 'N/A',
                    $b->customer_name ?? '',
                    $b->customer_phone ?? '',
                    $b->subtotal,
                    $b->discount_amount,
                    $b->tax_amount,
                    $b->total_amount,
                    $b->payment_method,
                    $b->pra_status,
                    $b->archived_at?->format('Y-m-d H:i:s'),
                    $b->archived_by_report_id,
                ]);
            }
            fclose($out);
        }, $filename, ['Content-Type' => 'text/csv']);
    }
}
