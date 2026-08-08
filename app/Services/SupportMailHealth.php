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

    public static function recordFailure(\Throwable $e): void
    {
        try {
            $count = (int) (self::current()['count'] ?? 0) + 1;

            SystemSetting::set(self::FAILURE_KEY, json_encode([
                'at' => now()->toIso8601String(),
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
}
