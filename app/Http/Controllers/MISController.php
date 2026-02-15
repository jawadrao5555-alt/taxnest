<?php

namespace App\Http\Controllers;

use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\Company;
use App\Models\ComplianceReport;
use App\Models\VendorRiskProfile;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Response;
use Carbon\Carbon;
use Barryvdh\DomPDF\Facade\Pdf;

class MISController extends Controller
{
    private function resolveDbStatus($status)
    {
        $map = ['production' => 'locked', 'draft' => 'draft'];
        return $map[$status] ?? 'locked';
    }

    private function displayStatus($dbStatus)
    {
        $map = ['locked' => 'production', 'draft' => 'draft'];
        return $map[$dbStatus] ?? $dbStatus;
    }

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
        $viewType = $request->get('view', 'whole');
        $status = $request->get('status', 'all');

        if ($type === 'monthly') {
            $query = Invoice::where('company_id', $companyId)
                ->whereMonth('created_at', now()->month)
                ->whereYear('created_at', now()->year);

            if ($status !== 'all') {
                $dbStatus = $this->resolveDbStatus($status);
                $query->where('status', $dbStatus);
            }

            $invoices = $query->orderBy('created_at')->get();

            if ($viewType === 'partywise') {
                $csv = "Party Name,NTN,Invoice No,Date,Subtotal,Sales Tax,WHT,Total,Status\n";
                $grouped = $invoices->groupBy('buyer_name');
                foreach ($grouped as $party => $rows) {
                    foreach ($rows as $inv) {
                        $csv .= '"' . str_replace('"', '""', $party) . '",'
                            . '"' . ($inv->buyer_ntn ?? '') . '",'
                            . '"' . ($inv->internal_invoice_number ?? $inv->invoice_number) . '",'
                            . '"' . $inv->invoice_date . '",'
                            . number_format($inv->total_value_excluding_st ?? ($inv->total_amount - $inv->total_sales_tax), 2, '.', '') . ','
                            . number_format($inv->total_sales_tax, 2, '.', '') . ','
                            . number_format($inv->wht_amount, 2, '.', '') . ','
                            . number_format($inv->total_amount, 2, '.', '') . ','
                            . $this->displayStatus($inv->status) . "\n";
                    }
                }
            } else {
                $csv = "Invoice Number,Date,Buyer Name,Buyer NTN,Subtotal,Sales Tax,WHT,Total,Status\n";
                foreach ($invoices as $inv) {
                    $csv .= implode(',', [
                        '"' . ($inv->internal_invoice_number ?? $inv->invoice_number) . '"',
                        '"' . $inv->invoice_date . '"',
                        '"' . str_replace('"', '""', $inv->buyer_name) . '"',
                        '"' . ($inv->buyer_ntn ?? '') . '"',
                        number_format($inv->total_value_excluding_st ?? ($inv->total_amount - $inv->total_sales_tax), 2, '.', ''),
                        number_format($inv->total_sales_tax, 2, '.', ''),
                        number_format($inv->wht_amount, 2, '.', ''),
                        number_format($inv->total_amount, 2, '.', ''),
                        $this->displayStatus($inv->status),
                    ]) . "\n";
                }
            }
            $statusSuffix = $status !== 'all' ? '_' . $status : '';
            $filename = "mis_monthly" . $statusSuffix . '_' . $viewType . '_' . now()->format('Y_m') . ".csv";
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

    public function exportPdf(Request $request)
    {
        $companyId = app('currentCompanyId');
        $company = Company::find($companyId);
        $type = $request->get('type', 'monthly');
        $viewType = $request->get('view', 'whole');
        $status = $request->get('status', 'all');
        $title = 'MIS Report';

        if ($type === 'monthly') {
            $statusLabel = $status !== 'all' ? ' (' . ucfirst($status) . ')' : '';
            $reportTitle = 'Monthly Invoice Report' . $statusLabel . ' - ' . now()->format('F Y');
            $query = Invoice::where('company_id', $companyId)
                ->whereMonth('created_at', now()->month)
                ->whereYear('created_at', now()->year);

            if ($status !== 'all') {
                $dbStatus = $this->resolveDbStatus($status);
                $query->where('status', $dbStatus);
            }

            $invoices = $query->orderBy('created_at')->get();

            $partyGroups = null;
            if ($viewType === 'partywise') {
                $partyGroups = $invoices->groupBy('buyer_name');
            }

            $statusSuffix = $status !== 'all' ? '_' . $status : '';
            $pdf = Pdf::loadView('reports.mis-pdf', compact('company', 'title', 'reportTitle', 'type', 'invoices', 'viewType', 'partyGroups'));
            $pdf->setPaper('a4', 'landscape');
            return $pdf->download('MIS_Monthly' . $statusSuffix . '_' . now()->format('Y-m') . '_' . $viewType . '.pdf');

        } elseif ($type === 'tax') {
            $reportTitle = 'Tax Collection Summary (Last 6 Months)';
            $taxSummary = [];
            for ($i = 5; $i >= 0; $i--) {
                $month = now()->subMonths($i);
                $invoiceIds = Invoice::where('company_id', $companyId)
                    ->whereMonth('created_at', $month->month)
                    ->whereYear('created_at', $month->year)
                    ->pluck('id');
                $totalTax = InvoiceItem::whereIn('invoice_id', $invoiceIds)->sum('tax');
                $totalSub = InvoiceItem::whereIn('invoice_id', $invoiceIds)
                    ->selectRaw('SUM(price * quantity) as s')->value('s') ?? 0;
                $taxSummary[] = [
                    'month' => $month->format('M Y'),
                    'tax_collected' => $totalTax,
                    'subtotal' => $totalSub,
                    'effective_rate' => $totalSub > 0 ? round(($totalTax / $totalSub) * 100, 2) : 0,
                ];
            }

            $pdf = Pdf::loadView('reports.mis-pdf', compact('company', 'title', 'reportTitle', 'type', 'taxSummary'));
            $pdf->setPaper('a4', 'landscape');
            return $pdf->download('MIS_Tax_Summary.pdf');

        } elseif ($type === 'hs') {
            $reportTitle = 'HS Code Concentration Report';
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

            $pdf = Pdf::loadView('reports.mis-pdf', compact('company', 'title', 'reportTitle', 'type', 'hsData'));
            $pdf->setPaper('a4', 'landscape');
            return $pdf->download('MIS_HS_Code_Report.pdf');

        } elseif ($type === 'vendor') {
            $reportTitle = 'Vendor Risk Profile Report';
            $vendors = VendorRiskProfile::where('company_id', $companyId)
                ->orderBy('vendor_score', 'asc')
                ->get();

            $pdf = Pdf::loadView('reports.mis-pdf', compact('company', 'title', 'reportTitle', 'type', 'vendors'));
            $pdf->setPaper('a4', 'landscape');
            return $pdf->download('MIS_Vendor_Risk_Report.pdf');
        }

        return back()->with('error', 'Invalid export type.');
    }
}
