<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\{DB, Hash, Mail};
use Tests\TestCase;

class PasswordResetGrantSafetyTest extends TestCase
{
    use RefreshDatabase;

    public function test_verified_link_cannot_leave_an_unbounded_password_reset_session(): void
    {
        Mail::fake();
        $user = User::factory()->create();
        $old = $user->password;
        $this->post('/forgot-password', ['email' => $user->email])->assertRedirect();
        $token = DB::table('password_reset_otps')->where('email', $user->email)->value('token');
        $this->get('/reset-password-link?token='.$token.'&email='.urlencode($user->email))->assertRedirect();
        $sessionToken = session('password_reset_token');
        $stored = DB::table('password_reset_otps')->where('email', $user->email)->sole();
        $this->assertSame(hash('sha256', $sessionToken), $stored->reset_grant_hash);
        $this->assertNotSame($sessionToken, $stored->reset_grant_hash);
        $this->travel(16)->minutes();
        $this->post('/reset-password', ['token' => $sessionToken, 'email' => $user->email,
            'password' => 'NewSynthetic123!', 'password_confirmation' => 'NewSynthetic123!'])
            ->assertRedirect(route('password.request'))->assertSessionHasErrors('email');
        $this->assertSame($old, $user->fresh()->password);
        $this->travelBack();
    }

    public function test_copied_grant_session_cannot_replay_a_completed_reset(): void
    {
        Mail::fake();
        $user = User::factory()->create();
        $this->post('/forgot-password', ['email' => $user->email]);
        $token = DB::table('password_reset_otps')->where('email', $user->email)->value('token');
        $this->get('/reset-password-link?token='.$token.'&email='.urlencode($user->email));
        $grantSession = session()->only(['password_reset_token', 'password_reset_email', 'password_reset_product', 'password_reset_record']);
        $input = ['token' => $grantSession['password_reset_token'], 'email' => $user->email,
            'password' => 'NewSynthetic123!', 'password_confirmation' => 'NewSynthetic123!'];
        $this->post('/reset-password', $input)->assertRedirect(route('login'));
        $this->assertTrue(Hash::check('NewSynthetic123!', $user->fresh()->password));
        $this->withSession($grantSession)->post('/reset-password', array_replace($input,
            ['password' => 'ReplaySynthetic123!', 'password_confirmation' => 'ReplaySynthetic123!']))
            ->assertRedirect(route('password.request'))->assertSessionHasErrors('email');
        $this->assertTrue(Hash::check('NewSynthetic123!', $user->fresh()->password));
    }

    public function test_expired_reset_form_redirects_to_a_fresh_request(): void
    {
        Mail::fake();
        $user = User::factory()->create();
        $this->post('/forgot-password', ['email' => $user->email]);
        $token = DB::table('password_reset_otps')->where('email', $user->email)->value('token');
        $this->get('/reset-password-link?token='.$token.'&email='.urlencode($user->email));
        $grant = session('password_reset_token');
        $this->travel(16)->minutes();
        $this->get('/reset-password?token='.$grant.'&email='.urlencode($user->email))
            ->assertRedirect(route('password.request'))->assertSessionHasErrors('email');
        $this->assertNull(session('password_reset_token'));
        $this->travelBack();
    }

    public function test_new_request_revokes_a_previously_verified_grant_and_otp_still_works(): void
    {
        Mail::fake();
        $user = User::factory()->create();
        $this->post('/forgot-password', ['email' => $user->email]);
        $token = DB::table('password_reset_otps')->where('email', $user->email)->value('token');
        $this->get('/reset-password-link?token='.$token.'&email='.urlencode($user->email));
        $oldGrant = session('password_reset_token');
        $this->post('/forgot-password', ['email' => $user->email]);
        $this->post('/reset-password', ['token' => $oldGrant, 'email' => $user->email,
            'password' => 'RevokedSynthetic123!', 'password_confirmation' => 'RevokedSynthetic123!'])
            ->assertRedirect(route('password.request'));
        $record = DB::table('password_reset_otps')->where('email', $user->email)->where('used', false)->sole();
        $this->post(route('password.verify.otp.submit'), ['email' => $user->email, 'otp' => $record->otp])->assertRedirect();
        $grant = session('password_reset_token');
        $this->assertNotEmpty($grant);
        $this->post('/reset-password', ['token' => $grant, 'email' => $user->email,
            'password' => 'ValidOtpSynthetic123!', 'password_confirmation' => 'ValidOtpSynthetic123!'])
            ->assertRedirect(route('login'));
        $this->assertTrue(Hash::check('ValidOtpSynthetic123!', $user->fresh()->password));
    }
}
