<?php

namespace Tests\Feature;

use App\Services\AgentCoreEventInboxService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class AgentCoreLeaseChainTest extends TestCase
{
    private const SECRET = 'lease-chain-test-secret-32-bytes!!';

    protected function setUp(): void
    {
        parent::setUp();
        Schema::dropAllTables();
        Schema::create('companies', function (Blueprint $t): void {
            $t->id(); $t->string('name'); $t->string('agent_api_key'); $t->boolean('agent_enabled')->default(true);
            $t->boolean('agent_core_enabled')->default(true); $t->softDeletes(); $t->timestamps();
        });
        Schema::create('pos_agent_devices', function (Blueprint $t): void {
            $t->id(); $t->unsignedBigInteger('company_id'); $t->string('device_uid'); $t->timestamps();
        });
        Schema::create('users', function (Blueprint $t): void {
            $t->id(); $t->unsignedBigInteger('company_id'); $t->string('name'); $t->string('pos_role');
            $t->string('role')->nullable(); $t->boolean('is_active')->default(true); $t->timestamps();
        });
        Schema::create('agent_core_scope_leases', function (Blueprint $t): void {
            $t->id(); $t->unsignedBigInteger('company_id'); $t->string('device_uid'); $t->unsignedBigInteger('branch_id');
            $t->unsignedBigInteger('user_id'); $t->string('token_hash'); $t->uuid('nonce'); $t->json('allowed_actions');
            $t->string('permission_version'); $t->timestamp('expires_at'); $t->timestamp('revoked_at')->nullable();
            $t->text('signing_secret'); $t->unsignedBigInteger('last_sequence')->default(0);
            $t->string('last_chain_hash', 64)->nullable(); $t->timestamps();
        });
        Schema::create('agent_core_events', function (Blueprint $t): void {
            $t->id(); $t->unsignedBigInteger('company_id'); $t->string('device_uid'); $t->string('event_id');
            $t->string('idempotency_key'); $t->string('event_type'); $t->timestamp('occurred_at')->nullable();
            $t->json('payload'); $t->string('content_hash', 64); $t->json('event_scope')->nullable();
            $t->string('projection_status', 24)->nullable(); $t->json('projection_result')->nullable();
            $t->text('projection_error')->nullable(); $t->string('projection_dependency')->nullable();
            $t->unsignedInteger('projection_attempts')->default(0); $t->timestamp('projected_at')->nullable();
            $t->boolean('legacy_backfilled')->default(false); $t->unsignedBigInteger('lease_id')->nullable();
            $t->unsignedBigInteger('lease_sequence')->nullable(); $t->string('lease_chain_hash', 64)->nullable();
            $t->timestamps(); $t->unique(['company_id', 'device_uid', 'event_id']); $t->unique(['lease_id', 'lease_sequence']);
        });
        DB::table('companies')->insert(['id' => 1, 'name' => 'Shop', 'agent_api_key' => 'key', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('pos_agent_devices')->insert(['company_id' => 1, 'device_uid' => 'd1', 'created_at' => now(), 'updated_at' => now()]);
        $stamp = now();
        foreach ([1, 2] as $id) {
            DB::table('users')->insert(['id' => $id, 'company_id' => 1, 'name' => "U$id", 'pos_role' => 'pos_admin',
                'is_active' => true, 'created_at' => $stamp, 'updated_at' => $stamp]);
            DB::table('agent_core_scope_leases')->insert(['id' => $id, 'company_id' => 1, 'device_uid' => 'd1',
                'branch_id' => $id, 'user_id' => $id, 'token_hash' => hash('sha256', 'unused'),
                'nonce' => sprintf('00000000-0000-0000-0000-%012d', $id), 'allowed_actions' => json_encode(['*']),
                'permission_version' => hash('sha256', implode('|', [$stamp->getTimestamp(), 1, 'pos_admin'])),
                'expires_at' => now()->subDay(), 'signing_secret' => Crypt::encryptString(self::SECRET),
                'created_at' => now(), 'updated_at' => now()]);
        }
    }

    private function signed(int $lease, int $sequence, string $prev, string $id): array
    {
        $event = ['event_id' => $id, 'event_type' => 'order.created', 'occurred_at' => '2026-10-06T12:00:00Z',
            'idempotency_key' => "idem-$id", 'scope' => ['company_id' => '1', 'branch_id' => (string) $lease,
                'device_id' => 'd1', 'user_id' => (string) $lease],
            'payload' => ['schema' => 'local-core.order.v1', 'command_type' => 'order.open',
                'aggregate_id' => $id, 'aggregate_revision' => 1, 'data' => []]];
        $canonical = AgentCoreEventInboxService::canonicalJson($event + [
            'lease_id' => $lease, 'sequence' => $sequence, 'prev_hash' => $prev,
        ]);
        $signature = hash_hmac('sha256', $canonical, self::SECRET);
        $event['lease_chain'] = ['lease_id' => $lease, 'sequence' => $sequence,
            'prev_hash' => $prev, 'signature' => $signature];
        return $event;
    }

    private function nextHash(array $event): string
    {
        $chain = $event['lease_chain'];
        $canonical = AgentCoreEventInboxService::canonicalJson(array_diff_key($event, ['lease_chain' => true]) + [
            'lease_id' => $chain['lease_id'], 'sequence' => $chain['sequence'], 'prev_hash' => $chain['prev_hash'],
        ]);
        return hash('sha256', $canonical . ':' . $chain['signature']);
    }

    private function send(array $events)
    {
        return $this->withToken('key')->postJson('/api/agent/v2/events', ['version' => 1, 'device_uid' => 'd1', 'events' => $events]);
    }

    public function test_multi_event_replay_order_tamper_mixed_leases_and_atomic_rollback(): void
    {
        $zero = str_repeat('0', 64);
        $one = $this->signed(1, 1, $zero, 'a1');
        $two = $this->signed(1, 2, $this->nextHash($one), 'a2');
        $this->send([$one, $two])->assertOk();
        $this->send([$one, $two])->assertOk();
        $this->assertSame(2, DB::table('agent_core_events')->count());

        $three = $this->signed(1, 3, $this->nextHash($two), 'a3');
        $this->send([$two, $three])->assertOk();
        $this->assertSame(3, (int) DB::table('agent_core_scope_leases')->where('id', 1)->value('last_sequence'));

        $b1 = $this->signed(2, 1, $zero, 'b1');
        $four = $this->signed(1, 4, $this->nextHash($three), 'a4');
        $this->send([$four, $b1])->assertOk();
        $this->assertSame(1, (int) DB::table('agent_core_scope_leases')->where('id', 2)->value('last_sequence'));

        $before = DB::table('agent_core_events')->count();
        $five = $this->signed(1, 5, $this->nextHash($four), 'a5');
        $bad = $this->signed(2, 3, $this->nextHash($b1), 'gap');
        $this->send([$five, $bad])->assertForbidden();
        $this->assertSame($before, DB::table('agent_core_events')->count());
        $this->assertSame(4, (int) DB::table('agent_core_scope_leases')->where('id', 1)->value('last_sequence'));

        $tampered = $five; $tampered['payload']['data']['x'] = 1;
        $this->send([$tampered])->assertForbidden();
        $this->send([$five, $four])->assertForbidden();
    }
}