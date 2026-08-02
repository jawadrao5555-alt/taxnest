<?php

namespace Tests\Feature;

use App\Models\SystemSetting;
use App\Services\HeartbeatHealth;
use Carbon\Carbon;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * TASK 209: lock the stale-heartbeat detection + watchdog notify throttle.
 *
 * HeartbeatHealth::warning() drives the red admin banner and the 15-min
 * watchdog email in routes/console.php ('heartbeat-watchdog'). These tests
 * pin its edge cases and the 12h shouldNotify/markNotified throttle so a
 * regression can't silently kill the alerting.
 *
 * Time frozen at 2026-08-01 12:00 (Carbon::setTestNow).
 */
class HeartbeatWatchdogTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow(Carbon::parse('2026-08-01 12:00:00'));

        Schema::dropAllTables();

        Schema::create('system_settings', function (Blueprint $t) {
            $t->id();
            $t->string('key')->unique();
            $t->text('value')->nullable();
            $t->text('description')->nullable();
            $t->timestamps();
        });
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function setBeat(string $key, ?string $ago): void
    {
        SystemSetting::set($key, $ago);
    }

    private function minutesAgo(int $m): string
    {
        return now()->subMinutes($m)->toDateTimeString();
    }

    // ---------------- warning() edge cases ----------------

    public function test_fresh_heartbeats_produce_no_warning(): void
    {
        $this->setBeat('scheduler_last_heartbeat', $this->minutesAgo(5));
        $this->setBeat('queue_last_heartbeat', $this->minutesAgo(3));

        $this->assertNull(HeartbeatHealth::warning());
    }

    public function test_never_recorded_heartbeats_stay_quiet(): void
    {
        // Fresh install / dev environment: no rows at all.
        $this->assertNull(HeartbeatHealth::warning());
    }

    public function test_empty_string_heartbeats_treated_as_never_recorded(): void
    {
        $this->setBeat('scheduler_last_heartbeat', '');
        $this->setBeat('queue_last_heartbeat', '');

        $this->assertNull(HeartbeatHealth::warning());
    }

    public function test_unparseable_heartbeat_values_stay_quiet(): void
    {
        $this->setBeat('scheduler_last_heartbeat', 'not-a-date');
        $this->setBeat('queue_last_heartbeat', 'garbage');

        $this->assertNull(HeartbeatHealth::warning());
    }

    public function test_queue_stale_while_scheduler_alive(): void
    {
        $this->setBeat('scheduler_last_heartbeat', $this->minutesAgo(5));
        $this->setBeat('queue_last_heartbeat', $this->minutesAgo(20));

        $warn = HeartbeatHealth::warning();
        $this->assertNotNull($warn);
        $this->assertTrue($warn['queue_stale']);
        $this->assertFalse($warn['scheduler_stale']);
        $this->assertNotNull($warn['queue_at']);
        $this->assertNotNull($warn['scheduler_at']);
    }

    public function test_queue_exactly_at_threshold_is_not_stale(): void
    {
        // lt() comparison: exactly 15 min old is NOT stale yet.
        $this->setBeat('scheduler_last_heartbeat', $this->minutesAgo(5));
        $this->setBeat('queue_last_heartbeat', $this->minutesAgo(HeartbeatHealth::QUEUE_STALE_MINUTES));

        $this->assertNull(HeartbeatHealth::warning());
    }

    public function test_scheduler_stale_flags_scheduler(): void
    {
        $this->setBeat('scheduler_last_heartbeat', $this->minutesAgo(60));
        $this->setBeat('queue_last_heartbeat', $this->minutesAgo(3));

        $warn = HeartbeatHealth::warning();
        $this->assertNotNull($warn);
        $this->assertTrue($warn['scheduler_stale']);
        $this->assertFalse($warn['queue_stale']);
    }

    public function test_queue_missing_while_scheduler_alive_is_stale(): void
    {
        // Queue heartbeat never recorded but scheduler is demonstrably alive:
        // the worker cron probably never existed — must warn.
        $this->setBeat('scheduler_last_heartbeat', $this->minutesAgo(5));

        $warn = HeartbeatHealth::warning();
        $this->assertNotNull($warn);
        $this->assertTrue($warn['queue_stale']);
        $this->assertFalse($warn['scheduler_stale']);
        $this->assertNull($warn['queue_at']);
    }

    public function test_queue_missing_while_scheduler_stale_flags_only_scheduler(): void
    {
        // Scheduler itself is dead — a missing queue beat proves nothing extra;
        // scheduler_stale carries the alert.
        $this->setBeat('scheduler_last_heartbeat', $this->minutesAgo(120));

        $warn = HeartbeatHealth::warning();
        $this->assertNotNull($warn);
        $this->assertTrue($warn['scheduler_stale']);
        $this->assertFalse($warn['queue_stale']);
    }

    public function test_both_stale_flags_both(): void
    {
        $this->setBeat('scheduler_last_heartbeat', $this->minutesAgo(90));
        $this->setBeat('queue_last_heartbeat', $this->minutesAgo(90));

        $warn = HeartbeatHealth::warning();
        $this->assertNotNull($warn);
        $this->assertTrue($warn['queue_stale']);
        $this->assertTrue($warn['scheduler_stale']);
    }

    public function test_warning_survives_missing_table(): void
    {
        // Exception-safe: liveness bookkeeping must never break page render.
        Schema::drop('system_settings');

        $this->assertNull(HeartbeatHealth::warning());
    }

    // ---------------- shouldNotify / markNotified throttle ----------------

    public function test_should_notify_when_never_notified(): void
    {
        $this->assertTrue(HeartbeatHealth::shouldNotify());
    }

    public function test_should_not_notify_within_12_hours(): void
    {
        SystemSetting::set('heartbeat_watchdog_last_notified_at', now()->subHours(11)->toDateTimeString());

        $this->assertFalse(HeartbeatHealth::shouldNotify());
    }

    public function test_should_notify_again_after_12_hours(): void
    {
        SystemSetting::set('heartbeat_watchdog_last_notified_at', now()->subHours(13)->toDateTimeString());

        $this->assertTrue(HeartbeatHealth::shouldNotify());
    }

    public function test_mark_notified_starts_the_throttle(): void
    {
        $this->assertTrue(HeartbeatHealth::shouldNotify());

        HeartbeatHealth::markNotified();

        $this->assertFalse(HeartbeatHealth::shouldNotify());
        $this->assertSame(
            now()->toDateTimeString(),
            SystemSetting::get('heartbeat_watchdog_last_notified_at')
        );
    }

    public function test_mark_notified_updates_existing_row(): void
    {
        SystemSetting::set('heartbeat_watchdog_last_notified_at', now()->subDays(2)->toDateTimeString());

        HeartbeatHealth::markNotified();

        $this->assertSame(1, SystemSetting::where('key', 'heartbeat_watchdog_last_notified_at')->count());
        $this->assertFalse(HeartbeatHealth::shouldNotify());
    }

    public function test_unparseable_notify_timestamp_allows_notify(): void
    {
        SystemSetting::set('heartbeat_watchdog_last_notified_at', 'garbage');

        $this->assertTrue(HeartbeatHealth::shouldNotify());
    }
}
