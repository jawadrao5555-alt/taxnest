<?php

namespace Tests\Feature;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class AgentCoreSnapshotTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Schema::dropAllTables();
        Schema::create('companies', function (Blueprint $t) {
            $t->id(); $t->string('name'); $t->string('agent_api_key'); $t->boolean('agent_enabled')->default(true);
            $t->boolean('agent_core_enabled')->default(true); $t->softDeletes(); $t->timestamps();
        });
        Schema::create('users', function (Blueprint $t) {
            $t->id(); $t->unsignedBigInteger('company_id'); $t->string('name'); $t->string('role')->default('cashier');
            $t->string('pos_role')->nullable(); $t->boolean('is_active')->default(true); $t->timestamps();
        });
        Schema::create('pos_agent_devices', function (Blueprint $t) {
            $t->id(); $t->unsignedBigInteger('company_id'); $t->string('device_uid'); $t->timestamp('last_seen_at')->nullable(); $t->timestamps();
        });
        Schema::create('agent_core_scope_leases', function (Blueprint $t) {
            $t->id(); $t->unsignedBigInteger('company_id'); $t->string('device_uid'); $t->unsignedBigInteger('branch_id');
            $t->unsignedBigInteger('user_id'); $t->string('token_hash', 64); $t->string('nonce')->nullable();
            $t->json('allowed_actions'); $t->string('permission_version', 64); $t->timestamp('expires_at');
            $t->timestamp('revoked_at')->nullable(); $t->text('signing_secret')->nullable(); $t->unsignedBigInteger('last_sequence')->default(0);
            $t->string('last_chain_hash', 64)->nullable(); $t->timestamps();
        });
        Schema::create('inventory_stocks', function (Blueprint $t) {
            $t->id(); $t->unsignedBigInteger('company_id'); $t->unsignedBigInteger('branch_id');
            $t->unsignedBigInteger('product_id'); $t->decimal('quantity', 12, 4); $t->timestamps();
        });
        DB::table('companies')->insert([
            ['id' => 1, 'name' => 'One', 'agent_api_key' => 'key-one', 'agent_enabled' => 1, 'agent_core_enabled' => 1, 'created_at' => now(), 'updated_at' => now()],
            ['id' => 2, 'name' => 'Two', 'agent_api_key' => 'key-two', 'agent_enabled' => 1, 'agent_core_enabled' => 1, 'created_at' => now(), 'updated_at' => now()],
        ]);
        foreach ([1, 2] as $id) {
            DB::table('users')->insert(['id' => $id, 'company_id' => $id, 'name' => "User $id", 'role' => 'company_admin',
                'is_active' => 1, 'created_at' => now(), 'updated_at' => now()]);
            DB::table('pos_agent_devices')->insert(['company_id' => $id, 'device_uid' => "device-$id",
                'last_seen_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
            $version = hash('sha256', implode('|', [now()->getTimestamp(), 1, 'company_admin']));
            DB::table('agent_core_scope_leases')->insert(['id' => $id, 'company_id' => $id, 'device_uid' => "device-$id",
                'branch_id' => 10 + $id, 'user_id' => $id, 'token_hash' => hash('sha256', "token-$id"),
                'allowed_actions' => json_encode(['*']), 'permission_version' => $version, 'expires_at' => now()->addHour(),
                'created_at' => now(), 'updated_at' => now()]);
        }
        DB::table('inventory_stocks')->insert([
            ['company_id' => 1, 'branch_id' => 11, 'product_id' => 5, 'quantity' => 7, 'created_at' => now(), 'updated_at' => now()],
            ['company_id' => 1, 'branch_id' => 99, 'product_id' => 5, 'quantity' => 999, 'created_at' => now(), 'updated_at' => now()],
            ['company_id' => 2, 'branch_id' => 12, 'product_id' => 5, 'quantity' => 222, 'created_at' => now(), 'updated_at' => now()],
        ]);
    }

    public function test_snapshot_is_hash_authenticated_and_company_branch_scoped(): void
    {
        $response = $this->withToken('key-one')->postJson('/api/agent/v2/snapshot', [
            'device_uid' => 'device-1', 'branch_id' => 11, 'lease_id' => 1, 'lease_token' => 'token-1',
        ])
            ->assertOk()->assertJsonPath('schema', 'local-core.snapshot.v1')
            ->assertJsonPath('scope.company_id', '1')->assertJsonPath('scope.branch_id', '11')
            ->assertJsonPath('payload.stock.product:5', 7);
        $body = $response->json();
        $canonical = ['schema' => $body['schema'], 'revision' => $body['revision'], 'scope' => $body['scope'], 'payload' => $body['payload']];
        $this->assertSame($body['hash'], hash('sha256', \App\Services\AgentCoreSnapshotService::canonicalJson($canonical)));
        $this->assertStringNotContainsString('999', json_encode($body['payload']['stock']));
        $this->assertStringNotContainsString('222', json_encode($body['payload']['stock']));
    }

    public function test_snapshot_refuses_cross_tenant_lease_and_stale_heartbeat(): void
    {
        $this->withToken('key-one')->postJson('/api/agent/v2/snapshot', [
            'device_uid' => 'device-2', 'branch_id' => 12, 'lease_id' => 2, 'lease_token' => 'token-2',
        ])
            ->assertForbidden()->assertJsonPath('error', 'snapshot_scope_denied');
        DB::table('pos_agent_devices')->where('company_id', 1)->update(['last_seen_at' => now()->subMinutes(3)]);
        $this->withToken('key-one')->postJson('/api/agent/v2/snapshot', [
            'device_uid' => 'device-1', 'branch_id' => 11, 'lease_id' => 1, 'lease_token' => 'token-1',
        ])
            ->assertForbidden()->assertJsonPath('error', 'snapshot_scope_denied');
    }
}