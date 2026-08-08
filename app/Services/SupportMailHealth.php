<?php

namespace App\Services;

use App\Models\SystemSetting;
use Illuminate\Support\Carbon;

/**
 * Support-mailbox (support@ IMAP) health tracker.
 *
 * If the cPanel password changes or IMAP goes down, the Support Inbox only
 * shows an error when someone opens it. SupportMailService records every
 * connect failure here and every successful connect clears it, so the SaaS
 * admin panel shows a persistent red banner proactively (same pattern as
 * MailHealth for outgoing SMTP).
 *
 * Storage: single SystemSetting JSON key — one cheap, exception-safe query
 * per admin page render. Bookkeeping must NEVER break the calling path, so
 * every write is wrapped in its own try/catch.
 */
class SupportMailHealth
{
    private const FAILURE_KEY = 'support_mail_health_failure';
    private const SUCCESS_KEY = 'support_mail_health_last_success_at';
    private const NOTIFIED_KEY = 'support_mail_health_last_notified_at';

    /** Failure must be at least this old before the admin email alert fires. */
    public const ALERT_AFTER_HOURS = 6;

    public static function recordFailure(\Throwable $e): void
    {
        try {
            $existing = self::current();
            $count = (int) ($existing['count'] ?? 0) + 1;

            // Preserve the ORIGINAL outage-start timestamp while a failure is
            // already active: every 15-min probe re-records the failure, and
            // overwriting 'at' would keep the outage looking "fresh" forever,
            // so the 6h+ admin email alert would never fire.
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
                'error' => mb_substr((string) $e->getMessage(), 0, 300),
                'count' => $count,
            ], JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE) ?: '', 'Support mailbox IMAP failure tracker (auto-managed)');
        } catch (\Throwable $ignored) {
            // Health bookkeeping must never break the calling path.
        }
    }

    public static function recordSuccess(): void
    {
        try {
            SystemSetting::set(self::SUCCESS_KEY, now()->toIso8601String(), 'Support mailbox last successful IMAP connect (auto-managed)');

            if ((string) SystemSetting::get(self::FAILURE_KEY, '') !== '') {
                SystemSetting::set(self::FAILURE_KEY, '', 'Support mailbox IMAP failure tracker (auto-managed)');
            }

            // Mailbox healthy again — reset the alert throttle so the NEXT
            // prolonged outage emails admins immediately once it crosses the
            // age threshold, instead of waiting out a stale 12h window.
            if ((string) SystemSetting::get(self::NOTIFIED_KEY, '') !== '') {
                SystemSetting::set(self::NOTIFIED_KEY, '', 'Last time the support-mailbox watchdog emailed admins (auto-managed).');
            }
        } catch (\Throwable $ignored) {
            // Health bookkeeping must never break the calling path.
        }
    }

    /**
     * Active failure state for the admin banner, or null when healthy.
     *
     * @return array{at: ?string, ago: ?string, error: string, count: int}|null
     */
    public static function current(): ?array
    {
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
            // Malformed timestamp — banner still renders without the "ago".
        }

        return [
            'at' => $data['at'] ?? null,
            'ago' => $ago,
            'error' => (string) ($data['error'] ?? ''),
            'count' => max(1, (int) ($data['count'] ?? 1)),
        ];
    }

    /**
     * Whether the prolonged-outage admin email may fire now: the recorded
     * failure must be at least ALERT_AFTER_HOURS old, and the last alert
     * (if any) more than 12h ago.
     */
    public static function shouldNotify(): bool
    {
        try {
            $failure = self::current();
            if (!$failure || empty($failure['at'])) {
                return false;
            }

            $failedAt = Carbon::parse($failure['at']);
            if ($failedAt->gt(now()->subHours(self::ALERT_AFTER_HOURS))) {
                return false;
            }

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
                'Last time the support-mailbox watchdog emailed admins (auto-managed).'
            );
        } catch (\Throwable $ignored) {
        }
    }
}
