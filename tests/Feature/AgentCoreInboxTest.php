<?php

namespace Tests\Feature;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class AgentCoreInboxTest extends TestCase
{
    private const KEY_ONE = 'agent-core-company-one';
    private const KEY_TWO = 'agent-core-company-two';

    protected function setUp(): void
    {
        parent::setUp();
        Schema::dropAllTables();

        Schema::create('companies', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('agent_api_key')->nullable();
            $table->boolean('agent_enabled')->default(true);
            $table->boolean('agent_core_enabled')->default(false);
            $table->softDeletes();
            $table->timestamps();
        });
        Schema::create('agent_core_events', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('company_id');
            $table->string('device_uid', 64);
            $table->string('event_id', 64);
            $table->string('idempotency_key', 128);
            $table->string('event_type', 64);
            $table->timestamp('occurred_at')->nullable();
            $table->json('payload');
            $table->string('content_hash', 64);
            $table->timestamps();
            $table->unique(['company_id', 'device_uid', 'event_id'], 'agent_core_events_idempotency');
            $table->unique(['company_id', 'device_uid', 'idempotency_key'], 'agent_core_events_idempotency_key');
        });
        Schema::create('pos_agent_devices', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('company_id');
            $table->string('device_uid', 64);
            $table->timestamps();
            $table->unique(['company_id', 'device_uid']);
        });

        DB::table('companies')->insert([
            [
                'id' => 1, 'name' => 'Enabled shop', 'agent_api_key' => self::KEY_ONE,
                'agent_enabled' => true, 'agent_core_enabled' => true,
                'created_at' => now(), 'updated_at' => now(),
            ],
            [
                'id' => 2, 'name' => 'Other shop', 'agent_api_key' => self::KEY_TWO,
                'agent_enabled' => true, 'agent_core_enabled' => true,
                'created_at' => now(), 'updated_at' => now(),
            ],
        ]);
        DB::table('pos_agent_devices')->insert([
            ['company_id' => 1, 'device_uid' => 'counter-1', 'created_at' => now(), 'updated_at' => now()],
            ['company_id' => 1, 'device_uid' => 'counter-2', 'created_at' => now(), 'updated_at' => now()],
            ['company_id' => 2, 'device_uid' => 'counter-1', 'created_at' => now(), 'updated_at' => now()],
        ]);
    }

    private function request(string $key, array $events, string $device = 'counter-1')
    {
        return $this->withToken($key)->postJson('/api/agent/v2/events', [
            'version' => 1,
            'device_uid' => $device,
            'events' => $events,
        ]);
    }

    private function event(string $id = 'event-1'): array
    {
        return [
            'event_id' => $id,
            'event_type' => 'sale.created',
            'occurred_at' => now()->toIso8601String(),
            'idempotency_key' => 'idem-' . $id,
            'payload' => ['local_sale_id' => 'L-100'],
        ];
    }

    public function test_core_is_explicitly_enabled_and_advertises_its_bounded_contract(): void
    {
        DB::table('companies')->where('id', 1)->update(['agent_core_enabled' => false]);
        $this->withToken(self::KEY_ONE)->getJson('/api/agent/v2/capabilities')->assertForbidden();

        DB::table('companies')->where('id', 1)->update(['agent_core_enabled' => true]);
        $this->withToken(self::KEY_ONE)->getJson('/api/agent/v2/capabilities')
            ->assertOk()
            ->assertJsonPath('version', 1)
            ->assertJsonPath('events.max_events', 100)
            ->assertJsonPath('events.max_payload_bytes', 16384);
    }

    public function test_events_are_idempotently_acknowledged_in_their_company_and_device_scope(): void
    {
        $event = $this->event();
        $first = $this->request(self::KEY_ONE, [$event])
            ->assertOk()->assertJson([
                'ok' => true, 'acknowledged_ids' => ['event-1'],
                'received_count' => 1, 'stored_count' => 1, 'duplicate_count' => 0,
            ]);

        $this->request(self::KEY_ONE, [$event])
            ->assertOk()->assertJson([
                'acknowledged_ids' => ['event-1'],
                'received_count' => 1, 'stored_count' => 0, 'duplicate_count' => 1,
            ]);

        // The same identifier is independent for another device and tenant.
        $this->request(self::KEY_ONE, [$event], 'counter-2')->assertOk()->assertJson(['stored_count' => 1]);
        $this->request(self::KEY_TWO, [$event])->assertOk()->assertJson(['stored_count' => 1]);

        $this->assertSame(3, DB::table('agent_core_events')->count());
        $this->assertSame(1, DB::table('agent_core_events')->where('company_id', 1)->where('device_uid', 'counter-1')->count());
    }

    public function test_events_require_a_device_registered_to_the_authenticated_company(): void
    {
        $this->request(self::KEY_ONE, [$this->event()], 'unknown-counter')
            ->assertForbidden()
            ->assertJson(['ok' => false, 'error' => 'device_not_registered']);

        DB::table('pos_agent_devices')->where('company_id', 1)->where('device_uid', 'counter-2')->delete();
        $this->request(self::KEY_ONE, [$this->event()], 'counter-2')
            ->assertForbidden()
            ->assertJsonPath('error', 'device_not_registered');

        Schema::drop('pos_agent_devices');
        $this->request(self::KEY_ONE, [$this->event()])
            ->assertStatus(503)
            ->assertJsonPath('error', 'device_registry_unavailable');

        $this->assertSame(0, DB::table('agent_core_events')->count());
    }

    public function test_unknown_types_oversize_payloads_and_batches_over_one_hundred_are_refused(): void
    {
        $badType = $this->event();
        $badType['event_type'] = 'sale.deleted';
        $this->request(self::KEY_ONE, [$badType])->assertStatus(422);

        $large = $this->event();
        $large['payload'] = ['data' => str_repeat('x', 16385)];
        $this->request(self::KEY_ONE, [$large])->assertStatus(422);

        $this->request(self::KEY_ONE, array_fill(0, 101, $this->event()))->assertStatus(422);
        $this->assertSame(0, DB::table('agent_core_events')->count());
    }

    public function test_event_id_accepts_its_canonical_sixty_four_character_limit_only(): void
    {
        $this->request(self::KEY_ONE, [$this->event(str_repeat('a', 64))])->assertOk();
        $this->request(self::KEY_ONE, [$this->event(str_repeat('b', 65))])->assertStatus(422);
    }

    public function test_reusing_an_event_or_idempotency_key_with_different_content_is_a_conflict(): void
    {
        $this->request(self::KEY_ONE, [$this->event('event-a')])->assertOk();

        $changed = $this->event('event-a');
        $changed['payload'] = ['local_sale_id' => 'L-101'];
        $this->request(self::KEY_ONE, [$changed])
            ->assertStatus(409)->assertJsonPath('ok', false);

        $otherIdSameKey = $this->event('event-b');
        $otherIdSameKey['idempotency_key'] = 'idem-event-a';
        $this->request(self::KEY_ONE, [$otherIdSameKey])->assertStatus(409);
        $this->assertSame(1, DB::table('agent_core_events')->count());
    }

    public function test_a_backfilled_nested_legacy_event_acknowledges_an_exact_canonical_node_retry(): void
    {
        Schema::drop('agent_core_events');
        Schema::create('agent_core_events', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('company_id');
            $table->string('device_uid', 64);
            $table->string('event_id', 64);
            $table->string('event_type', 64);
            $table->timestamp('occurred_at')->nullable();
            $table->json('payload');
        });
        $nodeOccurredAt = '2026-10-06T12:34:56.789Z';
        $legacyStoredAt = '2026-10-06 12:34:56';
        $payload = ['object' => ['z' => 3, 'a' => 1], 'items' => [['b' => 2, 'a' => 1]]];
        DB::table('agent_core_events')->insert([
            'company_id' => 1, 'device_uid' => 'counter-1', 'event_id' => 'legacy-node-1',
            'event_type' => 'sale.created', 'occurred_at' => $legacyStoredAt, 'payload' => json_encode($payload),
        ]);
        (require base_path('database/migrations/2026_10_06_010000_add_agent_core_idempotency_key.php'))->up();
        $this->assertSame(1, (int) DB::table('agent_core_events')->value('legacy_backfilled'));

        $nodeEvent = [
            'event_id' => 'legacy-node-1',
            'event_type' => 'sale.created',
            'occurred_at' => $nodeOccurredAt,
            'idempotency_key' => 'legacy-node-1',
            // Ordering differs intentionally: canonicalization must recurse
            // through nested objects while preserving list order.
            'payload' => ['items' => [['a' => 1, 'b' => 2]], 'object' => ['a' => 1, 'z' => 3]],
        ];
        $this->request(self::KEY_ONE, [$nodeEvent])
            ->assertOk()
            ->assertJson([
                'acknowledged_ids' => ['legacy-node-1'],
                'stored_count' => 0,
                'duplicate_count' => 1,
            ]);

        $nodeEvent['payload']['object']['z'] = 4;
        $this->request(self::KEY_ONE, [$nodeEvent])->assertStatus(409);
    }
}