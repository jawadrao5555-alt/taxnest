<?php

namespace App\Services;

/**
 * Pakistani phone normalization for WhatsApp deep links (wa.me).
 *
 * wa.me needs the number in full international format WITHOUT '+', '00' or a
 * leading local zero (e.g. 923001234567). Users type numbers in every local
 * format (0300-1234567, +92 300 1234567, 0092..., 3001234567), so this is the
 * single place that converts them. Returns NULL when the input cannot be a
 * routable number — callers must surface that as a validation error, never
 * silently drop the send.
 */
class PkPhone
{
    /**
     * Normalize to international digits (no '+'). Null when not routable.
     */
    public static function normalize(?string $raw): ?string
    {
        $digits = preg_replace('/\D+/', '', (string) $raw);
        if ($digits === '') {
            return null;
        }

        // "00" international dialing prefix → strip
        if (str_starts_with($digits, '00')) {
            $digits = substr($digits, 2);
        }

        // "+92 0300..." typo → 920300... (13 digits): drop the stray zero
        if (str_starts_with($digits, '920') && strlen($digits) === 13) {
            $digits = '92' . substr($digits, 3);
        }

        if (str_starts_with($digits, '92')) {
            // 92 + 10-digit mobile (3XXXXXXXXX) = 12, or 92 + 9-10 digit landline = 11-12
            return (strlen($digits) >= 11 && strlen($digits) <= 12) ? $digits : null;
        }

        if (str_starts_with($digits, '0')) {
            // Local format: 03001234567 (mobile) / 04235761234 (landline)
            $candidate = '92' . substr($digits, 1);
            return (strlen($candidate) >= 11 && strlen($candidate) <= 12) ? $candidate : null;
        }

        // Bare mobile without the zero: 3001234567
        if (str_starts_with($digits, '3') && strlen($digits) === 10) {
            return '92' . $digits;
        }

        // Foreign buyer with own country code (e.g. 9715..., 4477...) — pass
        // through if it plausibly is a full international number.
        return (strlen($digits) >= 10 && strlen($digits) <= 15) ? $digits : null;
    }

    /**
     * wa.me deep link that opens a chat with $normalized and the message prefilled.
     */
    public static function waUrl(string $normalized, string $message): string
    {
        return 'https://wa.me/' . $normalized . '?text=' . rawurlencode($message);
    }
}
