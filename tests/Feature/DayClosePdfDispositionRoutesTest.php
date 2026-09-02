<?php

namespace Tests\Feature;

use Tests\TestCase;

/**
 * Day-close PDFs are view-first: the report URL must be safe to open in a
 * browser, while a distinct URL deliberately requests a file attachment.
 */
class DayClosePdfDispositionRoutesTest extends TestCase
{
    public function test_pra_and_fbr_day_close_pdf_routes_separate_view_and_download_actions(): void
    {
        $routes = file_get_contents(base_path('routes/web.php'));

        foreach ([
            'pos.day-close-pdf',
            'pos.day-close-pdf-download',
            'pos.day-close-x-pdf',
            'pos.day-close-x-pdf-download',
            'pos.day-close-summary-pdf',
            'pos.day-close-summary-pdf-download',
            'pos.day-close-x-summary-pdf',
            'pos.day-close-x-summary-pdf-download',
            'fbrpos.day-close-pdf',
            'fbrpos.day-close-pdf-download',
        ] as $routeName) {
            $this->assertStringContainsString("->name('{$routeName}')", $routes);
        }

        $this->assertSame(5, substr_count($routes, "->defaults('download', true)"));
    }

    public function test_report_renderers_choose_inline_for_view_and_attachment_for_download(): void
    {
        foreach ([
            app_path('Http/Controllers/PosController.php'),
            app_path('Http/Controllers/FbrPosController.php'),
        ] as $controller) {
            $source = file_get_contents($controller);
            $this->assertStringContainsString('$pdf->stream($filename)', $source);
            $this->assertStringContainsString('$pdf->download($filename)', $source);
            $this->assertStringContainsString("'Content-Disposition' => \$disposition", file_get_contents(app_path('Support/MpdfRenderer.php')));
        }
    }

    public function test_unrelated_report_exports_keep_the_helper_attachment_default(): void
    {
        $pos = file_get_contents(app_path('Http/Controllers/PosController.php'));
        $fbr = file_get_contents(app_path('Http/Controllers/FbrPosController.php'));

        // These callers intentionally omit the day-close-only inline argument.
        // The helper's default must therefore remain attachment for exports.
        $this->assertStringContainsString("bool \$download = true", $pos);
        $this->assertStringContainsString("bool \$download = true", $fbr);
        $this->assertStringContainsString("'pos.reports-analytics-pdf'", $pos);
        $this->assertStringContainsString("return \$this->renderReportPdf('pos.tax-report-pdf', \$data, \$filename, 'landscape');", $pos);
        $this->assertStringContainsString("'pos.customer-history-pdf'", $pos);
        $this->assertStringContainsString("'pos.reports-hazri-payroll-pdf'", $pos);
        $this->assertStringContainsString("'fbr-pos.reports-analytics-pdf'", $fbr);
        $this->assertStringContainsString("'fbr-pos.tax-report-pdf'", $fbr);
        $this->assertStringContainsString("'pos.reports-hazri-payroll-pdf'", $fbr);
    }
}