<?php

namespace Tests\Feature\LiveOps;

use App\Services\LiveOps\LiveOpsAutonomousEngine;
use App\Services\LiveOps\LiveOpsDiagnosticsService;
use App\Services\LiveOps\LiveOpsOwnerReportFormatter;

class LiveOpsIncompleteCoverageTest extends LiveOpsTestCase
{
    public function test_missing_day_close_source_remains_visible_without_green_or_resolved_claim(): void
    {
        $company = $this->makeCompany('Synthetic Cash Cafe');
        $envelope = app(LiveOpsDiagnosticsService::class)->run('COMPANY_DIAGNOSTIC', ['company_id' => $company]);
        $this->assertFalse($envelope['data']['coverage']['complete']);
        $this->assertSame('UNAVAILABLE', $envelope['data']['day_close']['coverage']['status']);
        $report = app(LiveOpsOwnerReportFormatter::class)->format($envelope);
        $this->assertNotSame('GREEN', $report['overall']);
        $this->assertSame('UNAVAILABLE', $report['day_close']['coverage']['status']);
        $this->assertStringContainsString('Check abhi adhoora hai', $report['text']);
    }

    public function test_legacy_signal_only_report_cannot_imply_full_company_health(): void
    {
        $formatter = app(LiveOpsOwnerReportFormatter::class);
        $report = $formatter->format(['operation' => 'COMPANY_DIAGNOSTIC', 'data' => ['company' => ['name' => 'Synthetic'], 'likely_root_cause' => 'no_obvious_fault_from_available_signals']]);
        $this->assertNotSame('GREEN', $report['overall']);
        $this->assertSame('UNAVAILABLE', $report['coverage']['status']);
    }

    public function test_no_operational_fault_does_not_resolve_an_unmeasured_cash_complaint(): void
    {
        // Verify both resolution gates independent of the owner's natural-language classifier.
        $engine = app(LiveOpsAutonomousEngine::class);
        $method = new \ReflectionMethod($engine, 'postFixStatus');
        $primary = ['data' => ['likely_root_cause' => 'no_obvious_fault_from_available_signals', 'printer' => ['failed_job_count' => 0], 'coverage' => ['complete' => false]]];
        $this->assertSame('DIAGNOSED', $method->invoke($engine, $primary, ['Printer checked']));
        $row = new \App\Models\LiveOpsAutonomousRequest(['iteration' => 0]);
        $first = new \ReflectionMethod($engine, 'maybeOperationalFix');
        $result = $first->invoke($engine, $row, [], 1, 'synthetic', $primary);
        $this->assertSame('DIAGNOSED', $result['status']);
        $this->assertFalse($result['re_diagnose']);
    }
}
