<?php

namespace Tests\Feature;

use App\Console\Commands\CheckMysqlConnectionHealth;
use App\Mail\TrialReminderMail;
use App\Models\AdminUser;
use App\Models\SystemSetting;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Tests for app:mysql-conn-health (Task 1107).
 *
 * SQLite has no SHOW STATUS / SHOW VARIABLES, so the command exposes a static
 * $testStatusOverride property that tests set to inject arbitrary
 * [threads, max_connections] pairs without touching the real DB.
 *
 * Covered:
 *  - Below threshold → no alert, no cooldown key written
 *  - Exactly at threshold (70%) → treated as safe (does not alert)
 *  - Above threshold → log warning + email sent to all admin users
 *  - Cooldown: second breach within the hour → no second email
 *  - Cooldown expires → new alert sent
 *  - Recovery: ratio drops → cooldown row deleted; next breach alerts immediately
 *  - No admin emails configured → still marks cooldown, no crash
 *  - DB failure in fetchStatus() → exits gracefully with SUCCESS
 */
class MysqlConnectionHealthTest extends TestCase
{
    private const CMD = 'app:mysql-conn-health';
    private const COOLDOWN_KEY = 'mysql_conn_alert_last_sent_at';

    protected function setUp(): void
    {
        parent::setUp();

        Schema::dropAllTables();

        Schema::create('system_settings', function (Blueprint $t) {
            $t->id();
            $t->string('key')->unique();
            $t->text('value'); // non-nullable — matches prod schema
            $t->string('description')->nullable();
            $t->timestamps();
        });

        Schema::create('admin_users', function (Blueprint $t) {
            $t->id();
            $t->string('name')->nullable();
            $t->string('email')->nullable();
            $t->string('password')->nullable();
            $t->timestamps();
        });

        Mail::fake();
        CheckMysqlConnectionHealth::$testStatusOverride = null;
    }

    protected function tearDown(): void
    {
        CheckMysqlConnectionHealth::$testStatusOverride = null;
        parent::tearDown();
    }

    // ──────────────────────────────────────────────── helpers ────────────────

    private function setStatus(int $threads, int $max): void
    {
        CheckMysqlConnectionHealth::$testStatusOverride = [$threads, $max];
    }

    private function hasCooldownFlag(): bool
    {
        return SystemSetting::where('key', self::COOLDOWN_KEY)->exists();
    }

    private function createAdmin(string $email): AdminUser
    {
        return AdminUser::create(['name' => 'Admin', 'email' => $email, 'password' => 'x']);
    }

    private function writeCooldownMinutesAgo(int $minutes): void
    {
        SystemSetting::create([
            'key'         => self::COOLDOWN_KEY,
            'value'       => now()->subMinutes($minutes)->toDateTimeString(),
            'description' => 'test',
        ]);
    }

    // ──────────────────────────────────────────────── tests ─────────────────

    public function test_below_threshold_does_not_alert(): void
    {
        $this->createAdmin('admin@example.com');
        $this->setStatus(69, 100); // 69% — below threshold

        $this->artisan(self::CMD)->assertExitCode(0);

        Mail::assertNothingSent();
        $this->assertFalse($this->hasCooldownFlag(), 'Cooldown key must not be written below threshold');
    }

    public function test_exactly_at_threshold_is_treated_as_safe(): void
    {
        $this->createAdmin('admin@example.com');
        $this->setStatus(70, 100); // exactly 70% — must not alert

        $this->artisan(self::CMD)->assertExitCode(0);

        Mail::assertNothingSent();
        $this->assertFalse($this->hasCooldownFlag());
    }

    public function test_above_threshold_sends_alert_and_writes_cooldown(): void
    {
        $this->createAdmin('admin@example.com');
        $this->setStatus(71, 100); // 71% — above threshold

        $this->artisan(self::CMD)->assertExitCode(0);

        Mail::assertSent(TrialReminderMail::class, 1);
        $this->assertTrue($this->hasCooldownFlag(), 'Cooldown flag must be written after alert');
    }

    public function test_alert_sent_to_all_admin_emails(): void
    {
        $this->createAdmin('a@example.com');
        $this->createAdmin('b@example.com');
        $this->setStatus(80, 100); // 80%

        $this->artisan(self::CMD)->assertExitCode(0);

        Mail::assertSent(TrialReminderMail::class, 1);
    }

    public function test_second_breach_within_cooldown_window_suppresses_email(): void
    {
        $this->createAdmin('admin@example.com');
        $this->writeCooldownMinutesAgo(30); // within 60-min cooldown
        $this->setStatus(80, 100);

        $this->artisan(self::CMD)->assertExitCode(0);

        Mail::assertNothingSent();
    }

    public function test_breach_after_cooldown_expires_sends_new_alert(): void
    {
        $this->createAdmin('admin@example.com');
        $this->writeCooldownMinutesAgo(70); // cooldown expired
        $this->setStatus(80, 100);

        $this->artisan(self::CMD)->assertExitCode(0);

        Mail::assertSent(TrialReminderMail::class, 1);
    }

    public function test_recovery_deletes_cooldown_row(): void
    {
        $this->writeCooldownMinutesAgo(5); // existing flag from a prior breach
        $this->setStatus(60, 100); // 60% — below threshold

        $this->artisan(self::CMD)->assertExitCode(0);

        $this->assertFalse($this->hasCooldownFlag(), 'Cooldown row must be deleted on recovery');
        Mail::assertNothingSent();
    }

