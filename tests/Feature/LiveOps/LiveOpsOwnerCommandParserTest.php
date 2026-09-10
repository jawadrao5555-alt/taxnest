<?php

namespace Tests\Feature\LiveOps;

use App\Services\LiveOps\LiveOpsOwnerCommandParser;

class LiveOpsOwnerCommandParserTest extends LiveOpsTestCase
{
    private function parse(string $text): array
    {
        return app(LiveOpsOwnerCommandParser::class)->parse($text);
    }

    public function test_daily_report_phrases(): void
    {
        foreach (['Aaj ki report do.', "Today's report", 'daily ops'] as $text) {
            $p = $this->parse($text);
            $this->assertTrue($p['ok'], $text);
            $this->assertSame('DAILY_REPORT', $p['intent']);
            $this->assertSame('DAILY_OPS', $p['operation']);
            $this->assertNull($p['company_name']);
        }
    }

    public function test_company_check_by_name(): void
    {
        $p = $this->parse('Pizza Master check karo');
        $this->assertTrue($p['ok']);
        $this->assertSame('COMPANY_CHECK', $p['intent']);
        $this->assertSame('COMPANY_DIAGNOSTIC', $p['operation']);
        $this->assertSame('Pizza Master', $p['company_name']);
    }

    public function test_printing_solve_phrase(): void
    {
        $p = $this->parse('ZFC ka printing issue solve karo');
        $this->assertTrue($p['ok']);
        $this->assertSame('COMPANY_SOLVE', $p['intent']);
        $this->assertSame('ZFC', $p['company_name']);
        $this->assertSame('printing', $p['focus']);
    }

    public function test_fleet_check_and_solve(): void
    {
        $p = $this->parse('Sab companies check karo aur jahan issue ho solve karo');
        $this->assertTrue($p['ok']);
        $this->assertSame('FLEET_CHECK_AND_SOLVE', $p['intent']);
        $this->assertSame('DAILY_OPS', $p['operation']);
    }

    public function test_issue_prefix_is_stripped(): void
    {
        $p = $this->parse('[TAXNEST-OPS] Aaj ki report do');
        $this->assertTrue($p['ok']);
        $this->assertSame('DAILY_REPORT', $p['intent']);
    }

    public function test_rejects_unsafe_and_unauthorized_operations(): void
    {
        foreach ([
            'run deploy-production.yml',
            'ssh root@taxnest.pk',
            'DROP TABLE companies',
            'rm -rf /',
            'gh workflow run evil.yml',
            'skip_elaan true',
            'cat $PRODUCTION_SSH_PRIVATE_KEY',
        ] as $text) {
            $p = $this->parse($text);
            $this->assertFalse($p['ok'], $text);
            $this->assertStringContainsString('rejected', mb_strtolower($p['error']));
        }
    }

    public function test_allow_listed_operation_name_only(): void
    {
        $p = $this->parse('BILLING_BY_COMPANY');
        $this->assertTrue($p['ok']);
        $this->assertSame('DIAGNOSTIC', $p['intent']);
        $this->assertSame('BILLING_BY_COMPANY', $p['operation']);
    }

    public function test_company_scoped_bare_operation_requires_name(): void
    {
        $p = $this->parse('COMPANY_DIAGNOSTIC');
        $this->assertFalse($p['ok']);
    }
}
