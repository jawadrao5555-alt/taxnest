<?php

namespace Tests\Feature\Auth;

use App\Models\AdminUser;
use App\Models\Company;
use App\Models\User;
use App\Services\TwoStepLogin;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class TwoStepLoginTest extends TestCase
{
    use RefreshDatabase;

    private const KEY = 'GEZDGNBVGY3TQOJQGEZDGNBVGY3TQOJQ';

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
        foreach (['di' => 'auth', 'pos' => 'pos.auth', 'fbrpos' => 'fbrpos.auth', 'health' => 'health.auth', 'admin' => 'admin.auth'] as $panel => $middleware) {
            Route::middleware(['web', $middleware])->match(['GET', 'POST'], '/two-step-test/'.$panel, fn () => response('Protected handler reached'));
        }
    }

    private function actor(string $panel = 'di'): object
    {
        if ($panel === 'admin') {
            return AdminUser::create(['name' => 'Fictional admin', 'email' => 'factor-admin@example.invalid',
                'password' => 'Synthetic123!', 'role' => 'super_admin']);
        }
        $company = Company::create(['name' => 'Fictional factor '.$panel, 'ntn' => uniqid('factor'),
            'product_type' => $panel === 'health' ? 'erps' : $panel, 'fbr_pos_enabled' => $panel === 'fbrpos',
            'status' => 'approved', 'company_status' => 'active']);

        return User::factory()->create(['company_id' => $company->id, 'role' => 'company_admin', 'is_active' => true,
            'product_type' => $panel === 'health' ? 'erps' : $panel, 'password' => 'Synthetic123!']);
    }

    private function install(object $actor, ?int $last = null): void
    {
        DB::table('two_step_credentials')->insert(TwoStepLogin::identity($actor) + [
            'secret' => Crypt::encryptString(self::KEY), 'recovery_hashes' => json_encode([hash('sha256', 'synthetic-recovery')]),
            'last_step' => $last, 'created_at' => now(), 'updated_at' => now()]);
    }

    public function test_official_hotp_and_totp_sha1_vectors(): void
    {
        foreach (['755224', '287082', '359152', '969429', '338314', '254676', '287922', '162583', '399871', '520489'] as $step => $expected) {
            $this->assertSame($expected, TwoStepLogin::code(self::KEY, $step));
        }
        foreach ([59 => '94287082', 1111111109 => '07081804', 1111111111 => '14050471', 1234567890 => '89005924', 2000000000 => '69279037', 20000000000 => '65353130'] as $time => $expected) {
            $this->assertSame($expected, TwoStepLogin::code(self::KEY, intdiv($time, 30), 8));
        }
    }

    public function test_opt_in_requires_password_and_confirmed_code_and_stores_only_encrypted_key_and_hashed_recovery(): void
    {
        $actor = $this->actor();
        $this->actingAs($actor, 'web')->get('/security/two-step/di')->assertOk()->assertSee('Off');
        $this->post('/security/two-step/di/begin', ['password' => 'wrong'])->assertSessionHasErrors('password');
        $this->assertDatabaseCount('two_step_credentials', 0);
        $this->post('/security/two-step/di/begin', ['password' => 'Synthetic123!'])->assertRedirect();
        $key = session('two_step_enrollment.web.secret');
        $this->assertMatchesRegularExpression('/^[A-Z2-7]{32}$/D', $key);
        $this->get('/security/two-step/di')->assertOk()->assertSee($key);
        $this->post('/security/two-step/di/confirm', ['code' => TwoStepLogin::code($key, intdiv(now()->timestamp, 30))])->assertRedirect();
        $row = TwoStepLogin::credential($actor);
        $this->assertNotSame($key, $row->secret);
        $this->assertSame($key, Crypt::decryptString($row->secret));
        $codes = session('two_step_recovery_codes.codes');
        $this->assertCount(10, $codes);
        $this->assertSame(array_map(fn ($code) => hash('sha256', $code), $codes), json_decode($row->recovery_hashes, true));
        $response = $this->get('/security/two-step/di')->assertOk()->assertSee($codes[0]);
        $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
        $this->assertStringContainsString('private', $response->headers->get('Cache-Control'));
        $this->get('/security/two-step/di')->assertOk()->assertDontSee($codes[0]);
        $this->assertDatabaseHas('two_step_events', ['actor_id' => $actor->id, 'event' => 'enabled']);
    }

    public function test_every_panel_blocks_get_write_and_json_until_a_fresh_factor(): void
    {
        foreach (TwoStepLogin::PANELS as $panel => $guard) {
            $actor = $this->actor($panel);
            $this->install($actor);
            $this->actingAs($actor, $guard);
            $challenge = route('two-step.challenge', ['panel' => $panel]);
            $this->get('/two-step-test/'.$panel)->assertRedirect($challenge);
            $this->post('/two-step-test/'.$panel)->assertRedirect($challenge);
            $this->getJson('/two-step-test/'.$panel)->assertForbidden()->assertJsonPath('error', 'two_step_required');
            $this->post('/security/two-step/'.$panel.'/verify', ['code' => TwoStepLogin::code(self::KEY, intdiv(now()->timestamp, 30))])->assertRedirect();
            $this->get('/two-step-test/'.$panel)->assertOk()->assertSee('Protected handler reached');
            $this->post('/two-step-test/'.$panel)->assertOk();
            Auth::guard($guard)->logout();
            $this->flushSession();
        }
    }

    public function test_used_step_and_recovery_cannot_replay_and_other_actor_is_unchanged(): void
    {
        $actor = $this->actor();
        $other = $this->actor('pos');
        $this->install($actor);
        $this->install($other);
        $before = TwoStepLogin::credential($other);
        $code = TwoStepLogin::code(self::KEY, intdiv(now()->timestamp, 30));
        $this->assertTrue(TwoStepLogin::consume($actor, $code));
        $this->assertFalse(TwoStepLogin::consume($actor, $code));
        $this->assertTrue(TwoStepLogin::consume($actor, 'synthetic-recovery'));
        $this->assertFalse(TwoStepLogin::consume($actor, 'synthetic-recovery'));
        $this->assertEquals($before, TwoStepLogin::credential($other));
    }

    public function test_guess_limit_is_record_bound_and_recovers_after_cooldown(): void
    {
        $actor = $this->actor();
        $this->install($actor);
        $this->actingAs($actor, 'web');
        for ($i = 1; $i <= 5; $i++) {
            $this->withServerVariables(['REMOTE_ADDR' => '192.0.2.'.$i])
                ->post('/security/two-step/di/verify', ['code' => 'invalid'])->assertSessionHasErrors('code');
        }
        $this->assertSame(5, (int) TwoStepLogin::credential($actor)->failed_attempts);
        $this->assertFalse(TwoStepLogin::consume($actor, 'synthetic-recovery'));
        $this->travel(6)->minutes();
        $this->assertTrue(TwoStepLogin::consume($actor, 'synthetic-recovery'));
        $this->assertSame(0, (int) TwoStepLogin::credential($actor)->failed_attempts);
    }

    public function test_password_change_and_new_login_invalidate_proof_without_disabling_factor_or_other_guard(): void
    {
        $actor = $this->actor();
        $other = $this->actor('pos');
        $this->install($actor);
        $this->install($other);
        $this->actingAs($actor, 'web');
        Auth::guard('pos')->login($other);
        $this->post('/security/two-step/di/verify', ['code' => 'synthetic-recovery'])->assertRedirect();
        $proof = session('two_step_verified.web');
        $this->assertNotEmpty($proof);
        $this->get('/two-step-test/di')->assertOk();
        $actor->update(['password' => Hash::make('ChangedSynthetic123!')]);
        $this->get('/two-step-test/di')->assertRedirect(route('two-step.challenge', ['panel' => 'di']));
        $this->assertNotNull(TwoStepLogin::credential($actor));
        $this->withSession(['two_step_verified.web' => $proof]);
        Auth::guard('web')->login($actor->fresh());
        $this->assertNull(session('two_step_verified.web'));
        $this->assertAuthenticatedAs($other, 'pos');
    }

    public function test_enrollment_expires_and_cannot_survive_password_change(): void
    {
        $actor = $this->actor();
        $this->actingAs($actor, 'web');
        $this->post('/security/two-step/di/begin', ['password' => 'Synthetic123!']);
        $key = session('two_step_enrollment.web.secret');
        $this->travel(11)->minutes();
        $this->post('/security/two-step/di/confirm', ['code' => TwoStepLogin::code($key, intdiv(now()->timestamp, 30))])->assertSessionHasErrors('code');
        $this->assertDatabaseCount('two_step_credentials', 0);
        $this->travelBack();
        $this->post('/security/two-step/di/begin', ['password' => 'Synthetic123!']);
        $key = session('two_step_enrollment.web.secret');
        $actor->update(['password' => 'ChangedSynthetic123!']);
        $this->post('/security/two-step/di/confirm', ['code' => TwoStepLogin::code($key, intdiv(now()->timestamp, 30))])->assertSessionHasErrors('code');
        $this->assertDatabaseCount('two_step_credentials', 0);
    }

    public function test_disabling_requires_both_password_and_unused_factor_and_keeps_other_accounts(): void
    {
        $actor = $this->actor();
        $other = $this->actor('pos');
        $this->install($actor);
        $this->install($other);
        $this->actingAs($actor, 'web');
        $this->post('/security/two-step/di/disable', ['password' => 'wrong', 'code' => 'synthetic-recovery'])->assertSessionHasErrors('password');
        $this->assertNotNull(TwoStepLogin::credential($actor));
        $this->post('/security/two-step/di/disable', ['password' => 'Synthetic123!', 'code' => 'invalid'])->assertSessionHasErrors('code');
        $this->post('/security/two-step/di/disable', ['password' => 'Synthetic123!', 'code' => 'synthetic-recovery'])->assertRedirect();
        $this->assertNull(TwoStepLogin::credential($actor));
        $this->assertNotNull(TwoStepLogin::credential($other));
        $this->assertDatabaseHas('two_step_events', ['actor_id' => $actor->id, 'event' => 'disabled']);
    }

    public function test_guest_and_cross_product_cannot_manage_another_guard(): void
    {
        $this->get('/security/two-step/di')->assertForbidden();
        $actor = $this->actor('pos');
        $this->actingAs($actor, 'pos');
        $this->post('/security/two-step/di/begin', ['password' => 'Synthetic123!'])->assertForbidden();
        $this->assertDatabaseCount('two_step_credentials', 0);
    }

    public function test_read_only_impersonation_uses_admin_factor_without_changing_company_credentials(): void
    {
        $actor = $this->actor('pos');
        $admin = $this->actor('admin');
        $this->install($actor);
        $this->install($admin);
        $before = TwoStepLogin::credential($actor);
        $this->actingAs($actor, 'pos');
        Auth::guard('admin')->login($admin);
        $this->withSession(['impersonation' => ['admin_id' => $admin->id, 'guard' => 'pos', 'company_id' => $actor->company_id,
            'user_id' => $actor->id, 'readonly' => true, 'started_at' => now()->timestamp]]);
        $this->get('/two-step-test/pos')->assertRedirect(route('two-step.challenge', ['panel' => 'admin']));
        $this->post('/security/two-step/admin/verify', ['code' => 'synthetic-recovery'])->assertRedirect('/admin/dashboard');
        $this->get('/two-step-test/pos')->assertOk();
        $this->get('/security/two-step/pos')->assertForbidden();
        $this->assertEquals($before, TwoStepLogin::credential($actor));
    }

    public function test_consented_consultant_uses_own_factor_and_revoked_link_still_forces_exit(): void
    {
        $consultant = $this->actor();
        $client = $this->actor();
        \App\Services\ConsultantService::activateProfile($consultant);
        $link = \App\Models\ConsultantClientLink::create(['consultant_user_id' => $consultant->id,
            'company_id' => $client->company_id, 'status' => 'active']);
        $this->install($consultant);
        $this->install($client);
        $clientBefore = TwoStepLogin::credential($client);
        $this->actingAs($client, 'web')->withSession(['consultant_console' => [
            'consultant_user_id' => $consultant->id, 'client_user_id' => $client->id, 'client_company_id' => $client->company_id]]);
        $this->get('/two-step-test/di')->assertRedirect(route('two-step.challenge', ['panel' => 'di']));
        $this->post('/security/two-step/di/verify', ['code' => 'synthetic-recovery'])->assertRedirect();
        $this->get('/two-step-test/di')->assertOk();
        $this->get('/security/two-step/di')->assertForbidden();
        $this->assertEquals($clientBefore, TwoStepLogin::credential($client));
        $link->update(['status' => 'revoked']);
        $this->get('/two-step-test/di')->assertRedirect('/consultant');
        $this->assertAuthenticatedAs($consultant->fresh(), 'web');
        $this->assertFalse(session()->has('consultant_console'));
    }

    public function test_own_logout_and_delegated_exit_do_not_require_a_factor_or_end_other_guard(): void
    {
        $actor = $this->actor();
        $other = $this->actor('pos');
        $this->install($actor);
        $this->actingAs($actor, 'web');
        Auth::guard('pos')->login($other);
        $this->post('/security/two-step/di/logout')->assertRedirect('/login');
        $this->assertGuest('web');
        $this->assertAuthenticatedAs($other, 'pos');
        $consultant = $this->actor();
        \App\Services\ConsultantService::activateProfile($consultant);
        $this->install($consultant);
        \App\Models\ConsultantClientLink::create(['consultant_user_id' => $consultant->id, 'company_id' => $actor->company_id, 'status' => 'active']);
        $this->actingAs($actor, 'web')->withSession(['consultant_console' => [
            'consultant_user_id' => $consultant->id, 'client_user_id' => $actor->id, 'client_company_id' => $actor->company_id]]);
        $this->post('/consultant/exit')->assertRedirect('/consultant');
        $this->assertAuthenticatedAs($consultant->fresh(), 'web');
        $this->assertAuthenticatedAs($other,'pos');
    }
}
