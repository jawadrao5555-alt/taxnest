<?php

namespace App\Services;

use App\Models\SystemSetting;
use Illuminate\Support\Carbon;

/**
 * Lightweight outgoing-email health tracker.
 *
 * Real sends (payment-proof admin alerts, trial reminders, payment decisions,
 * password resets) fail SILENTLY on a bad SMTP config — only a log line is
 * written. Every mail catch block calls recordFailure() and every successful
 * send calls recordSuccess(), so the SaaS admin panel can show a persistent
 * red banner while mail is broken and clear it automatically once a send
 * (real or the Settings test button) succeeds.
 *
 * Storage: single SystemSetting JSON key — one cheap, exception-safe query
 * per admin page render. Bookkeeping must NEVER break the calling send path,
 * so every write is wrapped in its own try/catch.
 */
class MailHealth
{
    private const FAILURE_KEY = 'mail_health_failure';
    private const SUCCESS_KEY = 'mail_health_last_success_at';

    public static function recordFailure(string $context, \Throwable $e): void
    {
        try {
            $count = (int) (self::current()['count'] ?? 0) + 1;

            SystemSetting::set(self::FAILURE_KEY, json_encode([
                'at' => now()->toIso8601String(),
                'context' => mb_substr($context, 0, 120),
                'error' => mb_substr((string) $e->getMessage(), 0, 300),
                'count' => $count,
            ], JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE) ?: '', 'Outgoing email failure tracker (auto-managed)');
        } catch (\Throwable $ignored) {
            // Health bookkeeping must never break the send path.
        }
    }

    public static function recordSuccess(): void
    {
        try {
            SystemSetting::set(self::SUCCESS_KEY, now()->toIso8601String(), 'Outgoing email last successful send (auto-managed)');

            if ((string) SystemSetting::get(self::FAILURE_KEY, '') !== '') {
                SystemSetting::set(self::FAILURE_KEY, '', 'Outgoing email failure tracker (auto-managed)');
            }
        } catch (\Throwable $ignored) {
            // Health bookkeeping must never break the send path.
        }
    }

    /**
     * Active failure state for the admin banner, or null when mail is healthy.
     *
     * @return array{at: ?string, ago: ?string, context: string, error: string, count: int}|null
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
            'context' => (string) ($data['context'] ?? 'Email send'),
            'error' => (string) ($data['error'] ?? ''),
            'count' => max(1, (int) ($data['count'] ?? 1)),
        ];
    }

    public static function lastSuccessAgo(): ?string
    {
        $raw = SystemSetting::get(self::SUCCESS_KEY, '');
        if (!is_string($raw) || trim($raw) === '') {
            return null;
        }

        try {
            return Carbon::parse($raw)->diffForHumans();
        } catch (\Throwable $ignored) {
            return null;
        }
    }
}
