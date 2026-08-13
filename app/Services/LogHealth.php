<?php

namespace App\Services;

use App\Models\SystemSetting;
use Illuminate\Support\Carbon;

/**
 * Live logging health tracker (daily scheduled probe).
 *
 * deploy-live.sh checks LOG_LEVEL + laravel.log freshness at DEPLOY time, but
 * between deploys nobody would notice if the live .env quietly went to
 * LOG_LEVEL=error (Log::warning lines silently dropped) or the log file
 * became unwritable. logs:health-check probes this daily and records the
 * result here. IMPORTANT: when logging itself is dead, Log:: alerts can never
 * surface — so failures live in a SystemSetting flag (admin banner) plus a
 * synchronous email, same pattern as SupportMailHealth.
 *
 * All writes are exception-safe: health bookkeeping must never break the
 * calling path or a page render.
 */
class LogHealth
{
    private const FAILURE_KEY = 'log_health_failure';
    private const SUCCESS_KEY = 'log_health_last_success_at';
    private const NOTIFIED_KEY = 'log_health_last_notified_at';

    /**
     * @param string[] $issues Human-readable problem lines.
     */
    public static function recordFailure(array $issues): void
    {
        try {
            $existing = self::current();
            $count = (int) ($existing['count'] ?? 0) + 1;

            // Preserve the ORIGINAL outage-start timestamp while a failure is
            // already active so "failing since" stays honest across daily runs.
            $at = null;
            if (!empty($existing['at'])) {
                try {
                    $at = Carbon::parse($existing['at'])->toIso8601String();
                } catch (\Throwable $ignored) {
                    // Malformed stored timestamp — restart the clock below.
                }
            }

            SystemSetting::set(self::FAILURE_KEY, json_encode([
                'at' => $at ?? now()->toIso8601String(),
                'issues' => array_values(array_map(
                    fn ($i) => mb_substr((string) $i, 0, 300),
                    array_slice($issues, 0, 10)
                )),
                'count' => $count,
            ], JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE) ?: '', 'Live logging health failure tracker (auto-managed)');
        } catch (\Throwable $ignored) {
            // Health bookkeeping must never break the calling path.
        }
    }

    public static function recordSuccess(): void
    {
        try {
            SystemSetting::set(self::SUCCESS_KEY, now()->toIso8601String(), 'Logging health check last passed (auto-managed)');

            if ((string) SystemSetting::get(self::FAILURE_KEY, '') !== '') {
                SystemSetting::set(self::FAILURE_KEY, '', 'Live logging health failure tracker (auto-managed)');
            }

            // Healthy again — reset the alert throttle so the NEXT outage
            // emails admins immediately instead of waiting out a stale window.
            if ((string) SystemSetting::get(self::NOTIFIED_KEY, '') !== '') {
                SystemSetting::set(self::NOTIFIED_KEY, '', 'Last time the logging-health watchdog emailed admins (auto-managed).');
            }
        } catch (\Throwable $ignored) {
            // Health bookkeeping must never break the calling path.
        }
    }

    /**
     * Active failure state for the admin banner, or null when healthy.
     *
     * @return array{at: ?string, ago: ?string, issues: string[], count: int}|null
     */
    public static function current(): ?array
    {
        try {
            $raw = SystemSetting::get(self::FAILURE_KEY, '');
            if (!is_string($raw) || trim($raw) === '') {
                return null;
            }

            $data = json_decode($raw, true);
            if (!is_array($data)) {
                return null;
            }

            $ago = null;
            try {
                if (!empty($data['at'])) {
                    $ago = Carbon::parse($data['at'])->diffForHumans();
                }
            } catch (\Throwable $ignored) {
                // Malformed timestamp — banner still renders without "ago".
            }

            $issues = [];
            foreach ((array) ($data['issues'] ?? []) as $i) {
                if (is_string($i) && $i !== '') {
                    $issues[] = $i;
                }
            }

            return [
                'at' => $data['at'] ?? null,
                'ago' => $ago,
                'issues' => $issues,
                'count' => max(1, (int) ($data['count'] ?? 1)),
            ];
        } catch (\Throwable $e) {
            // Never let health bookkeeping break an admin page render.
            return null;
        }
    }

    /**
     * Whether the admin alert email may fire now (throttled to once per 12h).
     */
    public static function shouldNotify(): bool
    {
        try {
            $rawLast = SystemSetting::get(self::NOTIFIED_KEY, '');
            if (is_string($rawLast) && trim($rawLast) !== '') {
                $last = Carbon::parse($rawLast);
                if ($last->gt(now()->subHours(12))) {
                    return false;
                }
            }

            return true;
        } catch (\Throwable $e) {
            return false;
        }
    }

    public static function markNotified(): void
    {
        try {
            SystemSetting::set(
                self::NOTIFIED_KEY,
                now()->toDateTimeString(),
                'Last time the logging-health watchdog emailed admins (auto-managed).'
            );
        } catch (\Throwable $ignored) {
        }
    }
}
