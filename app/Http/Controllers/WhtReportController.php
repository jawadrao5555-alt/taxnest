<?php

namespace App\Http\Controllers;

use App\Models\Invoice;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

class WhtReportController extends Controller
{
    public function index(Request $request)
    {
        $companyId = app('currentCompanyId');
        $fromDate = $request->get('from_date', Carbon::now()->startOfMonth()->toDateString());
        $toDate = $request->get('to_date', Carbon::now()->toDateString());
        $partyFilter = $request->get('party');
        $period = $request->get('period', 'daily');

        $query = Invoice::where('company_id', $companyId)
            ->where('status', 'locked')
            ->where('wht_rate', '>', 0)
            ->whereBetween('invoice_date', [$fromDate, $toDate]);

        if ($partyFilter) {
            $query->where(function ($q) use ($partyFilter) {
                $q->where('buyer_name', 'ilike', "%{$partyFilter}%")
                  ->orWhere('buyer_cnic', 'ilike', "%{$partyFilter}%")
                  ->orWhere('buyer_ntn', 'ilike', "%{$partyFilter}%");
            });
        }

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

        $results = $query->select($selectColumns)
            ->groupBy($groupBy)
            ->orderBy('period_label', 'desc')
            ->get();

        $totals = [
            'invoice_count' => $results->sum('invoice_count'),
            'total_value' => $results->sum('total_value'),
            'total_sales_tax' => $results->sum('total_sales_tax'),
            'total_wht' => $results->sum('total_wht'),
            'total_net_receivable' => $results->sum('total_net_receivable'),
        ];

        return view('reports.wht-report', compact('results', 'totals', 'fromDate', 'toDate', 'partyFilter', 'period'));
    }

    public function taxSummary(Request $request)
    {
        $companyId = app('currentCompanyId');
        $year = $request->get('year', Carbon::now()->year);
        $partyFilter = $request->get('party');

        $query = Invoice::where('company_id', $companyId)
            ->where('status', 'locked')
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
            ->where('status', 'locked')
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

        return view('reports.tax-summary', compact('monthly', 'yearTotals', 'year', 'partyFilter', 'availableYears'));
    }

    public function downloadWht(Request $request)
    {
        $companyId = app('currentCompanyId');
        $fromDate = $request->get('from_date', Carbon::now()->startOfMonth()->toDateString());
        $toDate = $request->get('to_date', Carbon::now()->toDateString());
        $partyFilter = $request->get('party');

        $query = Invoice::where('company_id', $companyId)
            ->where('status', 'locked')
            ->where('wht_rate', '>', 0)
            ->whereBetween('invoice_date', [$fromDate, $toDate]);

        if ($partyFilter) {
            $query->where(function ($q) use ($partyFilter) {
                $q->where('buyer_name', 'ilike', "%{$partyFilter}%")
                  ->orWhere('buyer_cnic', 'ilike', "%{$partyFilter}%");
            });
        }

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

        return response($csv, 200, [
            'Content-Type' => 'text/csv',
            'Content-Disposition' => 'attachment; filename="wht-report-' . $fromDate . '-to-' . $toDate . '.csv"',
        ]);
    }

    public function downloadTaxSummary(Request $request)
    {
        $companyId = app('currentCompanyId');
        $year = $request->get('year', Carbon::now()->year);

        $monthly = Invoice::where('company_id', $companyId)
            ->where('status', 'locked')
            ->whereRaw("EXTRACT(YEAR FROM invoice_date::date) = ?", [$year])
            ->select([
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

        return response($csv, 200, [
            'Content-Type' => 'text/csv',
            'Content-Disposition' => 'attachment; filename="tax-summary-' . $year . '.csv"',
        ]);
    }
}
