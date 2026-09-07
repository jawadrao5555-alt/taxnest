<?php

namespace Tests\Feature\LiveOps;

use App\Models\Company;
use App\Services\LiveOps\LiveOpsAgentCommandService;
use Illuminate\Support\Facades\DB;

class LiveOpsAgentCommandChannelTest extends LiveOpsTestCase
{
    public function test_heartbeat_includes_pending_commands_and_force_update(): void
    {
        $id = $this->makeCompany('Agent Co', [
            'agent_force_update_at' => now(),
        ]);
        $company = Company::find($id);
        app(LiveOpsAgentCommandService::class)->enqueue([
            'command_type' => 'STATUS_REFRESH',
            'company_id' => $id,
            'idempotency_key' => 'hb-1',
        ]);

        $res = $this->postJson('/api/agent/heartbeat', [
            'version' => '1.13.2',
            'device_uid' => 'dev-1',
            'hostname' => 'TILL1',
        ], [
            'Authorization' => 'Bearer '.$company->agent_api_key,
        ]);

        $res->assertOk();
        $res->assertJsonPath('force_update', true);
        $res->assertJsonStructure(['pending_commands']);
        $this->assertNotEmpty($res->json('pending_commands'));
        $this->assertSame('STATUS_REFRESH', $res->json('pending_commands.0.type'));
    }

    public function test_command_result_company_bound(): void
    {
        $a = $this->makeCompany('A');
        $b = $this->makeCompany('B');
        $cmd = app(LiveOpsAgentCommandService::class)->enqueue([
            'command_type' => 'RESYNC',
            'company_id' => $a,
            'idempotency_key' => 'res-1',
        ]);
        $companyB = Company::find($b);

        $this->postJson('/api/agent/command-result', [
            'command_id' => $cmd->command_id,
            'ok' => true,
            'result' => ['synced' => true],
        ], [
            'Authorization' => 'Bearer '.$companyB->agent_api_key,
        ])->assertStatus(422);

        $companyA = Company::find($a);
        $this->postJson('/api/agent/command-result', [
            'command_id' => $cmd->command_id,
            'ok' => true,
            'result' => ['synced' => true, 'password' => 'secret'],
        ], [
            'Authorization' => 'Bearer '.$companyA->agent_api_key,
        ])->assertOk();

        $this->assertDatabaseHas('live_ops_agent_commands', [
            'command_id' => $cmd->command_id,
            'status' => 'succeeded',
        ]);
        $stored = DB::table('live_ops_agent_commands')->where('command_id', $cmd->command_id)->value('result');
        $this->assertStringNotContainsString('secret', (string) $stored);
        $this->assertStringContainsString('[REDACTED]', (string) $stored);
    }

    public function test_expired_command_not_delivered(): void
    {
        $id = $this->makeCompany('Exp');
        $cmd = app(LiveOpsAgentCommandService::class)->enqueue([
            'command_type' => 'RESYNC',
            'company_id' => $id,
            'idempotency_key' => 'exp-1',
        ]);
        DB::table('live_ops_agent_commands')->where('command_id', $cmd->command_id)->update([
            'expires_at' => now()->subMinute(),
        ]);

        $pending = app(LiveOpsAgentCommandService::class)->pendingForAgent($id);
        $this->assertSame([], $pending);
    }
}
