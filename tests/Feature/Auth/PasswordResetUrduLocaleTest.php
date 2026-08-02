<?php

namespace Tests\Feature\Auth;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Locks the reset-flow user-facing messages to the __('pos.auth_*') keys so a
 * future refactor can never silently drop them back to hardcoded English.
 * With session pos_locale=ur, the flashed errors must be the exact Urdu
 * strings from lang/ur/pos.php.
 */
class PasswordResetUrduLocaleTest extends TestCase
{
    use RefreshDatabase;

    /** Reset-flow keys that must exist in BOTH en and ur pos.php. */
    private const RESET_FLOW_KEYS = [
        'auth_fp_status_sent',
        'auth_fp_send_failed',
        'auth_otp_invalid',
        'auth_link_invalid',
        'auth_link_expired',
        'auth_session_invalid',
        'auth_password_reset_success',
        'auth_val_email_required',
        'auth_val_email_email',
        'auth_val_otp_required',
        'auth_val_otp_size',
        'auth_val_password_required',
        'auth_val_password_confirmed',
    ];

    private function urduLine(string $key): string
    {
        $lines = require base_path('lang/ur/pos.php');
        $this->assertArrayHasKey($key, $lines, "lang/ur/pos.php missing {$key}");

        return $lines[$key];
    }

    public function test_reset_link_without_token_flashes_urdu_invalid_link_error(): void
    {
        $response = $this->withSession(['pos_locale' => 'ur'])
            ->get('/reset-password-link');

        $response->assertRedirect(route('password.request'));
        $response->assertSessionHasErrors(['email' => $this->urduLine('auth_link_invalid')]);
    }

    public function test_reset_link_with_bad_token_flashes_urdu_expired_link_error(): void
    {
        $response = $this->withSession(['pos_locale' => 'ur'])
            ->get('/reset-password-link?token=deadbeef&email=someone@example.com');

        $response->assertRedirect(route('password.request'));
        $response->assertSessionHasErrors(['email' => $this->urduLine('auth_link_expired')]);
    }

    public function test_reset_form_with_bad_session_flashes_urdu_invalid_session_error(): void
    {
        // No password_reset_token/email in session → invalid reset session.
        $response = $this->withSession(['pos_locale' => 'ur'])
            ->get('/reset-password?token=deadbeef&email=someone@example.com');

        $response->assertRedirect(route('password.request'));
        $response->assertSessionHasErrors(['email' => $this->urduLine('auth_session_invalid')]);
    }

    public function test_reset_submit_with_bad_session_flashes_urdu_invalid_session_error(): void
    {
        $response = $this->withSession(['pos_locale' => 'ur'])
            ->post('/reset-password', [
                'token' => 'deadbeef',
                'email' => 'someone@example.com',
                'password' => 'new-Password-123',
                'password_confirmation' => 'new-Password-123',
            ]);

        $response->assertRedirect(route('password.request'));
        $response->assertSessionHasErrors(['email' => $this->urduLine('auth_session_invalid')]);
    }

    public function test_reset_flow_keys_exist_in_both_en_and_ur(): void
    {
        $en = require base_path('lang/en/pos.php');
        $ur = require base_path('lang/ur/pos.php');

        foreach (self::RESET_FLOW_KEYS as $key) {
            $this->assertArrayHasKey($key, $en, "lang/en/pos.php missing {$key}");
            $this->assertArrayHasKey($key, $ur, "lang/ur/pos.php missing {$key}");
            $this->assertNotSame('', trim((string) $en[$key]), "{$key} empty in en");
            $this->assertNotSame('', trim((string) $ur[$key]), "{$key} empty in ur");
        }
    }
}
