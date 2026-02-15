<?php

namespace App\Http\Controllers;

use App\Models\Invoice;
use App\Models\Company;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;
use Barryvdh\DomPDF\Facade\Pdf;

class WhtReportController extends Controller
{
    private function resolveDbStatus($status)
    {
        $map = ['production' => 'locked', 'draft' => 'draft'];
        return $map[$status] ?? 'locked';
    }

    private function getWhtQuery($companyId, $fromDate, $toDate, $partyFilter = null, $status = 'production')
    {
        $dbStatus = $this->resolveDbStatus($status);
        $query = Invoice::where('company_id', $companyId)
            ->where('status', $dbStatus)
            ->where('wht_rate', '>', 0)
            ->whereBetween('invoice_date', [$fromDate, $toDate]);

        if ($partyFilter) {
            $query->where(function ($q) use ($partyFilter) {
                $q->where('buyer_name', 'ilike', "%{$partyFilter}%")
                  ->orWhere('buyer_cnic', 'ilike', "%{$partyFilter}%")
                  ->orWhere('buyer_ntn', 'ilike', "%{$partyFilter}%");
            });
        }

        return $query;
    }

    private function getWhtResults($query, $period)
    {
        $selectColumns = [
            'buyer_name',
            'buyer_ntn',
            'buyer_cnic',
            DB::raw('COUNT(*) as invoice_count'),
            DB::raw('SUM(total_value_excluding_st) as total_value'),
            DB::raw('SUM(total_sales_tax) as total_sales_tax'),
            DB::raw('AVG(wht_rate) as avg_wht_rate'),
            DB::raw('SUM(wht_amount) as total_wht'),
            DB::raw('SUM(net_receivable) as total_net_receivable'),
        ];

        if ($period === 'monthly') {
            $selectColumns[] = DB::raw("TO_CHAR(invoice_date::date, 'YYYY-MM') as period_label");
            $groupBy = ['buyer_name', 'buyer_ntn', 'buyer_cnic', DB::raw("TO_CHAR(invoice_date::date, 'YYYY-MM')")];
        } elseif ($period === 'yearly') {
            $selectColumns[] = DB::raw("TO_CHAR(invoice_date::date, 'YYYY') as period_label");
            $groupBy = ['buyer_name', 'buyer_ntn', 'buyer_cnic', DB::raw("TO_CHAR(invoice_date::date, 'YYYY')")];
        } else {
            $selectColumns[] = DB::raw("invoice_date as period_label");
            $groupBy = ['buyer_name', 'buyer_ntn', 'buyer_cnic', 'invoice_date'];
        }

        return $query->select($selectColumns)
            ->groupBy($groupBy)
            ->orderBy('period_label', 'desc')
            ->get();
    }

    private function getWhtTotals($results)
    {
        return [
            'invoice_count' => $results->sum('invoice_count'),
            'total_value' => $results->sum('total_value'),
            'total_sales_tax' => $results->sum('total_sales_tax'),
            'total_wht' => $results->sum('total_wht'),
            'total_net_receivable' => $results->sum('total_net_receivable'),
        ];
    }

    public function index(Request $request)
    {
        $companyId = app('currentCompanyId');
        $fromDate = $request->get('from_date', Carbon::now()->startOfMonth()->toDateString());
        $toDate = $request->get('to_date', Carbon::now()->toDateString());
        $partyFilter = $request->get('party');
        $period = $request->get('period', 'daily');
        $status = $request->get('status', 'production');

        $query = $this->getWhtQuery($companyId, $fromDate, $toDate, $partyFilter, $status);
        $results = $this->getWhtResults(clone $query, $period);
        $totals = $this->getWhtTotals($results);

        return view('reports.wht-report', compact('results', 'totals', 'fromDate', 'toDate', 'partyFilter', 'period', 'status'));
    }

