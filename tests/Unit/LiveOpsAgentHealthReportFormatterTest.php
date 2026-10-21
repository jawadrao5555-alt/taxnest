<?php

namespace Tests\Unit;

use App\Services\LiveOps\LiveOpsOwnerReportFormatter;
use PHPUnit\Framework\TestCase;

class LiveOpsAgentHealthReportFormatterTest extends TestCase
{
    public function test_fleet_report_shows_complete_counts_and_explicit_detail_limit(): void
    {
        $report = (new LiveOpsOwnerReportFormatter)->format([
            'operation' => 'AGENT_HEALTH', 'summary_text' => 'AGENT_HEALTH completed.',
            'data' => [
                'counts' => ['enabled' => 2, 'online' => 1, 'long_offline' => 1],
                'coverage' => ['total' => 2, 'returned' => 1, 'truncated' => true],
                'agents' => [['company_id' => 1, 'name' => 'Synthetic Recent Shop',
                    'online' => true, 'long_offline' => false,
                    'last_seen' => '2026-10-21T09:00:00+00:00', 'version' => '1.13.19']],
            ],
        ]);
        $this->assertSame('ATTENTION', $report['overall']);
        $this->assertStringContainsString('Enabled: 2; online: 1; long offline: 1', $report['text']);
        $this->assertStringContainsString('Detail rows: 1 of 2', $report['text']);
        $this->assertStringContainsString('truncated', $report['text']);
        $this->assertStringContainsString('2026-10-21T09:00:00+00:00', $report['text']);
        $this->assertStringContainsString('1.13.19', $report['text']);
        $this->assertSame([], $report['actions_taken']);
    }

    public function test_scoped_report_keeps_device_observations_separate_and_cause_unknown(): void
    {
        $report = (new LiveOpsOwnerReportFormatter)->format([
            'operation' => 'AGENT_HEALTH',
            'data' => [
                'company' => ['id' => 7, 'name' => 'Synthetic Counter Shop', 'agent_enabled' => true,
                    'agent_online' => false, 'agent_last_seen' => null, 'agent_version' => '1.8.0'],
                'devices' => [
                    ['online' => true, 'last_seen_at' => '2026-10-21T09:00:00Z', 'agent_version' => '1.13.19'],
                    ['online' => false, 'last_seen_at' => null, 'agent_version' => '1.8.0'],
                ],
            ],
        ]);
        $this->assertSame('ATTENTION', $report['overall']);
        $this->assertStringContainsString('last heartbeat: not recorded', $report['text']);
        $this->assertStringContainsString('Counter 1: ONLINE', $report['text']);
        $this->assertStringContainsString('Counter 2: OFFLINE', $report['text']);
        $this->assertStringContainsString('Cause: UNKNOWN', $report['text']);
        $this->assertStringContainsString('PC power, Windows service, internet or rejected requests', $report['text']);
        $this->assertSame([], $report['actions_taken']);
    }

    public function test_missing_observations_are_unknown_not_a_healthy_fleet(): void
    {
        $report = (new LiveOpsOwnerReportFormatter)->format(['operation' => 'AGENT_HEALTH', 'data' => []]);
        $this->assertSame('UNKNOWN', $report['overall']);
        $this->assertStringContainsString('coverage unverified', $report['text']);
        $this->assertSame([], $report['actions_taken']);
    }
}
