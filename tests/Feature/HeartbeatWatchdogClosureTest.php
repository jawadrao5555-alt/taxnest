<?php

namespace Tests\Feature;

use App\Models\AdminUser;
use App\Models\SystemSetting;
use Carbon\Carbon;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * TASK 210: lock the scheduled 'heartbeat-watchdog' closure itself
 * (routes/console.php) — admin email pluck, Mail::raw send, markNotified,
 * and MailHealth::recordFailure on exception.
 *
 * NOTE: Mail::fake() cannot be used here — MailFake::raw() is an explicit
 * no-op in Laravel, so a faked mailer records nothing for Mail::raw sends.
 * Instead we run the real mail pipeline against the 'array' transport and
 * assert on the captured Symfony messages, which also proves the closure's
 * to()/subject() wiring. The exception path uses a facade mock that throws.
 *
 * Time frozen at 2026-08-01 12:00 (Carbon::setTestNow).
 */
class HeartbeatWatchdogClosureTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow(Carbon::parse('2026-08-01 12:00:00'));

        config(['mail.default' => 'array']);

        Schema::dropAllTables();

        Schema::create('system_settings', function (Blueprint $t) {
            $t->id();
            $t->string('key')->unique();
            $t->text('value')->nullable();
            $t->text('description')->nullable();
            $t->timestamps();
        });

        Schema::create('admin_users', function (Blueprint $t) {
            $t->id();
            $t->string('name')->nullable();
            $t->string('email')->nullable();
            $t->string('password')->nullable();
            $t->string('role')->nullable();
            $t->timestamps();
        });
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    // ---------------- helpers ----------------

    /** Run the scheduled 'heartbeat-watchdog' closure exactly as cron would. */
    private function runWatchdog(): void
    {
        $event = collect($this->app->make(Schedule::class)->events())
            ->first(fn ($e) => ($e->description ?? null) === 'heartbeat-watchdog');

        $this->assertNotNull($event, "Scheduled event 'heartbeat-watchdog' not found — was it renamed/removed?");

        $event->run($this->app);
    }

    private function makeQueueStale(): void
    {
        // Scheduler alive (cron fires) but queue worker dead for 20 min.
        SystemSetting::set('scheduler_last_heartbeat', now()->subMinutes(5)->toDateTimeString());
        SystemSetting::set('queue_last_heartbeat', now()->subMinutes(20)->toDateTimeString());
    }

    /** @return \Symfony\Component\Mailer\SentMessage[] */
    private function sentMessages(): array
    {
        return Mail::mailer('array')->getSymfonyTransport()->messages()->all();
    }

    // ---------------- send path ----------------

    public function test_queue_stale_emails_all_admins_and_marks_notified(): void
    {
        $this->makeQueueStale();
        AdminUser::create(['name' => 'A', 'email' => 'a@example.com', 'password' => 'x', 'role' => 'super_admin']);
        AdminUser::create(['name' => 'B', 'email' => 'b@example.com', 'password' => 'x', 'role' => 'admin']);
        // Duplicate + blank + null emails must be filtered out, never crash.
        AdminUser::create(['name' => 'Dup', 'email' => 'a@example.com', 'password' => 'x', 'role' => 'admin']);
        AdminUser::create(['name' => 'Blank', 'email' => '', 'password' => 'x', 'role' => 'admin']);
        AdminUser::create(['name' => 'Null', 'email' => null, 'password' => 'x', 'role' => 'admin']);

        $this->runWatchdog();

        $messages = $this->sentMessages();
        $this->assertCount(1, $messages, 'Exactly one alert email expected');

        $email = $messages[0]->getOriginalMessage();
        $to = collect($email->getTo())->map(fn ($a) => $a->getAddress())->sort()->values()->all();
        $this->assertSame(['a@example.com', 'b@example.com'], $to);
        $this->assertSame('WARNING: TaxNest queue worker has stopped', $email->getSubject());
        $this->assertStringContainsString('queue worker', $email->getTextBody());

        // markNotified: throttle key persisted with the frozen timestamp.
        $this->assertSame(
            now()->toDateTimeString(),
            SystemSetting::get('heartbeat_watchdog_last_notified_at')
        );
        // MailHealth success bookkeeping ran too.
        $this->assertNotEmpty(SystemSetting::get('mail_health_last_success_at'));
    }

    // ---------------- skip paths ----------------

    public function test_no_email_when_heartbeats_are_fresh(): void
    {
        SystemSetting::set('scheduler_last_heartbeat', now()->subMinutes(5)->toDateTimeString());
        SystemSetting::set('queue_last_heartbeat', now()->subMinutes(3)->toDateTimeString());
        AdminUser::create(['name' => 'A', 'email' => 'a@example.com', 'password' => 'x', 'role' => 'admin']);

        $this->runWatchdog();

        $this->assertCount(0, $this->sentMessages());
        $this->assertNull(SystemSetting::get('heartbeat_watchdog_last_notified_at'));
    }

    public function test_no_email_when_only_scheduler_is_stale(): void
    {
        // Watchdog covers ONLY a dead queue worker; a dead scheduler can't
        // run this closure anyway, so scheduler_stale alone must not email.
        SystemSetting::set('scheduler_last_heartbeat', now()->subMinutes(90)->toDateTimeString());
        SystemSetting::set('queue_last_heartbeat', now()->subMinutes(3)->toDateTimeString());
        AdminUser::create(['name' => 'A', 'email' => 'a@example.com', 'password' => 'x', 'role' => 'admin']);

        $this->runWatchdog();

        $this->assertCount(0, $this->sentMessages());
    }

    public function test_no_email_within_12h_throttle(): void
    {
        $this->makeQueueStale();
        SystemSetting::set('heartbeat_watchdog_last_notified_at', now()->subHours(11)->toDateTimeString());
        AdminUser::create(['name' => 'A', 'email' => 'a@example.com', 'password' => 'x', 'role' => 'admin']);

        $this->runWatchdog();

        $this->assertCount(0, $this->sentMessages());
        // Throttle timestamp untouched (not refreshed by a skipped run).
        $this->assertSame(
            now()->subHours(11)->toDateTimeString(),
            SystemSetting::get('heartbeat_watchdog_last_notified_at')
        );
    }

    public function test_emails_again_after_throttle_expires(): void
    {
        $this->makeQueueStale();
        SystemSetting::set('heartbeat_watchdog_last_notified_at', now()->subHours(13)->toDateTimeString());
        AdminUser::create(['name' => 'A', 'email' => 'a@example.com', 'password' => 'x', 'role' => 'admin']);

        $this->runWatchdog();

        $this->assertCount(1, $this->sentMessages());
        $this->assertSame(
            now()->toDateTimeString(),
            SystemSetting::get('heartbeat_watchdog_last_notified_at')
        );
    }

    public function test_skips_silently_when_no_admin_emails_exist(): void
    {
        $this->makeQueueStale();
        AdminUser::create(['name' => 'Blank', 'email' => '', 'password' => 'x', 'role' => 'admin']);
        AdminUser::create(['name' => 'Null', 'email' => null, 'password' => 'x', 'role' => 'admin']);

        $this->runWatchdog();

        $this->assertCount(0, $this->sentMessages());
        // Nothing was sent, so the throttle must NOT start.
        $this->assertNull(SystemSetting::get('heartbeat_watchdog_last_notified_at'));
    }

    // ---------------- exception path ----------------

    public function test_mail_exception_records_mail_health_failure_and_keeps_throttle_open(): void
    {
        $this->makeQueueStale();
        AdminUser::create(['name' => 'A', 'email' => 'a@example.com', 'password' => 'x', 'role' => 'admin']);

        Mail::shouldReceive('raw')->once()->andThrow(new \RuntimeException('SMTP down'));

        $this->runWatchdog(); // must NOT throw — closure swallows and records

        $failure = json_decode((string) SystemSetting::get('mail_health_failure'), true);
        $this->assertIsArray($failure);
        $this->assertSame('Queue-worker stale-heartbeat alert', $failure['context']);
        $this->assertStringContainsString('SMTP down', $failure['error']);

        // Send failed → throttle must stay open so the next run retries.
        $this->assertNull(SystemSetting::get('heartbeat_watchdog_last_notified_at'));
    }
}