    public function pdfWht(Request $request)
    {
        $companyId = app('currentCompanyId');
        $company = Company::find($companyId);
        $fromDate = $request->get('from_date', Carbon::now()->startOfMonth()->toDateString());
        $toDate = $request->get('to_date', Carbon::now()->toDateString());
        $partyFilter = $request->get('party');
        $period = $request->get('period', 'daily');
        $viewType = $request->get('view', 'whole');
        $status = $request->get('status', 'production');

        $query = $this->getWhtQuery($companyId, $fromDate, $toDate, $partyFilter, $status);
        $results = $this->getWhtResults(clone $query, $period);
        $totals = $this->getWhtTotals($results);

        $statusLabel = ucfirst($status);
        $title = 'WHT Collection Report (' . $statusLabel . ')';
        $partyGroups = null;
        $partyName = $partyFilter;

        if ($viewType === 'partywise') {
            $partyGroups = $results->groupBy('buyer_name');
        }

        $pdf = Pdf::loadView('reports.wht-pdf', compact('results', 'totals', 'fromDate', 'toDate', 'partyFilter', 'period', 'company', 'title', 'viewType', 'partyGroups', 'partyName'));
        $pdf->setPaper('a4', 'landscape');
        $filename = 'WHT_Report_' . $status . '_' . $fromDate . '_to_' . $toDate . '_' . $viewType . '.pdf';
        return $pdf->download($filename);
    }

    public function taxSummary(Request $request)
    {
        $companyId = app('currentCompanyId');
        $year = $request->get('year', Carbon::now()->year);
        $partyFilter = $request->get('party');
        $status = $request->get('status', 'production');
        $dbStatus = $this->resolveDbStatus($status);

        $query = Invoice::where('company_id', $companyId)
            ->where('status', $dbStatus)
            ->whereRaw("EXTRACT(YEAR FROM invoice_date::date) = ?", [$year]);

        if ($partyFilter) {
            $query->where(function ($q) use ($partyFilter) {
                $q->where('buyer_name', 'ilike', "%{$partyFilter}%")
                  ->orWhere('buyer_ntn', 'ilike', "%{$partyFilter}%");
            });
        }

        $monthly = $query->select([
                DB::raw("TO_CHAR(invoice_date::date, 'YYYY-MM') as month_label"),
                DB::raw("EXTRACT(MONTH FROM invoice_date::date) as month_num"),
                DB::raw('COUNT(*) as invoice_count'),
                DB::raw('SUM(total_amount) as total_billed'),
                DB::raw('SUM(total_sales_tax) as total_sales_tax'),
                DB::raw('SUM(wht_amount) as total_wht'),
                DB::raw('SUM(net_receivable) as total_net'),
            ])
            ->groupBy(DB::raw("TO_CHAR(invoice_date::date, 'YYYY-MM')"), DB::raw("EXTRACT(MONTH FROM invoice_date::date)"))
            ->orderBy('month_num')
            ->get();

        $yearTotals = [
            'invoice_count' => $monthly->sum('invoice_count'),
            'total_billed' => $monthly->sum('total_billed'),
            'total_sales_tax' => $monthly->sum('total_sales_tax'),
            'total_wht' => $monthly->sum('total_wht'),
            'total_net' => $monthly->sum('total_net'),
        ];

        $availableYears = Invoice::where('company_id', $companyId)
            ->where('status', $dbStatus)
            ->selectRaw("DISTINCT EXTRACT(YEAR FROM invoice_date::date) as yr")
            ->orderByDesc('yr')
            ->pluck('yr')
            ->map(fn($y) => (int)$y)
            ->toArray();

        if (!in_array((int)$year, $availableYears)) {
            $availableYears[] = (int)$year;
            sort($availableYears);
            $availableYears = array_reverse($availableYears);
        }

        return view('reports.tax-summary', compact('monthly', 'yearTotals', 'year', 'partyFilter', 'availableYears', 'status'));
    }

