<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

/**
 * 🎯 PHASE 2 — Reporting System
 *
 * Three reports: dailyReport, productReport, fbrComplianceReport.
 * Each supports HTML view (default), PDF (?export=pdf), Excel (?export=excel).
 * Filters: date_from, date_to, optional company_id, optional branch_id.
 *
 * Tenant isolation: company_id is super-admin-optional (platform-wide by default).
 * NEVER touches: FbrService, PRA POS, Digital Invoice (per scope).
 */
class ReportsController extends Controller
{
    /** Daily Sales Report — totals grouped by day. */
    public function dailyReport(Request $request)
    {
        return $this->report($request, 'admin.reports.daily', $this->dailyData(...));
    }

    private function dailyData(string $from, string $to, ?int $companyId, ?int $branchId): array
    {
        $rows = DB::table('fbr_pos_transactions')
            ->whereBetween(DB::raw('DATE(created_at)'), [$from, $to])
            ->where('status', 'completed')
            ->when($companyId, fn ($q) => $q->where('company_id', $companyId))
            ->when($branchId, fn ($q) => $q->where('branch_id', $branchId))
            ->selectRaw('
                DATE(created_at) AS sale_date,
                COUNT(*)         AS invoice_count,
                COALESCE(SUM(subtotal), 0)        AS subtotal,
                COALESCE(SUM(tax_amount), 0)      AS tax,
                COALESCE(SUM(discount_amount), 0) AS discount,
                COALESCE(SUM(total_amount), 0)    AS total
            ')
            ->groupBy('sale_date')
            ->orderBy('sale_date', 'desc')
            ->get();

        $totals = [
            'invoice_count' => $rows->sum('invoice_count'),
            'subtotal' => round($rows->sum('subtotal'), 2),
            'tax' => round($rows->sum('tax'), 2),
            'discount' => round($rows->sum('discount'), 2),
            'total' => round($rows->sum('total'), 2),
        ];

        $title = "Daily Sales Report ({$from} → {$to})";
        $headers = ['Date', 'Invoices', 'Subtotal', 'Tax', 'Discount', 'Total'];
        $excelRows = $rows->map(fn ($r) => [
            $r->sale_date, $r->invoice_count,
            (float) $r->subtotal, (float) $r->tax, (float) $r->discount, (float) $r->total,
        ])->toArray();

        return [
            'rows' => $rows, 'totals' => $totals,
            'title' => $title, 'headers' => $headers, 'excelRows' => $excelRows,
        ];
    }

    /** Product Performance Report — totals grouped by item_name. */
    public function productReport(Request $request)
    {
        return $this->report($request, 'admin.reports.products', $this->productData(...));
    }

    private function productData(string $from, string $to, ?int $companyId, ?int $branchId): array
    {
        $rows = DB::table('fbr_pos_transaction_items as i')
            ->join('fbr_pos_transactions as t', 't.id', '=', 'i.transaction_id')
            ->whereBetween(DB::raw('DATE(t.created_at)'), [$from, $to])
            ->where('t.status', 'completed')
            ->when($companyId, fn ($q) => $q->where('t.company_id', $companyId))
            ->when($branchId, fn ($q) => $q->where('t.branch_id', $branchId))
            ->selectRaw('
                i.item_name,
                MAX(i.product_id) AS product_id,
                COUNT(DISTINCT i.transaction_id) AS sold_in_invoices,
                SUM(i.quantity) AS units_sold,
                COALESCE(SUM(i.subtotal), 0)   AS revenue,
                COALESCE(SUM(i.tax_amount), 0) AS tax,
                COALESCE(SUM(i.total), 0)      AS gross
            ')
            ->groupBy('i.item_name')
            ->orderByDesc('revenue')
            ->get();

        $totals = [
            'units_sold' => round($rows->sum('units_sold'), 4),
            'revenue' => round($rows->sum('revenue'), 2),
            'tax' => round($rows->sum('tax'), 2),
            'gross' => round($rows->sum('gross'), 2),
        ];

        $title = "Product Performance Report ({$from} → {$to})";
        $headers = ['Product', 'Invoices', 'Units Sold', 'Revenue', 'Tax', 'Gross'];
        $excelRows = $rows->map(fn ($r) => [
            $r->item_name, $r->sold_in_invoices,
            (float) $r->units_sold, (float) $r->revenue, (float) $r->tax, (float) $r->gross,
        ])->toArray();

        return [
            'rows' => $rows, 'totals' => $totals,
            'title' => $title, 'headers' => $headers, 'excelRows' => $excelRows,
        ];
    }

    /** FBR Compliance Report — submitted vs failed vs pending. */
    public function fbrComplianceReport(Request $request)
    {
        return $this->report($request, 'admin.reports.fbr', $this->complianceData(...));
    }

    private function complianceData(string $from, string $to, ?int $companyId, ?int $branchId): array
    {
        $statusBreakdown = DB::table('fbr_pos_transactions')
            ->whereBetween(DB::raw('DATE(created_at)'), [$from, $to])
            ->when($companyId, fn ($q) => $q->where('company_id', $companyId))
            ->when($branchId, fn ($q) => $q->where('branch_id', $branchId))
            ->selectRaw('COALESCE(fbr_status, "none") AS fbr_status, COUNT(*) AS cnt, COALESCE(SUM(total_amount),0) AS total')
            ->groupBy('fbr_status')
            ->orderByDesc('cnt')
            ->get();

        $totalCount = $statusBreakdown->sum('cnt');
        $submittedCount = $statusBreakdown->where('fbr_status', 'submitted')->sum('cnt');
        $failedCount = $statusBreakdown->whereIn('fbr_status', ['failed', 'pending_verification'])->sum('cnt');
        $compliancePct = $totalCount > 0 ? round(($submittedCount / $totalCount) * 100, 2) : 0;

        $rows = $statusBreakdown;
        $totals = [
            'total_count' => $totalCount,
            'submitted_count' => $submittedCount,
            'failed_count' => $failedCount,
            'compliance_pct' => $compliancePct,
        ];

        $title = "FBR Compliance Report ({$from} → {$to})";
        $headers = ['FBR Status', 'Invoice Count', 'Total Amount (PKR)'];
        $excelRows = $rows->map(fn ($r) => [$r->fbr_status, $r->cnt, (float) $r->total])->toArray();

        return [
            'rows' => $rows, 'totals' => $totals,
            'title' => $title, 'headers' => $headers, 'excelRows' => $excelRows,
        ];
    }

    // ────────────────────────────────────────────────────────────────
    // Helpers
    // ────────────────────────────────────────────────────────────────
    private function parseFilters(Request $request): array
    {
        $customDates = ($request->input('period') ?? 'custom') === 'custom';
        $filters = $request->validate([
            'period' => 'nullable|in:custom,daily,monthly,yearly,two_year',
            'company_id' => 'nullable|integer|min:0',
            'branch_id' => 'nullable|integer|min:0',
            'comparison_as_of' => 'nullable|date_format:Y-m-d',
            ...($customDates ? [
                'date_from' => 'nullable|date_format:Y-m-d',
                'date_to' => 'nullable|date_format:Y-m-d',
            ] : []),
        ]);
        $period = $filters['period'] ?? 'custom';
        $today = today()->toImmutable();
        $to = $filters['date_to'] ?? $today->toDateString();
        if ($period === 'two_year') {
            $to = $filters['comparison_as_of'] ?? $to;
        }
        $from = match ($period) {
            'daily' => $to,
            'monthly' => $today->startOfMonth()->toDateString(),
            'yearly' => $today->startOfYear()->toDateString(),
            'two_year' => Carbon::parse($to)->startOfYear()->toDateString(),
            default => $filters['date_from'] ?? Carbon::parse($to)->subDays(30)->toDateString(),
        };
        if ($from > $to) {
            throw ValidationException::withMessages(['date_from' => 'Start date is after the reporting end date.']);
        }

        return [$from, $to, $filters['company_id'] ?? null, $filters['branch_id'] ?? null, $period];
    }

    private function report(Request $request, string $view, callable $loadData)
    {
        [$from, $to, $companyId, $branchId, $period] = $this->parseFilters($request);
        $data = $loadData($from, $to, $companyId, $branchId) + compact('from', 'to', 'companyId', 'branchId', 'period');
        if ($period === 'two_year') {
            $previous = Carbon::parse($to)->subYearNoOverflow();
            $previousFrom = $previous->copy()->startOfYear()->toDateString();
            $previousTo = $previous->toDateString();
            $data['comparison'] = [
                'from' => $previousFrom,
                'to' => $previousTo,
                'totals' => $loadData($previousFrom, $previousTo, $companyId, $branchId)['totals'],
            ];
        }

        return $this->respond($request, $view, $data);
    }

    private function respond(Request $request, string $view, array $data)
    {
        $export = $request->input('export', 'html');
        if ($export === 'json') {
            return response()->json($data);
        }
        if ($export === 'pdf') {
            return $this->exportPdf($data);
        }
        if ($export === 'excel') {
            return $this->exportExcel($data);
        }
        return view($view, $data);
    }

    private function exportPdf(array $data)
    {
        $pdf = Pdf::loadView('admin.reports.pdf-template', $data)->setPaper('A4', 'portrait');
        $safe = preg_replace('/[^a-zA-Z0-9_-]+/', '_', $data['title']) . '_' . date('Ymd_His') . '.pdf';
        return $pdf->download($safe);
    }

    private function exportExcel(array $data)
    {
        $title = $data['title'];
        $headers = $data['headers'];
        $rows = $data['excelRows'];

        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle(substr(preg_replace('/[^a-zA-Z0-9 _-]+/', '', $title), 0, 31) ?: 'Report');

        // Header row
        foreach ($headers as $i => $h) {
            $sheet->setCellValue([$i + 1, 1], $h);
        }
        $lastCol = Coordinate::stringFromColumnIndex(count($headers));
        $sheet->getStyle("A1:{$lastCol}1")->getFont()->setBold(true);
        $sheet->getStyle("A1:{$lastCol}1")->getFill()->setFillType(\PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID)
            ->getStartColor()->setRGB('E5E7EB');

        // Data rows
        foreach ($rows as $r => $row) {
            foreach ($row as $c => $val) {
                $sheet->setCellValue([$c + 1, $r + 2], $val);
            }
        }

        // Auto-size columns
        for ($c = 1; $c <= count($headers); $c++) {
            $sheet->getColumnDimension(Coordinate::stringFromColumnIndex($c))->setAutoSize(true);
        }

        if (isset($data['comparison'])) {
            $prior = $data['comparison'];
            $comparison = $spreadsheet->createSheet();
            $comparison->setTitle('Year comparison');
            $comparison->fromArray([
                ['Metric', $data['from'].' to '.$data['to'], $prior['from'].' to '.$prior['to']],
            ], null, 'A1', true);
            $row = 2;
            foreach ($data['totals'] as $metric => $total) {
                $comparison->fromArray([str($metric)->replace('_', ' ')->headline()->toString(), $total, $prior['totals'][$metric]], null, 'A'.$row++, true);
            }
            foreach (['A', 'B', 'C'] as $column) {
                $comparison->getColumnDimension($column)->setAutoSize(true);
            }
        }

        $writer = new Xlsx($spreadsheet);
        $filename = preg_replace('/[^a-zA-Z0-9_-]+/', '_', $title) . '_' . date('Ymd_His') . '.xlsx';

        return response()->streamDownload(function () use ($writer) {
            $writer->save('php://output');
        }, $filename, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        ]);
    }
}
