<?php

namespace App\Http\Controllers;

use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\ComplianceReport;
use App\Models\VendorRiskProfile;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Response;
use Carbon\Carbon;

class MISController extends Controller
{
    public function index()
    {
        $companyId = app('currentCompanyId');
        $currentMonth = now()->startOfMonth();
        $lastMonth = now()->subMonth()->startOfMonth();

        $currentMonthInvoices = Invoice::where('company_id', $companyId)
            ->where('created_at', '>=', $currentMonth)->get();
        $lastMonthInvoices = Invoice::where('company_id', $companyId)
            ->whereBetween('created_at', [$lastMonth, $currentMonth])->get();

        $monthlySummary = [
            'current_count' => $currentMonthInvoices->count(),
            'last_count' => $lastMonthInvoices->count(),
            'current_revenue' => $currentMonthInvoices->sum('total_amount'),
            'last_revenue' => $lastMonthInvoices->sum('total_amount'),
            'growth_count' => $lastMonthInvoices->count() > 0
                ? round((($currentMonthInvoices->count() - $lastMonthInvoices->count()) / $lastMonthInvoices->count()) * 100, 1) : 0,
            'growth_revenue' => $lastMonthInvoices->sum('total_amount') > 0
                ? round((($currentMonthInvoices->sum('total_amount') - $lastMonthInvoices->sum('total_amount')) / $lastMonthInvoices->sum('total_amount')) * 100, 1) : 0,
        ];

        $taxSummary = [];
        for ($i = 5; $i >= 0; $i--) {
            $month = now()->subMonths($i);
            $invoiceIds = Invoice::where('company_id', $companyId)
                ->whereMonth('created_at', $month->month)
                ->whereYear('created_at', $month->year)
                ->pluck('id');
            $totalTax = InvoiceItem::whereIn('invoice_id', $invoiceIds)->sum('tax');
            $totalSubtotal = InvoiceItem::whereIn('invoice_id', $invoiceIds)
                ->selectRaw('SUM(price * quantity) as subtotal')->value('subtotal') ?? 0;
            $taxSummary[] = [
                'month' => $month->format('M Y'),
                'tax_collected' => $totalTax,
                'subtotal' => $totalSubtotal,
                'effective_rate' => $totalSubtotal > 0 ? round(($totalTax / $totalSubtotal) * 100, 2) : 0,
            ];
        }

        $hsConcentration = InvoiceItem::whereIn('invoice_id',
                Invoice::where('company_id', $companyId)->pluck('id'))
            ->select(
                DB::raw("SUBSTRING(hs_code, 1, 2) as hs_prefix"),
                DB::raw('COUNT(*) as item_count'),
                DB::raw('SUM(price * quantity) as total_value'),
                DB::raw('SUM(tax) as total_tax')
            )
            ->groupBy('hs_prefix')
            ->orderByDesc('total_value')
            ->take(15)
            ->get();

        $vendorRanking = VendorRiskProfile::where('company_id', $companyId)
            ->orderBy('vendor_score', 'asc')
            ->take(20)
            ->get();

        return view('mis.index', compact('monthlySummary', 'taxSummary', 'hsConcentration', 'vendorRanking'));
    }

    public function exportCsv(Request $request)
    {
        $companyId = app('currentCompanyId');
        $type = $request->get('type', 'monthly');

        if ($type === 'monthly') {
            $invoices = Invoice::where('company_id', $companyId)
                ->with('items')
                ->whereMonth('created_at', now()->month)
                ->whereYear('created_at', now()->year)
                ->orderBy('created_at')
                ->get();

            $csv = "Invoice Number,Date,Buyer Name,Buyer NTN,Subtotal,Tax,Total,Status\n";
            foreach ($invoices as $inv) {
                $taxAmt = $inv->items->sum('tax');
                $subtotal = $inv->total_amount - $taxAmt;
                $csv .= implode(',', [
                    '"' . $inv->invoice_number . '"',
                    '"' . $inv->created_at->format('Y-m-d') . '"',
                    '"' . str_replace('"', '""', $inv->buyer_name) . '"',
                    '"' . $inv->buyer_ntn . '"',
                    round($subtotal, 2),
                    round($taxAmt, 2),
                    $inv->total_amount,
                    $inv->status,
                ]) . "\n";
            }
            $filename = "mis_monthly_" . now()->format('Y_m') . ".csv";
        } elseif ($type === 'tax') {
            $csv = "Month,Tax Collected,Subtotal,Effective Rate %\n";
            for ($i = 5; $i >= 0; $i--) {
                $month = now()->subMonths($i);
                $invoiceIds = Invoice::where('company_id', $companyId)
                    ->whereMonth('created_at', $month->month)
                    ->whereYear('created_at', $month->year)
                    ->pluck('id');
                $totalTax = InvoiceItem::whereIn('invoice_id', $invoiceIds)->sum('tax');
                $totalSub = InvoiceItem::whereIn('invoice_id', $invoiceIds)
                    ->selectRaw('SUM(price * quantity) as s')->value('s') ?? 0;
                $csv .= implode(',', [
                    '"' . $month->format('M Y') . '"',
                    round($totalTax, 2),
                    round($totalSub, 2),
                    $totalSub > 0 ? round(($totalTax / $totalSub) * 100, 2) : 0,
                ]) . "\n";
            }
            $filename = "mis_tax_summary_" . now()->format('Y_m') . ".csv";
        } elseif ($type === 'hs') {
            $hsData = InvoiceItem::whereIn('invoice_id',
                    Invoice::where('company_id', $companyId)->pluck('id'))
                ->select(
                    DB::raw("SUBSTRING(hs_code, 1, 2) as hs_prefix"),
                    DB::raw('COUNT(*) as item_count'),
                    DB::raw('SUM(price * quantity) as total_value'),
                    DB::raw('SUM(tax) as total_tax')
                )
                ->groupBy('hs_prefix')
                ->orderByDesc('total_value')
                ->get();

            $csv = "HS Prefix,Item Count,Total Value,Total Tax\n";
            foreach ($hsData as $hs) {
                $csv .= implode(',', [$hs->hs_prefix, $hs->item_count, round($hs->total_value, 2), round($hs->total_tax, 2)]) . "\n";
            }
            $filename = "mis_hs_concentration_" . now()->format('Y_m') . ".csv";
        } elseif ($type === 'vendor') {
            $vendors = VendorRiskProfile::where('company_id', $companyId)
                ->orderBy('vendor_score', 'asc')
                ->get();

            $csv = "Vendor Name,NTN,Score,Total Invoices,Rejected,Tax Mismatches,Anomalies\n";
            foreach ($vendors as $v) {
                $csv .= implode(',', [
                    '"' . str_replace('"', '""', $v->vendor_name ?? '') . '"',
                    '"' . $v->vendor_ntn . '"',
                    $v->vendor_score,
                    $v->total_invoices,
                    $v->rejected_invoices,
                    $v->tax_mismatches,
                    $v->anomaly_count,
                ]) . "\n";
            }
            $filename = "mis_vendor_risk_" . now()->format('Y_m') . ".csv";
        } else {
            return back()->with('error', 'Invalid export type.');
        }

        return Response::make($csv, 200, [
            'Content-Type' => 'text/csv',
            'Content-Disposition' => 'attachment; filename="' . $filename . '"',
        ]);
    }
}