    public function pdfTaxSummary(Request $request)
    {
        $companyId = app('currentCompanyId');
        $company = Company::find($companyId);
        $year = $request->get('year', Carbon::now()->year);
        $partyFilter = $request->get('party');
        $viewType = $request->get('view', 'whole');
        $status = $request->get('status', 'production');
        $dbStatus = $this->resolveDbStatus($status);

        $statusLabel = ucfirst($status);
        $title = 'Tax Collection Summary (' . $statusLabel . ')';

        if ($viewType === 'partywise') {
            $monthly = Invoice::where('company_id', $companyId)
                ->where('status', $dbStatus)
                ->whereRaw("EXTRACT(YEAR FROM invoice_date::date) = ?", [$year])
                ->when($partyFilter, function ($q) use ($partyFilter) {
                    $q->where(function ($qq) use ($partyFilter) {
                        $qq->where('buyer_name', 'ilike', "%{$partyFilter}%")
                           ->orWhere('buyer_ntn', 'ilike', "%{$partyFilter}%");
                    });
                })
                ->select([
                    'buyer_name',
                    DB::raw("TO_CHAR(invoice_date::date, 'YYYY-MM') as month_label"),
                    DB::raw("EXTRACT(MONTH FROM invoice_date::date) as month_num"),
                    DB::raw('COUNT(*) as invoice_count'),
                    DB::raw('SUM(total_amount) as total_billed'),
                    DB::raw('SUM(total_sales_tax) as total_sales_tax'),
                    DB::raw('SUM(wht_amount) as total_wht'),
                    DB::raw('SUM(net_receivable) as total_net'),
                ])
                ->groupBy('buyer_name', DB::raw("TO_CHAR(invoice_date::date, 'YYYY-MM')"), DB::raw("EXTRACT(MONTH FROM invoice_date::date)"))
                ->orderBy('buyer_name')
                ->orderBy('month_num')
                ->get();

            $partyGroups = $monthly->groupBy('buyer_name');

            $yearTotals = [
                'invoice_count' => $monthly->sum('invoice_count'),
                'total_billed' => $monthly->sum('total_billed'),
                'total_sales_tax' => $monthly->sum('total_sales_tax'),
                'total_wht' => $monthly->sum('total_wht'),
                'total_net' => $monthly->sum('total_net'),
            ];

            $pdf = Pdf::loadView('reports.tax-summary-pdf', compact('monthly', 'yearTotals', 'year', 'partyFilter', 'company', 'title', 'viewType', 'partyGroups'));
            $pdf->setPaper('a4', 'landscape');
            return $pdf->download('Tax_Summary_' . $year . '_partywise.pdf');
        }

        $query = Invoice::where('company_id', $companyId)
            ->where('status', $dbStatus)
            ->whereRaw("EXTRACT(YEAR FROM invoice_date::date) = ?", [$year]);

        if ($partyFilter) {
            $query->where(function ($q) use ($partyFilter) {
                $q->where('buyer_name', 'ilike', "%{$partyFilter}%")
                  ->orWhere('buyer_ntn', 'ilike', "%{$partyFilter}%");
            });
        }

        $monthly = $query->select([
                DB::raw("TO_CHAR(invoice_date::date, 'YYYY-MM') as month_label"),
                DB::raw("EXTRACT(MONTH FROM invoice_date::date) as month_num"),
                DB::raw('COUNT(*) as invoice_count'),
                DB::raw('SUM(total_amount) as total_billed'),
                DB::raw('SUM(total_sales_tax) as total_sales_tax'),
                DB::raw('SUM(wht_amount) as total_wht'),
                DB::raw('SUM(net_receivable) as total_net'),
            ])
            ->groupBy(DB::raw("TO_CHAR(invoice_date::date, 'YYYY-MM')"), DB::raw("EXTRACT(MONTH FROM invoice_date::date)"))
            ->orderBy('month_num')
            ->get();

        $yearTotals = [
            'invoice_count' => $monthly->sum('invoice_count'),
            'total_billed' => $monthly->sum('total_billed'),
            'total_sales_tax' => $monthly->sum('total_sales_tax'),
            'total_wht' => $monthly->sum('total_wht'),
            'total_net' => $monthly->sum('total_net'),
        ];

        $partyName = $partyFilter;

        $pdf = Pdf::loadView('reports.tax-summary-pdf', compact('monthly', 'yearTotals', 'year', 'partyFilter', 'company', 'title', 'viewType', 'partyName'));
        $pdf->setPaper('a4', 'landscape');
        return $pdf->download('Tax_Summary_' . $status . '_' . $year . '_' . $viewType . '.pdf');
    }

