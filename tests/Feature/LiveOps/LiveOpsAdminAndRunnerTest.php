<?php

namespace Tests\Feature\LiveOps;

use App\Models\AdminUser;
use App\Services\LiveOps\LiveOpsRemediationService;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

class LiveOpsAdminAndRunnerTest extends LiveOpsTestCase
{
    public function test_admin_live_ops_requires_super_admin(): void
    {
        DB::table('admin_users')->insert([
            'name' => 'Staff',
            'email' => 'staff@taxnest.test',
            'password' => Hash::make('x'),
            'role' => 'support',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $staff = AdminUser::where('email', 'staff@taxnest.test')->first();
        $this->actingAs($staff, 'admin')->get('/admin/live-ops')->assertStatus(403);
    }

    public function test_runner_api_daily_ops_without_company(): void
    {
        Config::set('live_ops.runner_token', str_repeat('c', 40));
        $this->makeCompany('Fleet Shop');
        $this->postJson('/api/live-ops/v1/diagnose', [
            'operation' => 'DAILY_OPS',
            'requester' => 'actions',
        ], [
            'X-Live-Ops-Token' => str_repeat('c', 40),
        ])->assertOk()
            ->assertJsonPath('ok', true)
            ->assertJsonPath('report.operation', 'DAILY_OPS')
            ->assertJsonPath('report.scope.company_id', null);
    }

    public function test_admin_index_and_company_diagnostic(): void
    {
        $id = $this->makeCompany('UI Shop');
        $this->actingAsAdmin()->get('/admin/live-ops')->assertOk()->assertSee('Live Ops')->assertSee('DAILY_OPS');
        $this->actingAsAdmin()->get('/admin/live-ops/company/'.$id)
            ->assertOk()
            ->assertSee('Diagnostic report')
            ->assertSee('UI Shop');
    }

    public function test_admin_propose_approve_execute_flow(): void
    {
        $id = $this->makeCompany('Fix Shop');
        $this->actingAsAdmin()->post('/admin/live-ops/propose', [
            '_token' => csrf_token(),
            'action' => 'REFRESH_OPERATIONAL_STATE',
            'company_id' => $id,
            'proposal' => 'refresh please',
            'idempotency_key' => 'ui-1',
        ])->assertRedirect();

        $actionId = DB::table('live_ops_remediation_requests')->value('action_id');
        $this->assertNotEmpty($actionId);

        $this->actingAsAdmin()->post('/admin/live-ops/remediation/'.$actionId.'/approve', [
            '_token' => csrf_token(),
            'owner_approval_phrase' => LiveOpsRemediationService::APPROVAL_PHRASE,
            'execute_now' => '1',
        ])->assertRedirect();

        $this->assertSame('executed', DB::table('live_ops_remediation_requests')->where('action_id', $actionId)->value('status'));
    }

    public function test_runner_api_requires_token(): void
    {
        Config::set('live_ops.runner_token', str_repeat('a', 40));
        $this->postJson('/api/live-ops/v1/diagnose', [
            'operation' => 'AGENT_HEALTH',
        ])->assertStatus(401);
    }

    public function test_runner_api_diagnose_with_token(): void
    {
        Config::set('live_ops.runner_token', str_repeat('b', 40));
        $id = $this->makeCompany('API Shop');
        $this->postJson('/api/live-ops/v1/diagnose', [
            'operation' => 'COMPANY_HEALTH',
            'company_id' => $id,
            'requester' => 'actions',
        ], [
            'X-Live-Ops-Token' => str_repeat('b', 40),
        ])->assertOk()
            ->assertJsonPath('ok', true)
            ->assertJsonPath('report.data.company.id', $id);
    }

    public function test_runner_rejects_short_token_config(): void
    {
        Config::set('live_ops.runner_token', 'short');
        $this->postJson('/api/live-ops/v1/diagnose', [
            'operation' => 'AGENT_HEALTH',
        ], [
            'X-Live-Ops-Token' => 'short',
        ])->assertStatus(503);
    }

    public function test_safe_auto_deploy_requires_token_and_blocks_high_risk_paths(): void
    {
        Config::set('live_ops.runner_token', str_repeat('d', 40));
        $this->postJson('/api/live-ops/v1/safe-auto-deploy', [
            'pull_request_number' => 1,
            'head_sha' => str_repeat('a', 40),
            'paths' => ['public/js/pos-print-attempt.js'],
        ])->assertStatus(401);

        $this->postJson('/api/live-ops/v1/safe-auto-deploy', [
            'pull_request_number' => 1,
            'head_sha' => str_repeat('a', 40),
            'paths' => ['database/migrations/x.php'],
        ], [
            'X-Live-Ops-Token' => str_repeat('d', 40),
        ])->assertStatus(422)
            ->assertJsonPath('ok', false);
    }
}
