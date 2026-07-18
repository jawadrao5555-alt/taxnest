<?php

namespace App\Services;

use App\Models\SystemSetting;
use Illuminate\Support\Facades\Crypt;

/**
 * Admin-managed outgoing email (SMTP) settings.
 *
 * The SaaS admin can save the noreply mailbox's SMTP details on the admin
 * Settings page. When saved AND enabled, these override the .env MAIL_*
 * values at runtime (config() only — nothing is written to .env). When
 * disabled or incomplete, the .env values keep working as the fallback,
 * so a bad panel entry can never brick email completely.
 *
 * Storage: ONE SystemSetting JSON key (`smtp_settings`) — a single cheap
 * indexed query at boot. The password is stored encrypted (Crypt) inside
 * the JSON; the `value` column is TEXT so ciphertext length is safe.
 * apply() must NEVER throw: it runs on every request incl. artisan
 * commands where the DB may be unavailable (dev cold-start).
 */
class SmtpRuntimeConfig
{
    public const KEY = 'smtp_settings';

    /**
     * Raw saved settings (password stays encrypted), or null when unset.
     */
    public static function settings(): ?array
    {
        $raw = SystemSetting::get(self::KEY, '');
        if (!is_string($raw) || trim($raw) === '') {
            return null;
        }

        $data = json_decode($raw, true);

        return is_array($data) ? $data : null;
    }

    /**
     * Persist settings from the admin form. Pass $newPassword = null/'' to
     * keep the previously saved password.
     */
    public static function save(array $input, ?string $newPassword): void
    {
        $existing = self::settings() ?? [];

        $passwordEnc = $existing['password_enc'] ?? '';
        if (is_string($newPassword) && $newPassword !== '') {
            $passwordEnc = Crypt::encryptString($newPassword);
        }

        SystemSetting::set(self::KEY, json_encode([
            'enabled' => (bool) ($input['enabled'] ?? false),
            'host' => trim((string) ($input['host'] ?? '')),
            'port' => (int) ($input['port'] ?? 465),
            'encryption' => in_array(($input['encryption'] ?? 'ssl'), ['ssl', 'tls'], true) ? $input['encryption'] : 'ssl',
            'username' => trim((string) ($input['username'] ?? '')),
            'password_enc' => $passwordEnc,
            'from_address' => trim((string) ($input['from_address'] ?? '')),
            'from_name' => trim((string) ($input['from_name'] ?? '')),
        ], JSON_UNESCAPED_UNICODE) ?: '', 'Admin-managed outgoing SMTP settings (password encrypted)');
    }

    /**
     * Whether a password has been saved (never exposes the value).
     */
    public static function hasPassword(): bool
    {
        return trim((string) (self::settings()['password_enc'] ?? '')) !== '';
    }

    /**
     * Override the mail config for this request/process when the admin-saved
     * settings are enabled and complete. Silent no-op on ANY failure.
     */
    public static function apply(): void
    {
        try {
            $s = self::settings();
            if (!$s || empty($s['enabled'])) {
                return;
            }

            $host = trim((string) ($s['host'] ?? ''));
            $username = trim((string) ($s['username'] ?? ''));
            $passwordEnc = (string) ($s['password_enc'] ?? '');
            if ($host === '' || $username === '' || $passwordEnc === '') {
                return; // Incomplete — keep .env fallback.
            }

            try {
                $password = Crypt::decryptString($passwordEnc);
            } catch (\Throwable $e) {
                return; // APP_KEY changed / corrupt — keep .env fallback.
            }

            $port = (int) ($s['port'] ?? 465) ?: 465;
            // 'ssl' = implicit TLS (usually port 465) → smtps scheme.
            // 'tls' = STARTTLS upgrade (usually port 587) → plain scheme, Symfony upgrades automatically.
            $scheme = (($s['encryption'] ?? 'ssl') === 'ssl') ? 'smtps' : null;

            config([
                'mail.default' => 'smtp',
                // A MAIL_URL DSN would beat host/port in Symfony's factory —
                // clear it so the admin-saved settings can never be ignored.
                'mail.mailers.smtp.url' => null,
                'mail.mailers.smtp.host' => $host,
                'mail.mailers.smtp.port' => $port,
                'mail.mailers.smtp.scheme' => $scheme,
                'mail.mailers.smtp.username' => $username,
                'mail.mailers.smtp.password' => $password,
            ]);

            // From address: saved value, else the mailbox username (cPanel
            // rejects / DMARC-fails sends whose From doesn't match the
            // authenticated mailbox) — matches the UI promise.
            $from = trim((string) ($s['from_address'] ?? ''));
            if ($from === '' || !filter_var($from, FILTER_VALIDATE_EMAIL)) {
                $from = filter_var($username, FILTER_VALIDATE_EMAIL) ? $username : '';
            }
            if ($from !== '') {
                config(['mail.from.address' => $from]);
            }
            $fromName = trim((string) ($s['from_name'] ?? ''));
            if ($fromName !== '') {
                config(['mail.from.name' => $fromName]);
            }
        } catch (\Throwable $e) {
            // Never break a request over mail settings.
        }
    }
}
