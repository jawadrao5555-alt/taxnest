<?php

namespace Tests\Feature;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * LAN Mode — offline caller rings replayed by the Desktop Agent.
 *
 * Net down: the Caller ID phone can only reach the shop's own PC, so the agent
 * keeps the rings and forwards them here once the line is back. What must hold:
 *
 *  1. A replayed ring is stored at the time it ACTUALLY rang. The sale-screen
 *     poll only surfaces rings from the last two minutes, so reconnecting must
 *     never make an hour-old call pop up on the counter.
 *  2. Replay is idempotent on offline_uuid — the agent retries until we
 *     acknowledge, so the same batch WILL arrive twice.
 *  3. The agent always learns which uuids it may drop, even when the shop's
 *     Caller ID is switched off, or its buffer would grow forever.
 *  4. A ring that already reached the cloud live is not stored a second time.
 */
class AgentCallerReplayTest extends TestCase
{
    private const KEY = 'agent-key-lan-test';

    protected function setUp(): void
    {
        parent::setUp();

        Schema::dropAllTables();

        Schema::create('companies', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('product_type')->nullable();
            $table->string('status')->default('approved');
            $table->string('company_status')->default('active');
            $table->string('agent_api_key')->nullable();
            $table->boolean('agent_enabled')->default(true);
            $table->timestamp('agent_last_seen')->nullable();
            $table->string('agent_version')->nullable();
            $table->boolean('caller_id_enabled')->default(true);
            $table->boolean('is_internal_account')->default(true);
            $table->text('feature_flags')->nullable();
            $table->softDeletes();
            $table->timestamps();
        });

        Schema::create('pos_caller_events', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('company_id');
            $table->string('offline_uuid', 64)->nullable();
            $table->string('phone', 20)->nullable();
            $table->string('caller_name', 120)->nullable();
            $table->string('source', 12)->default('sim');
            $table->dateTime('ring_at');
            $table->timestamp('cleared_at')->nullable();
            $table->timestamp('created_at')->nullable();
            $table->unique(['company_id', 'offline_uuid'], 'pos_caller_events_offline_uuid_unique');
        });

        DB::table('companies')->insert([
            'id' => 1,
            'name' => 'Bismillah Karyana',
            'product_type' => 'pos',
            'agent_api_key' => self::KEY,
            'agent_enabled' => true,
            'caller_id_enabled' => true,
            'is_internal_account' => true,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function replay(array $events)
    {
        return $this->withHeaders(['Authorization' => 'Bearer ' . self::KEY])
            ->postJson('/api/agent/caller-events', ['events' => $events]);
    }

    private function ring(string $uuid, string $number, int $minutesAgo): array
    {
        return [
            'uuid' => $uuid,
            'number' => $number,
            'name' => 'Ali Raza',
            'source' => 'sim',
            'at' => now()->subMinutes($minutesAgo)->getTimestampMs(),
        ];
    }

    public function test_a_replayed_ring_keeps_the_time_it_actually_rang(): void
    {
        $this->replay([$this->ring('u-1', '0300-1234567', 40)])
            ->assertOk()
            ->assertJson(['ok' => true, 'stored' => 1]);

        $row = DB::table('pos_caller_events')->first();
        $this->assertSame('923001234567', $row->phone, 'stored in PkPhone form');
        $this->assertSame('u-1', $row->offline_uuid);

        // Both stamps sit ~40 minutes back. created_at is what the sale-screen
        // poll filters on (last 120 s), so an old call cannot pop up now.
        $this->assertEqualsWithDelta(40 * 60, abs(now()->diffInSeconds($row->created_at)), 90);
        $this->assertEqualsWithDelta(40 * 60, abs(now()->diffInSeconds($row->ring_at)), 90);
    }

    public function test_the_same_batch_twice_stores_one_row(): void
    {
        $batch = [$this->ring('u-1', '03001234567', 30), $this->ring('u-2', '03007654321', 25)];

        $first = $this->replay($batch)->assertOk();
        $this->assertSame(2, $first->json('stored'));

        $second = $this->replay($batch)->assertOk();
        $this->assertSame(0, $second->json('stored'), 'a retry must store nothing');
        // The agent still needs the acknowledgement, or it retries forever.
        $this->assertSame(['u-1', 'u-2'], $second->json('accepted'));

        $this->assertSame(2, DB::table('pos_caller_events')->count());
    }

    public function test_a_ring_that_already_reached_the_cloud_is_not_doubled(): void
    {
        $ringAt = now()->subMinutes(10);
        DB::table('pos_caller_events')->insert([
            'company_id' => 1,
            'offline_uuid' => null,          // came in live over the internet
            'phone' => '923001234567',
            'caller_name' => 'Ali Raza',
            'source' => 'sim',
            'ring_at' => $ringAt,
            'created_at' => $ringAt,
        ]);

        $this->replay([[
            'uuid' => 'u-9',
            'number' => '03001234567',
            'name' => 'Ali Raza',
            'source' => 'sim',
            'at' => $ringAt->copy()->addSeconds(4)->getTimestampMs(),
        ]])->assertOk()->assertJson(['stored' => 0]);

        $this->assertSame(1, DB::table('pos_caller_events')->count());
    }

    public function test_caller_id_off_acknowledges_but_stores_nothing(): void
    {
        DB::table('companies')->where('id', 1)->update(['caller_id_enabled' => false]);

        $res = $this->replay([$this->ring('u-1', '03001234567', 5)])->assertOk();
        $this->assertSame(0, $res->json('stored'));
        $this->assertSame('disabled', $res->json('reason'));
        $this->assertSame(['u-1'], $res->json('accepted'), 'the agent must still drop them');
        $this->assertSame(0, DB::table('pos_caller_events')->count());
    }

    public function test_a_wrong_phone_clock_cannot_post_date_a_ring(): void
    {
        $this->replay([[
            'uuid' => 'u-future',
            'number' => '03001234567',
            'at' => now()->addHours(6)->getTimestampMs(),
        ]])->assertOk()->assertJson(['stored' => 1]);

        $row = DB::table('pos_caller_events')->first();
        $this->assertTrue(
            \Carbon\Carbon::parse($row->ring_at)->lte(now()->addMinute()),
            'a future timestamp falls back to now'
        );
    }

    public function test_an_empty_ring_and_a_bad_key_are_refused(): void
    {
        // Neither a number nor a name = nothing a cashier could act on.
        $this->replay([['uuid' => 'u-empty']])->assertOk()->assertJson(['stored' => 0]);
        $this->assertSame(0, DB::table('pos_caller_events')->count());

        // uuid is the whole idempotency contract — it may not be optional.
        $this->replay([['number' => '03001234567']])->assertStatus(422);

        $this->withHeaders(['Authorization' => 'Bearer wrong-key'])
            ->postJson('/api/agent/caller-events', ['events' => []])
            ->assertStatus(401);
    }
}
