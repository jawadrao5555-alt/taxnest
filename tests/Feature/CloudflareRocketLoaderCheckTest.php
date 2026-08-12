<?php

namespace Tests\Feature;

use App\Models\AdminUser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * Locks the Rocket Loader nightly guard (cloudflare:check-rocket-loader):
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

    private const URL = 'https://taxnest.com.pk/';

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
