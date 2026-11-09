<?php

namespace Tests\Feature\Auth;

use App\Models\{Company, User};
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\{Auth, DB, Http, Mail};
use Tests\TestCase;

class PasswordResetSessionRevocationTest extends TestCase
{
    use RefreshDatabase;

    public function test_password_reset_revokes_old_company_sessions_but_preserves_other_product_and_fresh_login(): void
    {
        Mail::fake(); Http::preventStrayRequests();
        $company = Company::create(['name' => 'Fictional reset sessions', 'ntn' => 'RCSESS1',
            'product_type' => 'di', 'status' => 'approved', 'company_status' => 'active', 'onboarding_completed' => false]);
        $owner = User::factory()->create(['company_id' => $company->id, 'role' => 'company_admin',
            'is_active' => true, 'password' => 'OldSynthetic123!']);
        $otherCompany = Company::create(['name' => 'Other fictional session', 'ntn' => 'RCSESS2', 'product_type' => 'pos']);
        $other = User::factory()->create(['company_id' => $otherCompany->id, 'is_active' => true]);

        $this->post('/login', ['login' => $owner->email, 'password' => 'OldSynthetic123!'])->assertRedirect('/dashboard');
        $oldSession = session()->all();
        Auth::guard('web')->logout();
        $this->flushSession();
        $this->post('/forgot-password', ['email' => $owner->email])->assertRedirect();
        $link = DB::table('password_reset_otps')->where('email', $owner->email)->sole()->token;
        $this->get('/reset-password-link?token='.$link.'&email='.urlencode($owner->email))->assertRedirect();
        $token = session('password_reset_token');
        $this->post('/reset-password', ['token' => $token, 'email' => $owner->email,
            'password' => 'NewSynthetic123!', 'password_confirmation' => 'NewSynthetic123!'])->assertRedirect('/login');

        // Restore the separate pre-reset browser session, while another product
        // identity coexists. No database session-table user_id sweep is allowed:
        // numeric IDs can overlap across providers and guards.
        $this->withSession($oldSession)->actingAs($owner, 'web');
        Auth::guard('pos')->login($other);
        $admin = \App\Models\AdminUser::create(['name' => 'Separate fictional admin', 'email' => 'session-admin@reset.invalid',
            'password' => 'SyntheticAdmin123!', 'role' => 'super_admin']);
        Auth::guard('admin')->login($admin);
        $otherBefore = $other->fresh()->getAttributes();
        $response = $this->post('/onboarding/skip');
        $this->assertFalse((bool) $company->fresh()->onboarding_completed);
        $response->assertRedirect('/login');
        $this->assertGuest('web');
        $this->assertAuthenticatedAs($other, 'pos');
        $this->assertAuthenticatedAs($admin, 'admin');
        $this->assertSame($otherBefore, $other->fresh()->getAttributes());

        $this->post('/login', ['login' => $owner->email, 'password' => 'NewSynthetic123!'])->assertRedirect('/dashboard');
        $this->post('/onboarding/skip')->assertRedirect('/dashboard');
        $this->assertTrue((bool) $company->fresh()->onboarding_completed);
        Http::assertNothingSent();
    }
}