    public function downloadWht(Request $request)
    {
        $companyId = app('currentCompanyId');
        $fromDate = $request->get('from_date', Carbon::now()->startOfMonth()->toDateString());
        $toDate = $request->get('to_date', Carbon::now()->toDateString());
        $partyFilter = $request->get('party');
        $viewType = $request->get('view', 'whole');
        $status = $request->get('status', 'production');

        $query = $this->getWhtQuery($companyId, $fromDate, $toDate, $partyFilter, $status);

        if ($viewType === 'partywise') {
            $results = $query->select([
                    'buyer_name', 'buyer_ntn', 'buyer_cnic', 'invoice_date',
                    DB::raw('COUNT(*) as invoice_count'),
                    DB::raw('SUM(total_value_excluding_st) as total_value'),
                    DB::raw('SUM(total_sales_tax) as total_sales_tax'),
                    DB::raw('AVG(wht_rate) as avg_wht_rate'),
                    DB::raw('SUM(wht_amount) as total_wht'),
                    DB::raw('SUM(net_receivable) as total_net_receivable'),
                ])
                ->groupBy('buyer_name', 'buyer_ntn', 'buyer_cnic', 'invoice_date')
                ->orderBy('buyer_name')
                ->orderBy('invoice_date', 'desc')
                ->get();

            $csv = "Party Name,NTN,CNIC,Date,Invoice Count,Total Value (Excl ST),Sales Tax,WHT Rate %,WHT Amount,Net Receivable\n";
            foreach ($results as $row) {
                $csv .= '"' . str_replace('"', '""', $row->buyer_name) . '",'
                    . '"' . ($row->buyer_ntn ?? '') . '",'
                    . '"' . ($row->buyer_cnic ?? '') . '",'
                    . '"' . $row->invoice_date . '",'
                    . $row->invoice_count . ','
                    . number_format($row->total_value, 2, '.', '') . ','
                    . number_format($row->total_sales_tax, 2, '.', '') . ','
                    . number_format($row->avg_wht_rate, 2, '.', '') . ','
                    . number_format($row->total_wht, 2, '.', '') . ','
                    . number_format($row->total_net_receivable, 2, '.', '') . "\n";
            }
        } else {
            $results = $query->select([
                    'buyer_name', 'buyer_ntn', 'buyer_cnic',
                    DB::raw('COUNT(*) as invoice_count'),
                    DB::raw('SUM(total_value_excluding_st) as total_value'),
                    DB::raw('SUM(total_sales_tax) as total_sales_tax'),
                    DB::raw('AVG(wht_rate) as avg_wht_rate'),
                    DB::raw('SUM(wht_amount) as total_wht'),
                    DB::raw('SUM(net_receivable) as total_net_receivable'),
                ])
                ->groupBy('buyer_name', 'buyer_ntn', 'buyer_cnic')
                ->orderBy('buyer_name')
                ->get();

            $csv = "Party Name,NTN,CNIC,Invoice Count,Total Value (Excl ST),Sales Tax,WHT Rate %,WHT Amount,Net Receivable\n";
            foreach ($results as $row) {
                $csv .= '"' . str_replace('"', '""', $row->buyer_name) . '",'
                    . '"' . ($row->buyer_ntn ?? '') . '",'
                    . '"' . ($row->buyer_cnic ?? '') . '",'
                    . $row->invoice_count . ','
                    . number_format($row->total_value, 2, '.', '') . ','
                    . number_format($row->total_sales_tax, 2, '.', '') . ','
                    . number_format($row->avg_wht_rate, 2, '.', '') . ','
                    . number_format($row->total_wht, 2, '.', '') . ','
                    . number_format($row->total_net_receivable, 2, '.', '') . "\n";
            }
        }

        return response($csv, 200, [
            'Content-Type' => 'text/csv',
            'Content-Disposition' => 'attachment; filename="wht-report-' . $status . '-' . $fromDate . '-to-' . $toDate . '-' . $viewType . '.csv"',
        ]);
    }

