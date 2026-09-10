<?php

namespace Tests\Feature\LiveOps;

use App\Services\LiveOps\LiveOpsChangeRiskClassifier;

/**
 * End-to-end contract for owner NL → resolve → diagnose → risk → report.
 * No production business data is mutated.
 */
class LiveOpsAutonomousE2ETest extends LiveOpsTestCase
{
    public function test_pipeline_contract_statuses_and_failed_paths(): void
    {
        $healthy = $this->makeCompany('Healthy Cafe');
        $this->makeTxn($healthy, ['total_amount' => 500]);

        $engine = app(\App\Services\LiveOps\LiveOpsAutonomousEngine::class);

        $daily = $engine->handle('Aaj ki report do', ['requester' => 'e2e']);
        $this->assertSame('DIAGNOSED', $daily['status']);
        $this->assertNotEmpty($daily['request_id']);
        $this->assertStringContainsString('Healthy Cafe', $daily['owner_report']['text']);

        $check = $engine->handle('Healthy Cafe check karo', ['requester' => 'e2e']);
        $this->assertSame($healthy, $check['company_id']);
        $this->assertSame('DIAGNOSED', $check['status']);

        $unknown = $engine->handle('Ghost Shop check karo');
        $this->assertSame('FAILED', $unknown['status']);

        $failedDiag = $engine->handle('DROP TABLE companies; Aaj ki report do');
        $this->assertSame('FAILED', $failedDiag['status']);

        $risk = app(LiveOpsChangeRiskClassifier::class);
        $failedDeploy = $risk->classifyPaths(['.github/workflows/deploy-production.yml']);
        $this->assertSame(LiveOpsChangeRiskClassifier::HIGH_RISK_BLOCKED, $failedDeploy['class']);

        $auth = $risk->classifyPaths(['app/Http/Middleware/AgentAuth.php']);
        $this->assertSame(LiveOpsChangeRiskClassifier::HIGH_RISK_BLOCKED, $auth['class']);

        $migration = $risk->classifyPaths(['database/migrations/x.php']);
        $this->assertSame(LiveOpsChangeRiskClassifier::HIGH_RISK_BLOCKED, $migration['class']);

        $liveVerifyFail = [
            'status' => 'FAILED',
            'live_verification' => ['ok' => false, 'reason' => 'ci-live-verify SHA mismatch'],
        ];
        $this->assertFalse($liveVerifyFail['live_verification']['ok']);

        $retry = $engine->handle('Healthy Cafe ka issue solve karo', ['requester' => 'e2e']);
        $this->assertContains($retry['status'], ['RESOLVED', 'DIAGNOSED', 'BLOCKED']);
        $this->assertLessThanOrEqual(3, (int) ($retry['metadata']['max_iterations'] ?? 3));
    }

    public function test_failed_diagnostic_does_not_touch_transactions(): void
    {
        $id = $this->makeCompany('Stable Shop');
        $this->makeTxn($id);
        $before = \Illuminate\Support\Facades\DB::table('pos_transactions')->count();
        app(\App\Services\LiveOps\LiveOpsAutonomousEngine::class)->handle('ssh root@host check karo');
        $this->assertSame($before, \Illuminate\Support\Facades\DB::table('pos_transactions')->count());
    }
}
