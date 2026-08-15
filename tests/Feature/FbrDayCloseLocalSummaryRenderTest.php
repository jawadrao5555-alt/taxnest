<?php

namespace Tests\Feature;

use Tests\TestCase;
use App\Models\FbrDayCloseReport;

/**
 * Task 733 render lock for Task 697: the printed Z-report (standalone PDF +
 * thermal blades) must show the "Local Bills Closed With This Day" audit
 * section from the STORED local_summary snapshot — finalized / deleted /
 * rider-guarded / per-bill counts — and must HIDE the section entirely when
 * local_summary is null/empty. Views are rendered directly with stub data
 * (self-contained HTML, no layout/auth), same pattern as the rider render lock.
 */
class FbrDayCloseLocalSummaryRenderTest extends TestCase
{
    private function stubReport(?array $localSummary): FbrDayCloseReport
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
            'counted_cash' => null,
            'expected_cash' => null,
            'cash_variance' => null,
            'rider_summary' => null,
            'local_summary' => $localSummary,
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

    private function richLocalSummary(): array
    {
        return ['provisional' => [
            'action' => 'finalize',
            'count' => 6,
            'amount' => 4321.50,
            'backlog' => 2,
            'finalized' => 3,
            'submitted' => 2,
            'queued' => 1,
            'failed' => 0,
            'deleted' => 2,
            'rider_guarded' => 1,
            'per_bill' => ['save' => 4, 'delete' => 2, 'carry' => 0],
        ]];
    }

    private function assertSectionRendered(string $html, string $surface): void
    {
        $this->assertStringContainsString(__('pos.local_bills_closed_with_day'), $html, "$surface must show the local-bills section heading");
        // Finalized line with count + submitted/queued sub-counts
        $this->assertStringContainsString(ltrim(__('pos.dayclose_bills_finalized', ['count' => 3]), ' —'), $html, "$surface must show finalized count");
        $this->assertStringContainsString(__('pos.dayclose_bills_submitted', ['count' => 2]), $html, "$surface must show submitted count");
        $this->assertStringContainsString(__('pos.dayclose_bills_queued', ['count' => 1]), $html, "$surface must show queued count");
        // Deleted line
        $this->assertStringContainsString(ltrim(__('pos.dayclose_bills_deleted', ['count' => 2]), ' —'), $html, "$surface must show deleted count");
        // Rider-guarded line
        $this->assertStringContainsString(__('pos.dc_rider_guarded_kept', ['count' => 1]), $html, "$surface must show rider-guarded count");
        // Per-bill split line
        $this->assertStringContainsString(__('pos.dc_per_bill_split', ['save' => 4, 'delete' => 2, 'carry' => 0]), $html, "$surface must show per-bill split");
        // Snapshot totals: bill count + amount, backlog note
        $this->assertStringContainsString(number_format(4321.50, 2), $html, "$surface must show snapshot amount");
        $this->assertStringContainsString(__('pos.n_older_dates_included', ['count' => 2]), $html, "$surface must show backlog note");
    }

    public function test_rich_local_summary_renders_on_pdf_and_thermal(): void
    {
        $report = $this->stubReport($this->richLocalSummary());

        $pdf = view('fbr-pos.day-close-pdf', $this->viewData($report))->render();
        $this->assertSectionRendered($pdf, 'PDF');
        // PDF badge column shows the action label
        $this->assertStringContainsString(__('pos.badge_finalized'), $pdf);

        $thermal = view('fbr-pos.day-close-thermal', $this->viewData($report))->render();
        $this->assertSectionRendered($thermal, 'Thermal');
        $this->assertStringContainsString(__('pos.badge_finalized'), $thermal);
    }

    public function test_null_local_summary_hides_section(): void
    {
        $report = $this->stubReport(null);

        $pdf = view('fbr-pos.day-close-pdf', $this->viewData($report))->render();
        $this->assertStringNotContainsString(__('pos.local_bills_closed_with_day'), $pdf, 'PDF must hide section when local_summary is null');

        $thermal = view('fbr-pos.day-close-thermal', $this->viewData($report))->render();
        $this->assertStringNotContainsString(__('pos.local_bills_closed_with_day'), $thermal, 'Thermal must hide section when local_summary is null');
    }

    public function test_zero_count_provisional_summary_hides_section(): void
    {
        // Snapshot exists but records nothing happened (0 pending, 0 finalized,
        // 0 deleted) — the guard must keep the section off the printed report.
        $report = $this->stubReport(['provisional' => [
            'action' => 'carry', 'count' => 0, 'amount' => 0, 'finalized' => 0, 'deleted' => 0,
        ]]);

        $pdf = view('fbr-pos.day-close-pdf', $this->viewData($report))->render();
        $this->assertStringNotContainsString(__('pos.local_bills_closed_with_day'), $pdf, 'PDF must hide section for an all-zero snapshot');

        $thermal = view('fbr-pos.day-close-thermal', $this->viewData($report))->render();
        $this->assertStringNotContainsString(__('pos.local_bills_closed_with_day'), $thermal, 'Thermal must hide section for an all-zero snapshot');
    }
}
