<?php

namespace App\Console\Commands;

use App\Mail\TrialReminderMail;
use App\Models\AdminUser;
use App\Models\SystemSetting;
use App\Services\MailHealth;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

/**
 * Task 1107: alert BEFORE live MySQL connections hit the cap (not after).
 *
 * On 17 Aug 2026 Max_used_connections reached 401 with max_connections=400
 * and nobody noticed until shops started getting "Too many connections" errors.
 * This command runs every 5 minutes and fires a one-time-per-hour email when
 * Threads_connected exceeds 70 % of max_connections, giving the owner time to
 * act before the next rush saturates the new cap.
 *
 * Behaviour:
 *  - Best-effort: every failure is caught and logged — nothing ever bubbles up.
 *  - Throttled: at most one alert email per hour (SystemSetting key), so a
 *    sustained spike cannot generate 12 emails/hour.
 *  - Above 70 % threshold: log a WARNING + email all admin users.
 *  - Always logs the current ratio at DEBUG level for trend analysis.
 */
class CheckMysqlConnectionHealth extends Command
{
    protected $signature = 'app:mysql-conn-health';

    protected $description = 'Alert admins when MySQL Threads_connected exceeds 70 % of max_connections.';

    /** Minimum minutes between alert emails — prevents spam during a sustained spike. */
    private const ALERT_COOLDOWN_MINUTES = 60;

    /** Fraction of max_connections that triggers an alert (exclusive — exactly 70% is safe). */
    private const THRESHOLD = 0.70;

    /**
     * Test-only override. Set to [threads, max_connections] in unit tests to
     * skip the real SHOW STATUS / SHOW VARIABLES query against the live DB.
     *
     * @internal
     * @var int[]|null
     */
    public static ?array $testStatusOverride = null;

    public function handle(): int
    {
        try {
            [$threads, $maxConn] = $this->fetchStatus();
        } catch (\Throwable $e) {
            // DB might itself be the problem — log and exit gracefully.
            Log::warning('mysql-conn-health: could not query connection status', [
                'error' => $e->getMessage(),
            ]);
            $this->warn('Could not query MySQL status: ' . $e->getMessage());
            return self::SUCCESS;
        }

        if ($maxConn <= 0) {
            Log::warning('mysql-conn-health: max_connections returned 0 — skipping');
            return self::SUCCESS;
        }

        $ratio = $threads / $maxConn;
        $pct   = round($ratio * 100, 1);

        Log::debug('mysql-conn-health', [
            'threads_connected' => $threads,
            'max_connections'   => $maxConn,
            'ratio_pct'         => $pct,
        ]);

        $this->line("Threads_connected: {$threads} / max_connections: {$maxConn} ({$pct}%)");

        if ($ratio <= self::THRESHOLD) {
            // Within safe range — reset the "alert sent" flag if we had been in
            // a warning state so a future spike generates a fresh email.
            $this->clearAlertFlag();
            return self::SUCCESS;
        }

        // Persist the breach timestamp and ratio so the admin panel can show a
        // persistent banner for up to 10 minutes after the last high-water mark.
        $this->recordBreach($pct);

        // Threshold exceeded.
        Log::warning('mysql-conn-health: connection threshold exceeded', [
            'threads_connected' => $threads,
            'max_connections'   => $maxConn,
            'ratio_pct'         => $pct,
            'threshold_pct'     => round(self::THRESHOLD * 100),
        ]);

        $this->warn("ALERT: {$pct}% of MySQL connections in use (threshold: >" . round(self::THRESHOLD * 100) . "%)");

        if (! $this->shouldSendAlert()) {
            $this->line('Alert already sent within the cooldown window — skipping email.');
            return self::SUCCESS;
        }

        $this->sendAlert($threads, $maxConn, $pct);

        return self::SUCCESS;
    }

