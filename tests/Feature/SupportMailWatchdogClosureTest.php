<?php

namespace Tests\Feature;

use App\Models\AdminUser;
use App\Models\SystemSetting;
use App\Services\SupportMailHealth;
use Carbon\Carbon;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * TASK 372: lock the scheduled 'support-mail-watchdog' closure
 * (routes/console.php) — when the support@ IMAP mailbox has been failing
 * for 6h+, every admin gets a synchronous Mail::raw alert, throttled to
 * once per 12h; the throttle resets when the mailbox recovers.
 *
 * Same testing approach as HeartbeatWatchdogClosureTest: Mail::fake()
 * cannot capture Mail::raw, so we run the real pipeline against the
 * 'array' transport and assert on captured Symfony messages.
 *
 * Time frozen at 2026-08-01 12:00 (Carbon::setTestNow).
 */
class SupportMailWatchdogClosureTest extends TestCase
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

    /** Run the scheduled 'support-mail-watchdog' closure exactly as cron would. */
    private function runWatchdog(): void
    {
        $event = collect($this->app->make(Schedule::class)->events())
            ->first(fn ($e) => ($e->description ?? null) === 'support-mail-watchdog');

        $this->assertNotNull($event, "Scheduled event 'support-mail-watchdog' not found — was it renamed/removed?");

        $event->run($this->app);
    }

    private function recordFailureAgo(int $hours, int $count = 5): void
    {
        SystemSetting::set('support_mail_health_failure', json_encode([
            'at' => now()->subHours($hours)->toIso8601String(),
            'error' => 'AUTHENTICATIONFAILED: invalid credentials',
            'count' => $count,
        ]));
    }

    /** @return \Symfony\Component\Mailer\SentMessage[] */
    private function sentMessages(): array
    {
        return Mail::mailer('array')->getSymfonyTransport()->messages()->all();
    }

    // ---------------- send path ----------------

    public function test_long_outage_emails_all_admins_and_marks_notified(): void
    {
        $this->recordFailureAgo(7);
        AdminUser::create(['name' => 'A', 'email' => 'a@example.com', 'password' => 'x', 'role' => 'super_admin']);
        AdminUser::create(['name' => 'B', 'email' => 'b@example.com', 'password' => 'x', 'role' => 'admin']);
        AdminUser::create(['name' => 'Dup', 'email' => 'a@example.com', 'password' => 'x', 'role' => 'admin']);
        AdminUser::create(['name' => 'Blank', 'email' => '', 'password' => 'x', 'role' => 'admin']);
        AdminUser::create(['name' => 'Null', 'email' => null, 'password' => 'x', 'role' => 'admin']);

        $this->runWatchdog();

        $messages = $this->sentMessages();
        $this->assertCount(1, $messages, 'Exactly one alert email expected');

        $email = $messages[0]->getOriginalMessage();
        $to = collect($email->getTo())->map(fn ($a) => $a->getAddress())->sort()->values()->all();
        $this->assertSame(['a@example.com', 'b@example.com'], $to);
        $this->assertSame('WARNING: TaxNest support mailbox has been down for hours', $email->getSubject());
        $this->assertStringContainsString('support@ mailbox', $email->getTextBody());
        $this->assertStringContainsString('AUTHENTICATIONFAILED', $email->getTextBody());

        $this->assertSame(
            now()->toDateTimeString(),
            SystemSetting::get('support_mail_health_last_notified_at')
        );
        $this->assertNotEmpty(SystemSetting::get('mail_health_last_success_at'));
    }

    // ---------------- skip paths ----------------

    public function test_no_email_when_mailbox_is_healthy(): void
    {
        AdminUser::create(['name' => 'A', 'email' => 'a@example.com', 'password' => 'x', 'role' => 'admin']);

        $this->runWatchdog();

        $this->assertCount(0, $this->sentMessages());
        $this->assertNull(SystemSetting::get('support_mail_health_last_notified_at'));
    }

    public function test_no_email_when_failure_is_too_recent(): void
    {
        // Failing for only 2h — below the 6h threshold; banner covers this.
        $this->recordFailureAgo(2);
        AdminUser::create(['name' => 'A', 'email' => 'a@example.com', 'password' => 'x', 'role' => 'admin']);

        $this->runWatchdog();

        $this->assertCount(0, $this->sentMessages());
    }

    public function test_throttled_within_12_hours(): void
    {
        $this->recordFailureAgo(20);
        SystemSetting::set('support_mail_health_last_notified_at', now()->subHours(5)->toDateTimeString());
        AdminUser::create(['name' => 'A', 'email' => 'a@example.com', 'password' => 'x', 'role' => 'admin']);

        $this->runWatchdog();

        $this->assertCount(0, $this->sentMessages());
    }

    public function test_alerts_again_after_12_hour_throttle_expires(): void
    {
        $this->recordFailureAgo(20);
        SystemSetting::set('support_mail_health_last_notified_at', now()->subHours(13)->toDateTimeString());
        AdminUser::create(['name' => 'A', 'email' => 'a@example.com', 'password' => 'x', 'role' => 'admin']);

        $this->runWatchdog();

        $this->assertCount(1, $this->sentMessages());
        $this->assertSame(
            now()->toDateTimeString(),
            SystemSetting::get('support_mail_health_last_notified_at')
        );
    }

    public function test_no_email_when_no_admin_emails_exist(): void
    {
        $this->recordFailureAgo(8);
        AdminUser::create(['name' => 'Blank', 'email' => '', 'password' => 'x', 'role' => 'admin']);

        $this->runWatchdog();

        $this->assertCount(0, $this->sentMessages());
        // Throttle must NOT be marked when nothing was sent.
        $this->assertNull(SystemSetting::get('support_mail_health_last_notified_at'));
    }

    // ---------------- production failure lifecycle ----------------

    public function test_repeated_probe_failures_preserve_outage_start_and_alert_fires(): void
    {
        AdminUser::create(['name' => 'A', 'email' => 'a@example.com', 'password' => 'x', 'role' => 'admin']);

        // Outage begins at 05:00 and the 15-min probe keeps failing. Each
        // recordFailure must PRESERVE the original 'at' — if it overwrote
        // it, the outage would always look <15 min old and never alert.
        $start = Carbon::parse('2026-08-01 05:00:00');
        for ($i = 0; $i < 8 * 4; $i++) { // 8 hours of quarter-hourly failures
            Carbon::setTestNow($start->copy()->addMinutes(15 * $i));
            SupportMailHealth::recordFailure(new \RuntimeException('LOGIN failed'));
        }

        $failure = SupportMailHealth::current();
        $this->assertSame($start->toIso8601String(), Carbon::parse($failure['at'])->toIso8601String());
        $this->assertSame(32, $failure['count']);

        // 4h into the outage: below the 6h threshold, no alert yet.
        Carbon::setTestNow($start->copy()->addHours(4));
        $this->runWatchdog();
        $this->assertCount(0, $this->sentMessages());

        // 7h45m in (last recorded failure): threshold crossed, alert fires.
        Carbon::setTestNow($start->copy()->addHours(8)->subMinutes(15));
        $this->runWatchdog();
        $this->assertCount(1, $this->sentMessages());
    }

    // ---------------- recovery resets throttle ----------------

    public function test_record_success_clears_failure_and_throttle(): void
    {
        $this->recordFailureAgo(8);
        SystemSetting::set('support_mail_health_last_notified_at', now()->subHours(2)->toDateTimeString());

        SupportMailHealth::recordSuccess();

        $this->assertNull(SupportMailHealth::current());
        $this->assertSame('', (string) SystemSetting::get('support_mail_health_last_notified_at', ''));

        $this->runWatchdog();
        $this->assertCount(0, $this->sentMessages());
    }
}