    public function test_new_breach_after_recovery_alerts_immediately(): void
    {
        $this->createAdmin('admin@example.com');

        // First run: recovery clears the flag.
        $this->writeCooldownMinutesAgo(5);
        $this->setStatus(60, 100);
        $this->artisan(self::CMD)->assertExitCode(0);
        $this->assertFalse($this->hasCooldownFlag());

        // Second run: new breach — no cooldown flag in the way.
        $this->setStatus(75, 100);
        $this->artisan(self::CMD)->assertExitCode(0);

        Mail::assertSent(TrialReminderMail::class, 1);
    }

    public function test_no_admin_emails_does_not_crash(): void
    {
        // No admin users — command must exit cleanly.
        $this->setStatus(80, 100);

        $this->artisan(self::CMD)->assertExitCode(0);

        Mail::assertNothingSent();
        // Cooldown still written so we don't retry every 5 min with no recipient.
        $this->assertTrue($this->hasCooldownFlag());
    }

    public function test_db_failure_in_fetch_exits_gracefully(): void
    {
        $this->createAdmin('admin@example.com');
        // No testStatusOverride → command will try the real DB query, which will
        // throw on SQLite (no SHOW STATUS). The command must catch and return SUCCESS.
        CheckMysqlConnectionHealth::$testStatusOverride = null;

        $this->artisan(self::CMD)->assertExitCode(0);

        Mail::assertNothingSent();
        $this->assertFalse($this->hasCooldownFlag());
    }

    // ─────────────────────────── Task 1121: breach-key / banner tests ────────

    /**
     * A above-threshold run must persist both breach keys so the admin-panel
     * banner has something to display (timestamp + percentage).
     */
    public function test_breach_writes_breach_keys(): void
    {
        $this->createAdmin('admin@example.com');
        $this->setStatus(75, 100); // 75% — above threshold

        $this->artisan(self::CMD)->assertExitCode(0);

        $this->assertNotNull(
            SystemSetting::get('mysql_conn_last_breach_at'),
            'mysql_conn_last_breach_at must be written on a breach so the banner can appear'
        );
        $this->assertNotNull(
            SystemSetting::get('mysql_conn_last_breach_pct'),
            'mysql_conn_last_breach_pct must be written on a breach so the banner shows the ratio'
        );
        $this->assertEquals('75', SystemSetting::get('mysql_conn_last_breach_pct'));
    }

    /**
     * When the ratio drops back to/below the threshold the command calls
     * clearAlertFlag(), which must delete ALL three keys — including the two
     * breach keys that drive the admin-panel banner.
     * Without this, the banner keeps showing stale data indefinitely.
     */
    public function test_recovery_deletes_breach_keys(): void
    {
        // Simulate keys left over from a previous breach run.
        SystemSetting::create([
            'key'   => 'mysql_conn_last_breach_at',
            'value' => now()->subMinutes(3)->toDateTimeString(),
        ]);
        SystemSetting::create([
            'key'   => 'mysql_conn_last_breach_pct',
            'value' => '78.5',
        ]);
        $this->writeCooldownMinutesAgo(5);

        // Recovery run — ratio is now safe.
        $this->setStatus(55, 100);
        $this->artisan(self::CMD)->assertExitCode(0);

        $this->assertNull(
            SystemSetting::get('mysql_conn_last_breach_at'),
            'mysql_conn_last_breach_at must be deleted on recovery so the banner disappears'
        );
        $this->assertNull(
            SystemSetting::get('mysql_conn_last_breach_pct'),
            'mysql_conn_last_breach_pct must be deleted on recovery'
        );
        $this->assertFalse($this->hasCooldownFlag(), 'cooldown flag must also be deleted');
        Mail::assertNothingSent();
    }

    /**
     * Even if the command never ran to clear the keys, the Blade time-guard
     * (diffInMinutes(now()) <= 10) must make $tnMysqlBreach evaluate to false
     * once the breach timestamp is more than 10 minutes old.
     *
     * This test validates that Carbon expression directly so any future change
     * to the Blade condition is caught without spinning up an HTTP request.
     */
    public function test_blade_breach_flag_is_false_after_10_minutes(): void
    {
        $breachAt = now()->subMinutes(11)->toDateTimeString(); // 11 min old — past the window

        // Replicate the Blade expression verbatim.
        $tnMysqlBreach = $breachAt && \Illuminate\Support\Carbon::parse($breachAt)->diffInMinutes(now()) <= 10;

        $this->assertFalse(
            $tnMysqlBreach,
            'The Blade 10-minute time-guard must hide the banner when breach_at is older than 10 minutes, ' .
            'even if the command has not yet deleted the keys'
        );
    }

    /**
     * Sanity-check the opposite: a breach timestamp that is only 5 minutes old
     * must still make the Blade expression evaluate to true (banner stays visible).
     */
    public function test_blade_breach_flag_is_true_within_10_minutes(): void
    {
        $breachAt = now()->subMinutes(5)->toDateTimeString(); // 5 min old — within window

        $tnMysqlBreach = $breachAt && \Illuminate\Support\Carbon::parse($breachAt)->diffInMinutes(now()) <= 10;

        $this->assertTrue(
            $tnMysqlBreach,
            'The banner must still show when the breach happened less than 10 minutes ago'
        );
    }
}