    /**
     * Fetch Threads_connected and max_connections.
     *
     * In unit tests, set the static $testStatusOverride property instead of
     * hitting the DB (SQLite has no SHOW STATUS / SHOW VARIABLES).
     *
     * @return array{int, int}  [threads_connected, max_connections]
     */
    protected function fetchStatus(): array
    {
        if (self::$testStatusOverride !== null) {
            return self::$testStatusOverride;
        }

        // information_schema approach — works on MySQL 5.6+ and MariaDB 10.0+.
        try {
            $rows = DB::select("
                SELECT 'Threads_connected' AS name, VARIABLE_VALUE AS value
                  FROM information_schema.GLOBAL_STATUS
                 WHERE VARIABLE_NAME = 'Threads_connected'
                UNION ALL
                SELECT 'max_connections' AS name, VARIABLE_VALUE AS value
                  FROM information_schema.GLOBAL_VARIABLES
                 WHERE VARIABLE_NAME = 'max_connections'
            ");

            $map = [];
            foreach ($rows as $row) {
                $map[$row->name] = (int) $row->value;
            }

            if (isset($map['Threads_connected'], $map['max_connections'])) {
                return [$map['Threads_connected'], $map['max_connections']];
            }
        } catch (\Throwable) {
            // Fall through to SHOW-based fallback.
        }

        // Fallback: some MySQL/MariaDB versions surface these via SHOW STATUS/VARIABLES.
        $threads = 0;
        $maxConn = 0;

        $status = DB::select("SHOW STATUS LIKE 'Threads_connected'");
        if (isset($status[0])) {
            $threads = (int) $status[0]->Value;
        }

        $vars = DB::select("SHOW VARIABLES LIKE 'max_connections'");
        if (isset($vars[0])) {
            $maxConn = (int) $vars[0]->Value;
        }

        return [$threads, $maxConn];
    }

    /**
     * True if enough time has passed since the last alert email.
     */
    private function shouldSendAlert(): bool
    {
        try {
            $lastSent = SystemSetting::get('mysql_conn_alert_last_sent_at');
            if (! $lastSent) {
                return true;
            }
            $sentAt = \Illuminate\Support\Carbon::parse($lastSent);
            return $sentAt->diffInMinutes(now()) >= self::ALERT_COOLDOWN_MINUTES;
        } catch (\Throwable $e) {
            Log::warning('mysql-conn-health: could not read alert cooldown flag', ['error' => $e->getMessage()]);
            return true; // Err on the side of sending.
        }
    }

    /**
     * Record that an alert was just sent, so the cooldown window starts now.
     */
    private function markAlertSent(): void
    {
        try {
            SystemSetting::set(
                'mysql_conn_alert_last_sent_at',
                now()->toDateTimeString(),
                'Last time the mysql-conn-health alert email was sent (throttled to 1/hour).'
            );
        } catch (\Throwable $e) {
            Log::warning('mysql-conn-health: could not write alert cooldown flag', ['error' => $e->getMessage()]);
        }
    }

    /**
     * Clear the alert flag when the ratio drops back below/at the threshold, so
     * the next spike generates a fresh alert immediately.
     * Also clears the breach keys so the admin-panel banner disappears.
     * Deletes rows rather than nulling values — system_settings.value
     * is a non-nullable TEXT column on prod.
     */
    private function clearAlertFlag(): void
    {
        try {
            $deleted = SystemSetting::whereIn('key', [
                'mysql_conn_alert_last_sent_at',
                'mysql_conn_last_breach_at',
                'mysql_conn_last_breach_pct',
            ])->delete();
            if ($deleted) {
                Log::debug('mysql-conn-health: cooldown + breach flags cleared (ratio back at/below threshold)');
            }
        } catch (\Throwable $e) {
            Log::warning('mysql-conn-health: could not clear alert cooldown flag', ['error' => $e->getMessage()]);
        }
    }

    /**
     * Write (or refresh) the breach timestamp and ratio so the admin panel
     * banner always shows the most recent high-water mark.
     */
    private function recordBreach(float $pct): void
    {
        try {
            SystemSetting::set(
                'mysql_conn_last_breach_at',
                now()->toDateTimeString(),
                'Last time MySQL Threads_connected exceeded the warning threshold (written by mysql-conn-health).'
            );
            SystemSetting::set(
                'mysql_conn_last_breach_pct',
                (string) $pct,
                'Percentage of max_connections in use at the last breach (written by mysql-conn-health).'
            );
        } catch (\Throwable $e) {
            Log::warning('mysql-conn-health: could not write breach flags', ['error' => $e->getMessage()]);
        }
    }

    /**
     * Email all admin users and log the attempt.
     */
    private function sendAlert(int $threads, int $maxConn, float $pct): void
    {
        try {
            $emails = AdminUser::whereNotNull('email')
                ->where('email', '!=', '')
                ->pluck('email')->unique()->values();

            if ($emails->isEmpty()) {
                Log::warning('mysql-conn-health: no admin emails to notify');
                $this->warn('No admin email addresses found — alert not sent.');
                // Still mark sent so we don't retry every 5 min with no recipient.
                $this->markAlertSent();
                return;
            }

            $threshold = round(self::THRESHOLD * 100);
            $cooldownH = round(self::ALERT_COOLDOWN_MINUTES / 60);

            Mail::to($emails->all())->send(new TrialReminderMail(
                subjectLine: "⚠️ TaxNest: MySQL connections at {$pct}% — action may be needed",
                companyName: 'TaxNest',
                headline: "MySQL connections at {$pct}% ({$threads}/{$maxConn})",
                paragraphs: [
                    "Threads_connected has exceeded {$threshold}% of max_connections on the live server.",
                    "If usage keeps climbing, the server will return \"Too many connections\" errors to live shops.",
                    "Immediate actions:\n"
                        . "  1. Check WHM → SQL Services → MySQL/MariaDB Configuration and raise max_connections if not already done.\n"
                        . "  2. Review slow queries or long-lived connections: SHOW PROCESSLIST;\n"
                        . "  3. Verify the Laravel DB pool size (DB_MAX_CONNECTIONS in .env).",
                    "This alert will not repeat for {$cooldownH} hour(s).",
                ],
                ctaUrl: route('saas.admin.system'),
                ctaLabel: 'System Control Panel',
                panelName: 'TaxNest Admin',
            ));

            MailHealth::recordSuccess();
            $this->markAlertSent();

            $this->info('Alert email sent to: ' . $emails->implode(', '));
            Log::warning('mysql-conn-health: alert email sent', [
                'threads_connected' => $threads,
                'max_connections'   => $maxConn,
                'ratio_pct'         => $pct,
                'recipients'        => $emails->all(),
            ]);
        } catch (\Throwable $e) {
            Log::warning('mysql-conn-health: alert email failed', [
                'error' => $e->getMessage(),
            ]);
            MailHealth::recordFailure('MySQL connection health alert', $e);
            $this->warn('Alert email failed: ' . $e->getMessage());
            // Still mark sent so a broken mailer can't turn into a per-run retry storm.
            $this->markAlertSent();
        }
    }
}
