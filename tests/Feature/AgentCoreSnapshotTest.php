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
        // Shape-contract fixtures: ingredients (stock on the row itself),
        // active + retired recipe parts, floors/tables and an open held order
        // sitting on table 1.
        Schema::create('ingredients', function (Blueprint $t) {
            $t->id(); $t->unsignedBigInteger('company_id'); $t->string('name'); $t->decimal('current_stock', 15, 2)->default(0);
            $t->boolean('is_active')->default(true); $t->timestamps();
        });
        Schema::create('product_recipes', function (Blueprint $t) {
            $t->id(); $t->unsignedBigInteger('company_id'); $t->unsignedBigInteger('product_id'); $t->unsignedBigInteger('ingredient_id');
            $t->decimal('quantity_needed', 15, 4); $t->boolean('is_active')->default(true); $t->timestamps();
        });
        Schema::create('restaurant_floors', function (Blueprint $t) {
            $t->id(); $t->unsignedBigInteger('company_id'); $t->string('name'); $t->timestamps();
        });
        Schema::create('restaurant_tables', function (Blueprint $t) {
            $t->id(); $t->unsignedBigInteger('company_id'); $t->unsignedBigInteger('floor_id'); $t->string('table_number');
            $t->string('status')->default('available'); $t->timestamps();
        });
        Schema::create('restaurant_orders', function (Blueprint $t) {
            $t->id(); $t->unsignedBigInteger('company_id'); $t->string('order_number'); $t->string('status')->default('held');
            $t->unsignedBigInteger('table_id')->nullable(); $t->unsignedBigInteger('created_by')->nullable(); $t->timestamps();
        });
        DB::table('ingredients')->insert([
            ['id' => 3, 'company_id' => 1, 'name' => 'Milk', 'current_stock' => 12.5, 'is_active' => 1, 'created_at' => now(), 'updated_at' => now()],
            ['id' => 4, 'company_id' => 2, 'name' => 'Other tenant', 'current_stock' => 555, 'is_active' => 1, 'created_at' => now(), 'updated_at' => now()],
        ]);
        DB::table('product_recipes')->insert([
            ['company_id' => 1, 'product_id' => 5, 'ingredient_id' => 3, 'quantity_needed' => 2, 'is_active' => 1, 'created_at' => now(), 'updated_at' => now()],
            ['company_id' => 1, 'product_id' => 5, 'ingredient_id' => 4, 'quantity_needed' => 9, 'is_active' => 0, 'created_at' => now(), 'updated_at' => now()],
        ]);
        DB::table('restaurant_floors')->insert(['id' => 1, 'company_id' => 1, 'name' => 'Hall', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('restaurant_tables')->insert([
            ['id' => 1, 'company_id' => 1, 'floor_id' => 1, 'table_number' => 'T1', 'status' => 'occupied', 'created_at' => now(), 'updated_at' => now()],
            ['id' => 2, 'company_id' => 1, 'floor_id' => 1, 'table_number' => 'T2', 'status' => 'available', 'created_at' => now(), 'updated_at' => now()],
            ['id' => 3, 'company_id' => 1, 'floor_id' => 1, 'table_number' => 'T3', 'status' => 'occupied', 'created_at' => now(), 'updated_at' => now()],
        ]);
        DB::table('restaurant_orders')->insert([
            ['id' => 40, 'company_id' => 1, 'order_number' => 'W-40', 'status' => 'held', 'table_id' => 1, 'created_by' => 1, 'created_at' => now(), 'updated_at' => now()],
            ['id' => 41, 'company_id' => 1, 'order_number' => 'W-41', 'status' => 'paid', 'table_id' => 2, 'created_by' => 1, 'created_at' => now(), 'updated_at' => now()],
        ]);
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
            ->assertJsonPath('payload.stock.5', 7);
        $body = $response->json();
        $payload = $body['payload'];
        // Stock + recipes use the CLIENT keying ('ingredient-<id>' / bare product id).
        $this->assertSame(12.5, (float) $payload['stock']['ingredient-3']);
        $this->assertArrayNotHasKey('product:5', $payload['stock']);
        $this->assertEquals([['stock_id' => 'ingredient-3', 'quantity' => 2, 'version' => 1]], $payload['recipes']['5'],
            'only active recipe parts, keyed like the sale screens bake them');
        $ingredientIds = array_column($payload['catalog']['ingredients'], 'id');
        $this->assertContains('ingredient-3', $ingredientIds, 'ingredient rows are a findEntity target');
        $this->assertContains('5', $ingredientIds, 'direct product consumption resolves through catalog.ingredients too');
        $this->assertNotContains('ingredient-4', $ingredientIds, 'other tenant ingredients never leak');
        // Tables: rows live in the catalog; the top level carries CLAIMS only.
        $this->assertSame(['1', '2', '3'], array_map(fn ($t) => (string) $t['id'], $payload['catalog']['tables']));
        $this->assertSame('Hall', $payload['catalog']['floors'][0]['name']);
        $this->assertSame(['1', '3'], array_map('strval', array_keys($payload['tables'])), 'open order on T1 + manually occupied T3 are claimed; free T2 is not');
        $this->assertSame('40', $payload['tables']['1']['order_id']);
        $this->assertNull($payload['tables']['3']['order_id']);
        $this->assertArrayNotHasKey('table_number', $payload['tables']['1'], 'claims are not table rows');
        // Open orders only — settled history never rides in the snapshot.
        $this->assertArrayHasKey('40', $payload['orders']);
        $this->assertArrayNotHasKey('41', $payload['orders']);
        // Offline KOT routing block is always present (defaults when unset).
        $this->assertSame(['silent_print_enabled', 'kot_printer', 'kot_printer_device', 'counter_kot_enabled', 'counter_kot_printer',
            'kot_compact', 'kot_align_center', 'kot_left_margin_mm'], array_keys($payload['settings']['print']));
        $this->assertFalse($payload['settings']['print']['silent_print_enabled']);
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