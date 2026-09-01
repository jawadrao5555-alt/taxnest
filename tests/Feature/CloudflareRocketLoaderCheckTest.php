<?php

namespace Tests\Feature;

use App\Models\AdminUser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * Locks the Rocket Loader every-30-min guard (cloudflare:check-rocket-loader):
 *  - clean homepage => success, no mail
 *  - rocket-loader injection detected => failure exit + urgent admin email
 *  - fetch failure / non-2xx => failure exit, NO alert (transient blip ≠ incident)
 *
 * NOTE: Mail::fake() cannot be used — MailFake::raw() is an explicit no-op,
 * so the command's Mail::raw send would record nothing. We run the real mail
 * pipeline against the 'array' transport and assert on the captured Symfony
 * messages instead (same pattern as HeartbeatWatchdogClosureTest).
 */
class CloudflareRocketLoaderCheckTest extends TestCase
{
    use RefreshDatabase;

    private const URL = 'https://taxnest.pk/';

    protected function setUp(): void
    {
        parent::setUp();
        config(['mail.default' => 'array']);
    }

    private function makeAdmin(string $email = 'admin@example.com'): void
    {
        AdminUser::create([
            'name' => 'Admin',
            'email' => $email,
            'password' => 'secret',
            'role' => 'super_admin',
        ]);
    }

    private function sentMessages(): array
    {
        return Mail::mailer('array')->getSymfonyTransport()->messages()->all();
    }

    public function test_clean_page_passes_and_sends_no_mail(): void
    {
        $this->makeAdmin();
        Http::fake([self::URL => Http::response('<html><body>ok</body></html>', 200)]);

        $this->artisan('cloudflare:check-rocket-loader')->assertExitCode(0);

        $this->assertCount(0, $this->sentMessages());
    }

    public function test_rocket_loader_detected_alerts_all_admins(): void
    {
        $this->makeAdmin('one@example.com');
        $this->makeAdmin('two@example.com');
        Http::fake([self::URL => Http::response(
            '<html><head><script src="/cdn-cgi/scripts/7d0fa10a/cloudflare-static/rocket-loader.min.js"></script></head></html>',
            200
        )]);

        $this->artisan('cloudflare:check-rocket-loader')->assertExitCode(1);

        $messages = $this->sentMessages();
        $this->assertCount(1, $messages, 'Exactly one alert email expected');

        $email = $messages[0]->getOriginalMessage();
        // A default admin may be seeded by migrations; assert OUR admins are
        // both addressed rather than pinning the exact recipient set.
        $to = array_map(fn ($a) => $a->getAddress(), $email->getTo());
        $this->assertContains('one@example.com', $to);
        $this->assertContains('two@example.com', $to);
        $this->assertStringContainsString('Rocket Loader', (string) $email->getSubject());
        $this->assertStringContainsString('Rocket Loader OFF', $email->getTextBody());
    }

    public function test_detected_with_api_configured_auto_fixes_and_sends_fixed_email(): void
    {
        $this->makeAdmin();
        config(['services.cloudflare.api_token' => 'test-token', 'services.cloudflare.zone_id' => 'zone123']);
        Http::fake([
            self::URL => Http::response(
                '<html><head><script src="/cdn-cgi/scripts/7d0fa10a/cloudflare-static/rocket-loader.min.js"></script></head></html>',
                200
            ),
            'https://api.cloudflare.com/client/v4/zones/zone123/settings/rocket_loader' => Http::response(
                ['success' => true, 'result' => ['id' => 'rocket_loader', 'value' => 'off']],
                200
            ),
        ]);

        // Still exit 1 — detection happened; scheduler/log should show the incident.
        $this->artisan('cloudflare:check-rocket-loader')->assertExitCode(1);

        Http::assertSent(function ($request) {
            return $request->url() === 'https://api.cloudflare.com/client/v4/zones/zone123/settings/rocket_loader'
                && $request->method() === 'PATCH'
                && ($request->data()['value'] ?? null) === 'off'
                && $request->hasHeader('Authorization', 'Bearer test-token');
        });

        $messages = $this->sentMessages();
        $this->assertCount(1, $messages);
        $email = $messages[0]->getOriginalMessage();
        $this->assertStringContainsString('FIXED', (string) $email->getSubject());
        $this->assertStringContainsString('AUTOMATICALLY turned OFF', $email->getTextBody());
    }

    public function test_detected_with_api_failure_falls_back_to_urgent_email(): void
    {
        $this->makeAdmin();
        config(['services.cloudflare.api_token' => 'test-token', 'services.cloudflare.zone_id' => 'zone123']);
        Http::fake([
            self::URL => Http::response(
                '<html><head><script src="/cdn-cgi/scripts/7d0fa10a/cloudflare-static/rocket-loader.min.js"></script></head></html>',
                200
            ),
            'https://api.cloudflare.com/client/v4/zones/zone123/settings/rocket_loader' => Http::response(
                ['success' => false, 'errors' => [['code' => 9109, 'message' => 'Invalid access token']]],
                403
            ),
        ]);

        $this->artisan('cloudflare:check-rocket-loader')->assertExitCode(1);

        $messages = $this->sentMessages();
        $this->assertCount(1, $messages);
        $email = $messages[0]->getOriginalMessage();
        $this->assertStringContainsString('URGENT', (string) $email->getSubject());
        $this->assertStringContainsString('Fix NOW', $email->getTextBody());
    }

