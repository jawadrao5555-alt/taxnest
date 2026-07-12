<?php

namespace App\Http\Controllers;

use App\Models\PosTransaction;
use App\Models\User;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

class PosLocalBillsController extends Controller
{
    /**
     * Local Bills Portal — accessible by pos_role='local_viewer' accounts AND by
     * POS admins of the company (owner request Jul 2026). The ONLY surface where
     * local (non-PRA) bills are visible: live local bills plus those already
     * archived at day-close / Local Final. Cashiers cannot see this data
     * (PosAuth confines access).
     */
    private function baseQuery(int $companyId)
    {
        return PosTransaction::withoutGlobalScope('hide_archived')
            ->where('company_id', $companyId)
            ->where('invoice_mode', 'local')
            ->where('status', 'completed');
    }

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
        if ($from = $request->input('from')) {
            $query->whereDate('created_at', '>=', $from);
        }
        if ($to = $request->input('to')) {
            $query->whereDate('created_at', '<=', $to);
        }
        if ($cashier = $request->input('cashier')) {
            $query->where('created_by', $cashier);
        }

        $stats = [
            'total' => (clone $query)->count(),
            'sum' => (clone $query)->sum('total_amount'),
            'today' => $this->baseQuery($companyId)->whereDate('created_at', today())->count(),
            'today_sum' => $this->baseQuery($companyId)->whereDate('created_at', today())->sum('total_amount'),
        ];

        $bills = $query->with(['creator', 'items'])
            ->orderBy('created_at', 'desc')
            ->orderBy('id', 'desc')
            ->paginate(50)
            ->withQueryString();

        $cashiers = User::where('company_id', $companyId)
            ->whereIn('pos_role', ['pos_admin', 'pos_cashier'])
            ->orderBy('name')
            ->get(['id', 'name']);

        return view('pos.local.index', compact('bills', 'stats', 'cashiers'));
    }

    public function show($id)
    {
        $companyId = app('currentCompanyId');
        $bill = $this->baseQuery($companyId)
            ->with(['items', 'creator', 'company'])
            ->findOrFail($id);

        return view('pos.local.show', compact('bill'));
    }

    public function exportCsv(Request $request): StreamedResponse
    {
        $companyId = app('currentCompanyId');
        $query = $this->baseQuery($companyId);

        if ($from = $request->input('from')) {
            $query->whereDate('created_at', '>=', $from);
        }
        if ($to = $request->input('to')) {
            $query->whereDate('created_at', '<=', $to);
        }

        $bills = $query->with('creator')->orderBy('created_at', 'desc')->get();
        $filename = 'local-bills-' . now()->format('Ymd-His') . '.csv';

        return response()->streamDownload(function () use ($bills) {
            $out = fopen('php://output', 'w');
            fputcsv($out, [
                'Invoice #', 'Created At', 'Cashier', 'Customer', 'Phone',
                'Subtotal', 'Discount', 'Tax', 'Total',
                'Payment Method', 'Status', 'Archived',
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
                    $b->is_archived ? 'Yes' : 'No',
                ]);
            }
            fclose($out);
        }, $filename, ['Content-Type' => 'text/csv']);
    }
}