    public function downloadTaxSummary(Request $request)
    {
        $companyId = app('currentCompanyId');
        $year = $request->get('year', Carbon::now()->year);
        $partyFilter = $request->get('party');
        $viewType = $request->get('view', 'whole');
        $status = $request->get('status', 'production');
        $dbStatus = $this->resolveDbStatus($status);

        $query = Invoice::where('company_id', $companyId)
            ->where('status', $dbStatus)
            ->whereRaw("EXTRACT(YEAR FROM invoice_date::date) = ?", [$year]);

        if ($partyFilter) {
            $query->where(function ($q) use ($partyFilter) {
                $q->where('buyer_name', 'ilike', "%{$partyFilter}%")
                  ->orWhere('buyer_ntn', 'ilike', "%{$partyFilter}%");
            });
        }

        if ($viewType === 'partywise') {
            $monthly = $query->select([
                    'buyer_name',
                    DB::raw("TO_CHAR(invoice_date::date, 'YYYY-MM') as month_label"),
                    DB::raw('COUNT(*) as invoice_count'),
                    DB::raw('SUM(total_amount) as total_billed'),
                    DB::raw('SUM(total_sales_tax) as total_sales_tax'),
                    DB::raw('SUM(wht_amount) as total_wht'),
                    DB::raw('SUM(net_receivable) as total_net'),
                ])
                ->groupBy('buyer_name', DB::raw("TO_CHAR(invoice_date::date, 'YYYY-MM')"))
                ->orderBy('buyer_name')
                ->orderBy('month_label')
                ->get();

            $csv = "Party Name,Month,Invoice Count,Total Billed,Sales Tax Collected,WHT Collected,Net Amount\n";
            foreach ($monthly as $row) {
                $csv .= '"' . str_replace('"', '""', $row->buyer_name) . '",'
                    . $row->month_label . ','
                    . $row->invoice_count . ','
                    . number_format($row->total_billed, 2, '.', '') . ','
                    . number_format($row->total_sales_tax, 2, '.', '') . ','
                    . number_format($row->total_wht, 2, '.', '') . ','
                    . number_format($row->total_net, 2, '.', '') . "\n";
            }
        } else {
            $monthly = $query->select([
                    DB::raw("TO_CHAR(invoice_date::date, 'YYYY-MM') as month_label"),
                    DB::raw('COUNT(*) as invoice_count'),
                    DB::raw('SUM(total_amount) as total_billed'),
                    DB::raw('SUM(total_sales_tax) as total_sales_tax'),
                    DB::raw('SUM(wht_amount) as total_wht'),
                    DB::raw('SUM(net_receivable) as total_net'),
                ])
                ->groupBy(DB::raw("TO_CHAR(invoice_date::date, 'YYYY-MM')"))
                ->orderBy('month_label')
                ->get();

            $csv = "Month,Invoice Count,Total Billed,Sales Tax Collected,WHT Collected,Net Amount\n";
            foreach ($monthly as $row) {
                $csv .= $row->month_label . ','
                    . $row->invoice_count . ','
                    . number_format($row->total_billed, 2, '.', '') . ','
                    . number_format($row->total_sales_tax, 2, '.', '') . ','
                    . number_format($row->total_wht, 2, '.', '') . ','
                    . number_format($row->total_net, 2, '.', '') . "\n";
            }
        }

        return response($csv, 200, [
            'Content-Type' => 'text/csv',
            'Content-Disposition' => 'attachment; filename="tax-summary-' . $status . '-' . $year . '-' . $viewType . '.csv"',
        ]);
    }
}
