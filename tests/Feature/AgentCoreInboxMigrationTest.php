<?php

namespace Tests\Feature;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class AgentCoreInboxMigrationTest extends TestCase
{
    private function initialMigration()
    {
        return require base_path('database/migrations/2026_10_06_000000_add_agent_core_inbox.php');
    }

    private function companionMigration()
    {
        return require base_path('database/migrations/2026_10_06_010000_add_agent_core_idempotency_key.php');
    }

    protected function setUp(): void
    {
        parent::setUp();
        Schema::dropAllTables();
    }

    public function test_fresh_schema_has_the_complete_inbox_contract(): void
    {
        Schema::create('companies', fn (Blueprint $table) => $table->id());
        $this->initialMigration()->up();
        $this->companionMigration()->up();

        $this->assertTrue(Schema::hasColumn('agent_core_events', 'idempotency_key'));
        $this->assertTrue(Schema::hasColumn('agent_core_events', 'content_hash'));
        $this->assertTrue(Schema::hasColumn('agent_core_events', 'legacy_backfilled'));
        $this->assertTrue(Schema::hasIndex('agent_core_events', 'agent_core_events_idempotency'));
        $this->assertTrue(Schema::hasIndex('agent_core_events', 'agent_core_events_idempotency_key'));
    }

    public function test_populated_legacy_schema_is_backfilled_before_its_key_index_is_added(): void
    {
        $this->createLegacyInbox(false);
        DB::table('agent_core_events')->insert([
            'company_id' => 9, 'device_uid' => 'counter-a', 'event_id' => 'old-1',
            'event_type' => 'sale.created', 'occurred_at' => '2026-10-01 10:00:00',
            'payload' => json_encode(['z' => 1, 'a' => ['b' => 2]]),
        ]);

        $this->companionMigration()->up();
        $row = DB::table('agent_core_events')->first();
        $this->assertSame('old-1', $row->idempotency_key);
        $this->assertSame(64, strlen($row->content_hash));
        $this->assertSame(1, (int) $row->legacy_backfilled);
        $this->assertSame('{"a":{"b":2},"z":1}', $row->payload);
        $this->assertTrue(Schema::hasIndex('agent_core_events', 'agent_core_events_idempotency_key'));
    }

    public function test_partial_schema_with_an_existing_key_still_gets_only_missing_hash_backfilled(): void
    {
        $this->createLegacyInbox(true);
        DB::table('agent_core_events')->insert([
            'company_id' => 9, 'device_uid' => 'counter-a', 'event_id' => 'old-2',
            'idempotency_key' => 'known-key', 'event_type' => 'caller.ring',
            'payload' => json_encode(['phone' => '03001234567']),
        ]);

        $this->companionMigration()->up();
        $row = DB::table('agent_core_events')->first();
        $this->assertSame('known-key', $row->idempotency_key);
        $this->assertSame(64, strlen($row->content_hash));
    }

    public function test_duplicate_legacy_event_ids_receive_distinct_deterministic_fallback_keys(): void
    {
        $this->createLegacyInbox(false);
        foreach ([1, 2] as $id) {
            DB::table('agent_core_events')->insert([
                'company_id' => 9, 'device_uid' => 'counter-a', 'event_id' => 'duplicated',
                'event_type' => 'sale.created', 'payload' => json_encode(['n' => $id]),
            ]);
        }

        $this->companionMigration()->up();
        $keys = DB::table('agent_core_events')->orderBy('id')->pluck('idempotency_key')->all();
        $this->assertSame(['legacy-1-duplicated', 'legacy-2-duplicated'], $keys);
        $this->assertFalse(Schema::hasIndex('agent_core_events', 'agent_core_events_idempotency'));
    }

    public function test_initial_migration_rollback_does_not_drop_a_preexisting_partial_inbox(): void
    {
        $this->createLegacyInbox(false);
        $migration = $this->initialMigration();
        $migration->up();
        $migration->down();

        $this->assertTrue(Schema::hasTable('agent_core_events'));
    }

    public function test_initial_migration_rollback_preserves_a_preexisting_company_flag(): void
    {
        Schema::create('companies', function (Blueprint $table) {
            $table->id();
            $table->boolean('agent_core_enabled')->default(false);
        });
        $migration = $this->initialMigration();
        $migration->up();
        $migration->down();

        $this->assertTrue(Schema::hasColumn('companies', 'agent_core_enabled'));
    }

    public function test_initial_migration_rollback_removes_only_its_fresh_company_flag(): void
    {
        Schema::create('companies', fn (Blueprint $table) => $table->id());
        $migration = $this->initialMigration();
        $migration->up();
        $this->assertTrue(Schema::hasColumn('companies', 'agent_core_enabled'));

        $migration->down();
        $this->assertFalse(Schema::hasColumn('companies', 'agent_core_enabled'));
    }

    private function createLegacyInbox(bool $withKey): void
    {
        Schema::create('agent_core_events', function (Blueprint $table) use ($withKey) {
            $table->id();
            $table->unsignedBigInteger('company_id');
            $table->string('device_uid', 64);
            $table->string('event_id', 64);
            if ($withKey) {
                $table->string('idempotency_key', 128)->nullable();
            }
            $table->string('event_type', 64);
            $table->timestamp('occurred_at')->nullable();
            $table->json('payload');
        });
    }
}