    public function test_detected_without_api_config_sends_urgent_email(): void
    {
        $this->makeAdmin();
        config(['services.cloudflare.api_token' => '', 'services.cloudflare.zone_id' => '']);
        Http::fake([self::URL => Http::response(
            '<html><head><script src="/cdn-cgi/scripts/7d0fa10a/cloudflare-static/rocket-loader.min.js"></script></head></html>',
            200
        )]);

        $this->artisan('cloudflare:check-rocket-loader')->assertExitCode(1);

        // No API call attempted at all.
        Http::assertNotSent(fn ($request) => str_contains($request->url(), 'api.cloudflare.com'));

        $messages = $this->sentMessages();
        $this->assertCount(1, $messages);
        $email = $messages[0]->getOriginalMessage();
        $this->assertStringContainsString('URGENT', (string) $email->getSubject());
    }

    public function test_marker_words_apart_do_not_false_positive(): void
    {
        // "rocket-loader" mentioned in page copy without a cdn-cgi injection
        // must NOT alert (both markers are required together).
        $this->makeAdmin();
        Http::fake([self::URL => Http::response(
            '<html><body>We keep rocket-loader OFF, see docs.</body></html>',
            200
        )]);

        $this->artisan('cloudflare:check-rocket-loader')->assertExitCode(0);

        $this->assertCount(0, $this->sentMessages());
    }

    public function test_repeat_detection_within_throttle_window_sends_one_email(): void
    {
        // 30-min schedule + lingering Cloudflare edge cache: the SAME incident
        // must not email once per run. Second run still fails + re-attempts
        // auto-fix, but sends no second email.
        $this->makeAdmin();
        Http::fake([self::URL => Http::response(
            '<html><head><script src="/cdn-cgi/scripts/7d0fa10a/cloudflare-static/rocket-loader.min.js"></script></head></html>',
            200
        )]);

        $this->artisan('cloudflare:check-rocket-loader')->assertExitCode(1);
        $this->artisan('cloudflare:check-rocket-loader')->assertExitCode(1);

        $this->assertCount(1, $this->sentMessages(), 'Repeat detection must be throttled to one email');
    }

    public function test_clean_run_resets_throttle_so_new_incident_alerts_again(): void
    {
        $this->makeAdmin();
        $dirty = '<html><head><script src="/cdn-cgi/scripts/7d0fa10a/cloudflare-static/rocket-loader.min.js"></script></head></html>';

        Http::fake([self::URL => Http::sequence()
            ->push($dirty, 200)
            ->push('<html><body>ok</body></html>', 200)
            ->push($dirty, 200)]);

        $this->artisan('cloudflare:check-rocket-loader')->assertExitCode(1);

        // Site recovers — clean run resets the throttle.
        $this->artisan('cloudflare:check-rocket-loader')->assertExitCode(0);

        // A NEW incident must alert immediately, not wait out the 6h window.
        $this->artisan('cloudflare:check-rocket-loader')->assertExitCode(1);

        $this->assertCount(2, $this->sentMessages(), 'New incident after recovery must alert again');
    }

    public function test_alert_sent_again_after_throttle_window_expires(): void
    {
        $this->makeAdmin();
        Http::fake([self::URL => Http::response(
            '<html><head><script src="/cdn-cgi/scripts/7d0fa10a/cloudflare-static/rocket-loader.min.js"></script></head></html>',
            200
        )]);

        $this->artisan('cloudflare:check-rocket-loader')->assertExitCode(1);
        $this->assertCount(1, $this->sentMessages());

        // Backdate the throttle marker past the 6h window.
        \App\Models\SystemSetting::set(
            'rocket_loader_last_alert_at',
            now()->subHours(7)->toDateTimeString()
        );

        $this->artisan('cloudflare:check-rocket-loader')->assertExitCode(1);
        $this->assertCount(2, $this->sentMessages(), 'Persisting incident should re-alert after the window');
    }

    public function test_non_2xx_response_fails_without_alert(): void
    {
        $this->makeAdmin();
        Http::fake([self::URL => Http::response('bad gateway', 502)]);

        $this->artisan('cloudflare:check-rocket-loader')->assertExitCode(1);

        $this->assertCount(0, $this->sentMessages());
    }

    public function test_connection_failure_fails_without_alert(): void
    {
        $this->makeAdmin();
        Http::fake(function () {
            throw new \Illuminate\Http\Client\ConnectionException('timeout');
        });

        $this->artisan('cloudflare:check-rocket-loader')->assertExitCode(1);

        $this->assertCount(0, $this->sentMessages());
    }
}
