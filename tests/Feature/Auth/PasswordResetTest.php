<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * The app replaced Breeze's token-notification reset with a custom
 * OTP + signed-link flow (password_reset_otps table, session-bound
 * reset form). These tests exercise that real flow.
 */
class PasswordResetTest extends TestCase
{
    use RefreshDatabase;

    public function test_reset_password_link_screen_can_be_rendered(): void
    {
        $response = $this->get('/forgot-password');

        $response->assertStatus(200);
    }

    public function test_reset_can_be_requested_and_creates_otp_record(): void
    {
        $user = User::factory()->create();

        $response = $this->post('/forgot-password', ['email' => $user->email]);

        $response->assertRedirect(route('password.verify.otp', ['email' => $user->email]));

        $this->assertDatabaseHas('password_reset_otps', [
            'email' => $user->email,
            'used' => false,
        ]);
    }

    public function test_unknown_email_gets_neutral_response_without_otp_record(): void
    {
        $response = $this->post('/forgot-password', ['email' => 'nobody@example.com']);

        // Neutral redirect — must not reveal whether the email is registered.
        $response->assertRedirect(route('password.verify.otp', ['email' => 'nobody@example.com']));

        $this->assertDatabaseMissing('password_reset_otps', [
            'email' => 'nobody@example.com',
        ]);
    }

    public function test_reset_link_opens_session_bound_reset_form(): void
    {
        $user = User::factory()->create();
        $this->post('/forgot-password', ['email' => $user->email]);

        $token = DB::table('password_reset_otps')->where('email', $user->email)->value('token');

        $response = $this->get('/reset-password-link?token=' . $token . '&email=' . urlencode($user->email));

        $response->assertRedirect();
        $this->assertStringContainsString('/reset-password', $response->headers->get('Location'));

        // The one-time link is consumed
        $this->assertDatabaseHas('password_reset_otps', [
            'email' => $user->email,
            'used' => true,
        ]);
    }

    public function test_password_can_be_reset_via_link_flow(): void
    {
        $user = User::factory()->create();
        $this->post('/forgot-password', ['email' => $user->email]);

        $token = DB::table('password_reset_otps')->where('email', $user->email)->value('token');
        $this->get('/reset-password-link?token=' . $token . '&email=' . urlencode($user->email));

        $sessionToken = session('password_reset_token');
        $this->assertNotEmpty($sessionToken);

        $response = $this->post('/reset-password', [
            'token' => $sessionToken,
            'email' => $user->email,
            'password' => 'new-password-123',
            'password_confirmation' => 'new-password-123',
        ]);

        $response
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('login'));

        $this->assertTrue(Hash::check('new-password-123', $user->fresh()->password));
    }

    public function test_reset_with_wrong_session_token_is_rejected(): void
    {
        $user = User::factory()->create();
        $this->post('/forgot-password', ['email' => $user->email]);

        $token = DB::table('password_reset_otps')->where('email', $user->email)->value('token');
        $this->get('/reset-password-link?token=' . $token . '&email=' . urlencode($user->email));

        $response = $this->post('/reset-password', [
            'token' => 'forged-token',
            'email' => $user->email,
            'password' => 'new-password-123',
            'password_confirmation' => 'new-password-123',
        ]);

        $response->assertRedirect(route('password.request'));
        $this->assertFalse(Hash::check('new-password-123', $user->fresh()->password));
    }
}
