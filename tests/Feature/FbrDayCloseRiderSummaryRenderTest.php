<?php

namespace Tests\Feature;

use Tests\TestCase;
use App\Models\FbrDayCloseReport;

/**
 * Task 541 render lock: a CASH-IN-ONLY rider day (old bills settled today,
 * NO same-day rider bills → rider_summary active with EMPTY riders list)
 * must still show the rider cash movement on the Z-report representations —
 * even when the optional cash reconciliation was skipped (counted_cash NULL).
 *
 * Renders the standalone PDF + thermal blades directly with stub data (they
 * are self-contained HTML, no layout/auth), asserting the stored cash_in is
 * visible. The day-close PAGE uses the same relaxed condition (riders OR
 * nonzero cash figure) — its blade shares the exact guard added here.
 */
class FbrDayCloseRiderSummaryRenderTest extends TestCase
{
    private function stubReport(array $riderSummary): FbrDayCloseReport
    {
        $report = new FbrDayCloseReport();
        $report->forceFill([
            'company_id' => 7,
            'report_date' => now()->toDateString(),
            'report_number' => 'ZRPT-00001',
            'total_invoices' => 0,
            'fbr_invoices' => 0,
            'local_invoices' => 0,
            'failed_invoices' => 0,
            'gross_sales' => 0,
            'total_discount' => 0,
            'net_sales' => 0,
            'total_tax' => 0,
            'total_fbr_fee' => 0,
            'total_amount' => 0,
            'cash_amount' => 0,
            'card_amount' => 0,
            'other_amount' => 0,
            'first_invoice_number' => null,
            'last_invoice_number' => null,
            'first_invoice_time' => null,
            'last_invoice_time' => null,
            'closed_by' => null,
            'notes' => null,
            'opening_float' => null,
            'counted_cash' => null,      // recon skipped — the edge case under lock
            'expected_cash' => null,
            'cash_variance' => null,
            'rider_summary' => $riderSummary,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        return $report;
    }

    private function stubAnalytics(): object
    {
        $hourly = [];
        for ($h = 0; $h < 24; $h++) {
            $hourly[$h] = (object) ['count' => 0, 'revenue' => 0.0];
        }
        return (object) [
            'top_products' => collect(),
            'hourly' => $hourly,
            'fbr_health' => (object) ['submitted' => 0, 'pending' => 0, 'failed' => 0, 'local' => 0],
            'discounts' => (object) ['bill_count' => 0, 'bill_total' => 0.0, 'item_total' => 0.0, 'total' => 0.0],
            'avg_bill' => 0.0,
            'unique_customers' => 0,
            'comparison' => (object) [
                'yesterday' => (object) ['date' => now()->subDay()->toDateString(), 'invoices' => 0, 'revenue' => 0.0, 'tax' => 0.0],
                'last_week' => (object) ['date' => now()->subDays(7)->toDateString(), 'invoices' => 0, 'revenue' => 0.0, 'tax' => 0.0],
                'vs_yesterday_revenue_pct' => null,
                'vs_yesterday_invoices_pct' => null,
                'vs_last_week_revenue_pct' => null,
                'vs_last_week_invoices_pct' => null,
            ],
        ];
    }

    private function viewData(FbrDayCloseReport $report): array
    {
        $company = new \App\Models\Company();
        $company->forceFill(['name' => 'Test Shop', 'address' => 'Test Address', 'ntn' => '1234567']);
        return [
            'company' => $company,
            'report' => $report,
            'transactions' => collect(),
            'cashierBreakdown' => collect(),
            'analytics' => $this->stubAnalytics(),
            'displayUdhaar' => 0.0,
            'displayOther' => 0.0,
        ];
    }

    public function test_cash_in_only_summary_renders_on_pdf_and_thermal_without_recon(): void
    {
        // Cash-in-only: rider handed over 700 today against older bills; no
        // same-day rider bills → riders list EMPTY, cash_in 700, recon skipped.
        $report = $this->stubReport(['active' => true, 'riders' => [], 'cash_out' => 0.0, 'cash_in' => 700.0]);

        $pdf = view('fbr-pos.day-close-pdf', $this->viewData($report))->render();
        $this->assertStringContainsString('+700.00', $pdf, 'PDF must show cash_in even with no rider rows and no recon');

        $thermal = view('fbr-pos.day-close-thermal', $this->viewData($report))->render();
        $this->assertStringContainsString('+700.00', $thermal, 'Thermal must show cash_in even with no rider rows and no recon');
    }

    public function test_cash_out_only_summary_renders_without_recon(): void
    {
        $report = $this->stubReport(['active' => true, 'riders' => [
            ['name' => 'Qaisar', 'deliveries' => 2, 'delivered' => 1, 'returned' => 0, 'cash_total' => 900.0, 'cash_pending' => 300.0],
        ], 'cash_out' => 300.0, 'cash_in' => 0.0]);

        $pdf = view('fbr-pos.day-close-pdf', $this->viewData($report))->render();
        $this->assertStringContainsString('-300.00', $pdf, 'PDF must show cash_out without recon');
        $this->assertStringContainsString('Qaisar', $pdf);

        $thermal = view('fbr-pos.day-close-thermal', $this->viewData($report))->render();
        $this->assertStringContainsString('-300.00', $thermal, 'Thermal must show cash_out without recon');
        $this->assertStringContainsString('Qaisar', $thermal);
    }
}
