<?php

namespace Tests\Feature;

use Tests\TestCase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;

/**
 * Silent-print long-poll (agent v1.6.2, ZFC instant-print request Aug 2026).
 *
 * Covers:
 *  - pending job → answered instantly with the claimed job (no hold)
 *  - empty queue + ?wait → request is held, responds held:true
 *  - concurrency cap reached → instant short-poll answer (held:false)
 *  - legacy agent (no wait param) → unchanged instant behavior
 *  - two agents / double poll for one company → job claimed exactly once
 */
class PrintJobLongPollTest extends TestCase
{
    private string $agentKey = 'test-agent-key-longpoll';

    protected function setUp(): void
    {
        parent::setUp();

        Schema::dropAllTables();

        Schema::create('companies', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('company_status')->default('approved');
            $table->string('agent_api_key')->nullable();
            $table->boolean('agent_enabled')->default(false);
            $table->timestamp('agent_last_seen')->nullable();
            $table->text('pos_printer_settings')->nullable();
            $table->softDeletes();
            $table->timestamps();
        });

        Schema::create('pos_print_jobs', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('company_id');
            $table->string('type');
            $table->string('target_printer')->nullable();
            $table->unsignedBigInteger('transaction_id')->nullable();
            $table->unsignedBigInteger('restaurant_order_id')->nullable();
            $table->string('render_query')->nullable();
            $table->string('status')->default('pending');
            $table->string('claim_token')->nullable();
            $table->unsignedInteger('attempts')->default(0);
            $table->text('error')->nullable();
            $table->timestamps();
        });

        DB::table('companies')->insert([
            'id' => 1,
            'name' => 'LongPoll Test Co',
            'agent_api_key' => $this->agentKey,
            'agent_enabled' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        Cache::flush();
        // Deployment-tunable cap (config/print.php, default 3 for shared-host
        // headroom); pin to 10 here so the boundary tests are explicit.
        config(['print.longpoll_max_holds' => 10, 'print.longpoll_max_wait' => 8]);
    }

    private function poll(string $query = ''): \Illuminate\Testing\TestResponse
    {
        return $this->getJson('/api/agent/print-jobs' . $query, [
            'Authorization' => 'Bearer ' . $this->agentKey,
        ]);
    }

    /**
     * Mark the shop as "currently printing". Holding a worker is only offered
     * inside this window (see config/print.php) — a quiet shop short-polls.
     */
    private function armPrintingActivity(): void
    {
        Cache::put('print_recent_activity_1', 1, now()->addMinutes(20));
    }

    private function enqueueJob(): int
    {
        return DB::table('pos_print_jobs')->insertGetId([
            'company_id' => 1,
            'type' => 'bill',
            'target_printer' => 'TestPrinter',
            'transaction_id' => 1,
            'status' => 'pending',
            'attempts' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function test_pending_job_is_claimed_instantly_without_hold(): void
    {
        $jobId = $this->enqueueJob();

        $start = microtime(true);
        $res = $this->poll('?wait=5');
        $elapsed = microtime(true) - $start;

        $res->assertOk()->assertJson(['ok' => true, 'count' => 1, 'held' => false]);
        $this->assertSame($jobId, $res->json('jobs.0.id'));
        $this->assertLessThan(1.0, $elapsed, 'pending job must be answered without holding');
        $this->assertSame('printing', DB::table('pos_print_jobs')->where('id', $jobId)->value('status'));
    }

    public function test_empty_queue_with_wait_holds_and_reports_held(): void
    {
        $this->armPrintingActivity();
        $start = microtime(true);
        $res = $this->poll('?wait=1');
        $elapsed = microtime(true) - $start;

        $res->assertOk()->assertJson(['ok' => true, 'count' => 0, 'held' => true]);
        $this->assertGreaterThanOrEqual(0.9, $elapsed, 'empty queue with wait must be held');
    }

    public function test_concurrency_cap_falls_back_to_instant_short_poll(): void
    {
        // Must be an ACTIVELY printing shop, otherwise the quiet-shop gate
        // answers before slot acquisition is ever reached and this test would
        // pass even with admission control removed entirely.
        $this->armPrintingActivity();

        // Occupy all 10 slots (atomic Cache::add slot keys on non-mysql).
        for ($i = 0; $i < 10; $i++) {
            Cache::add('print_jobs_longpoll_slot_' . $i, 1, 60);
        }

        $start = microtime(true);
        $res = $this->poll('?wait=5');
        $elapsed = microtime(true) - $start;

        $res->assertOk()->assertJson(['ok' => true, 'count' => 0, 'held' => false]);
        $this->assertLessThan(0.9, $elapsed, 'over the cap the poll must answer instantly');
    }

    public function test_slot_acquisition_is_bounded_and_atomic(): void
    {
        // Acquire slots directly: exactly 10 succeed, the 11th is refused.
        $controller = app(\App\Http\Controllers\AgentController::class);
        $m = new \ReflectionMethod($controller, 'acquireLongPollSlot');
        $m->setAccessible(true);

        $slots = [];
        for ($i = 0; $i < 11; $i++) {
            $slots[] = $m->invoke($controller);
        }
        $granted = array_filter($slots, fn ($s) => $s !== null);
        $this->assertCount(10, $granted, 'exactly the cap may hold concurrently');
        $this->assertCount(10, array_unique($granted), 'each hold gets a DISTINCT slot');
        $this->assertNull($slots[10], 'the 11th concurrent hold must be refused');

        // Releasing one slot makes it available again.
        $r = new \ReflectionMethod($controller, 'releaseLongPollSlot');
        $r->setAccessible(true);
        $r->invoke($controller, $slots[3]);
        $this->assertNotNull($m->invoke($controller), 'released slot must be reusable');
    }

    public function test_default_cap_is_conservative_for_shared_hosting(): void
    {
        config(['print.longpoll_max_holds' => null]); // fall back to default
        $controller = app(\App\Http\Controllers\AgentController::class);
        $m = new \ReflectionMethod($controller, 'acquireLongPollSlot');
        $m->setAccessible(true);
        $granted = [];
        for ($i = 0; $i < 5; $i++) {
            $granted[] = $m->invoke($controller);
        }
        $this->assertCount(1, array_filter($granted), 'default cap must hold at most ONE worker');
    }

    public function test_quiet_shop_is_never_held(): void
    {
        // No recent print activity: the shared host's tiny worker pool must not
        // be tied up by an agent polling a closed shop through the night.
        $start = microtime(true);
        $res = $this->poll('?wait=5');
        $elapsed = microtime(true) - $start;

        $res->assertOk()->assertJson(['ok' => true, 'count' => 0, 'held' => false]);
        $this->assertLessThan(0.9, $elapsed, 'a quiet shop must be answered instantly, never held');
    }

    public function test_claiming_a_job_arms_the_holding_window(): void
    {
        // A real job proves the shop is printing, so the NEXT empty poll may
        // hold — that is what keeps a busy rush printing instantly.
        $this->enqueueJob();
        $this->poll()->assertOk()->assertJson(['count' => 1]);

        $start = microtime(true);
        $res = $this->poll('?wait=1');
        $elapsed = microtime(true) - $start;

        $res->assertOk()->assertJson(['count' => 0, 'held' => true]);
        $this->assertGreaterThanOrEqual(0.9, $elapsed);
    }

    public function test_wait_zero_config_disables_holding(): void
    {
        config(['print.longpoll_max_wait' => 0]);
        $start = microtime(true);
        $res = $this->poll('?wait=8');
        $elapsed = microtime(true) - $start;
        $res->assertOk()->assertJson(['count' => 0, 'held' => false]);
        $this->assertLessThan(0.9, $elapsed, 'wait cap 0 must answer instantly (pure short-poll mode)');
    }

    public function test_hold_slot_is_released_after_the_hold(): void
    {
        $this->armPrintingActivity();
        $this->poll('?wait=1')->assertOk()->assertJson(['held' => true]);
        for ($i = 0; $i < 10; $i++) {
            $this->assertNull(Cache::get('print_jobs_longpoll_slot_' . $i), "slot $i must be free after the hold");
        }
    }

    public function test_legacy_agent_without_wait_param_is_unchanged(): void
    {
        $start = microtime(true);
        $res = $this->poll();
        $elapsed = microtime(true) - $start;

        $res->assertOk()->assertJson(['ok' => true, 'count' => 0, 'held' => false]);
        $this->assertLessThan(0.9, $elapsed);

        $jobId = $this->enqueueJob();
        $res2 = $this->poll();
        $res2->assertOk()->assertJson(['count' => 1, 'held' => false]);
        $this->assertSame($jobId, $res2->json('jobs.0.id'));
    }

    public function test_job_is_claimed_exactly_once_across_repeated_polls(): void
    {
        $this->enqueueJob();

        $first = $this->poll('?wait=1');
        $first->assertOk()->assertJson(['count' => 1]);

        // Second poll (same or another agent process for this company) must not
        // re-claim the job — it is already 'printing'.
        Cache::forget('print_jobs_housekeeping_1'); // fresh housekeeping pass must not requeue it either
        $second = $this->poll();
        $second->assertOk()->assertJson(['count' => 0]);

        $this->assertSame(1, DB::table('pos_print_jobs')->where('status', 'printing')->count());
    }

    public function test_wakes_up_and_claims_job_enqueued_mid_hold(): void
    {
        // Bind a controller subclass whose pause hook enqueues a pending job
        // on the SECOND tick — a real job arriving while the request is held.
        $this->app->bind(
            \App\Http\Controllers\AgentController::class,
            MidHoldEnqueueAgentController::class
        );
        $this->armPrintingActivity();

        $start = microtime(true);
        $res = $this->poll('?wait=5');
        $elapsed = microtime(true) - $start;

        $res->assertOk()->assertJson(['ok' => true, 'count' => 1, 'held' => true]);
        $this->assertSame('bill', $res->json('jobs.0.type'));
        $this->assertLessThan(2.5, $elapsed, 'must wake up on the next 250ms tick, not sit out the full 5s wait');
        $this->assertSame(1, DB::table('pos_print_jobs')->where('status', 'printing')->count());
    }

    public function test_done_rows_do_not_end_the_hold(): void
    {
        $this->armPrintingActivity();
        DB::table('pos_print_jobs')->insert([
            'company_id' => 1, 'type' => 'bill', 'target_printer' => 'T',
            'transaction_id' => 2, 'status' => 'done', 'attempts' => 1,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $start = microtime(true);
        $res = $this->poll('?wait=1');
        $elapsed = microtime(true) - $start;
        $res->assertOk()->assertJson(['count' => 0, 'held' => true]);
        $this->assertGreaterThanOrEqual(0.9, $elapsed);
    }
}

/**
 * Test double: identical to the real controller except the hold-loop pause
 * hook enqueues a pending print job on its second invocation, simulating a
 * cashier hitting Print while the agent's long-poll is being held.
 */
class MidHoldEnqueueAgentController extends \App\Http\Controllers\AgentController
{
    private int $ticks = 0;

    protected function longPollPause(): void
    {
        usleep(50000); // faster ticks keep the test quick
        $this->ticks++;
        if ($this->ticks === 2) {
            DB::table('pos_print_jobs')->insert([
                'company_id' => 1,
                'type' => 'bill',
                'target_printer' => 'TestPrinter',
                'transaction_id' => 99,
                'status' => 'pending',
                'attempts' => 0,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }
}
