<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\User;
use App\Services\CompanyGreetingService;
use App\Services\GreetingTransport;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class OptInGreetingsTest extends TestCase
{
    use RefreshDatabase;

    private function seedConsent(): array
    {
        $company = Company::create(['name' => 'Synthetic greeting company', 'ntn' => 'SYN-GREET']);
        $user = User::factory()->create(['company_id' => $company->id, 'role' => 'company_admin']);
        DB::table('greeting_preferences')->insert(['company_id' => $company->id, 'enabled' => true, 'timezone' => 'Asia/Karachi', 'templates' => json_encode(['birthday' => 'Hello {name} from {company}']), 'updated_by' => $user->id, 'created_at' => now(), 'updated_at' => now()]);
        $recipient = DB::table('greeting_recipients')->insertGetId(['company_id' => $company->id, 'name' => 'Synthetic recipient', 'email' => 'recipient@example.test', 'event' => 'birthday', 'event_date' => '2000-02-29', 'email_consent' => true, 'recorded_by' => $user->id, 'created_at' => now(), 'updated_at' => now()]);

        return [$company, $user, $recipient];
    }

    public function test_welcome_is_once_on_exact_local_date_and_leap_day_is_explicit(): void
    {
        $service = app(CompanyGreetingService::class);
        $welcome = (object) ['event' => 'welcome', 'event_date' => '2027-02-28'];
        $this->assertTrue($service->due($welcome, CarbonImmutable::parse('2027-02-28', 'Asia/Karachi')));
        $this->assertFalse($service->due($welcome, CarbonImmutable::parse('2028-02-28', 'Asia/Karachi')));
        $birthday = (object) ['event' => 'birthday', 'event_date' => '2000-02-29'];
        $this->assertFalse($service->due($birthday, CarbonImmutable::parse('2028-02-28', 'Asia/Karachi')));
        $this->assertTrue($service->due($birthday, CarbonImmutable::parse('2028-02-29', 'Asia/Karachi')));
    }

    public function test_stale_owner_cannot_read_preview_or_mutate_after_role_active_or_membership_change(): void
    {
        [$company, $owner] = $this->seedConsent();
        $other = Company::create(['name'=>'Synthetic moved company','ntn'=>'SYN-MOVED']);
        foreach ([['role'=>'user'], ['is_active'=>false], ['company_id'=>$other->id]] as $change) {
            DB::table('users')->where('id',$owner->id)->update(['role'=>'company_admin','is_active'=>true,'company_id'=>$company->id]);
            $stale = $owner->fresh();
            DB::table('users')->where('id',$owner->id)->update($change);
            foreach (['index','preview','save','recipient'] as $action) {
                $request = \Illuminate\Http\Request::create('/profile/greetings','POST',['template'=>'Hello {name}']);
                $request->setUserResolver(fn () => $stale);
                try {
                    app(\App\Http\Controllers\GreetingPreferencesController::class)->{$action}($request);
                    $this->fail('Stale owner retained '.$action.' access');
                } catch (\Symfony\Component\HttpKernel\Exception\HttpException $error) {
                    $this->assertSame(403,$error->getStatusCode());
                }
            }
        }
        $this->assertDatabaseCount('greeting_recipients',1);
        $this->assertDatabaseCount('greeting_deliveries',0);
    }

    public function test_validation_error_has_a_unique_focus_target_inside_greeting_workspace(): void
    {
        [, $owner] = $this->seedConsent();
        $this->actingAs($owner)->from('/profile/greetings')->followingRedirects()
            ->post('/profile/greetings', ['enabled'=>false,'timezone'=>'Asia/Karachi','templates'=>['welcome'=>'Invalid {secret}']])
            ->assertOk()->assertSee('id="greeting-errors" role="alert" tabindex="-1"', false)
            ->assertSee("document.getElementById('greeting-errors')", false)
            ->assertSee('for="greeting-event-new"', false)->assertSee('id="greeting-event-new" name="event"', false);
    }

    public function test_default_release_gate_never_calls_transport(): void
    {
        $this->seedConsent();
        $this->artisan('greetings:dispatch')->assertSuccessful();
        $this->assertDatabaseCount('greeting_deliveries', 0);
    }

    public function test_leap_day_local_date_dedup_and_template_change_do_not_resend(): void
    {
        [$company] = $this->seedConsent();
        $fake = new class extends GreetingTransport
        {
            public array $sent = [];

            public function available(): bool
            {
                return true;
            }

            public function send(string $email, string $body): void
            {
                $this->sent[] = [$email, $body];
            }
        };
        $this->app->instance(GreetingTransport::class, $fake);
        $now = CarbonImmutable::parse('2027-02-27 20:00:00', 'UTC');
        $service = app(CompanyGreetingService::class);
        $service->run($now);
        $service->run($now);
        $this->assertCount(1, $fake->sent);
        $this->assertStringContainsString('Synthetic recipient', $fake->sent[0][1]);
        $this->assertDatabaseHas('greeting_deliveries', ['occurrence' => '2027-02-28', 'status' => 'sent']);
        DB::table('greeting_preferences')->where('company_id', $company->id)->update(['templates' => json_encode(['birthday' => 'Changed {name}'])]);
        $service->run($now);
        $this->assertCount(1, $fake->sent);
    }

    public function test_unknown_transport_outcome_is_held_without_retry(): void
    {
        $this->seedConsent();
        $fake = new class extends GreetingTransport
        {
            public int $calls = 0;

            public function available(): bool
            {
                return true;
            }

            public function send(string $email, string $body): void
            {
                $this->calls++;
                throw new \RuntimeException('timeout');
            }
        };
        $this->app->instance(GreetingTransport::class, $fake);
        $s = app(CompanyGreetingService::class);
        $now = CarbonImmutable::parse('2027-02-28');
        $s->run($now);
        $s->run($now);
        $this->assertSame(1, $fake->calls);
        $this->assertDatabaseHas('greeting_deliveries', ['status' => 'hold', 'reason' => 'delivery_outcome_unknown']);
    }

    public function test_provider_retry_bounded_and_revoked_consent_rechecked(): void
    {
        [,$user,$recipient] = $this->seedConsent();
        $fake = new class extends GreetingTransport
        {
            public function available(): bool
            {
                return false;
            }

            public function send(string $email, string $body): void
            {
                throw new \LogicException('must not send');
            }
        };
        $this->app->instance(GreetingTransport::class, $fake);
        $s = app(CompanyGreetingService::class);
        $now = CarbonImmutable::parse('2027-02-28');
        for ($i = 0; $i < 5; $i++) {
            $s->run($now);
        }$this->assertDatabaseHas('greeting_deliveries', ['attempts' => 3, 'reason' => 'provider_unavailable']);
        DB::table('greeting_deliveries')->update(['attempts' => 0, 'status' => 'pending']);
        DB::table('greeting_recipients')->where('id', $recipient)->update(['email_consent' => false]);
        $s->run($now);
        $this->assertDatabaseHas('greeting_deliveries', ['status' => 'cancelled']);
    }

    public function test_owner_only_cross_tenant_denial_and_preview_never_sends(): void
    {
        [,$owner,$recipient] = $this->seedConsent();
        $other = Company::create(['name' => 'Other synthetic', 'ntn' => 'SYN-GREET2']);
        $foreign = User::factory()->create(['company_id' => $other->id, 'role' => 'company_admin']);
        $this->actingAs($foreign)->postJson('/profile/greetings/recipient', ['id' => $recipient, 'name' => 'x', 'email' => 'x@example.test', 'event' => 'welcome', 'event_date' => '2026-01-01', 'email_consent' => false])->assertNotFound();
        $this->actingAs($owner)->postJson('/profile/greetings/preview', ['template' => 'Hello {secret}'])->assertUnprocessable();
        $this->actingAs($owner)->postJson('/profile/greetings/preview', ['template' => 'Hello {name}'])->assertOk()->assertJson(['sent' => false]);
        $staff = User::factory()->create(['company_id' => $owner->company_id, 'role' => 'user']);
        $this->actingAs($staff)->getJson('/profile/greetings')->assertForbidden();
    }
}
