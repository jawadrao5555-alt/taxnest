<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
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
        [$from, $to, $companyId, $branchId] = $this->parseFilters($request);

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

        return $this->respond($request, 'admin.reports.daily', [
            'rows' => $rows, 'totals' => $totals, 'from' => $from, 'to' => $to,
            'companyId' => $companyId, 'branchId' => $branchId,
            'title' => $title, 'headers' => $headers, 'excelRows' => $excelRows,
        ]);
    }

    /** Product Performance Report — totals grouped by item_name. */
    public function productReport(Request $request)
    {
        [$from, $to, $companyId, $branchId] = $this->parseFilters($request);

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

        return $this->respond($request, 'admin.reports.products', [
            'rows' => $rows, 'totals' => $totals, 'from' => $from, 'to' => $to,
            'companyId' => $companyId, 'branchId' => $branchId,
            'title' => $title, 'headers' => $headers, 'excelRows' => $excelRows,
        ]);
    }

    /** FBR Compliance Report — submitted vs failed vs pending. */
    public function fbrComplianceReport(Request $request)
    {
        [$from, $to, $companyId, $branchId] = $this->parseFilters($request);

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

        return $this->respond($request, 'admin.reports.fbr', [
            'rows' => $rows, 'totals' => $totals, 'from' => $from, 'to' => $to,
            'companyId' => $companyId, 'branchId' => $branchId,
            'title' => $title, 'headers' => $headers, 'excelRows' => $excelRows,
        ]);
    }

    // ────────────────────────────────────────────────────────────────
    // Helpers
    // ────────────────────────────────────────────────────────────────
    private function parseFilters(Request $request): array
    {
        $to = $request->input('date_to')
            ? Carbon::parse($request->input('date_to'))->toDateString()
            : Carbon::today()->toDateString();
        $from = $request->input('date_from')
            ? Carbon::parse($request->input('date_from'))->toDateString()
            : Carbon::parse($to)->subDays(30)->toDateString();
        $companyId = $request->input('company_id');
        $branchId = $request->input('branch_id');
        return [$from, $to, $companyId, $branchId];
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

        $writer = new Xlsx($spreadsheet);
        $filename = preg_replace('/[^a-zA-Z0-9_-]+/', '_', $title) . '_' . date('Ymd_His') . '.xlsx';

        return response()->streamDownload(function () use ($writer) {
            $writer->save('php://output');
        }, $filename, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        ]);
    }
}
